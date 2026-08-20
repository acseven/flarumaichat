<?php

namespace Wszdb\FlarumAiChat;

use Flarum\User\User;

/**
 * The tools the model may call, off by default. They run through the same
 * BlockedTags-aware readers as every other cross-discussion context: there is
 * no raw query here, so a blocked-tag or private thread is as unreachable for
 * a tool as it is for a quoted link.
 *
 * The execution cap bounds cost and blast radius only; it is not an injection
 * defence. Confidentiality rests on the reader scoping above.
 */
class Tools
{
    public const MAX_RUNS = 3;

    private const MAX_QUERY_CHARS = 64;
    private const READ_CHARS = 2000;

    public function __construct(private User $reader)
    {
    }

    /**
     * The OpenAI-style tool definitions, merged with any provider-native tool
     * (the z.ai web search) rather than overwriting it.
     */
    public static function definitions(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'read_thread',
                    'description' => 'Read one discussion of this forum by its numeric id.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => ['id' => ['type' => 'integer', 'description' => 'the discussion id']],
                        'required' => ['id'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search_threads',
                    'description' => "Search the titles of this forum's discussions.",
                    'parameters' => [
                        'type' => 'object',
                        'properties' => ['query' => ['type' => 'string', 'description' => 'plain search terms']],
                        'required' => ['query'],
                    ],
                ],
            ],
        ];
    }

    /**
     * One tool call: validated arguments in, a string for the model out.
     */
    public function run(string $name, array $args): string
    {
        try {
            return match ($name) {
                'read_thread' => $this->readThread($args),
                'search_threads' => $this->searchThreads($args),
                default => $this->error('unknown tool'),
            };
        } catch (\Throwable $e) {
            resolve('log')->error('[ChatGPT Tools] tool call failed', ['tool' => $name, 'error' => $e->getMessage()]);

            return $this->error('the tool failed');
        }
    }

    /**
     * A positive integer, the only shape an id may arrive in.
     */
    public static function validId(mixed $id): bool
    {
        return (is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id > 0;
    }

    /**
     * Model-written search terms: bounded, and stripped of every boolean-mode
     * operator, so only natural-language input ever reaches the index.
     * An over-long or empty result refuses the call.
     */
    public static function cleanQuery(mixed $query): string
    {
        if (!is_string($query)) {
            return '';
        }

        $query = trim((string) preg_replace('#(?:[+\-<>()~*"@]|\s)+#', ' ', $query));

        return mb_strlen($query) > self::MAX_QUERY_CHARS ? '' : $query;
    }

    private function readThread(array $args): string
    {
        if (!self::validId($args['id'] ?? null)) {
            return $this->error('id must be a positive integer');
        }

        $block = (new ThreadSummary($this->reader, null, self::READ_CHARS))->render((int) $args['id']);

        return $block === '' ? $this->error('no such thread here') : $block;
    }

    private function searchThreads(array $args): string
    {
        $query = self::cleanQuery($args['query'] ?? null);

        if ($query === '') {
            return $this->error('query must be plain search terms, at most ' . self::MAX_QUERY_CHARS . ' characters');
        }

        $found = RelatedThreads::find($query, 0, $this->reader, 5);

        if (!$found) {
            return 'nothing found';
        }

        return implode("\n", array_map(
            fn (object $discussion) => $discussion->id . ' ' . $discussion->title,
            $found
        ));
    }

    private function error(string $message): string
    {
        return (string) (json_encode(['error' => $message]) ?: '{"error":"failed"}');
    }
}
