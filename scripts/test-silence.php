<?php

/**
 * Self-check for the guard that keeps the assistant out of a discussion:
 * php -d zend.assertions=1 scripts/test-silence.php
 */

use Flarum\Discussion\Discussion;
use Flarum\Settings\SettingsRepositoryInterface;
use Wszdb\FlarumAiChat\Silence;

if (!interface_exists(SettingsRepositoryInterface::class)) {
    eval('namespace Flarum\Settings; interface SettingsRepositoryInterface {
        public function all(): array;
        public function get($key, $default = null);
        public function set($key, $value);
        public function delete($key);
    }');
}

if (!class_exists(Discussion::class)) {
    eval('namespace Flarum\Discussion; class Discussion {
        public $is_private = false;
        public $tags = [];
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

// the guard reads its settings out of the container
if (!function_exists('resolve')) {
    function resolve($abstract)
    {
        global $settings;

        return $settings;
    }
}

require __DIR__ . '/../src/BlockedTags.php';
require __DIR__ . '/../src/Silence.php';

$tag = function (int $id, ?int $parent = null) {
    return (object) ['id' => $id, 'parent_id' => $parent];
};

$open = new Discussion();

assert(Silence::reason($open) === null, 'a plain discussion may be answered');

$private = new Discussion();
$private->is_private = true;

assert(Silence::reason($private) === 'private', 'a private discussion is out by default');

$settings->set('wszdb-flarumaichat.reply_in_private', true);
assert(Silence::reason($private) === null, 'the setting opens private discussions');
$settings->delete('wszdb-flarumaichat.reply_in_private');

$settings->set('wszdb-flarumaichat.blocked-tags', json_encode([7]));

$tagged = new Discussion();
$tagged->tags = [$tag(3), $tag(7)];
assert(Silence::reason($tagged) === 'blocked_tags', 'a blocked tag keeps the assistant out');

$child = new Discussion();
$child->tags = [$tag(9, 7)];
assert(Silence::reason($child) === 'blocked_tags', 'blocking a parent tag blocks its children');

$other = new Discussion();
$other->tags = [$tag(3), $tag(4, 3)];
assert(Silence::reason($other) === null, 'other tags are left alone');

// private wins, so the reason names the setting the admin can act on
$both = new Discussion();
$both->is_private = true;
$both->tags = [$tag(7)];
assert(Silence::reason($both) === 'private', 'privacy is reported before the tags');

echo "ok\n";
