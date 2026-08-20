<?php

namespace Wszdb\FlarumAiChat;

/**
 * Reads local data files and picks the entries a post is about, so the model
 * answers from the site's own data instead of from memory.
 *
 * A structured file (JSON, YAML, CSV/TSV) holding a list of records is matched
 * record by record: records whose name, id or alias appears in the post are
 * projected to compact "key: value" lines, ranked by term overlap and handed
 * over within the budget; a record's `related: [ids]` pulls those records in
 * behind it, one hop, inside the same admin-trusted corpus. A Markdown file is
 * chunked by heading and matched by term overlap. Any other file (XML and the
 * rest: no parser, no XXE surface) is included as plain text, cut to budget.
 */
class ContextFiles
{
    /** Fields a record may carry that name the thing it describes. */
    private const NAME_FIELDS = ['match', 'aka', 'name', 'id', 'title', 'slug'];

    /** Fields used for matching only: printing them would just burn tokens. */
    private const SKIP_FIELDS = ['match', 'aka'];

    private const MAX_RECORDS = 15;
    private const MAX_RECORD_CHARS = 400;
    private const MAX_LIST_ITEMS = 8;
    private const MAX_FILES = 10;
    private const MAX_RECORDS_PER_FILE = 5000;
    private const MAX_HEADER_CHARS = 300;
    private const MAX_TEXT_CHARS = 1500;
    private const MAX_FILE_BYTES = 8388608; // 8 MB
    public const MAX_TOTAL_CHARS = 6000;

    private const STOPWORDS = [
        'the', 'and', 'for', 'with', 'from', 'that', 'this', 'are', 'was', 'not', 'but', 'can',
        'how', 'what', 'when', 'who', 'why', 'you', 'your', 'its', 'about', 'into', 'does',
        'on', 'is', 'to', 'of', 'in', 'it', 'at', 'my', 'do', 'no', 'so', 'up', 'me', 'we',
        'an', 'as', 'be', 'by', 'or', 'if',
    ];

    public function __construct(
        private string $baseDir,
        private string $paths,
        private int $maxTotalChars = self::MAX_TOTAL_CHARS
    ) {
    }

    /**
     * The block of facts to hand the model, empty when nothing matches.
     */
    public function factsFor(string $text): string
    {
        $blocks = [];
        $budget = $this->maxTotalChars;

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

        return array_slice($files, 0, self::MAX_FILES);
    }

    private function readFile(string $path, string $text, int $budget): string
    {
        $raw = file_get_contents($path);

        if ($raw === false || trim($raw) === '') {
            return '';
        }

        $name = basename($path);

        // [header lines, records, match by term overlap instead of by name].
        // null means no parser for this file, and only then is it quoted whole;
        // an empty list means it parsed and holds nothing to match, which must
        // not fall through to a raw dump past the field redaction below
        [$header, $records, $byOverlap] = $this->source($name, $raw);

        if ($records === null) {
            return $name . ":\n" . $this->cut($raw, min($budget, self::MAX_TEXT_CHARS));
        }

        $lines = $this->rank($records, $text, $byOverlap);

        if ($lines === []) {
            return '';
        }

        $block = $name . ":\n" . ($header !== '' ? $header . "\n" : '');

        return $this->cut($block . implode("\n", $lines), $budget);
    }

    /**
     * @return array{0: string, 1: ?array<int, array<string, mixed>>, 2: bool}
     */
    private function source(string $name, string $raw): array
    {
        switch (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            case 'json':
                $data = json_decode($raw, true);

                return is_array($data) ? $this->structured($data) : ['', null, false];

            case 'yml':
            case 'yaml':
                // no object/tag flags are ever passed, so nothing is instantiated;
                // ponytail: YAML alias expansion can balloon memory, an admin-trust
                // ceiling like the files themselves
                if (!class_exists(\Symfony\Component\Yaml\Yaml::class)) {
                    return ['', null, false];
                }

                try {
                    $data = \Symfony\Component\Yaml\Yaml::parse($raw);
                } catch (\Throwable) {
                    return ['', null, false];
                }

                return is_array($data) ? $this->structured($data) : ['', null, false];

            case 'csv':
                return $this->delimited($raw, ',');

            case 'tsv':
                return $this->delimited($raw, "\t");

            case 'md':
                // a Markdown file without headings has no records to rank, and
                // quoting it whole is what an admin listing prose expects
                $records = $this->markdown($raw);

                return ['', $records ?: null, true];
        }

        return ['', null, false];
    }

    /**
     * A JSON/YAML document: its longest list is the records, its scalar
     * top-level keys (build, mirror, ...) a short header.
     *
     * @param array<string, mixed> $data
     */
    private function structured(array $data): array
    {
        if (array_is_list($data)) {
            return ['', array_values(array_filter($data, 'is_array')), false];
        }

        $header = [];
        $list = [];

        foreach ($data as $key => $value) {
            if (is_scalar($value)) {
                $header[] = $key . ': ' . trim((string) $value);
            } elseif (is_array($value) && array_is_list($value) && count($value) > count($list)) {
                $list = $value;
            }
        }

        return [
            $this->cut(implode("\n", $header), self::MAX_HEADER_CHARS),
            array_values(array_filter($list, 'is_array')),
            false,
        ];
    }

    /**
     * @return array{0: string, 1: ?array<int, array<string, mixed>>, 2: bool}
     */
    private function delimited(string $raw, string $separator): array
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $raw)), 'strlen'));

        if ($lines === []) {
            return ['', null, false];
        }

        $columns = str_getcsv(array_shift($lines), $separator, '"', '\\');
        $records = [];

        foreach ($lines as $line) {
            $cells = str_getcsv($line, $separator, '"', '\\');
            $record = [];

            foreach ($columns as $i => $column) {
                $value = trim($cells[$i] ?? '');

                if ($value !== '') {
                    $record[trim($column)] = $value;
                }
            }

            if ($record !== []) {
                $records[] = $record;
            }
        }

        return ['', $records, false];
    }

    /**
     * A Markdown file as one record per heading.
     *
     * @return array<int, array<string, mixed>>
     */
    private function markdown(string $raw): array
    {
        preg_match_all('~^#{1,6}[ \t]*(.+?)[ \t]*$~m', $raw, $headings, PREG_OFFSET_CAPTURE);

        $records = [];
        $count = count($headings[0]);

        for ($i = 0; $i < $count; $i++) {
            $start = (int) $headings[0][$i][1] + strlen($headings[0][$i][0]);
            $end = $i + 1 < $count ? (int) $headings[0][$i + 1][1] : strlen($raw);
            $body = trim(substr($raw, $start, $end - $start));

            if ($body !== '') {
                $records[] = ['title' => trim($headings[1][$i][0]), 'content' => $body];
            }
        }

        return $records;
    }

    /**
     * The matching records, best overlap first, as projected lines.
     *
     * @param array<int, array<string, mixed>> $records
     * @return string[]
     */
    private function rank(array $records, string $text, bool $byOverlap): array
    {
        $records = array_slice($records, 0, self::MAX_RECORDS_PER_FILE, true);
        $terms = $this->terms($text);
        $chosen = [];

        foreach ($records as $index => $record) {
            $score = $this->score($terms, $record);

            if ($byOverlap ? $score < 2 : !$this->mentions($text, $record)) {
                continue;
            }

            $chosen[$index] = $score;
        }

        // one `related` hop, inside this same corpus, ranked behind what matched
        $ids = [];

        foreach ($chosen as $index => $score) {
            foreach ((array) ($records[$index]['related'] ?? []) as $relatedId) {
                if (is_scalar($relatedId)) {
                    $ids[(string) $relatedId] = true;
                }
            }
        }

        foreach ($records as $index => $record) {
            if (isset($ids[(string) ($record['id'] ?? null)]) && !isset($chosen[$index])) {
                $chosen[$index] = -1;
            }
        }

        uasort($chosen, fn (int $a, int $b) => $b <=> $a);

        $lines = [];

        foreach ($chosen as $index => $score) {
            if (count($lines) >= self::MAX_RECORDS) {
                break;
            }

            $lines[] = $this->project($records[$index]);
        }

        return $lines;
    }

    /**
     * How much of the post this record talks about: the count of the post's
     * terms that appear in it. No IDF maths — a record that names the post's
     * subject carries more of its words, and that is rank enough.
     *
     * @param string[] $terms
     * @param array<string, mixed> $record
     */
    private function score(array $terms, array $record): int
    {
        $haystack = mb_strtolower((string) (json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''));

        $hits = 0;

        foreach ($terms as $term) {
            if (str_contains($haystack, $term)) {
                $hits++;
            }
        }

        return $hits;
    }

    /**
     * The post's own terms: lowercase words of two characters or more, without
     * the stoplist noise.
     *
     * @return string[]
     */
    private function terms(string $text): array
    {
        preg_match_all('~[\w]{2,}~u', mb_strtolower($text), $matches);

        $terms = [];

        foreach ($matches[0] ?? [] as $term) {
            if (!in_array($term, self::STOPWORDS, true) && !in_array($term, $terms, true)) {
                $terms[] = $term;
            }
        }

        return $terms;
    }

    /**
     * A record as compact "key: value" lines: match fields, urls, hashes and
     * byte sizes dropped, lists flattened and capped, whole lines dropped when
     * the record outgrows its budget.
     *
     * @param array<string, mixed> $record
     */
    private function project(array $record): string
    {
        $lines = [];

        foreach ($record as $key => $value) {
            if (in_array($key, self::SKIP_FIELDS, true)) {
                continue;
            }

            foreach ($this->values((string) $key, $value) as $line) {
                $lines[] = $line;
            }
        }

        $out = '';
        $dropped = false;

        foreach ($lines as $line) {
            if (strlen($out) + strlen($line) + 1 > self::MAX_RECORD_CHARS) {
                $dropped = true;
                break;
            }

            $out .= ($out === '' ? '' : "\n") . $line;
        }

        return $dropped ? $out . "\n…" : $out;
    }

    /**
     * The "key: value" lines for one field, none when it carries nothing worth
     * the tokens.
     *
     * @return string[]
     */
    private function values(string $key, mixed $value): array
    {
        if (is_bool($value)) {
            return [$key . ': ' . ($value ? 'yes' : 'no')];
        }

        if (is_int($value) || is_float($value)) {
            return $this->isByteSize($key, $value) ? [] : [$key . ': ' . $value];
        }

        if (is_string($value)) {
            $value = trim($value);

            if ($value === '' || preg_match('~^(https?://|www\.)~i', $value) || preg_match('~^[0-9a-f]{32,}$~i', $value)) {
                return [];
            }

            return [$key . ': ' . $value];
        }

        if (is_array($value)) {
            if ($value === array_values($value)) {
                // a list of records is named by its ids, a list of scalars joined
                if ($value !== [] && !in_array(false, array_map('is_array', $value), true)) {
                    $ids = [];

                    foreach ($value as $item) {
                        foreach (['id', 'name', 'title', 'slug'] as $field) {
                            if (isset($item[$field]) && is_scalar($item[$field])) {
                                $ids[] = (string) $item[$field];
                                break;
                            }
                        }
                    }

                    return $ids === [] ? [] : [$key . ': ' . implode(', ', array_slice($ids, 0, self::MAX_LIST_ITEMS))];
                }

                $scalars = array_values(array_filter($value, 'is_scalar'));

                return $scalars === [] ? [] : [$key . ': ' . implode(', ', array_slice($scalars, 0, self::MAX_LIST_ITEMS))];
            }

            // one nested object flattens to dotted keys
            $lines = [];

            foreach ($value as $nested => $nestedValue) {
                if (is_scalar($nestedValue)) {
                    $lines[] = $this->values($key . '.' . $nested, $nestedValue);
                }
            }

            return $lines === [] ? [] : array_merge(...$lines);
        }

        return [];
    }

    /**
     * A numeric "size"/"bytes" field is a download detail, not a fact.
     */
    private function isByteSize(string $key, int|float $value): bool
    {
        return $value >= 1024 && (bool) preg_match('~(bytes?|size)$~i', $key);
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
        // mb_strcut, not substr: a cut through a multibyte character would make
        // the whole request body invalid UTF-8 and lose the answer
        return strlen($value) <= $limit ? $value : mb_strcut($value, 0, max(0, $limit)) . '…';
    }
}
