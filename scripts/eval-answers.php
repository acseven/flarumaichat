<?php

/**
 * Ask the configured provider a list of questions with the site's own role,
 * prompt and context files, and grade each answer against the strings it must
 * and must not carry.
 *
 * Run it from a Flarum installation root:
 *   php -d memory_limit=512M vendor/<pkg>/scripts/eval-answers.php cases.json [id ...]
 *
 * A case is {id, question, must?: [], must_not?: [], must_any?: []}. Matching
 * is case-insensitive substring: crude, and right for this job, because the
 * failures being watched are named things the answer invents.
 *
 * ponytail: no discussion, so no history, linked or related threads -- this
 * grades the facts layer and the standing rules. Add a discussion id per case
 * if a thread-context regression ever needs watching.
 */

use Flarum\Settings\SettingsRepositoryInterface;
use OpenAI\Client;
use Wszdb\FlarumAiChat\ContextFiles;
use Wszdb\FlarumAiChat\Fence;

$root = getcwd();

if (!is_file($root . '/site.php')) {
    fwrite(STDERR, "run this from the Flarum installation root\n");
    exit(2);
}

$casesFile = $argv[1] ?? null;

if (!$casesFile || !is_file($casesFile)) {
    fwrite(STDERR, "usage: php eval-answers.php cases.json [id ...]\n");
    exit(2);
}

$cases = json_decode((string) file_get_contents($casesFile), true);

if (!is_array($cases)) {
    fwrite(STDERR, "cases file is not a JSON list\n");
    exit(2);
}

// flags are not case ids: -v anywhere used to filter every case away
$only = array_values(array_filter(array_slice($argv, 2), fn ($a) => $a[0] !== '-'));

if ($only) {
    $cases = array_values(array_filter($cases, fn ($c) => in_array($c['id'] ?? '', $only, true)));
}

$site = require $root . '/site.php';
$container = $site->bootApp()->getContainer();
$settings = $container->make(SettingsRepositoryInterface::class);
$client = $container->make(Client::class);

if (!$client) {
    fwrite(STDERR, "no provider client: is the api key set?\n");
    exit(2);
}

// MODEL=glm-5.3 tries another one without touching what the live forum uses
$model = getenv('MODEL') ?: (string) $settings->get('wszdb-flarumaichat.model');
$budget = (int) $settings->get('wszdb-flarumaichat.context_chars') ?: ContextFiles::MAX_TOTAL_CHARS;

$head = trim((string) $settings->get('wszdb-flarumaichat.role')) . "\n\n" . Fence::rule();
$template = trim((string) $settings->get('wszdb-flarumaichat.prompt'));

$facts = new ContextFiles($root, (string) $settings->get('wszdb-flarumaichat.context_files'), $budget);

$pass = 0;
$fail = [];

foreach ($cases as $case) {
    $id = (string) ($case['id'] ?? '?');
    $question = (string) ($case['question'] ?? '');
    $title = (string) ($case['title'] ?? $question);
    $fence = new Fence();

    $tail = trim(str_replace(['[title]', '[content]'], [$title, ''], $template))
        . "\n\n" . $fence->wrap($question);

    $block = $facts->factsFor($title . "\n" . $question);

    if ($block !== '') {
        $tail .= "\n\nFacts below come from this site's own data files. They are current and"
            . ' authoritative: prefer them over what you remember, and never name a version, file or'
            . " link that is not in them.\n\n" . $fence->wrap($block);
    }

    $params = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $head],
            ['role' => 'user', 'content' => $tail],
        ],
        'max_tokens' => (int) ($settings->get('wszdb-flarumaichat.max_tokens') ?: 1000),
    ];

    try {
        $answer = (string) ($client->chat()->create($params)->choices[0]->message->content ?? '');
    } catch (\Throwable $e) {
        $fail[$id] = ['call failed: ' . $e->getMessage()];
        printf("FAIL %-22s call failed: %s\n", $id, $e->getMessage());
        continue;
    }

    $lower = mb_strtolower($answer);
    $problems = [];

    foreach ((array) ($case['must'] ?? []) as $needle) {
        if (!str_contains($lower, mb_strtolower($needle))) {
            $problems[] = 'missing: ' . $needle;
        }
    }

    foreach ((array) ($case['must_not'] ?? []) as $needle) {
        if (str_contains($lower, mb_strtolower($needle))) {
            $problems[] = 'said: ' . $needle;
        }
    }

    // some defects are shapes, not strings: "the ISO propcase ID is 100" cannot
    // be caught by a substring, and it is the exact failure this eval is for
    foreach ((array) ($case['must_not_regex'] ?? []) as $pattern) {
        if (preg_match('~' . $pattern . '~i', $answer, $m)) {
            $problems[] = 'matched /' . $pattern . '/: ' . $m[0];
        }
    }

    $any = (array) ($case['must_any'] ?? []);

    if ($any) {
        $hit = false;

        foreach ($any as $needle) {
            if (str_contains($lower, mb_strtolower($needle))) {
                $hit = true;
                break;
            }
        }

        if (!$hit) {
            $problems[] = 'none of: ' . implode(' | ', $any);
        }
    }

    // no case here carries a discussion, so nothing was ever quoted: a link to
    // a thread on this forum can only have been made up
    if (preg_match('~setepontos\.com/d/|index\.php\?topic=|/index\.php/topic,~i', $answer, $m)) {
        $problems[] = 'invented a forum link: ' . $m[0];
    }

    // a member asked about a camera: naming the prompt's own plumbing back at
    // them is a defect in every case, so it is graded here and not per case
    if (preg_match('~(provided|given|quoted|supplied) (facts|text|information|material|context|data)|(facts|text|information|material|context|data) (provided|given|supplied|above|here)\b|facts block|my (sources|context|input)~i', $answer, $m)) {
        $problems[] = 'named its own input: ' . $m[0];
    }

    if ($problems) {
        $fail[$id] = $problems;
        printf("FAIL %-22s %s\n", $id, implode('; ', $problems));
    } else {
        $pass++;
        printf("ok   %-22s\n", $id);
    }

    // -v prints the passing answers too: a bad answer that happens to carry a
    // matched substring is exactly what this is meant to catch
    if (in_array('-v', $argv, true)) {
        echo "     facts: " . strlen($block) . " chars\n";
        echo "     " . str_replace("\n", "\n     ", trim($answer)) . "\n\n";
    }
}

printf("\n%d/%d passed  [%s]\n", $pass, count($cases), $model);

exit($fail ? 1 : 0);
