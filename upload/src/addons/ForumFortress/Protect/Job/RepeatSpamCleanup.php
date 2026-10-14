<?php

namespace ForumFortress\Protect\Job;

use ForumFortress\Protect\Service\RepeatOffender as Evidence;
use XF\Entity\User;
use XF\Job\AbstractJob;

/** Runs the native, moderator-attributed XenForo Spam Cleaner after strict evidence revalidation. */
class RepeatSpamCleanup extends AbstractJob
{
    protected $defaultData = ['user_id' => 0];

    public function run($maxRunTime)
    {
        $uid = (int) $this->data['user_id'];
        if ($uid <= 1) return $this->complete();
        $db = $this->app->db();
        $now = time();
        $db->beginTransaction();
        try
        {
            $state = $db->fetchRow('SELECT * FROM xf_ff_protect_repeat_cleanup_state WHERE user_id = ? FOR UPDATE', $uid);
            $member = $db->fetchRow('SELECT * FROM xf_user WHERE user_id = ? FOR UPDATE', $uid);
            $count = (int) $db->fetchOne('SELECT COUNT(*) FROM xf_ff_protect_repeat_cleanup_event
                WHERE user_id = ? AND consumed = 0 AND observed_at > ?', [$uid, $now - Evidence::WINDOW]);
            if (!$state || !empty($state['last_cleanup_at']) || empty($this->app->options()->ffProtectEnabled)
                || empty($this->app->options()->ffProtectRepeatOffenderCleanup) || !is_array($member)
                || !Evidence::eligibleUser($member, $now, 5) || $count < 3)
            {
                $db->query('UPDATE xf_ff_protect_repeat_cleanup_event SET consumed = 1 WHERE user_id = ?', $uid);
                $db->commit();
                return $this->complete();
            }
            $target = $this->app->finder('XF:User')->where('user_id', $uid)->fetchOne();
            if (!$target || !$target->isPossibleSpammer())
            {
                $db->query('UPDATE xf_ff_protect_repeat_cleanup_event SET consumed = 1 WHERE user_id = ?', $uid);
                $db->commit();
                return $this->complete();
            }
            $db->commit();
        }
        catch (\Throwable $e)
        {
            $db->rollback();
            throw $e;
        }

        $moderators = $db->fetchAllColumn("SELECT user_id FROM xf_user
            WHERE user_state = 'valid' AND (is_moderator = 1 OR is_admin = 1)
            ORDER BY last_activity DESC, user_id LIMIT 100");
        foreach ($moderators as $moderatorId)
        {
            // Recheck the account after moderator lookup and immediately before
            // the native action; the evidence may have aged or permissions may
            // have changed since the producer queued this job.
            $db->beginTransaction();
            try
            {
                $state = $db->fetchRow('SELECT * FROM xf_ff_protect_repeat_cleanup_state WHERE user_id = ? FOR UPDATE', $uid);
                $member = $db->fetchRow('SELECT * FROM xf_user WHERE user_id = ? FOR UPDATE', $uid);
                $count = (int) $db->fetchOne('SELECT COUNT(*) FROM xf_ff_protect_repeat_cleanup_event
                    WHERE user_id = ? AND consumed = 0 AND observed_at > ?', [$uid, time() - Evidence::WINDOW]);
                if (!$state || !empty($state['last_cleanup_at']) || empty($this->app->options()->ffProtectEnabled)
                    || empty($this->app->options()->ffProtectRepeatOffenderCleanup) || !is_array($member)
                    || !Evidence::eligibleUser($member, time(), 5) || $count < 3)
                {
                    $db->query('UPDATE xf_ff_protect_repeat_cleanup_event SET consumed = 1 WHERE user_id = ?', $uid);
                    $db->commit();
                    return $this->complete();
                }
            }
            catch (\Throwable $e)
            {
                $db->rollback();
                throw $e;
            }
            try
            {
                $target = $this->app->finder('XF:User')->where('user_id', $uid)->fetchOne();
                if (!$target || !$target->isPossibleSpammer())
                {
                    $db->commit();
                    continue;
                }
                $moderator = $this->app->em()->find('XF:User', (int) $moderatorId);
                if (!$moderator instanceof User)
                {
                    $db->commit();
                    continue;
                }
                $ran = false;
                \XF::asVisitor($moderator, function () use ($target, &$ran, $db, $uid): void {
                    if (!\XF::visitor()->canCleanSpam() || !$target->isPossibleSpammer()) return;
                    $cleaner = new \ForumFortress\Protect\Service\RepeatSpamCleaner($this->app, $target);
                    if (!is_callable([$cleaner, 'cleanUp'])) return;
                    if ($cleaner->isRecentlyCleaned()) { $ran = true; return; }
                    // XenForo's native Post/ProfilePost handlers use this
                    // option to choose between hard delete and soft delete.
                    // Override it only for this request so cleanup always
                    // remains recoverable through the Spam Cleaner log.
                    $options = $this->app->options();
                    $savedMessageAction = $options->spamMessageAction;
                    try
                    {
                        $options->spamMessageAction = 'soft';
                        $priorLogCount = (int) $db->fetchOne(
                            'SELECT COUNT(*) FROM xf_spam_cleaner_log WHERE user_id = ?', $uid
                        );
                        $cleaner->cleanUp([
                            'action_threads' => 0,
                            'delete_messages' => 1,
                            'delete_conversations' => 0,
                            'ban_user' => 1,
                            'check_ips' => 0,
                            'no_redirect' => 1,
                        ]);
                        // The XenForo finalize API need not return a boolean.
                        // Validate persisted, recoverable native side effects
                        // while the qualification transaction is still open.
                        $cleaner->finalize();
                        $logged = (int) $db->fetchOne(
                            'SELECT COUNT(*) FROM xf_spam_cleaner_log WHERE user_id = ?', $uid
                        );
                        $banned = (int) $db->fetchOne(
                            'SELECT COUNT(*) FROM xf_user_ban WHERE user_id = ?', $uid
                        );
                        if ($logged <= $priorLogCount || !$banned)
                        {
                            throw new \RuntimeException('XenForo Spam Cleaner did not persist its ban and restore log');
                        }
                    }
                    finally
                    {
                        $options->spamMessageAction = $savedMessageAction;
                    }
                    $ran = true;
                });
                if ($ran)
                {
                    // Keep the qualification row locks through the native
                    // action. Its nested transaction and our completion receipt
                    // commit together, including the native restoration log.
                    $db->query('UPDATE xf_ff_protect_repeat_cleanup_state SET last_cleanup_at = ? WHERE user_id = ?', [$now, $uid]);
                    $db->query('UPDATE xf_ff_protect_repeat_cleanup_event SET consumed = 1 WHERE user_id = ?', $uid);
                    $db->commit();
                    return $this->complete();
                }
                $db->commit();
            }
            catch (\Throwable $e)
            {
                // A native handler can throw before Cleaner::finalize releases
                // its savepoint. Roll back the complete job transaction so a
                // partial ban/content change cannot survive without its log.
                $db->rollbackAll();
                $this->app->em()->clearEntityCache();
                \XF::logException($e, false, 'Forum Fortress native Spam Cleaner: ');
                throw $e;
            }
        }
        throw new \RuntimeException('No XenForo moderator with Spam Cleaner permission could run the cleanup');
    }

    public function getStatusMessage() { return (string) \XF::phrase('ff_repeat_cleanup_job'); }
    public function canCancel() { return true; }
    public function canTriggerByChoice() { return false; }
}
