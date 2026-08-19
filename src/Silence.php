<?php

namespace Wszdb\FlarumAiChat;

use Flarum\Discussion\Discussion;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;

/**
 * Where the assistant must not answer, whoever asks. The listeners check this
 * before queueing, the jobs check it again when they run (a discussion can be
 * made private, or gain a blocked tag, while the answer waits) and the manual
 * trigger checks it too.
 */
class Silence
{
    /**
     * Why the assistant must stay out of this discussion, or out of answering
     * this author, or null when it may answer. The reason names a locale key: forum.post_controls.error_{reason}.
     */
    public static function reason(Discussion $discussion, ?User $user = null): ?string
    {
        $settings = resolve(SettingsRepositoryInterface::class);

        if ($discussion->is_private && !$settings->get('wszdb-flarumaichat.reply_in_private')) {
            return 'private';
        }

        if (BlockedTags::block($discussion)) {
            return 'blocked_tags';
        }

        // $user is the author whose post would be answered
        if (BlockedGroups::block($user)) {
            return 'blocked_groups';
        }

        return null;
    }
}
