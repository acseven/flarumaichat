<?php

namespace Wszdb\FlarumAiChat\Job;

use Flarum\Discussion\Discussion;
use Flarum\Post\CommentPost;
use Flarum\Queue\AbstractJob;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Wszdb\FlarumAiChat\Agent;
use Wszdb\FlarumAiChat\BlockedGroups;
use Wszdb\FlarumAiChat\Silence;

class ReplyPostJob extends AbstractJob
{
    use Queueable;
    use SerializesModels;
    use DelaysReply;

    /**
     * Waiting for the answer to fall due releases the job, and a release counts
     * as an attempt, so one try is never enough.
     */
    public $tries = 5;

    public function __construct(protected CommentPost $post, protected bool $mentioned = false)
    {
    }

    public function handle(Agent $agent): void
    {
        $log = resolve('log');

        try {
            $log->info('[ChatGPT Job] ReplyPostJob started', [
                'post_id' => $this->post->id,
                'discussion_id' => $this->post->discussion_id,
                'post_number' => $this->post->number,
                'created_at' => $this->post->created_at->toDateTimeString()
            ]);

            if ($this->holdBack($this->post->created_at, ['post_id' => $this->post->id])) {
                return;
            }

            // the answer waited, so the discussion may have been made private
            // or given a blocked tag since the listener queued this job
            $post = $this->post->fresh();

            $reason = $post && $post->discussion ? Silence::reason($post->discussion, $post->user) : null;

            // the same override the listener granted, checked again against
            // settings that may have changed while the answer waited
            if ($this->mentioned
                && in_array($reason, ['blocked_groups', 'blocked_tags'], true)
                && BlockedGroups::manualOverride($post->user)) {
                $reason = null;
            }

            if (!$post || !$post->discussion || $reason) {
                $log->info('[ChatGPT Job] Skipping - the assistant must stay out of this discussion', [
                    'post_id' => $this->post->id,
                    'reason' => $post && $post->discussion ? $reason : 'gone'
                ]);
                return;
            }

            $settings = resolve(SettingsRepositoryInterface::class);

            // a post that calls the assistant by name is answered whether or
            // not it keeps answering replies on its own
            $continueToReply = $settings->get('wszdb-flarumaichat.continue_to_reply');
            if (!$continueToReply && !$this->mentioned) {
                $log->info('[ChatGPT Job] Skipping - continue_to_reply disabled', [
                    'post_id' => $this->post->id,
                    'continue_to_reply' => $continueToReply
                ]);
                return;
            }

            $log->info('[ChatGPT Job] Calling agent->repliesToCommentPost', [
                'post_id' => $this->post->id
            ]);

            $agent->repliesToCommentPost($post, $this->mentioned);

            $log->info('[ChatGPT Job] ReplyPostJob completed successfully', [
                'post_id' => $this->post->id
            ]);
        } catch (\Exception $e) {
            $log->error('[ChatGPT Job] Exception in ReplyPostJob', [
                'post_id' => $this->post->id ?? null,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}
