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
        $setting = resolve(SettingsRepositoryInterface::class)->get('wszdb-flarumaichat.blocked-groups');

        return array_map('intval', json_decode($setting ?: '[]', true) ?: []);
    }

    public static function block(?User $user): bool
    {
        $blocked = static::ids();

        if (!$blocked || !$user) {
            return false;
        }

        foreach ($user->groups as $group) {
            if (in_array((int) $group->id, $blocked, true)) {
                return true;
            }
        }

        return false;
    }
}
