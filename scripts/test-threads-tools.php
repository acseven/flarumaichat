<?php

/**
 * Self-checks for the related-thread picker, the tool argument checks and the
 * summary-cache freshness rule:
 * php -d zend.assertions=1 scripts/test-threads-tools.php
 */

use Flarum\Discussion\Discussion;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Guest;
use Flarum\User\User;
use Illuminate\Support\Collection;
use Wszdb\FlarumAiChat\Readers;
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
    eval('namespace Flarum\\User; class User {
        public $id = 1;
        public $groups;
        public static $forumOpen = false;
        public function can($permission) { return static::$forumOpen; }
        public function isGuest() { return false; }
        public function setRelation($name, $value) { $this->$name = $value; return $this; }
    }');
}

if (!class_exists(\Flarum\User\Guest::class)) {
    eval('namespace Flarum\\User; class Guest extends User {
        public $id = 0;
        public function isGuest() { return true; }
    }');
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

if (!class_exists(\Illuminate\Support\Collection::class)) {
    eval('namespace Illuminate\\Support; class Collection implements \\Countable {
        public array $items;
        public function __construct(array $items = []) { $this->items = $items; }
        public function count(): int { return count($this->items); }
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

// the model numbers the FULLTEXT index is blind to
assert(RelatedThreads::modelNumbers('IXUS 75 missing modules') === ['75'], 'a short model number is found');
assert(RelatedThreads::modelNumbers('CHDK 1.6 on the G7 X') === ['g7'], 'a lone digit is not a model number');
assert(RelatedThreads::modelNumbers('S5 IS and 5D and A570') === ['s5', '5d'], 'at most two, longer names left to the index');
assert(RelatedThreads::modelNumbers('lens problem') === [], 'a title without one gets no extra ordering');

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

// the cross-discussion reader: a guest when guests may read, else the asker,
// and never the bot user — not even when there is no asker at all
$asker = new User();

User::$forumOpen = true;
assert(Readers::crossDiscussion($asker) instanceof Guest, 'an open forum is read as a guest');

User::$forumOpen = false;
$asker->setRelation('groups', new Collection(['staff']));
$closed = Readers::crossDiscussion($asker);
assert($closed !== $asker, 'a closed forum never reads as the asker themselves');
assert($closed->groups->count() === 0, 'the fallback reader carries none of the asker groups');
assert($asker->groups->count() === 1, 'the asker keeps their own groups');
assert(Readers::crossDiscussion(null) instanceof Guest, 'no asker means a guest, never the bot');
assert(Readers::crossDiscussion(new Guest()) instanceof Guest, 'a guest asker stays a guest');

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
