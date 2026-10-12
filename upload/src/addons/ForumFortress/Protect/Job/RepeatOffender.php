<?php

namespace ForumFortress\Protect\Job;

use ForumFortress\Protect\Service\RepeatOffender as Evidence;
use XF\Job\AbstractJob;

class RepeatOffender extends AbstractJob
{
    protected $defaultData = ['user_id' => 0];

    public function run($maxRunTime)
    {
        $uid = (int) $this->data['user_id'];
        if ($uid <= 1) return $this->complete();
        $db = $this->app->db();
        $now = time();
        $evidence = new Evidence($this->app);
        $db->beginTransaction();
        try
        {
            // Same lock order as the producer; serialize jobs and new evidence.
            $state = $db->fetchRow('SELECT * FROM xf_ff_protect_repeat_state WHERE user_id = ? FOR UPDATE', $uid);
            $member = $db->fetchRow('SELECT * FROM xf_user WHERE user_id = ? FOR UPDATE', $uid);
            $count = (int) $db->fetchOne('SELECT COUNT(*) FROM xf_ff_protect_repeat_event
                WHERE user_id = ? AND consumed = 0 AND observed_at > ?', [$uid, $now - Evidence::WINDOW]);
            if (!$state || !empty($state['last_ban_at']) || !$evidence->enabled() || !is_array($member)
                || !Evidence::eligibleUser($member, $now) || $count < 2)
            {
                $db->query('UPDATE xf_ff_protect_repeat_event SET consumed = 1 WHERE user_id = ?', $uid);
                $db->commit();
                return $this->complete();
            }
            $repo = $this->app->repository('XF:Banning');
            if (!is_callable([$repo, 'banUser']))
            {
                throw new \RuntimeException('XenForo native Banning::banUser is unavailable');
            }
            $user = $this->app->finder('XF:User')->where('user_id', $uid)->fetchOne();
            if (!$user || $user->is_admin || $user->is_moderator || $user->is_staff || $user->is_banned)
            {
                $db->commit();
                return $this->complete();
            }
            $error = null;
            $end = $now + Evidence::BAN_DURATION;
            if (!$repo->banUser($user, $end, (string) \XF::phrase('ff_repeat_offender_ban_reason'), $error))
            {
                throw new \RuntimeException('XenForo declined the Forum Fortress temporary ban');
            }
            // The native ban record provides reversal and reason; retain local
            // action time without storing message text or API credentials.
            $db->query('UPDATE xf_ff_protect_repeat_state SET last_ban_at = ?, ban_end = ? WHERE user_id = ?',
                [$now, $end, $uid]);
            $db->query('UPDATE xf_ff_protect_repeat_event SET consumed = 1 WHERE user_id = ?', $uid);
            $db->commit();
        }
        catch (\Throwable $e)
        {
            $db->rollback();
            throw $e; // Native job failure is visible; evidence is retained.
        }
        return $this->complete();
    }

    public function getStatusMessage() { return (string) \XF::phrase('ff_repeat_offender_job'); }
    public function canCancel() { return true; }
    public function canTriggerByChoice() { return false; }
}
