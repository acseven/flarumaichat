<?php

namespace Wszdb\FlarumAiChat\Listener;

use Flarum\Discussion\Event\Started;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Support\Arr;
use Wszdb\FlarumAiChat\Agent;
use Wszdb\FlarumAiChat\Job\ReplyJob;

class ReplyToPost
{
    public function __construct(
        protected Agent $agent,
        protected Queue $queue
    )
    {
    }

    /**
     * @param \Flarum\Discussion\Event\Started $event
     * @return void
     */
    public function handle(Started $event): void
    {
        $settings = resolve(SettingsRepositoryInterface::class);
        $enabled = $settings->get('wszdb-flarumaichat.queue_active');
        $enabledTagIds = $settings->get('wszdb-flarumaichat.enabled-tags', []);
        $actor = $event->actor;
        $discussion = $event->discussion;

        // actor-independent kill switch: admins pass every permission check
        if (!$settings->get('wszdb-flarumaichat.enable_on_discussion_started')) {
            return;
        }

        if ($discussion->is_private && !$settings->get('wszdb-flarumaichat.reply_in_private')) {
            return;
        }

        if ($enabledTagIds = json_decode($enabledTagIds, true)) {
            $tagIds = Arr::pluck($discussion->tags, 'id');

            if (!array_intersect($enabledTagIds, $tagIds)) {
                return;
            }
        }

        if($actor->can('discussion.useChatGPTAssistant', $discussion) === false) {
            return;
        }

        if (!$enabled) {
            $this->agent->repliesTo($event->discussion);
            return;
        }

        // check queue redis, or database queue is installed

        $this->queue->push(new ReplyJob($event->discussion));
    }
}
