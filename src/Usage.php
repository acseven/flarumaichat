<?php

namespace Wszdb\FlarumAiChat;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Running totals of what the assistant spent at the API.
 */
class Usage
{
    public const KEYS = ['requests', 'failures', 'prompt_tokens', 'completion_tokens', 'cached_tokens'];

    // ponytail: running totals in the settings table, no history and no locking.
    // The queue worker answers one post at a time, so the read-modify-write is
    // safe here. Per-day charts or concurrent workers need a real table.
    public static function record(
        int $promptTokens = 0,
        int $completionTokens = 0,
        bool $failed = false,
        ?SettingsRepositoryInterface $settings = null,
        int $cachedTokens = 0
    ): void {
        $settings = $settings ?? resolve(SettingsRepositoryInterface::class);

        $add = function (string $key, int $amount) use ($settings) {
            if ($amount === 0) {
                return;
            }

            $settings->set('wszdb-flarumaichat.usage_' . $key, self::read($settings, $key) + $amount);
        };

        $add('requests', 1);
        $add('failures', $failed ? 1 : 0);
        $add('prompt_tokens', $promptTokens);
        $add('completion_tokens', $completionTokens);
        $add('cached_tokens', $cachedTokens);

        if (!$settings->get('wszdb-flarumaichat.usage_since')) {
            $settings->set('wszdb-flarumaichat.usage_since', time());
        }

        $settings->set('wszdb-flarumaichat.usage_last', time());

        self::recordDay($settings, $promptTokens, $completionTokens, $failed);
    }

    /**
     * Daily buckets, so the admin page can draw a chart.
     */
    public static function daily(SettingsRepositoryInterface $settings): array
    {
        $days = json_decode((string) $settings->get('wszdb-flarumaichat.usage_daily'), true);

        return is_array($days) ? $days : [];
    }

    // ponytail: the last 30 days only, as one JSON settings row. A longer
    // history, or per-model figures, wants a table of its own.
    private static function recordDay(
        SettingsRepositoryInterface $settings,
        int $promptTokens,
        int $completionTokens,
        bool $failed
    ): void {
        $days = self::daily($settings);
        $today = gmdate('Y-m-d');
        $day = $days[$today] ?? ['requests' => 0, 'failures' => 0, 'prompt_tokens' => 0, 'completion_tokens' => 0];

        $day['requests']++;
        $day['failures'] += $failed ? 1 : 0;
        $day['prompt_tokens'] += $promptTokens;
        $day['completion_tokens'] += $completionTokens;
        $days[$today] = $day;

        ksort($days);
        $days = array_slice($days, -30, null, true);

        $settings->set('wszdb-flarumaichat.usage_daily', json_encode($days));
    }

    public static function totals(SettingsRepositoryInterface $settings): array
    {
        $totals = [];

        foreach (self::KEYS as $key) {
            $totals[$key] = self::read($settings, $key);
        }

        $totals['since'] = (int) $settings->get('wszdb-flarumaichat.usage_since');
        $totals['last'] = (int) $settings->get('wszdb-flarumaichat.usage_last');

        return $totals;
    }

    public static function reset(SettingsRepositoryInterface $settings): void
    {
        foreach (array_merge(self::KEYS, ['since', 'last']) as $key) {
            $settings->set('wszdb-flarumaichat.usage_' . $key, 0);
        }

        $settings->set('wszdb-flarumaichat.usage_daily', '[]');
    }

    private static function read(SettingsRepositoryInterface $settings, string $key): int
    {
        return (int) $settings->get('wszdb-flarumaichat.usage_' . $key);
    }
}
