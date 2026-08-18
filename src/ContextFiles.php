<?php

namespace Wszdb\FlarumAiChat;

/**
 * Reads local data files and picks the entries a post is about, so the model
 * answers from the site's own data instead of from memory.
 *
 * A JSON file holding a list of records (a plain list, or an object with one
 * list in it) is matched record by record: a record whose name, id, or any of
 * its alias fields appears in the post is included whole. Any other file is
 * included as plain text, cut to the same budget.
 */
class ContextFiles
{
    /** Fields a record may carry that name the thing it describes. */
    private const NAME_FIELDS = ['match', 'aka', 'name', 'id', 'title', 'slug'];

    private const MAX_RECORDS = 5;
    private const MAX_RECORD_CHARS = 1500;
    private const MAX_TOTAL_CHARS = 6000;
    private const MAX_FILE_BYTES = 8388608; // 8 MB

    public function __construct(
        private string $baseDir,
        private string $paths
    ) {
    }

    /**
     * The block of facts to hand the model, empty when nothing matches.
     */
    public function factsFor(string $text): string
    {
        $blocks = [];
        $budget = self::MAX_TOTAL_CHARS;

        foreach ($this->files() as $path) {
            if ($budget <= 0) {
                break;
            }

            $block = $this->readFile($path, $text, $budget);

            if ($block === '') {
                continue;
            }

            $blocks[] = $block;
            $budget -= strlen($block);
        }

        return implode("\n\n", $blocks);
    }

    /**
     * The configured paths that exist and stay inside the installation.
     *
     * @return string[]
     */
    private function files(): array
    {
        $base = rtrim($this->baseDir, '/');
        $files = [];

        foreach (preg_split('/[\r\n,]+/', $this->paths) as $line) {
            $line = trim($line, " \t\n\r\0\x0B/");

            if ($line === '') {
                continue;
            }

            // the path is written by an admin and stays under the installation:
            // no climbing out, no absolute paths. What it resolves to may well sit
            // elsewhere, since an extension directory is often a symlink.
            if (str_contains($line, '..') || preg_match('/^[a-z]+:/i', $line)) {
                continue;
            }

            $path = $base . '/' . $line;

            if (! is_file($path) || filesize($path) > self::MAX_FILE_BYTES) {
                continue;
            }

            $files[] = $path;
        }

        return $files;
    }

    private function readFile(string $path, string $text, int $budget): string
    {
        $raw = file_get_contents($path);

        if ($raw === false || $raw === '') {
            return '';
        }

        $name = basename($path);
        $records = $this->records($raw);

        if ($records === null) {
            return $name . ":\n" . $this->cut($raw, min($budget, self::MAX_RECORD_CHARS));
        }

        $matched = [];

        foreach ($records as $record) {
            if (count($matched) >= self::MAX_RECORDS) {
                break;
            }

            if ($this->mentions($text, $record)) {
                $matched[] = $this->cut(
                    json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    self::MAX_RECORD_CHARS
                );
            }
        }

        if (! $matched) {
            return '';
        }

        return $this->cut($name . ":\n" . implode("\n", $matched), $budget);
    }

    /**
     * The list of records in a JSON file, or null when the file is not one.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function records(string $raw): ?array
    {
        $data = json_decode($raw, true);

        if (! is_array($data)) {
            return null;
        }

        // a file may wrap its records next to other keys, and may hold more than
        // one list (records plus a changelog, say): the longest list is the records
        if (! array_is_list($data)) {
            $longest = [];

            foreach ($data as $value) {
                if (is_array($value) && array_is_list($value) && count($value) > count($longest)) {
                    $longest = $value;
                }
            }

            $data = $longest;
        }

        if (! array_is_list($data)) {
            return null;
        }

        $records = array_values(array_filter($data, 'is_array'));

        return $records ?: null;
    }

    /**
     * Whether the post names the thing this record describes.
     *
     * @param array<string, mixed> $record
     */
    private function mentions(string $text, array $record): bool
    {
        foreach (self::NAME_FIELDS as $field) {
            foreach ((array) ($record[$field] ?? []) as $name) {
                if (! is_scalar($name)) {
                    continue;
                }

                $name = trim((string) $name);

                // a one-character name matches far too much to be a signal
                if (mb_strlen($name) < 2) {
                    continue;
                }

                if (preg_match('/(?<![\w-])' . preg_quote($name, '/') . '(?![\w-])/iu', $text)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function cut(string $value, int $limit): string
    {
        return strlen($value) <= $limit ? $value : substr($value, 0, $limit) . '…';
    }
}
