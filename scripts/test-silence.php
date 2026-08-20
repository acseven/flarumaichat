<?php

/**
 * Self-check for the guard that keeps the assistant out of a discussion:
 * php -d zend.assertions=1 scripts/test-silence.php
 */

use Flarum\Discussion\Discussion;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Wszdb\FlarumAiChat\Silence;

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
        public $groups = [];
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
require __DIR__ . '/../src/BlockedGroups.php';
require __DIR__ . '/../src/Silence.php';
require __DIR__ . '/../src/Readers.php';

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

$settings->delete('wszdb-flarumaichat.blocked-tags');

$group = function (int $id) {
    return (object) ['id' => $id];
};

$member = new User();
$member->groups = [$group(3), $group(8)];

assert(Silence::reason($open, $member) === null, 'no blocked group, no reason to stay out');

$settings->set('wszdb-flarumaichat.blocked-groups', json_encode([8]));

assert(Silence::reason($open, $member) === 'blocked_groups', 'a blocked group keeps the assistant out');
assert(Silence::reason($open) === null, 'without an author there is nothing to block');

$other = new User();
$other->groups = [$group(3)];
assert(Silence::reason($open, $other) === null, 'other groups are left alone');

// a blocked group does not open a private discussion, and privacy is reported first
assert(Silence::reason($private, $member) === 'private', 'privacy is reported before the groups');

// the override only opens the door the manual trigger uses; the block itself stands
$settings->set('wszdb-flarumaichat.manual-override-groups', json_encode([8]));

assert(\Wszdb\FlarumAiChat\BlockedGroups::manualOverride($member) === true, 'the override names the group');
assert(\Wszdb\FlarumAiChat\BlockedGroups::manualOverride($other) === false, 'other groups have no override');
assert(Silence::reason($open, $member) === 'blocked_groups', 'the override leaves the automatic answer blocked');

// the cross-discussion reader policy: what may be quoted into another thread
$settings->set('wszdb-flarumaichat.blocked-tags', json_encode([7]));

assert(\Wszdb\FlarumAiChat\Readers::crossOk($open) === true, 'a plain discussion may be quoted');
assert(\Wszdb\FlarumAiChat\Readers::crossOk($private) === false, 'a private discussion is never quoted');
assert(\Wszdb\FlarumAiChat\Readers::crossOk($tagged) === false, 'a blocked-tag discussion is never quoted');
assert(\Wszdb\FlarumAiChat\Readers::crossOk($child) === false, 'a blocked parent tag is never quoted either');

$settings->delete('wszdb-flarumaichat.blocked-tags');
$settings->delete('wszdb-flarumaichat.blocked-groups');
$settings->delete('wszdb-flarumaichat.manual-override-groups');

assert(\Wszdb\FlarumAiChat\Readers::crossOk($tagged) === true, 'without blocked tags the discussion is quotable again');

echo "ok\n";
