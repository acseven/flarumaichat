<?php

/**
 * Self-check for ContextFiles. Run: php -d zend.assertions=1 scripts/test-context-files.php
 */

require __DIR__ . '/../src/ContextFiles.php';

use Wszdb\FlarumAiChat\ContextFiles;

$base = sys_get_temp_dir() . '/flarumaichat-context-check';
@mkdir($base . '/data', 0777, true);

file_put_contents($base . '/data/cameras.json', json_encode([
    'build' => '1.6.1-6355',
    'cameras' => [
        ['id' => 'm3', 'name' => 'M3', 'match' => ['M3'], 'state' => 'stable'],
        [
            'id' => 'g1x', 'name' => 'G1 X', 'match' => ['G1 X', 'G1X'], 'state' => 'beta',
            'url' => 'https://example.com/g1x.zip', 'sha' => str_repeat('a', 40), 'size' => 4567890,
            'related' => ['m3'],
        ],
        ['id' => 'a3200', 'name' => 'A3200 IS', 'match' => ['A3200'], 'state' => 'stable'],
    ],
]));
file_put_contents($base . '/data/notes.txt', 'plain text notes about the forum');
file_put_contents($base . '/secret.json', json_encode([['id' => 'nope', 'name' => 'Outside']]));

$facts = fn (string $paths, string $text) => (new ContextFiles($base . '/data', $paths))->factsFor($text);

// the named camera is handed over as projected lines, with the file header
$out = $facts('cameras.json', 'What can you say on the M3?');
assert(str_contains($out, 'cameras.json:'), 'the block names its file');
assert(str_contains($out, 'build: 1.6.1-6355'), 'the header carries the top-level keys');
assert(str_contains($out, 'id: m3'), 'M3 must be matched');
assert(str_contains($out, 'state: stable'), 'the record is projected as key: value lines');
assert(! str_contains($out, 'g1x'), 'unmentioned cameras must stay out');
assert(! str_contains($out, 'match'), 'match fields are dropped from the projection');

// noise fields never burn tokens: urls, long hashes, byte sizes
$g1x = $facts('cameras.json', 'my G1X wont boot');
assert(str_contains($g1x, 'id: g1x'), 'the alias must match');
assert(! str_contains($g1x, 'https://'), 'urls are dropped');
assert(! str_contains($g1x, str_repeat('a', 40)), 'long hashes are dropped');
assert(! str_contains($g1x, '4567890'), 'byte sizes are dropped');

// the related hop pulls the linked record in, behind the one that matched
assert(str_contains($g1x, 'id: m3'), 'a related id is quoted too');
assert(strpos($g1x, 'id: g1x') < strpos($g1x, 'id: m3'), 'what matched comes first');

// the records win over a shorter list living beside them
file_put_contents($base . '/data/wrapped.json', json_encode([
    'changelog' => [['revision' => 'M3 rebuilt']],
    'cameras' => [['id' => 'm3', 'name' => 'M3'], ['id' => 'g7x', 'name' => 'G7 X']],
]));
assert(str_contains($facts('wrapped.json', 'about the M3'), 'id: m3'), 'the longest list holds the records');

// a name written with a space, and an alias, both match
assert(str_contains($facts('cameras.json', 'my G1 X wont boot'), 'id: g1x'), 'spaced name must match');

// a name inside a longer word is not a mention
assert($facts('cameras.json', 'the A32000 is not a camera') === '', 'no match inside longer tokens');

// ranking: of the records that matched, the one sharing more of the post's terms leads
file_put_contents($base . '/data/ranked.json', json_encode([
    'records' => [
        ['name' => 'Beta', 'notes' => 'screen'],
        ['name' => 'Alpha', 'notes' => 'battery port'],
    ],
]));
$ranked = $facts('ranked.json', 'Alpha and Beta: which has the better battery and port?');
assert(strpos($ranked, 'name: Alpha') < strpos($ranked, 'name: Beta'), 'the better overlap ranks first');

// lists are flattened and capped
file_put_contents($base . '/data/lists.json', json_encode([
    'records' => [['id' => 's100', 'name' => 'S100', 'ports' => ['usb', 'hdmi'], 'builds' => array_map(fn ($i) => "b$i", range(1, 20))]],
]));
$lists = $facts('lists.json', 'the S100 ports');
assert(str_contains($lists, 'ports: usb, hdmi'), 'scalar lists are joined');
assert(substr_count($lists, 'b1, b2') === 1 && ! str_contains($lists, 'b9,'), 'lists are capped');

// a csv file is records too
file_put_contents($base . '/data/states.csv', "name,state\nM3,stable\nG1X,beta\n");
$csv = $facts('states.csv', 'is the M3 stable?');
assert(str_contains($csv, 'name: M3') && str_contains($csv, 'state: stable'), 'csv rows are records');
assert(! str_contains($csv, 'G1X'), 'unmatched csv rows stay out');

// a tsv file is records too
file_put_contents($base . '/data/states.tsv', "name\tstate\nM3\tstable\n");
assert(str_contains($facts('states.tsv', 'is the M3 stable?'), 'state: stable'), 'tsv rows are records');

// a markdown file is chunked by heading and matched by overlap
file_put_contents($base . '/data/notes.md', "# Battery life\n\nThe M3 runs long.\n\n# Screen\n\nBright.\n");
$md = $facts('notes.md', 'how is battery life on the M3?');
assert(str_contains($md, 'title: Battery life') && str_contains($md, 'The M3 runs long.'), 'the matching section is quoted');
assert(! str_contains($md, 'Screen'), 'other sections stay out');

// a yaml file is records, when a yaml parser is installed
if (class_exists(\Symfony\Component\Yaml\Yaml::class)) {
    file_put_contents($base . '/data/states.yml', "records:\n  - name: M3\n    state: stable\n  - name: G1X\n    state: beta\n");
    $yml = $facts('states.yml', 'is the M3 stable?');
    assert(str_contains($yml, 'name: M3') && str_contains($yml, 'state: stable'), 'yaml rows are records');
    assert(! str_contains($yml, 'G1X'), 'unmatched yaml rows stay out');
}

// nothing configured, nothing said, nothing read
assert($facts('', 'M3') === '', 'no paths means no facts');
assert($facts('cameras.json', 'hello there') === '', 'no mention means no facts');
assert($facts('missing.json', 'M3') === '', 'a missing file is skipped');

// a path may not climb out of the base directory, nor be absolute
assert($facts('../secret.json', 'Outside') === '', 'traversal must be refused');
assert($facts($base . '/secret.json', 'Outside') === '', 'absolute paths must be refused');

// a symlinked file inside the base directory is read: extension directories are often symlinks
@symlink($base . '/secret.json', $base . '/data/linked.json');
assert(str_contains($facts('linked.json', 'Outside'), 'id: nope'), 'a symlink inside the base is followed');

// the budget bounds what one prompt carries
$tight = (new ContextFiles($base . '/data', 'notes.txt', 20))->factsFor('anything');
assert($tight !== '' && strlen($tight) < 200, 'a small budget cuts the block down');

// a non-structured file is passed through as text
assert(str_contains($facts('notes.txt', 'anything'), 'plain text notes'), 'plain files pass through');

// a file that parses but holds no records is not dumped whole: the raw path
// skips the ranking and the field redaction
file_put_contents($base . '/data/empty.csv', "name,state\n");
assert($facts('empty.csv', 'name') === '', 'a header-only table yields nothing');
file_put_contents($base . '/data/flat.json', '{"build":"1.6.1","token":"secret-value"}');
assert($facts('flat.json', 'build token') === '', 'a record-less document yields nothing');

array_map('unlink', glob($base . '/data/*') ?: []);
@unlink($base . '/secret.json');

echo "ok\n";
