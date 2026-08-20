<?php

namespace Wszdb\FlarumAiChat;

use Flarum\Discussion\Discussion;
use Flarum\User\User;

/**
 * Quotes the threads a post links to, when the link points at this same forum.
 * The assistant cannot open a url, but it can be handed what is behind one of
 * ours: the reader is the bot user, so a thread it may not see stays unread.
 */
class LinkedDiscussions
{
    private const MAX_THREADS = 2;
    private const MAX_POSTS = 20;
    private const MAX_POST_CHARS = 1200;

    public function __construct(
        private User $reader,
        private string $forumUrl,
        private int $maxChars
    ) {
    }

    /**
     * The discussion ids the text links to, in the order they appear, once each.
     */
    public static function ids(string $text, string $forumUrl): array
    {
        $base = rtrim(trim($forumUrl), '/');

        if ($base === '') {
            return [];
        }

        // only our own links: a discussion is /d/<id> or /d/<id>-<slug>, with an
        // optional post number after it
        $pattern = '~' . preg_quote($base, '~') . '/d/(\d+)~i';

        preg_match_all($pattern, $text, $matches);

        return array_values(array_unique(array_map('intval', $matches[1] ?? [])));
    }

    /**
     * The linked threads as text, empty when nothing is linked or readable.
     */
    public function factsFor(string $text): string
    {
        $blocks = [];
        $budget = $this->maxChars;

        foreach (array_slice(static::ids($text, $this->forumUrl), 0, self::MAX_THREADS) as $id) {
            if ($budget <= 0) {
                break;
            }

            $block = $this->readDiscussion($id, $budget);

            if ($block === '') {
                continue;
            }

            $blocks[] = $block;
            $budget -= strlen($block);
        }

        return implode("\n\n", $blocks);
    }

    private function readDiscussion(int $id, int $budget): string
    {
        /** @var Discussion|null $discussion */
        $discussion = Discussion::whereVisibleTo($this->reader)->find($id);

        if (!$discussion) {
            return '';
        }

        $posts = $discussion->posts()
            ->where('type', 'comment')
            ->whereNull('hidden_at')
            ->whereVisibleTo($this->reader)
            ->orderBy('number')
            ->limit(self::MAX_POSTS)
            ->get();

        $lines = ['Thread "' . $discussion->title . '" (' . rtrim($this->forumUrl, '/') . '/d/' . $id . '):'];
        $spent = strlen($lines[0]);

        foreach ($posts as $post) {
            $body = trim((string) $post->content);

            if ($body === '') {
                continue;
            }

            $line = '#' . $post->number . ' ' . ($post->user->username ?? 'unknown') . ': '
                . $this->cut($body, self::MAX_POST_CHARS);

            if ($spent + strlen($line) > $budget) {
                $lines[] = '(the rest of the thread is left out)';
                break;
            }

            $lines[] = $line;
            $spent += strlen($line);
        }

        // nothing but the header means nothing was read
        return count($lines) > 1 ? implode("\n", $lines) : '';
    }

    private function cut(string $value, int $limit): string
    {
        return strlen($value) <= $limit ? $value : substr($value, 0, $limit) . '…';
    }
}
