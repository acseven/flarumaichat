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
use Wszdb\FlarumAiChat\Agent;

/**
 * Makes the assistant answer one post on demand, for staff who hold the
 * discussion.triggerChatGPTAssistant permission.
 */
class TriggerReplyController implements RequestHandlerInterface
{
    public function __construct(
        protected PostRepository $posts,
        protected Agent $agent
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);

        if (!$actor->hasPermission('discussion.triggerChatGPTAssistant')) {
            throw new PermissionDeniedException();
        }

        $post = $this->posts->findOrFail(Arr::get($request->getQueryParams(), 'id'), $actor);

        if ($post->type !== 'comment') {
            return new JsonResponse(['error' => 'Only comments can be answered.'], 422);
        }

        $before = $post->discussion->posts()->count();

        if ($post->number == 1) {
            $this->agent->repliesTo($post->discussion);
        } else {
            $this->agent->repliesToCommentPost($post, true);
        }

        // the agent logs and swallows its own failures, so compare post counts
        $answered = $post->discussion->posts()->count() > $before;

        return new JsonResponse(['answered' => $answered], $answered ? 200 : 500);
    }
}
