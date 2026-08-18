<?php

namespace Wszdb\FlarumAiChat;

use Flarum\Discussion\Discussion;
use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Tags where the assistant must stay silent, whatever the actor is. Blocking a
 * primary tag blocks its children too. Flarum nests tags one level deep, so
 * checking the parent is enough.
 */
class BlockedTags
{
    public static function ids(): array
    {
        $setting = resolve(SettingsRepositoryInterface::class)->get('wszdb-flarumaichat.blocked-tags');

        return array_map('intval', json_decode($setting ?: '[]', true) ?: []);
    }

    public static function block(Discussion $discussion): bool
    {
        $blocked = static::ids();

        if (!$blocked) {
            return false;
        }

        foreach ($discussion->tags as $tag) {
            if (in_array((int) $tag->id, $blocked, true) || in_array((int) $tag->parent_id, $blocked, true)) {
                return true;
            }
        }

        return false;
    }
}
