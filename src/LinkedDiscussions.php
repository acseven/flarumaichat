<?php

namespace Wszdb\FlarumAiChat;

use Flarum\User\User;

/**
 * Quotes the threads a post links to, when the link points at this same forum.
 * The assistant cannot open a url, but it can be handed what is behind one of
 * ours — read as the cross-discussion reader (Guest, else the asker; never the
 * bot user), with hidden, private and blocked-tag discussions refused, so a
 * member cannot have a closed thread quoted into a public answer.
 */
class LinkedDiscussions
{
    private const MAX_THREADS = 2;

    public function __construct(
        private User $asker,
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
    public function factsFor(string $text, $summarizer = null): string
    {
        $reader = Readers::crossDiscussion($this->asker);
        $budget = $this->maxChars;
        $blocks = [];

        foreach (array_slice(static::ids($text, $this->forumUrl), 0, self::MAX_THREADS) as $id) {
            if ($budget <= 0) {
                break;
            }

            $block = (new ThreadSummary($reader, $summarizer, $budget))->render($id);

            if ($block === '') {
                continue;
            }

            $blocks[] = $block;
            $budget -= strlen($block);
        }

        return implode("\n\n", $blocks);
    }
}
