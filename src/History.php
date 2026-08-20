<?php

namespace Wszdb\FlarumAiChat;

use Flarum\Discussion\Discussion;
use Flarum\User\User;

/**
 * The thread's earlier posts as chat turns, read as the asker: everything
 * visible to them and not hidden, newest first inside the budget, the oldest
 * dropped behind a marker line. The first post is the subject and is always
 * kept.
 *
 * Residual, stated not hidden: history stays as real chat turns, so earlier
 * posters keep instruction-level weight. The defence is that policy is
 * enforced in PHP (Silence, BlockedTags, visibility scopes), never by the
 * model, and the answer can only be posted where those guards already allow.
 */
class History
{
    public const DEFAULT_CHARS = 8000;

    // ponytail: bounded fetch of the newest posts; the budget trims the rest.
    // A thread longer than this loses its middle either way.
    private const MAX_POSTS = 120;

    public function __construct(private User $asker)
    {
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    public function turns(Discussion $discussion, int $beforeNumber, int $botId, int $maxChars): array
    {
        $window = $this->visible($discussion)
            ->where('number', '<', $beforeNumber)
            ->orderByDesc('number')
            ->limit(self::MAX_POSTS)
            ->get()
            ->reverse()
            ->values();

        $rows = [];

        foreach ($window as $post) {
            $rows[] = ['number' => (int) $post->number, 'user_id' => $post->user_id, 'content' => (string) $post->content];
        }

        // past MAX_POSTS the window no longer reaches the opening post, and that
        // post is what the thread is about: fetch it on its own and lead with it
        if ($beforeNumber > 1 && ($rows === [] || $rows[0]['number'] > 1)) {
            $first = $this->visible($discussion)->where('number', 1)->first();

            if ($first) {
                $missing = ($rows[0]['number'] ?? $beforeNumber) - 2;

                if ($missing > 0) {
                    array_unshift($rows, ['number' => 1, 'user_id' => null, 'content' => '(' . $missing . ' older replies left out)']);
                }

                array_unshift($rows, [
                    'number' => 1,
                    'user_id' => $first->user_id,
                    'content' => (string) $first->content,
                ]);
            }
        }

        return static::select($rows, $botId, $maxChars);
    }

    private function visible(Discussion $discussion)
    {
        return $discussion->posts()
            ->where('type', 'comment')
            ->whereNull('hidden_at')
            ->whereVisibleTo($this->asker);
    }

    /**
     * The posts as turns within the budget. Pure, for the self-check.
     *
     * @param array<int, array{number: int, user_id: mixed, content: string}> $posts ascending by number
     * @return array<int, array{role: string, content: string}>
     */
    public static function select(array $posts, int $botId, int $maxChars): array
    {
        $posts = array_values(array_filter($posts, fn (array $post) => trim($post['content']) !== ''));

        if ($posts === []) {
            return [];
        }

        $kept = [];
        $budget = max(0, $maxChars);

        // newest first, so the fresh posts win the budget
        for ($i = count($posts) - 1; $i >= 0; $i--) {
            $content = Fence::clean(trim($posts[$i]['content']));
            $cost = strlen($content) + 1;

            // the first post is the subject: keep it whatever the budget says
            if ($i !== 0 && $cost > $budget) {
                continue;
            }

            $kept[$i] = [
                'role' => (int) $posts[$i]['user_id'] === $botId ? 'assistant' : 'user',
                'content' => $content,
            ];

            $budget -= $cost;
        }

        ksort($kept);

        $turns = [];
        $previous = null;

        foreach ($kept as $i => $turn) {
            if ($previous !== null && $i > $previous + 1) {
                $turns[] = ['role' => 'user', 'content' => '(' . ($i - $previous - 1) . ' older replies left out)'];
            }

            $turns[] = $turn;
            $previous = $i;
        }

        return $turns;
    }
}
