<?php

namespace ForumFortress\Protect\Service;

/** Native content cleanup and restoration with a reversible temporary ban. */
class RepeatSpamCleaner extends \XF\Spam\Cleaner
{
    public function banUser()
    {
        $this->forceTransaction();
        $error = null;
        $success = $this->app->repository('XF:Banning')->banUser(
            $this->user, time() + RepeatOffender::BAN_DURATION,
            (string) \XF::phrase('ff_repeat_cleanup_ban_reason'), $error
        );
        if (!$success)
        {
            throw new \RuntimeException('XenForo could not persist the repeat-spammer cleanup ban');
        }
        // Restorer::liftBan understands this native log entry. The stock
        // Cleaner::banUser permanently removes avatars/banners and submits
        // external spam data; those effects cannot participate in rollback.
        $this->log('user', 'banned');
    }
}
