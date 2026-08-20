<?php

namespace Wszdb\FlarumAiChat\Middleware;

use Flarum\Api\JsonApiResponse;
use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Flarum\Http\UrlGenerator;
use Flarum\Settings\SettingsRepositoryInterface;
use Laminas\Diactoros\Uri;
use Wszdb\FlarumAiChat\Agent;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tobscure\JsonApi\Document;
use Tobscure\JsonApi\Exception\Handler\ResponseBag;

class ModerationMiddleware implements MiddlewareInterface
{
    // per-actor ceiling: the check is a billed call, so no guest or script may
    // spend the owner's quota through it
    private const CALLS_PER_HOUR = 30;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // resolved lazily: this middleware runs in four pipelines, moderation on
        // or off, and must not touch the database or the container before here
        $settings = resolve(SettingsRepositoryInterface::class);

        if (!$settings->get('wszdb-flarumaichat.moderation')) {
            return $handler->handle($request);
        }

        $uri = new Uri($request->getUri());
        $path = $uri->getPath();

        if ($request->getMethod() !== 'POST' || !in_array($path, ['/discussions', '/posts'], true)) {
            return $handler->handle($request);
        }

        $attributes = (array) ($request->getParsedBody()['data']['attributes'] ?? []);
        $title = is_string($attributes['title'] ?? null) ? $attributes['title'] : '';
        $content = $attributes['content'] ?? null;

        // a missing or malformed body is core's validation problem, not a
        // moderation one: let it through to the controllers
        if (!is_string($content)) {
            return $handler->handle($request);
        }

        // only members who may actually post through this route get a check;
        // the controllers enforce the real permission, this only stops paying
        // for callers who would never get past them
        $actor = RequestUtil::getActor($request);

        if ($actor->isGuest() || !$this->mayPost($request, $actor, $path)) {
            return $handler->handle($request);
        }

        if ($this->overBudget($actor)) {
            return $handler->handle($request);
        }

        // fail open: moderation is an extra check, never a reason to lose a post
        try {
            $agent = resolve(Agent::class);
            $flag = $agent->checkModeration($title, $content);
        } catch (\Throwable $e) {
            resolve('log')->warning('[ChatGPT] Moderation skipped, the call failed', [
                'user_id' => $actor->id,
                'error' => $e->getMessage(),
            ]);

            return $handler->handle($request);
        }

        if (!$flag) {
            return $handler->handle($request);
        }

        resolve('log')->info('[ChatGPT] Moderation flagged a post', ['path' => $path, 'user_id' => $actor->id]);

        $error = new ResponseBag('422', [
            [
                'status' => '422',
                'code' => 'validation_error',
                'source' => [
                    'pointer' => '/data/attributes/content',
                ],
                'detail' => 'Your post includes bad words. Please try again.',
            ],
        ]);

        $document = new Document();
        $document->setErrors($error->getErrors());

        return new JsonApiResponse($document, $error->getStatus());
    }

    /**
     * Whether this actor could post here at all. The controllers enforce the
     * real permission; this only keeps the billed call away from callers who
     * would never reach them.
     */
    private function mayPost(ServerRequestInterface $request, $actor, string $path): bool
    {
        if ($path === '/discussions') {
            return $actor->can('startDiscussions');
        }

        $id = $request->getParsedBody()['data']['relationships']['discussion']['data']['id'] ?? null;

        if (!is_scalar($id) || !ctype_digit((string) $id)) {
            return false;
        }

        $discussion = Discussion::whereVisibleTo($actor)->find((int) $id);

        return $discussion && $actor->can('reply', $discussion);
    }

    /**
     * A per-actor counter in the cache store. The extension answers posts from
     * a queue, so a member cannot race this window meaningfully.
     */
    private function overBudget($actor): bool
    {
        $key = 'wszdb-flarumaichat.moderation.' . $actor->id;

        try {
            $cache = resolve('cache.store');
            $cache->add($key, 0, 3600);
            $count = (int) $cache->increment($key);
        } catch (\Throwable) {
            // no cache store: the ceiling is off, the check still runs
            return false;
        }

        if ($count > self::CALLS_PER_HOUR) {
            resolve('log')->warning('[ChatGPT] Moderation calls over the hourly ceiling, skipping', [
                'user_id' => $actor->id,
            ]);

            return true;
        }

        return false;
    }
}
