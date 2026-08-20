<?php

namespace Wszdb\FlarumAiChat;

use Flarum\Discussion\Discussion;
use Flarum\User\Guest;
use Flarum\User\User;

/**
 * Who the assistant reads the forum as. Two readers, never the bot user:
 *
 *  - same-discussion history is read as the asker: everyone who can read the
 *    answer can already read that thread, and the threads the admin enabled
 *    keep their context;
 *  - anything quoted across discussions (linked, related, summaries, tools)
 *    is read as Guest — falling back to the asker when guests may not view
 *    the forum — and hidden, private or blocked-tag discussions are refused.
 */
class Readers
{
    public static function sameDiscussion(User $asker): User
    {
        return $asker;
    }

    public static function crossDiscussion(?User $asker = null): User
    {
        $guest = new Guest();

        if ($guest->can('viewForum')) {
            return $guest;
        }

        // never the bot user: without an asker there is nothing safer than a guest
        return $asker && !$asker->isGuest() ? $asker : $guest;
    }

    /**
     * A discussion that may be quoted into another thread, or null when the
     * reader may not see it, it is hidden, private, or carries a blocked tag.
     */
    public static function crossFetch(int $id, User $reader): ?Discussion
    {
        /** @var Discussion|null $discussion */
        $discussion = Discussion::whereVisibleTo($reader)
            ->whereNull('hidden_at')
            ->find($id);

        return $discussion && static::crossOk($discussion) ? $discussion : null;
    }

    /**
     * The policy half of crossFetch, pure so the self-check can reach it.
     */
    public static function crossOk(Discussion $discussion): bool
    {
        return !$discussion->is_private && !BlockedTags::block($discussion);
    }
}
