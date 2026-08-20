<?php

namespace Wszdb\FlarumAiChat;

use Flarum\Discussion\Discussion;
use Flarum\User\User;

/**
 * Discussions whose title is about the same thing. Title matching only: a
 * natural-language FULLTEXT match first (the title index answers in tens of
 * milliseconds and stayed relevant on every probe), and when it finds nothing
 * — two-character camera names sit under the server's innodb_ft_min_token_size
 * of 3 — a bounded LIKE probe over the title tokens, which has no token floor.
 *
 * Everything is read as the cross-discussion reader, never as the bot, and
 * hidden, private or blocked-tag discussions are never returned. Content is
 * never matched: SUM-aggregated relevance rewarded the 1800-reply threads and
 * returned junk.
 */
class RelatedThreads
{
    private const STOPWORDS = [
        'the', 'and', 'for', 'with', 'from', 'that', 'this', 'are', 'was', 'not', 'but', 'can',
        'how', 'what', 'when', 'who', 'why', 'you', 'your', 'its', 'about', 'into', 'does', 'to',
    ];

    /**
     * @return Discussion[]
     */
    public static function find(string $title, int $excludingId, User $reader, int $count): array
    {
        $title = trim($title);

        if ($title === '' || $count <= 0) {
            return [];
        }

        $query = Discussion::whereVisibleTo($reader)
            ->whereNull('hidden_at')
            ->where('id', '!=', $excludingId)
            // natural language mode only: no boolean operators, ever
            ->whereRaw('MATCH(title) AGAINST(? IN NATURAL LANGUAGE MODE)', [$title]);

        // the index drops tokens under innodb_ft_min_token_size, and on a camera
        // forum those are the model numbers that tell "IXUS 75" from "IXUS 700"
        foreach (static::modelNumbers($title) as $number) {
            $query->orderByRaw('title LIKE ? DESC', ['%' . $number . '%']);
        }

        $matched = $query
            // the relevance score is the whole point of the match: without it the
            // slice below is an arbitrary set of rows that merely scored above zero
            ->orderByRaw('MATCH(title) AGAINST(? IN NATURAL LANGUAGE MODE) DESC', [$title])
            ->limit($count * 3)
            ->get()
            ->all();

        return static::pick($matched, $count)
            ?: static::like(static::tokens($title), $excludingId, $reader, $count);
    }

    /**
     * The candidates a reader may be shown: private and blocked-tag discussions
     * are dropped. Pure, for the self-check.
     *
     * @param Discussion[] $candidates
     * @return Discussion[]
     */
    public static function pick(array $candidates, int $count): array
    {
        $picked = [];

        foreach ($candidates as $discussion) {
            if (Readers::crossOk($discussion)) {
                $picked[] = $discussion;
            }

            if (count($picked) >= $count) {
                break;
            }
        }

        return $picked;
    }

    /**
     * The title's two character tokens that carry a digit: too short for the
     * FULLTEXT index, and the whole difference between two camera models. A
     * lone digit is left out, it matches half the forum. Pure, for the
     * self-check.
     *
     * @return string[]
     */
    public static function modelNumbers(string $title): array
    {
        preg_match_all('~(?<![\w-])(?:[a-z][0-9]|[0-9][a-z0-9])(?![\w-])~u', mb_strtolower($title), $matches);

        return array_slice(array_values(array_unique($matches[0] ?? [])), 0, 2);
    }

    /**
     * The distinctive words of a title, for the LIKE fallback. Two-character
     * names are kept on purpose: LIKE has no token floor, that is its job here.
     *
     * @return string[]
     */
    public static function tokens(string $title): array
    {
        preg_match_all('~[\w]{2,}~u', mb_strtolower($title), $matches);

        $tokens = [];

        foreach ($matches[0] ?? [] as $token) {
            if (!in_array($token, self::STOPWORDS, true) && !in_array($token, $tokens, true)) {
                $tokens[] = $token;
            }
        }

        return array_slice($tokens, 0, 4);
    }

    /**
     * @param string[] $tokens
     * @return Discussion[]
     */
    private static function like(array $tokens, int $excludingId, User $reader, int $count): array
    {
        if (!$tokens) {
            return [];
        }

        $query = Discussion::whereVisibleTo($reader)
            ->whereNull('hidden_at')
            ->where('id', '!=', $excludingId)
            ->where(function ($query) use ($tokens) {
                foreach ($tokens as $token) {
                    $query->orWhere('title', 'like', '%' . addcslashes($token, '%_\\') . '%');
                }
            });

        return static::pick($query->limit($count * 3)->get()->all(), $count);
    }
}
