<?php

namespace Wszdb\FlarumAiChat\Job;

use Flarum\Discussion\Discussion;
use Flarum\Queue\AbstractJob;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Wszdb\FlarumAiChat\Agent;
use Wszdb\FlarumAiChat\Silence;

class ReplyJob extends AbstractJob
{
    use Queueable;
    use SerializesModels;
    use DelaysReply;

    /**
     * Waiting for the answer to fall due releases the job, and a release counts
     * as an attempt, so one try is never enough.
     */
    public $tries = 5;

    public function __construct(protected Discussion $discussion)
    {
    }

    public function handle(Agent $agent): void
    {
        $log = resolve('log');

        try {
            $log->info('[ChatGPT Job] ReplyJob started', [
                'discussion_id' => $this->discussion->id,
                'title' => $this->discussion->title,
                'created_at' => $this->discussion->created_at->toDateTimeString()
            ]);

            if ($this->holdBack($this->discussion->created_at, ['discussion_id' => $this->discussion->id])) {
                return;
            }

            // the answer waited, so the discussion may have been made private
            // or given a blocked tag since the listener queued this job
            $discussion = $this->discussion->fresh();

            if (!$discussion || ($reason = Silence::reason($discussion, $discussion->user))) {
                $log->info('[ChatGPT Job] Skipping - the assistant must stay out of this discussion', [
                    'discussion_id' => $this->discussion->id,
                    'reason' => $discussion ? $reason : 'gone'
                ]);
                return;
            }

            $settings = resolve(SettingsRepositoryInterface::class);

            // check reply_to_post setting in settings
            $replyToPost = $settings->get('wszdb-flarumaichat.reply_to_post');

            // check if any user replied to the post and replyToPost setting is true
            $postCount = $discussion->posts()->where('type', 'comment')->count();
            if ($replyToPost && $postCount > 1) {
                $log->info('[ChatGPT Job] Skipping - users already replied', [
                    'discussion_id' => $this->discussion->id,
                    'post_count' => $postCount,
                    'reply_to_post_setting' => $replyToPost
                ]);
                return;
            }

            $log->info('[ChatGPT Job] Calling agent->repliesTo', [
                'discussion_id' => $this->discussion->id
            ]);

            $agent->repliesTo($discussion);

            $log->info('[ChatGPT Job] ReplyJob completed successfully', [
                'discussion_id' => $this->discussion->id
            ]);
        } catch (\Exception $e) {
            $log->error('[ChatGPT Job] Exception in ReplyJob', [
                'discussion_id' => $this->discussion->id,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}
