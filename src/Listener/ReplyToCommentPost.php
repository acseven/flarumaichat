<?php

namespace Wszdb\FlarumAiChat\Listener;

use Flarum\Post\Event\Posted;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Support\Arr;
use Wszdb\FlarumAiChat\Agent;
use Wszdb\FlarumAiChat\BlockedGroups;
use Wszdb\FlarumAiChat\Mentions;
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

        $mentioned = $this->callsAssistant($event->post, $settings);
        $reason = Silence::reason($discussion, $actor);

        // calling the assistant by name is the point of the override: a block
        // on the author's group or on the discussion's tags only holds against
        // the assistant answering on its own. Privacy is not a block and stands
        $trusted = $mentioned && BlockedGroups::manualOverride($actor);

        if ($trusted && in_array($reason, ['blocked_groups', 'blocked_tags'], true)) {
            $reason = null;
        }

        if ($reason) {
            return;
        }

        // the enabled tags say where the assistant may speak up by itself, so
        // a trusted member calling it by name reaches past them too
        if (!$trusted && $enabledTagIds = json_decode($enabledTagIds, true)) {
            $tagIds = Arr::pluck($discussion->tags, 'id');

            if (!array_intersect($enabledTagIds, $tagIds)) {
                return;
            }
        }

        // who may summon it at all is the same permission either way
        if (!$actor || $actor->can('discussion.useChatGPTAssistant', $discussion) === false) {
            return;
        }

        if (!$enabled) {
            $this->agent->repliesToCommentPost($event->post, $mentioned);
            return;
        }
        $this->queue->push(new ReplyPostJob($event->post, $mentioned));
    }

    /**
     * One user lookup, and only for a post that holds an @ at all.
     */
    private function callsAssistant($post, $settings): bool
    {
        if (!$settings->get('wszdb-flarumaichat.mentions') || !str_contains((string) $post->content, '@')) {
            return false;
        }

        $bot = Mentions::bot();

        return $bot && Mentions::callsBot($post->content, (int) $bot->id, $bot->username);
    }
}
