<?php

namespace ForumFortress\Protect\Cron;

use ForumFortress\Protect\Service\RepeatOffender;

class RepeatEvidence
{
    public static function run($entry = null): void
    {
        // Expiry also runs while enforcement is disabled. State/audit rows are
        // retained (one per member); never remove a producer's locking row.
        $db = \XF::db();
        // Seven-day tombstones outlive the 24-hour counter window. After this
        // retention period the account itself is too old to qualify.
        $db->query('DELETE FROM xf_ff_protect_repeat_event WHERE observed_at <= ?', time() - RepeatOffender::BAN_DURATION);
        $db->query('DELETE FROM xf_ff_protect_repeat_cleanup_event WHERE observed_at <= ?', time() - RepeatOffender::BAN_DURATION);
    }
}
