<?php

namespace Wszdb\FlarumAiChat;

use Flarum\Discussion\Discussion;
use Flarum\Post\CommentPost;
use Flarum\Foundation\Paths;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Guest;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Arr;
use OpenAI;
use OpenAI\Client;


class Agent
{
    protected int $maxTokens;
    protected string $model;

    /** Who asked, for the reads that happen after the prompt is built (tools). */
    private ?User $currentAsker = null;

    public function __construct(
        public readonly User $user,
        protected ?Client    $client = null,
        ?string              $model = null,
        ?int                 $maxTokens = null
    )
    {
        $this->model = $model ?? 'gpt-3.5-turbo-instruct';
        $this->maxTokens = $maxTokens ?? 100;
    }

    public function repliesTo(Discussion $discussion): void
    {
        $log = resolve('log');

        try {
            $log->info('[ChatGPT] Starting repliesTo for discussion', [
                'discussion_id' => $discussion->id,
                'model' => $this->model,
                'is_reasoning_model' => $this->isReasoningModel()
            ]);

            $firstPost = $discussion->firstPost ?: $discussion->posts()->where('type', 'comment')->orderBy('number')->first();
            $content = $firstPost->content ?? '';
            $title = $discussion->title;

            if (trim($content) === '') {
                $log->warning('[ChatGPT] First post content empty, skipping', ['discussion_id' => $discussion->id]);
                return;
            }

            $messages = $this->promptFor($discussion, $title, $content, '', (int) ($discussion->user_id ?? 0), 1);

            $response = $this->sendCompletionRequest($messages);

            if (empty($response->choices)) {
                $log->warning('[ChatGPT] Empty response from model', [
                    'discussion_id' => $discussion->id,
                    'model' => $this->model
                ]);
                return;
            }

            $this->logSaved($this->saveResponse($response, $discussion->id), $discussion->id);
        } catch (\Exception $e) {
            $log->error('[ChatGPT] Exception in repliesTo', [
                'discussion_id' => $discussion->id,
                'exception' => get_class($e),
                'message' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function repliesToCommentPost(CommentPost $commentPost, bool $force = false): void
    {
        $log = resolve('log');

        try {
            $discussion = $commentPost->discussion;
            $title = $discussion->title;
            $content = $discussion->firstPost->content ?? '';

            $log->info('[ChatGPT] Starting repliesToCommentPost', [
                'discussion_id' => $discussion->id,
                'post_id' => $commentPost->id,
                'post_number' => $commentPost->number,
                'model' => $this->model
            ]);

            if (!$force && !$this->checkIfAssistantCanReplyToPost($commentPost)) {
                $log->info('[ChatGPT] Assistant cannot reply to this post', [
                    'post_id' => $commentPost->id,
                    'reason' => 'checkIfAssistantCanReplyToPost returned false'
                ]);
                return;
            }

            $messages = $this->promptFor(
                $discussion,
                $title,
                $content,
                (string) $commentPost->content,
                (int) ($commentPost->user_id ?? 0),
                (int) $commentPost->number
            );

            $response = $this->sendCompletionRequest($messages);

            if (empty($response->choices)) {
                $log->warning('[ChatGPT] Empty response from model for comment', [
                    'post_id' => $commentPost->id,
                    'model' => $this->model
                ]);
                return;
            }

            $this->logSaved($this->saveResponse($response, $discussion->id), $discussion->id, $commentPost->id);
        } catch (\Exception $e) {
            $log->error('[ChatGPT] Exception in repliesToCommentPost', [
                'post_id' => $commentPost->id ?? null,
                'exception' => get_class($e),
                'message' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * The whole prompt for one answer: a byte-identical head (role + data
     * rule), the thread history as chat turns read as the asker, and a
     * volatile tail carrying the title, the fenced subject and the local
     * context blocks.
     *
     * @return array<int, array{role: string, content: string}>
     */
    private function promptFor(
        Discussion $discussion,
        string $title,
        string $content,
        string $extra,
        int $askerId,
        int $beforeNumber
    ): array {
        $settings = resolve(SettingsRepositoryInterface::class);
        $fence = new Fence();
        $asker = $this->currentAsker = $this->asker($askerId);

        ['role' => $role, 'prompt' => $prompt] = $this->prepareChatForMessage();

        $head = trim($role) . "\n\n" . Fence::rule();

        // the template names the thread, so it travels with the tail, not the head
        $tail = trim(str_replace(['[title]', '[content]'], [$title, ''], $prompt));

        // one shared context budget: files, linked threads and related threads
        $budget = (int) $settings->get('wszdb-flarumaichat.context_chars') ?: ContextFiles::MAX_TOTAL_CHARS;
        $subject = $title . "\n" . $content . ($extra !== '' ? "\n" . $extra : '');

        $blocks = [];

        $facts = $this->contextFacts($subject, $budget);
        $threads = $this->linkedThreads($subject, $asker, $budget - strlen($facts));
        $related = $this->relatedThreads($title, $discussion, $asker, $subject, $budget - strlen($facts) - strlen($threads));

        foreach ([$facts, $threads, $related] as $block) {
            if ($block !== '') {
                $blocks[] = $fence->wrap($block);
            }
        }

        $tail .= "\n\n" . $fence->wrap($extra !== '' ? $extra : $content);

        if ($blocks !== []) {
            $tail .= "\n\n" . implode("\n\n", $blocks);
        }

        // same-discussion history is read as the asker, inside its own budget
        $history = (new History($asker))->turns(
            $discussion,
            $beforeNumber,
            (int) $settings->get('wszdb-flarumaichat.user_prompt'),
            (int) ($settings->get('wszdb-flarumaichat.history_chars') ?: History::DEFAULT_CHARS)
        );

        return $this->assembleMessages($head, $history, $tail);
    }

    /**
     * @param array<int, array{role: string, content: string}> $history
     * @return array<int, array{role: string, content: string}>
     */
    private function assembleMessages(string $head, array $history, string $tail): array
    {
        // reasoning models take no system role: head and tail ride the first user turn
        if ($this->isReasoningModel()) {
            array_unshift($history, ['role' => 'user', 'content' => $head . "\n\n" . $tail]);

            return $history;
        }

        return array_merge(
            [['role' => 'system', 'content' => $head]],
            $history,
            [['role' => 'user', 'content' => $tail]]
        );
    }

    /**
     * The member whose post is being answered. A deleted or unknown author
     * falls back to a guest, never to the bot user, so a missing row cannot
     * widen what the answer may quote.
     */
    private function asker(int $userId): User
    {
        return User::query()->find($userId) ?: new Guest();
    }

    private function prepareChatForMessage(): array
    {
        // get settings from the database
        $settings = resolve(SettingsRepositoryInterface::class);
        // get role
        $role = $settings->get('wszdb-flarumaichat.role');
        if (empty($role)) {
            // if the role is empty, set the role to default
            $role = 'You are a helpful assistant.';
        }
        // get the prompt from the settings
        $prompt = $settings->get('wszdb-flarumaichat.prompt');
        if (empty($prompt)) {
            // if the prompt is empty, set the prompt to default
            $prompt = 'Write a arguable or thankfully opinion asking or arguing something about an answer that has talked about "[title]" and who talked about [content]. Don\'t talk about what you would like or don\'t like. Speak in a close tone, like you are writing in a Tech Forum. Be random and unpredictable. Answer in [language].';
        }

        return [
            'role' => $role,
            'prompt' => $prompt
        ];
    }

    public function checkModeration(string $title, string $content): bool
    {
        // check if the title or post content includes bad words
        // if it includes bad words, do not reply and give error message
        // if it does not include bad words, continue to reply
        $response = $this->client->moderations()->create([
            'input' => $title . ' ' . $content
        ]);

        $results = Arr::first($response->results);

        return !!$results->flagged;
    }


    /**
     * The domain list as z.ai wants it: comma separated, no blanks.
     */
    private function searchDomains(string $domains): string
    {
        $list = array_filter(array_map('trim', preg_split('/[\r\n,]+/', $domains)));

        return implode(',', $list);
    }

    private function sendCompletionRequest(array $messages)
    {
        $log = resolve('log');

        try {
            $response = $this->chat($messages, $this->extraParams());

            // the tool loop: bounded by executions, each round still billed
            if ($this->toolsEnabled()) {
                $response = $this->runTools($messages, $response);
            }

            return $response;
        } catch (\OpenAI\Exceptions\ErrorException $e) {
            $log->error('[ChatGPT] OpenAI API Error', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'model' => $this->model
            ]);
            Usage::record(failed: true);

            throw $e;
        } catch (\Exception $e) {
            $log->error('[ChatGPT] Unexpected error in sendCompletionRequest', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'model' => $this->model
            ]);
            Usage::record(failed: true);

            throw $e;
        }
    }

    /**
     * One chat completion. Records usage, cached tokens included.
     *
     * @param array<int, array<string, mixed>> $messages
     */
    private function chat(array $messages, array $extra = [])
    {
        $params = array_merge(['model' => $this->model, 'messages' => $messages], $extra);

        // z.ai GLM: thinking tokens consume max_tokens and add ~70s latency
        if (str_starts_with(strtolower($this->model), 'glm')) {
            $settings = resolve(SettingsRepositoryInterface::class);
            $params['thinking'] = ['type' => $settings->get('wszdb-flarumaichat.glm_thinking') ? 'enabled' : 'disabled'];
        }

        // Use max_completion_tokens for reasoning models (o1, o3, o4, gpt-5 series)
        // Use max_tokens for legacy models (gpt-3.5, gpt-4, etc.)
        if ($this->isReasoningModel()) {
            $params['max_completion_tokens'] = $this->maxTokens;
        } else {
            $params['max_tokens'] = $this->maxTokens;
        }

        $log = resolve('log');
        $log->info('[ChatGPT] API Request', [
            'model' => $this->model,
            'token_param' => $this->isReasoningModel() ? 'max_completion_tokens' : 'max_tokens',
            'token_value' => $this->maxTokens,
            'message_count' => count($messages),
            'tools' => isset($params['tools']) ? count($params['tools']) : 0
        ]);

        $response = $this->client->chat()->create($params);

        Usage::record(
            (int) ($response->usage->promptTokens ?? 0),
            (int) ($response->usage->completionTokens ?? 0),
            cachedTokens: (int) ($response->usage->promptTokensDetails->cachedTokens ?? 0)
        );

        return $response;
    }

    /**
     * Provider-native and function tools together: the GLM web search entry is
     * merged with, never overwritten by, the function definitions.
     */
    private function extraParams(): array
    {
        $params = [];
        $settings = resolve(SettingsRepositoryInterface::class);

        if (str_starts_with(strtolower($this->model), 'glm') && $settings->get('wszdb-flarumaichat.web_search')) {
            $search = ['enable' => true];
            $domains = $this->searchDomains((string) $settings->get('wszdb-flarumaichat.web_search_domains'));

            if ($domains !== '') {
                $search['search_domain_filter'] = $domains;
            }

            $params['tools'] = [['type' => 'web_search', 'web_search' => $search]];
        }

        if ($this->toolsEnabled()) {
            $params['tools'] = array_merge($params['tools'] ?? [], Tools::definitions());
            $params['tool_choice'] = 'auto';
        }

        return $params;
    }

    private function toolsEnabled(): bool
    {
        return (bool) resolve(SettingsRepositoryInterface::class)->get('wszdb-flarumaichat.tools_enabled');
    }

    /**
     * Run the model's tool calls until it answers or the execution budget is
     * spent. The cap bounds cost and blast radius only; confidentiality rests
     * on the readers the tools go through.
     *
     * @param array<int, array<string, mixed>> $messages
     */
    private function runTools(array $messages, $response)
    {
        $tools = new Tools(Readers::crossDiscussion($this->currentAsker));
        $fence = new Fence();
        $runs = 0;

        // ponytail: one round past the budget lets the model wrap up after its
        // last results; a model that keeps calling gets error results, not more runs
        for ($round = 0; $round <= Tools::MAX_RUNS; $round++) {
            $choice = $response->choices[0] ?? null;
            $calls = $choice->message->toolCalls ?? null;

            if (empty($calls)) {
                return $response;
            }

            // the assistant turn that asked, then one result per call
            $messages[] = [
                'role' => 'assistant',
                'content' => $choice->message->content ?? '',
                'tool_calls' => json_decode(json_encode($calls), true),
            ];

            foreach ($calls as $call) {
                // every call spends budget, malformed arguments included
                $runs++;

                $args = json_decode((string) ($call->function->arguments ?? ''), true);
                $result = $runs <= Tools::MAX_RUNS && is_array($args)
                    ? $fence->wrap($tools->run((string) ($call->function->name ?? ''), $args))
                    : 'error: tool budget spent';

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => (string) ($call->id ?? ''),
                    'content' => $result,
                ];
            }

            $response = $this->chat($messages, $this->extraParams());
        }

        resolve('log')->warning('[ChatGPT] Tool rounds exhausted, answering with the last response');

        return $response;
    }

    /**
     * Determine if the current model is a reasoning model.
     * Reasoning models (o1, o3, o4, gpt-5 series) require max_completion_tokens
     * instead of max_tokens. Anchored prefixes, so "gpt-4o1-mini" and the like
     * do not trip the check.
     */
    private function isReasoningModel(): bool
    {
        $modelLower = strtolower($this->model);

        return (bool) preg_match('~^(o[134](?:[-.]|$)|gpt-5)~', $modelLower);
    }


    private function saveResponse($response, $discussionId): bool
    {
        $log = resolve('log');

        try {
            $choice = Arr::first($response->choices);
            $respond = $choice->message->content ?? null;

            // length only: the answer itself is the post, not the log
            $log->info('[ChatGPT] Response details', [
                'discussion_id' => $discussionId,
                'has_choice' => !empty($choice),
                'has_message' => !empty($choice->message ?? null),
                'content_length' => strlen($respond ?? ''),
                'finish_reason' => $choice->finish_reason ?? null,
                'refusal' => $choice->message->refusal ?? null
            ]);

            if (empty($respond)) {
                $log->warning('[ChatGPT] Empty content in response', [
                    'discussion_id' => $discussionId,
                    'finish_reason' => $choice->finish_reason ?? 'unknown',
                    'refusal' => $choice->message->refusal ?? null
                ]);
                return false;
            }

            $post = CommentPost::reply(
                discussionId: $discussionId,
                content: $respond,
                userId: $this->user->id,
                ipAddress: '127.0.0.1'
            );
            $post->save();

            // ponytail: core dispatches the post's pending Posted event in its own
            // reply handler. Saving the model alone leaves the discussion's comment
            // count, last post and reply notifications stale.
            $events = resolve(Dispatcher::class);
            foreach ($post->releaseEvents() as $event) {
                $event->actor = $this->user;
                $events->dispatch($event);
            }

            return true;
        } catch (\Exception $e) {
            $log->error('[ChatGPT] Exception in saveResponse', [
                'discussion_id' => $discussionId,
                'exception' => get_class($e),
                'message' => $e->getMessage()
            ]);
            return false;
        }
    }

    private function logSaved(bool $saved, int $discussionId, ?int $postId = null): void
    {
        resolve('log')->{$saved ? 'info' : 'error'}('[ChatGPT] ' . ($saved ? 'Saved' : 'Failed to save') . ' response', [
            'discussion_id' => $discussionId,
            'post_id' => $postId
        ]);
    }

    /**
     * Facts about the post's subject, read from the local data files the admin listed.
     */
    private function contextFacts(string $text, int $budget): string
    {
        $settings = resolve(SettingsRepositoryInterface::class);
        $paths = (string) $settings->get('wszdb-flarumaichat.context_files');

        if (trim($paths) === '') {
            return '';
        }

        $facts = (new ContextFiles(resolve(Paths::class)->base, $paths, $budget))->factsFor($text);

        resolve('log')->info('[ChatGPT] Local context', ['chars' => strlen($facts)]);

        return $facts;
    }

    /**
     * The threads the post links to, when they live on this forum. Read as the
     * cross-discussion reader, never as the bot user.
     */
    private function linkedThreads(string $text, User $asker, int $budget): string
    {
        $settings = resolve(SettingsRepositoryInterface::class);

        if (!$settings->get('wszdb-flarumaichat.linked_threads')) {
            return '';
        }

        $forumUrl = (string) $settings->get('forum_url');

        $threads = (new LinkedDiscussions($asker, $forumUrl, $budget))
            ->factsFor($text, $this->threadSummaries() ? $this->summarizer() : null);

        resolve('log')->info('[ChatGPT] Linked threads', ['chars' => strlen($threads)]);

        return $threads;
    }

    /**
     * Discussions about the same thing, quoted the same way as linked threads.
     */
    private function relatedThreads(string $title, Discussion $current, User $asker, string $subject, int $budget): string
    {
        $settings = resolve(SettingsRepositoryInterface::class);

        if (!$settings->get('wszdb-flarumaichat.related_threads')) {
            return '';
        }

        $count = (int) ($settings->get('wszdb-flarumaichat.related_threads_count') ?: 2);

        if ($budget <= 0) {
            return '';
        }

        $reader = Readers::crossDiscussion($asker);
        $found = RelatedThreads::find($title, (int) $current->id, $reader, $count);

        if (!$found) {
            return '';
        }

        $blocks = [];
        $summary = new ThreadSummary($reader, $this->threadSummaries() ? $this->summarizer() : null, max(200, $budget));
        $summaryOf = fn (Discussion $discussion) => $summary->render($discussion);

        foreach ($found as $discussion) {
            if ($budget <= 0) {
                break;
            }

            $block = $summaryOf($discussion);

            if ($block === '') {
                continue;
            }

            $blocks[] = $block;
            $budget -= strlen($block);
        }

        $text = implode("\n\n", $blocks);

        resolve('log')->info('[ChatGPT] Related threads', ['chars' => strlen($text), 'count' => count($blocks)]);

        return $text;
    }

    private function threadSummaries(): bool
    {
        return (bool) resolve(SettingsRepositoryInterface::class)->get('wszdb-flarumaichat.thread_summaries');
    }

    /**
     * The summarizer behind the thread-summary cache: one small chat call per
     * stale summary, nothing when the cache is warm.
     */
    private function summarizer(): \Closure
    {
        return function (string $raw): string {
            $response = $this->chat([
                [
                    'role' => 'user',
                    'content' => "Summarize this forum thread in a few factual sentences,"
                        . " for another thread's assistant to quote:\n\n" . (new Fence())->wrap($raw),
                ],
            ]);

            return (string) ($response->choices[0]->message->content ?? '');
        };
    }

    private function checkIfAssistantCanReplyToPost($commentPost): bool
    {
        $discussion = $commentPost->discussion;

        $settings = resolve(SettingsRepositoryInterface::class);

        $userPromptId = $settings->get('wszdb-flarumaichat.user_prompt');
        if ($commentPost->user_id == $userPromptId) {
            return false;
        }

        // is it the first post?
        if ($commentPost->number == 1) {
            return false;
        }


        $maxReplyCount = $settings->get('wszdb-flarumaichat.continue_to_reply_count');
        $assistantReplyCount = $discussion->posts()->where('type', 'comment')->where('user_id', $userPromptId)->count();

        if ($assistantReplyCount >= $maxReplyCount) {
            return false;
        }

        return true;
    }

}
