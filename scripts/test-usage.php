<?php

/**
 * Self-check for the usage counters: php -d zend.assertions=1 scripts/test-usage.php
 */

require __DIR__ . '/../src/Usage.php';

use Flarum\Settings\SettingsRepositoryInterface;
use Wszdb\FlarumAiChat\Usage;

if (!interface_exists(SettingsRepositoryInterface::class)) {
    eval('namespace Flarum\Settings; interface SettingsRepositoryInterface {
        public function all(): array;
        public function get($key, $default = null);
        public function set($key, $value);
        public function delete($key);
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

// two answered posts and one failure
Usage::record(1000, 200, false, $settings);
Usage::record(500, 100, false, $settings);
Usage::record(0, 0, true, $settings);

$totals = Usage::totals($settings);

assert($totals['requests'] === 3, 'every call counts as a request');
assert($totals['failures'] === 1, 'only the failed call counts as a failure');
assert($totals['prompt_tokens'] === 1500, 'prompt tokens add up');
assert($totals['completion_tokens'] === 300, 'completion tokens add up');
assert($totals['cached_tokens'] === 0, 'no cached tokens were recorded yet');
assert($totals['since'] > 0 && $totals['last'] >= $totals['since'], 'the window has both ends');

// the provider reports part of the prompt as cached, and it is counted
Usage::record(400, 50, false, $settings, cachedTokens: 250);
assert(Usage::totals($settings)['cached_tokens'] === 250, 'cached prompt tokens add up');

$since = $totals['since'];
Usage::record(1, 1, false, $settings);
assert(Usage::totals($settings)['since'] === $since, 'the start of the window is kept');

Usage::reset($settings);
$totals = Usage::totals($settings);

assert($totals['requests'] === 0 && $totals['prompt_tokens'] === 0, 'reset clears the counters');
assert($totals['since'] === 0, 'reset clears the window');

Usage::record(7, 3, false, $settings);
assert(Usage::totals($settings)['prompt_tokens'] === 7, 'counting restarts after a reset');

echo "ok\n";
