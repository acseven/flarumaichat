<?php

namespace Wszdb\FlarumAiChat;

use Flarum\Discussion\Discussion;
use Flarum\Post\CommentPost;
use Flarum\Foundation\Paths;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Arr;
use OpenAI;
use OpenAI\Client;


class Agent
{
    protected int $maxTokens;
    protected string $model;

    public function __construct(
        public readonly User $user,
        protected ?Client    $client = null,
        string               $model = null,
        int                  $maxTokens = null
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
                'title' => $discussion->title,
                'model' => $this->model,
                'max_tokens' => $this->maxTokens,
                'is_reasoning_model' => $this->isReasoningModel()
            ]);

            $firstPost = $discussion->firstPost ?: $discussion->posts()->where('type', 'comment')->orderBy('number')->first();
            $content = $firstPost->content ?? '';
            $title = $discussion->title;

            if (trim($content) === '') {
                $log->warning('[ChatGPT] First post content empty, skipping', ['discussion_id' => $discussion->id]);
                return;
            }

            ['role' => $role, 'prompt' => $prompt] = $this->prepareChatForMessage();

            $messages = $this->createMessages($title, $content, $role, $prompt);

            $log->info('[ChatGPT] Sending request to OpenAI', [
                'model' => $this->model,
                'message_count' => count($messages),
                'token_param' => $this->isReasoningModel() ? 'max_completion_tokens' : 'max_tokens'
            ]);

            $response = $this->sendCompletionRequest($messages);

            if (empty($response->choices)) {
                $log->warning('[ChatGPT] Empty response from OpenAI', [
                    'discussion_id' => $discussion->id,
                    'model' => $this->model
                ]);
                return;
            }

            $log->info('[ChatGPT] Received response from OpenAI', [
                'discussion_id' => $discussion->id,
                'choices_count' => count($response->choices)
            ]);

            $saved = $this->saveResponse($response, $discussion->id);

            if ($saved) {
                $log->info('[ChatGPT] Successfully saved response', [
                    'discussion_id' => $discussion->id
                ]);
            } else {
                $log->error('[ChatGPT] Failed to save response', [
                    'discussion_id' => $discussion->id
                ]);
            }
        } catch (\Exception $e) {
            $log->error('[ChatGPT] Exception in repliesTo', [
                'discussion_id' => $discussion->id,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    public function repliesToCommentPost(CommentPost $commentPost, bool $force = false): void
    {
        $log = resolve('log');

        try {
            // get the discussion title
            $discussion = $commentPost->discussion;
            $title = $discussion->title;
            $content = $discussion->firstPost->content;

            $log->info('[ChatGPT] Starting repliesToCommentPost', [
                'discussion_id' => $discussion->id,
                'post_id' => $commentPost->id,
                'post_number' => $commentPost->number,
                'model' => $this->model,
                'is_reasoning_model' => $this->isReasoningModel()
            ]);

            if (!$force && !$this->checkIfAssistantCanReplyToPost($commentPost)) {
                $log->info('[ChatGPT] Assistant cannot reply to this post', [
                    'post_id' => $commentPost->id,
                    'reason' => 'checkIfAssistantCanReplyToPost returned false'
                ]);
                return;
            }

            ['role' => $role, 'prompt' => $prompt] = $this->prepareChatForMessage();

            $messages = $this->createMessages($title, $content, $role, $prompt, $commentPost->content);

            $settings = resolve(SettingsRepositoryInterface::class);
            $userPromptId = $settings->get('wszdb-flarumaichat.user_prompt');

            // get the posts where the number is greater than 1 to the last message not include last message
            $posts = $discussion->posts()
                ->where('number', '>', 1)
                ->where('number', '<', $commentPost->number)
                ->get();

            foreach ($posts as $post) {
                if ($post->type == 'comment') {
                    $messages[] = [
                        'role' => $post->user_id == $userPromptId ? 'assistant' : 'user',
                        'content' => $post->content
                    ];
                }
            }

            // add the last message with the prompt
            $messages[] = $this->createMessageForUser($commentPost->content);

            $log->info('[ChatGPT] Sending request to OpenAI for comment reply', [
                'model' => $this->model,
                'message_count' => count($messages),
                'token_param' => $this->isReasoningModel() ? 'max_completion_tokens' : 'max_tokens'
            ]);

            // answer to the post
            $response = $this->sendCompletionRequest($messages);

            if (empty($response->choices)) {
                $log->warning('[ChatGPT] Empty response from OpenAI for comment', [
                    'post_id' => $commentPost->id,
                    'model' => $this->model
                ]);
                return;
            }

            $log->info('[ChatGPT] Received response from OpenAI for comment', [
                'post_id' => $commentPost->id,
                'choices_count' => count($response->choices)
            ]);

            $saved = $this->saveResponse($response, $discussion->id);

            if ($saved) {
                $log->info('[ChatGPT] Successfully saved comment response', [
                    'discussion_id' => $discussion->id,
                    'post_id' => $commentPost->id
                ]);
            } else {
                $log->error('[ChatGPT] Failed to save comment response', [
                    'discussion_id' => $discussion->id,
                    'post_id' => $commentPost->id
                ]);
            }
        } catch (\Exception $e) {
            $log->error('[ChatGPT] Exception in repliesToCommentPost', [
                'post_id' => $commentPost->id ?? null,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
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

        // convert results to array
        $res = json_decode(json_encode($results), true);

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
            $params = [
                'model' => $this->model,
                'messages' => $messages,
            ];

            // z.ai GLM: thinking tokens consume max_tokens and add ~70s latency
            if (str_starts_with(strtolower($this->model), 'glm')) {
                $settings = resolve(SettingsRepositoryInterface::class);
                $thinking = $settings->get('wszdb-flarumaichat.glm_thinking');
                $params['thinking'] = ['type' => $thinking ? 'enabled' : 'disabled'];

                if ($settings->get('wszdb-flarumaichat.web_search')) {
                    $search = ['enable' => true];
                    $domains = $this->searchDomains((string) $settings->get('wszdb-flarumaichat.web_search_domains'));

                    if ($domains !== '') {
                        $search['search_domain_filter'] = $domains;
                    }

                    $params['tools'] = [['type' => 'web_search', 'web_search' => $search]];
                }
            }

            // Use max_completion_tokens for reasoning models (o1, o3, o4, gpt-5 series)
            // Use max_tokens for legacy models (gpt-3.5, gpt-4, etc.)
            if ($this->isReasoningModel()) {
                $params['max_completion_tokens'] = $this->maxTokens;
            } else {
                $params['max_tokens'] = $this->maxTokens;
            }

            $log->info('[ChatGPT] API Request Parameters', [
                'model' => $params['model'],
                'token_param_used' => $this->isReasoningModel() ? 'max_completion_tokens' : 'max_tokens',
                'token_value' => $this->maxTokens,
                'message_count' => count($messages)
            ]);

            $response = $this->client->chat()->create($params);

            $log->info('[ChatGPT] API Response Received', [
                'has_choices' => !empty($response->choices),
                'choice_count' => count($response->choices ?? [])
            ]);

            Usage::record(
                (int) ($response->usage->promptTokens ?? 0),
                (int) ($response->usage->completionTokens ?? 0)
            );

            return $response;
        } catch (\OpenAI\Exceptions\ErrorException $e) {
            $log->error('[ChatGPT] OpenAI API Error', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'model' => $this->model,
                'is_reasoning_model' => $this->isReasoningModel(),
                'token_param' => $this->isReasoningModel() ? 'max_completion_tokens' : 'max_tokens',
                'token_value' => $this->maxTokens
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
     * Determine if the current model is a reasoning model.
     * Reasoning models (o1, o3, o4, gpt-5 series) require max_completion_tokens
     * instead of max_tokens.
     *
     * @return bool
     */
    private function isReasoningModel(): bool
    {
        $modelLower = strtolower($this->model);

        // Check for reasoning model patterns
        $reasoningPatterns = ['o1', 'o3', 'o4', 'gpt-5'];

        foreach ($reasoningPatterns as $pattern) {
            if (str_contains($modelLower, $pattern)) {
                return true;
            }
        }

        return false;
    }


    private function saveResponse($response, $discussionId): bool
    {
        $log = resolve('log');

        try {
            $choice = Arr::first($response->choices);
            $respond = $choice->message->content ?? null;

            // Log full response structure for debugging
            $log->info('[ChatGPT] Response details', [
                'discussion_id' => $discussionId,
                'has_choice' => !empty($choice),
                'has_message' => !empty($choice->message ?? null),
                'content' => $respond,
                'content_length' => strlen($respond ?? ''),
                'finish_reason' => $choice->finish_reason ?? null,
                'refusal' => $choice->message->refusal ?? null
            ]);

            if (empty($respond)) {
                $log->warning('[ChatGPT] Empty content in response', [
                    'discussion_id' => $discussionId,
                    'has_choice' => !empty($choice),
                    'has_message' => !empty($choice->message ?? null),
                    'finish_reason' => $choice->finish_reason ?? 'unknown',
                    'refusal' => $choice->message->refusal ?? null
                ]);
                return false;
            }

            $userPrompt = $this->user->id;

            $log->info('[ChatGPT] Saving response as CommentPost', [
                'discussion_id' => $discussionId,
                'user_id' => $userPrompt,
                'content_length' => strlen($respond)
            ]);

            $post = CommentPost::reply(
                discussionId: $discussionId,
                content: $respond,
                userId: $userPrompt,
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
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Facts about the post's subject, read from the local data files the admin listed.
     */
    private function contextFacts(string $text): string
    {
        $settings = resolve(SettingsRepositoryInterface::class);
        $paths = (string) $settings->get('wszdb-flarumaichat.context_files');

        if (trim($paths) === '') {
            return '';
        }

        $budget = (int) $settings->get('wszdb-flarumaichat.context_chars') ?: ContextFiles::MAX_TOTAL_CHARS;

        $facts = (new ContextFiles(resolve(Paths::class)->base, $paths, $budget))->factsFor($text);

        resolve('log')->info('[ChatGPT] Local context', ['chars' => strlen($facts)]);

        return $facts;
    }

    private function createMessages($title, $content, $role, $prompt, $extra = ''): array
    {
        $prompt = str_replace(
            ['[title]', '[content]'],
            [$title, ''],
            $prompt
        );
        $systemPrompt = $role . ' ' . $prompt;

        $subject = $title . "\n" . $content . "\n" . $extra;

        $facts = $this->contextFacts($subject);

        if ($facts !== '') {
            $systemPrompt .= "\n\nFacts below come from this site's own data files. They are current and"
                . " authoritative: prefer them over what you remember, and never name a version, file or"
                . " link that is not in them.\n\n" . $facts;
        }

        $threads = $this->linkedThreads($subject);

        if ($threads !== '') {
            $systemPrompt .= "\n\nThe conversation links to threads on this same forum, quoted below. You have"
                . " read them: answer about what they say, and never claim you cannot open a link to this"
                . " forum.\n\n" . $threads;
        }

        // Reasoning models (o1, o3, o4, gpt-5) don't support 'system' role
        // Prepend system instructions to first user message instead
        if ($this->isReasoningModel()) {
            return [
                [
                    'role' => 'user',
                    'content' => $systemPrompt . "\n\n" . $content
                ]
            ];
        }

        // Legacy models: use system role + user message
        return [
            [
                'role' => 'system',
                'content' => $systemPrompt
            ],
            [
                'role' => 'user',
                'content' => $content
            ]
        ];
    }

    /**
     * The threads the post links to, when they live on this forum and the bot
     * user may read them.
     */
    private function linkedThreads(string $text): string
    {
        $settings = resolve(SettingsRepositoryInterface::class);

        if (!$settings->get('wszdb-flarumaichat.linked_threads')) {
            return '';
        }

        $forumUrl = (string) $settings->get('forum_url');
        $budget = (int) $settings->get('wszdb-flarumaichat.context_chars') ?: ContextFiles::MAX_TOTAL_CHARS;

        $threads = (new LinkedDiscussions($this->user, $forumUrl, $budget))->factsFor($text);

        resolve('log')->info('[ChatGPT] Linked threads', ['chars' => strlen($threads)]);

        return $threads;
    }

    private function createMessageForUser($content): array
    {
        return [
            'role' => 'user',
            'content' => $content
        ];
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
