<?php

/**
 * Self-check for the url reading behind the linked-thread context:
 * php -d zend.assertions=1 scripts/test-linked-discussions.php
 */

use Wszdb\FlarumAiChat\LinkedDiscussions;

// the class only needs the models when it reads a thread; the url reading stands alone
if (!class_exists(\Flarum\Discussion\Discussion::class)) {
    eval('namespace Flarum\Discussion; class Discussion {}');
}

if (!class_exists(\Flarum\User\User::class)) {
    eval('namespace Flarum\User; class User {}');
}

require __DIR__ . '/../src/LinkedDiscussions.php';

$base = 'https://forum.example.com';

$ids = fn (string $text) => LinkedDiscussions::ids($text, $base);

assert($ids('see https://forum.example.com/d/14881-sd-overclocking') === [14881], 'a slugged link is read');
assert($ids('see https://forum.example.com/d/14881') === [14881], 'a bare link is read');
assert($ids('https://forum.example.com/d/14881-sd/7 says so') === [14881], 'a post number is ignored');
assert($ids('a https://forum.example.com/d/1 b https://forum.example.com/d/2') === [1, 2], 'order is kept');
assert($ids('https://forum.example.com/d/5 and again /d/5') === [5], 'a repeat counts once');

// only our own forum, and only discussions
assert($ids('https://evil.example.com/d/14881') === [], 'another host is not ours');
assert($ids('https://forum.example.com/u/someone') === [], 'a profile is not a thread');
assert($ids('/d/14881 on its own') === [], 'a bare path names no host');
assert(LinkedDiscussions::ids('https://forum.example.com/d/1', '') === [], 'no forum url, no links');

// a trailing slash in the forum url must not break the match
assert(LinkedDiscussions::ids('https://forum.example.com/d/9', $base . '/') === [9], 'a trailing slash is trimmed');

echo "ok\n";
