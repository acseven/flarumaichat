<?php

namespace Wszdb\FlarumAiChat\Listener;

use Flarum\Post\Event\Posted;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Support\Arr;
use Wszdb\FlarumAiChat\Agent;
use Wszdb\FlarumAiChat\Silence;
use Wszdb\FlarumAiChat\Job\ReplyPostJob;

class ReplyToCommentPost
{
    public function __construct(
        protected Agent $agent,
        protected Queue $queue
    )
    {
    }

    /**
     * @param \Flarum\Post\Event\Posted $event
     * @return void
     */
    public function handle(Posted $event): void
    {
        $settings = resolve(SettingsRepositoryInterface::class);
        $enabledTagIds = $settings->get('wszdb-flarumaichat.enabled-tags', []);
        $enabled = $settings->get('wszdb-flarumaichat.queue_active');
        $actor = $event->actor;
        $discussion = $event->post->discussion;

        // the assistant's own answer is a post like any other: never answer it
        if ($event->post->user_id == $settings->get('wszdb-flarumaichat.user_prompt')) {
            return;
        }

        if (Silence::reason($discussion, $actor)) {
            return;
        }

        if ($enabledTagIds = json_decode($enabledTagIds, true)) {
            $tagIds = Arr::pluck($discussion->tags, 'id');

            if (!array_intersect($enabledTagIds, $tagIds)) {
                return;
            }
        }

        if (!$actor || $actor->can('discussion.useChatGPTAssistant', $discussion) === false) {
            return;
        }

        if (!$enabled) {
            $this->agent->repliesToCommentPost($event->post);
            return;
        }
        $this->queue->push(new ReplyPostJob($event->post));
    }
}
