<?php

namespace Wszdb\FlarumAiChat;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;

/**
 * Calling the assistant by name. A mention is a hand-made request, so it
 * answers past the reply count the automatic path keeps to, and past a tag or
 * group block for the groups the admin trusts. Privacy is never past.
 *
 * flarum/mentions ships no event, and it writes its post_mentions_user rows
 * from its own Posted listener with no ordering against ours, so the post
 * content is what we read.
 */
class Mentions
{
    public static function enabled(): bool
    {
        return (bool) resolve(SettingsRepositoryInterface::class)->get('wszdb-flarumaichat.mentions');
    }

    /**
     * The user the assistant posts as, or null when the setting names nobody.
     */
    public static function bot(): ?User
    {
        $id = (int) resolve(SettingsRepositoryInterface::class)->get('wszdb-flarumaichat.user_prompt');

        return $id ? User::find($id) : null;
    }

    /**
     * Does this post call the assistant? Both shapes flarum/mentions writes:
     * the autocompleted @"display name"#id, and the plain @username that the
     * allow_username_format setting keeps alive.
     */
    public static function callsBot(?string $content, ?int $botId, ?string $botUsername = null): bool
    {
        $content = (string) $content;

        // ponytail: a mention inside a quoted post counts as a call. Quotes
        // are markup this class does not read; the bot's own posts are skipped
        // by the caller, so the worst case is one answer to a requoted call
        if ($botId && preg_match_all('~@"[^"\n]*"#(\d+)~u', $content, $matches)) {
            if (in_array((string) $botId, $matches[1], true)) {
                return true;
            }
        }

        if ($botUsername === null || $botUsername === '') {
            return false;
        }

        return (bool) preg_match('~(?<![\w.-])@' . preg_quote($botUsername, '~') . '(?![\w.-])~iu', $content);
    }
}
