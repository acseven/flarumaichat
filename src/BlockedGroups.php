<?php

namespace Wszdb\FlarumAiChat;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;

/**
 * Groups whose members the assistant never answers. A blocklist, not a
 * permission: Flarum permissions add up, so one granting group would hand the
 * assistant back to a member the admin wants left alone.
 */
class BlockedGroups
{
    public static function ids(): array
    {
        return static::read('wszdb-flarumaichat.blocked-groups');
    }

    public static function block(?User $user): bool
    {
        return static::member($user, static::ids());
    }

    /**
     * Groups the block leaves open to a hand-made request: staff may still use
     * the post control to have the assistant answer one of their posts.
     */
    public static function manualOverride(?User $user): bool
    {
        return static::member($user, static::overrideIds());
    }

    public static function overrideIds(): array
    {
        return static::read('wszdb-flarumaichat.manual-override-groups');
    }

    private static function read(string $key): array
    {
        $setting = resolve(SettingsRepositoryInterface::class)->get($key);

        return array_map('intval', json_decode($setting ?: '[]', true) ?: []);
    }

    private static function member(?User $user, array $ids): bool
    {
        if (!$ids || !$user) {
            return false;
        }

        foreach ($user->groups as $group) {
            if (in_array((int) $group->id, $ids, true)) {
                return true;
            }
        }

        return false;
    }
}
