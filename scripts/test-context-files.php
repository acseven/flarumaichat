<?php

/**
 * Self-check for ContextFiles. Run: php scripts/test-context-files.php
 */

require __DIR__ . '/../src/ContextFiles.php';

use Wszdb\FlarumAiChat\ContextFiles;

$base = sys_get_temp_dir() . '/flarumaichat-context-check';
@mkdir($base . '/data', 0777, true);

file_put_contents($base . '/data/cameras.json', json_encode([
    'build' => '1.6.1-6355',
    'cameras' => [
        ['id' => 'm3', 'name' => 'M3', 'match' => ['M3'], 'state' => 'stable'],
        ['id' => 'g1x', 'name' => 'G1 X', 'match' => ['G1 X', 'G1X'], 'state' => 'beta'],
        ['id' => 'a3200', 'name' => 'A3200 IS', 'match' => ['A3200'], 'state' => 'stable'],
    ],
]));
file_put_contents($base . '/data/notes.txt', 'plain text notes about the forum');
file_put_contents($base . '/secret.json', json_encode([['id' => 'nope', 'name' => 'Outside']]));

$facts = fn (string $paths, string $text) => (new ContextFiles($base . '/data', $paths))->factsFor($text);

// the named camera is handed over, the others are not
$out = $facts('cameras.json', 'What can you say on the M3?');
assert(str_contains($out, '"id":"m3"'), 'M3 must be matched');
assert(! str_contains($out, '"id":"g1x"'), 'unmentioned cameras must stay out');
assert(str_contains($out, 'cameras.json:'), 'the block names its file');

// the records win over a shorter list living beside them
file_put_contents($base . '/data/wrapped.json', json_encode([
    'changelog' => [['revision' => 'M3 rebuilt']],
    'cameras' => [['id' => 'm3', 'name' => 'M3'], ['id' => 'g7x', 'name' => 'G7 X']],
]));
assert(str_contains($facts('wrapped.json', 'about the M3'), '"id":"m3"'), 'the longest list holds the records');

// a name written with a space, and an alias, both match
assert(str_contains($facts('cameras.json', 'my G1 X wont boot'), '"id":"g1x"'), 'spaced name must match');
assert(str_contains($facts('cameras.json', 'is the G1X supported?'), '"id":"g1x"'), 'alias must match');

// a name inside a longer word is not a mention
assert($facts('cameras.json', 'the A32000 is not a camera') === '', 'no match inside longer tokens');

// nothing configured, nothing said, nothing read
assert($facts('', 'M3') === '', 'no paths means no facts');
assert($facts('cameras.json', 'hello there') === '', 'no mention means no facts');
assert($facts('missing.json', 'M3') === '', 'a missing file is skipped');

// a path may not climb out of the base directory, nor be absolute
assert($facts('../secret.json', 'Outside') === '', 'traversal must be refused');
assert($facts($base . '/secret.json', 'Outside') === '', 'absolute paths must be refused');

// a symlinked file inside the base directory is read: extension directories are often symlinks
@symlink($base . '/secret.json', $base . '/data/linked.json');
assert(str_contains($facts('linked.json', 'Outside'), '"id":"nope"'), 'a symlink inside the base is followed');

// the budget bounds what one prompt carries
$tight = (new ContextFiles($base . '/data', 'notes.txt', 20))->factsFor('anything');
assert($tight !== '' && strlen($tight) < 200, 'a small budget cuts the block down');

// a non-JSON file is passed through as text
assert(str_contains($facts('notes.txt', 'anything'), 'plain text notes'), 'plain files pass through');

array_map('unlink', glob($base . '/data/*') ?: []);
@unlink($base . '/secret.json');

echo "ok\n";
