<?php

namespace Wszdb\FlarumAiChat;

use Flarum\Discussion\Discussion;
use Flarum\User\User;
use Illuminate\Support\Arr;

/**
 * A thread quoted across discussions: a short summary the model wrote once and
 * that is cached until the thread moves (a newer post, a post hidden after the
 * summary was written, a new tag, privacy), or the raw posts when the
 * summarizer is off.
 *
 * The reader is the cross-discussion reader from Readers, never the bot user,
 * and Readers::crossFetch already refused hidden, private and blocked-tag
 * discussions before any of this runs.
 */
class ThreadSummary
{
    public const MAX_POSTS = 20;

    private const MAX_POST_CHARS = 1200;
    private const MAX_SUMMARY_CHARS = 600;
    private const SUMMARY_INPUT_CHARS = 4000;
    private const TTL = 2592000; // 30 days

    public function __construct(
        private User $reader,
        private $summarizer = null, // ?callable(string): string
        private int $maxChars = self::MAX_SUMMARY_CHARS
    ) {
    }

    /**
     * The thread as context text: its cached summary when fresh, a fresh
     * summary when not, the raw posts when the summarizer is off. Empty when
     * there is nothing to quote.
     */
    public function render(int|Discussion $id): string
    {
        $discussion = $id instanceof Discussion ? $id : Readers::crossFetch($id, $this->reader);

        if (!$discussion) {
            return '';
        }

        if (!$this->summarizer) {
            return $this->readRaw($discussion, $this->maxChars);
        }

        $markers = self::markers($discussion);
        $stored = self::cache()->get(self::key((int) $discussion->id));

        if (self::fresh($stored, $markers)) {
            return $this->cut($stored['text']);
        }

        $raw = $this->readRaw($discussion, max(self::SUMMARY_INPUT_CHARS, $this->maxChars));

        if ($raw === '') {
            return '';
        }

        try {
            $text = trim((string) ($this->summarizer)($raw));
        } catch (\Throwable $e) {
            resolve('log')->warning('[ChatGPT] Summarizer failed, quoting the thread raw', [
                'discussion_id' => $discussion->id,
                'error' => $e->getMessage(),
            ]);

            return $this->cut($raw);
        }

        if ($text === '') {
            return $this->cut($raw);
        }

        $text = $this->cut($text);
        self::cache()->put(self::key((int) $discussion->id), ['markers' => $markers, 'text' => $text], self::TTL);

        return $text;
    }

    /**
     * Whether a stored summary still matches its discussion. The markers are
     * everything a stale summary would leak: a newer post, a change in which
     * posts are hidden, a new tag, a turn to privacy.
     */
    public static function fresh(?array $stored, array $markers): bool
    {
        return is_array($stored)
            && ($stored['markers'] ?? null) === $markers
            && is_string($stored['text'] ?? null)
            && $stored['text'] !== '';
    }

    /**
     * One cheap aggregate instead of event listeners.
     */
    private static function markers(Discussion $discussion): array
    {
        $row = $discussion->posts()
            ->where('type', 'comment')
            // the hidden posts are identified, not counted: un-hiding one post and
            // hiding another would leave a count unchanged and serve a stale summary
            ->selectRaw('COALESCE(MAX(id), 0) as last_post_id,'
                . ' COALESCE(SUM(CASE WHEN hidden_at IS NOT NULL THEN id ELSE 0 END), 0) as hidden_mark')
            ->first();

        return [
            (int) ($row->last_post_id ?? 0),
            (int) ($row->hidden_mark ?? 0),
            // array_values: Arr::sort keeps the original keys, so the same tags in
            // another row order would compare unequal and miss the cache
            array_values(array_map('intval', Arr::sort(Arr::pluck($discussion->tags ?? [], 'id')))),
            (bool) $discussion->is_private,
        ];
    }

    private static function key(int $id): string
    {
        return 'wszdb-flarumaichat.summary.' . $id;
    }

    private static function cache()
    {
        return resolve('cache.store');
    }

    /**
     * The thread as text: a title line plus its visible posts, within a budget.
     */
    private function readRaw(Discussion $discussion, int $budget): string
    {
        $posts = $discussion->posts()
            ->where('type', 'comment')
            ->whereNull('hidden_at')
            ->whereVisibleTo($this->reader)
            ->orderBy('number')
            ->limit(self::MAX_POSTS)
            ->get();

        $lines = ['Thread "' . Fence::clean($discussion->title) . '":'];
        $spent = strlen($lines[0]);

        foreach ($posts as $post) {
            $body = trim(Fence::clean((string) $post->content));

            if ($body === '') {
                continue;
            }

            $line = '#' . $post->number . ' ' . ($post->user->username ?? 'unknown') . ': '
                . $this->cutOne($body, self::MAX_POST_CHARS);

            if ($spent + strlen($line) > max(0, $budget)) {
                $lines[] = '(the rest of the thread is left out)';
                break;
            }

            $lines[] = $line;
            $spent += strlen($line);
        }

        return count($lines) > 1 ? implode("\n", $lines) : '';
    }

    private function cut(string $value): string
    {
        return $this->cutOne(trim(Fence::clean($value)), $this->maxChars);
    }

    private function cutOne(string $value, int $limit): string
    {
        return mb_strlen($value) <= $limit ? $value : mb_substr($value, 0, $limit) . '…';
    }
}
