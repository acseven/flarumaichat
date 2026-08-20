<?php

namespace Wszdb\FlarumAiChat\Controller;

use Flarum\Http\RequestUtil;
use Flarum\Post\PostRepository;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Wszdb\FlarumAiChat\Agent;
use Wszdb\FlarumAiChat\BlockedGroups;
use Wszdb\FlarumAiChat\Silence;

/**
 * Makes the assistant answer one post on demand, for staff who hold the
 * discussion.triggerChatGPTAssistant permission.
 */
class TriggerReplyController implements RequestHandlerInterface
{
    public function __construct(
        protected PostRepository $posts,
        protected Agent $agent,
        protected TranslatorInterface $translator
    ) {
    }

    /**
     * Flarum only shows the detail of a JSON:API error to the user, so refusals
     * have to carry their message in that shape.
     */
    private function refuse(string $key, int $status): JsonResponse
    {
        $detail = $this->translator->trans('wszdb-flarumaichat.forum.post_controls.'.$key);

        return new JsonResponse(['errors' => [['status' => (string) $status, 'detail' => $detail]]], $status);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $post = $this->posts->findOrFail(Arr::get($request->getQueryParams(), 'id'), $actor);

        // scoped to the discussion, the way the automatic reply checks its own permission
        if ($actor->cannot('discussion.triggerChatGPTAssistant', $post->discussion)) {
            throw new PermissionDeniedException();
        }

        $reason = Silence::reason($post->discussion, $post->user);

        // asking by hand is the point of the override: a block on the author's
        // group or on the discussion's tags only holds against the assistant
        // answering on its own. Privacy is not a block and stands.
        if (in_array($reason, ['blocked_groups', 'blocked_tags'], true) && BlockedGroups::manualOverride($post->user)) {
            $reason = null;
        }

        if ($reason) {
            return $this->refuse('error_'.$reason, 403);
        }

        if ($post->type !== 'comment') {
            return $this->refuse('error_not_comment', 422);
        }

        $before = $post->discussion->posts()->count();

        try {
            if ($post->number == 1) {
                $this->agent->repliesTo($post->discussion);
            } else {
                $this->agent->repliesToCommentPost($post, true);
            }
        } catch (\Exception $e) {
            // the agent already logged it; the job path needs the throw to retry, this path does not
            return $this->refuse('error_failed', 500);
        }

        // the agent can also bail without an error (moderation, empty content), so compare post counts
        if ($post->discussion->posts()->count() === $before) {
            return $this->refuse('error_no_answer', 500);
        }

        return new JsonResponse(['answered' => true], 200);
    }
}
