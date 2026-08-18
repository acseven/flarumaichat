<?php

namespace Wszdb\FlarumAiChat\Job;

use Carbon\Carbon;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\SyncQueue;

trait DelaysReply
{
    /**
     * Hold the answer back until the post is old enough.
     *
     * Returns true when the job must stop now (it was put back on the queue),
     * false when the answer is due and the job may carry on.
     */
    private function holdBack(Carbon $createdAt, array $context): bool
    {
        $settings = resolve(SettingsRepositoryInterface::class);
        $wait = ((int) $settings->get('wszdb-flarumaichat.answer_duration')) * 60
            + (int) $settings->get('wszdb-flarumaichat.answer_delay');

        $remaining = $wait - $createdAt->diffInSeconds();
        if ($remaining <= 0) {
            return false;
        }

        $log = resolve('log');

        // ponytail: the sync queue driver runs jobs inside the posting request and
        // throws released jobs away, so a short wait is slept out instead. It blocks
        // that request for the same seconds; run a queue worker to make it free.
        if ($remaining <= 60 && resolve(Queue::class) instanceof SyncQueue) {
            $log->info('[ChatGPT Job] Waiting before answering', $context + ['wait_seconds' => $remaining]);
            sleep($remaining);

            return false;
        }

        $log->info('[ChatGPT Job] Too recent, releasing job', $context + ['wait_seconds' => $remaining]);
        $this->release($remaining);

        return true;
    }
}
