<?php

/**
 * Self-checks for the related-thread picker, the tool argument checks and the
 * summary-cache freshness rule:
 * php -d zend.assertions=1 scripts/test-threads-tools.php
 */

use Flarum\Discussion\Discussion;
use Flarum\Settings\SettingsRepositoryInterface;
use Wszdb\FlarumAiChat\RelatedThreads;
use Wszdb\FlarumAiChat\ThreadSummary;
use Wszdb\FlarumAiChat\Tools;

if (!interface_exists(SettingsRepositoryInterface::class)) {
    eval('namespace Flarum\Settings; interface SettingsRepositoryInterface {
        public function all(): array;
        public function get($key, $default = null);
        public function set($key, $value);
        public function delete($key);
    }');
}

if (!class_exists(\Flarum\User\User::class)) {
    eval('namespace Flarum\\User; class User {}');
}

if (!class_exists(Discussion::class)) {
    eval('namespace Flarum\Discussion; class Discussion {
        public $id;
        public $title = "";
        public $is_private = false;
        public $tags = [];
        public function __construct($id = null) { $this->id = $id; }
    }');
}

if (!class_exists(\Illuminate\Support\Arr::class)) {
    eval('namespace Illuminate\\Support; class Arr {
        public static function pluck($items, $key) {
            $out = [];
            foreach ($items as $item) { if (is_object($item) && isset($item->$key)) { $out[] = $item->$key; } }
            return $out;
        }
        public static function sort($items) { sort($items); return $items; }
    }');
}

$settings = new class implements SettingsRepositoryInterface {
    public array $rows = [];

    public function all(): array
    {
        return $this->rows;
    }

    public function get($key, $default = null)
    {
        return $this->rows[$key] ?? $default;
    }

    public function set($key, $value)
    {
        $this->rows[$key] = $value;
    }

    public function delete($key)
    {
        unset($this->rows[$key]);
    }
};

if (!function_exists('resolve')) {
    function resolve($abstract)
    {
        global $settings;

        return $settings;
    }
}

require __DIR__ . '/../src/BlockedTags.php';
require __DIR__ . '/../src/Readers.php';
require __DIR__ . '/../src/Fence.php';
require __DIR__ . '/../src/ThreadSummary.php';
require __DIR__ . '/../src/RelatedThreads.php';
require __DIR__ . '/../src/Tools.php';

// related threads: the picker never returns what the reader policy refuses
$tag = fn (int $id) => (object) ['id' => $id, 'parent_id' => null];

$openA = new Discussion(1);
$openB = new Discussion(2);
$private = new Discussion(3);
$private->is_private = true;
$blocked = new Discussion(4);
$blocked->tags = [$tag(7)];

// fill the candidates past the count, so refusal cannot hide behind the limit
$candidates = [$openA, $private, $blocked, $openB];

$settings->set('wszdb-flarumaichat.blocked-tags', json_encode([7]));

$picked = RelatedThreads::pick($candidates, 3);
assert(in_array($openA, $picked, true) && in_array($openB, $picked, true), 'open discussions are picked');
assert(! in_array($private, $picked, true), 'a private discussion is never returned');
assert(! in_array($blocked, $picked, true), 'a blocked-tag discussion is never returned');
assert(count($picked) === 2, 'the refusal does not hand back fewer than earned');

$settings->delete('wszdb-flarumaichat.blocked-tags');

// the LIKE fallback tokens: distinctive words only, two-character names kept
$tokens = RelatedThreads::tokens('How to fix the G9 lens');
assert(in_array('g9', $tokens, true), 'short model names are kept for the fallback');
assert(! in_array('how', $tokens, true) && ! in_array('the', $tokens, true), 'stopwords are dropped');
assert(count($tokens) === 3, 'only the distinctive words remain');

// tool arguments: ids and queries from the model are never trusted
assert(Tools::validId(12) && Tools::validId('12'), 'integer ids pass');
assert(! Tools::validId('twelve') && ! Tools::validId(0) && ! Tools::validId(-3) && ! Tools::validId(1.5), 'anything else is refused');

assert(Tools::cleanQuery('+G9 -ixus (raw) "quoted"') === 'G9 ixus raw quoted', 'boolean operators are stripped');
assert(Tools::cleanQuery(str_repeat('a', 65)) === '', 'an over-long query is refused');
assert(Tools::cleanQuery(42) === '', 'only strings are queries');

// the tool definitions merge with a provider-native entry instead of overwriting
$defs = Tools::definitions();
assert(count($defs) === 2, 'the two tools are defined');
assert($defs[0]['type'] === 'function' && $defs[0]['function']['name'] === 'read_thread', 'read_thread is first');

// summary cache: keyed by discussion id alone, fresh only while the markers hold
$markers = [14881, 0, [3, 7], false];
assert(! ThreadSummary::fresh(null, $markers), 'nothing cached means not fresh');
assert(! ThreadSummary::fresh(['markers' => $markers, 'text' => ''], $markers), 'an empty summary is not fresh');
assert(ThreadSummary::fresh(['markers' => $markers, 'text' => 'summary'], $markers), 'matching markers are fresh');
assert(! ThreadSummary::fresh(['markers' => [14882, 0, [3, 7], false], 'text' => 'summary'], $markers), 'a newer post means stale');
assert(! ThreadSummary::fresh(['markers' => [14881, 1, [3, 7], false], 'text' => 'summary'], $markers), 'a newly hidden post means stale');
assert(! ThreadSummary::fresh(['markers' => [14881, 0, [3, 8], false], 'text' => 'summary'], $markers), 'a new tag means stale');
assert(! ThreadSummary::fresh(['markers' => [14881, 0, [3, 7], true], 'text' => 'summary'], $markers), 'privacy means stale');

echo "ok\n";
