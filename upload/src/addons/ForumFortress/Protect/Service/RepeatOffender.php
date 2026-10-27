<?php

namespace ForumFortress\Protect\Service;

use XF\App;
use XF\Entity\User;

/** Local evidence only; account mutations belong to the separate job. */
class RepeatOffender
{
    public const WINDOW = 86400;
    public const BAN_DURATION = 604800;

    protected App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public static function eligibleUser(array $user, int $now, int $maxPosts = 10): bool
    {
        return (int) ($user['user_id'] ?? 0) > 1
            && empty($user['is_admin']) && empty($user['is_moderator'])
            && empty($user['is_staff']) && empty($user['is_banned'])
            && ($user['user_state'] ?? '') === 'valid'
            && (int) ($user['register_date'] ?? 0) > $now - self::BAN_DURATION
            && (int) ($user['register_date'] ?? 0) <= $now
            && isset($user['message_count']) && (int) $user['message_count'] <= $maxPosts;
    }

    public function enabled(): bool
    {
        $options = $this->app->options();
        return !empty($options->ffProtectEnabled)
            && !empty($options->ffProtectRepeatOffenderBan)
            && empty($options->ffProtectRepeatOffenderCleanup);
    }

    public static function reference(?array $response): ?string
    {
        // The advisory describes the ORIGINAL enforced score (>80). Never
        // reconstruct eligibility from a later/review score or a policy block.
        if (!$response || ($response['escalation_eligible'] ?? null) !== true
            || DecisionMapper::toContentDecision($response) !== 'denied')
        {
            return null;
        }
        $reference = $response['decision_reference'] ?? null;
        if (is_string($reference) && trim($reference) !== '' && strlen($reference) <= 256)
        {
            return hash('sha256', 'reference:' . trim($reference));
        }
        $id = $response['decision_id'] ?? null;
        if ((is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id > 0)
        {
            return hash('sha256', 'decision:' . (int) $id);
        }
        // No fallback to a request/correlation ID: it can change on retries.
        return null;
    }

    public function record(User $user, int $actorId, ?array $response,
        string $contentType, string $message, int $contentId = 0): void
    {
        // Include option, cache and DB-adapter acquisition in the failure boundary.
        // Optional bookkeeping must never turn a denied submission into a 500.
        try
        {
            $options = $this->app->options();
            if (empty($options->ffProtectEnabled)
                || (empty($options->ffProtectRepeatOffenderBan) && empty($options->ffProtectRepeatOffenderCleanup)))
            {
                $this->note('disabled_by_administrator', (int) $user->user_id);
                return;
            }
            if (!empty($options->ffProtectRepeatOffenderCleanup))
            {
                $this->recordCleanup($user, $actorId, $response, $contentType, $message, $contentId);
                return;
            }
            $this->recordBan($user, $actorId, $response, $contentType, $message, $contentId);
        }
        catch (\Throwable $e)
        {
            $this->note('bookkeeping_failed', (int) $user->user_id);
            \XF::logException($e, false, 'Forum Fortress repeat-offender bookkeeping: ');
        }
    }

    /** Bounded local status for authenticated administrators; never stores submitted text. */
    public function note(string $reason, int $userId): void
    {
        try
        {
            $this->app->simpleCache()->setValue('ForumFortress/Protect', 'repeatOffenderStatus',
                ['reason' => $reason, 'user_id' => $userId, 'at' => time()]);
        }
        catch (\Throwable $e) { /* Diagnostics are optional too. */ }
    }

    public function diagnostics(): array
    {
        $options = $this->app->options();
        return [
            'mode' => empty($options->ffProtectEnabled) ? 'off'
                : (!empty($options->ffProtectRepeatOffenderCleanup) ? 'cleanup'
                : (!empty($options->ffProtectRepeatOffenderBan) ? 'ban' : 'off')),
            'last' => $this->app->simpleCache()->getValue('ForumFortress/Protect', 'repeatOffenderStatus'),
        ];
    }

    protected function recordBan(User $user, int $actorId, ?array $response,
        string $contentType, string $message, int $contentId): void
    {
        $uid = (int) $user->user_id;
        $reference = self::reference($response);
        if (!$this->enabled() || !$reference || $uid <= 1 || $uid !== $actorId)
        {
            $this->note($uid !== $actorId ? 'actor_mismatch' : 'certificate_or_reference_absent', $uid);
            return;
        }
        // Only content with an unambiguous author is supported. Registration,
        // contact forms and unknown third-party content types cannot count.
        $tables = [
            'post' => ['xf_post', 'post_id'],
            'thread' => ['xf_thread', 'thread_id'],
            'profile_post' => ['xf_profile_post', 'profile_post_id'],
            'profile_post_comment' => ['xf_profile_post_comment', 'profile_post_comment_id'],
        ];
        if (!isset($tables[$contentType]) && !in_array($contentType, ['user', 'user_signature'], true))
        {
            $this->note('unsupported_content', $uid);
            return;
        }
        $db = $this->app->db();
        $now = time();
        $transaction = false;
        try
        {
            if ($contentId > 0)
            {
                if (isset($tables[$contentType]))
                {
                    [$table, $key] = $tables[$contentType];
                    if ((int) $db->fetchOne("SELECT user_id FROM $table WHERE $key = ?", $contentId) !== $uid)
                    {
                        $this->note('actor_mismatch', $uid);
                        return;
                    }
                }
                elseif ($contentId !== $uid)
                {
                    $this->note('actor_mismatch', $uid);
                    return;
                }
            }
            // A second API call for the same submitted body is still ONE event,
            // even if it receives a different decision ID. Conservative body
            // dedup also covers thread/post checker aliases and repeated edits.
            $event = hash('sha256', $message);
            $db->beginTransaction();
            $transaction = true;
            $db->query('INSERT IGNORE INTO xf_ff_protect_repeat_state (user_id) VALUES (?)', $uid);
            $state = $db->fetchRow('SELECT * FROM xf_ff_protect_repeat_state WHERE user_id = ? FOR UPDATE', $uid);
            $member = $db->fetchRow('SELECT * FROM xf_user WHERE user_id = ?', $uid);
            if (!$state || !empty($state['last_ban_at']) || !$this->enabled()
                || !is_array($member) || !self::eligibleUser($member, $now))
            {
                $db->commit();
                $this->note(!empty($state['last_ban_at']) ? 'moderation_completed' : 'protected_or_established_account', $uid);
                return;
            }
            // Keep expired/consumed receipts while the account can qualify.
            // A replay must never refresh an old event's observation time.
            $db->query('UPDATE xf_ff_protect_repeat_event SET consumed = 1 WHERE user_id = ? AND observed_at <= ?',
                [$uid, $now - self::WINDOW]);
            $receipts = (int) $db->fetchOne('SELECT COUNT(*) FROM xf_ff_protect_repeat_event WHERE user_id = ?', $uid);
            if ($receipts >= 128)
            {
                // Conservative saturation: disable automation for this member
                // instead of evicting dedup records and accepting old replays.
                $db->commit();
                $this->note('evidence_capacity_reached', $uid);
                return;
            }
            $count = (int) $db->fetchOne('SELECT COUNT(*) FROM xf_ff_protect_repeat_event WHERE user_id = ? AND consumed = 0', $uid);
            $beforeCount = $count;
            if ($count < 2)
            {
                $db->query('INSERT IGNORE INTO xf_ff_protect_repeat_event
                    (user_id, event_hash, reference_hash, observed_at) VALUES (?, ?, ?, ?)',
                    [$uid, $event, $reference, $now]);
            }
            $count = (int) $db->fetchOne('SELECT COUNT(*) FROM xf_ff_protect_repeat_event WHERE user_id = ? AND consumed = 0', $uid);
            if ($count >= 2)
            {
                // Job queue and evidence share the XF DB transaction. A rollback
                // cannot strand a triggered counter without its moderation job.
                $this->enqueueUniqueJob('ffRepeatOffender' . $uid,
                    'ForumFortress\\Protect:RepeatOffender', $uid);
            }
            $db->commit();
            $this->note($count >= 2 ? 'native_ban_queued' : ($count === $beforeCount ? 'duplicate_decision_or_body' : 'evidence_recorded'), $uid);
        }
        catch (\Throwable $e)
        {
            // Optional enforcement must never change the ordinary BLOCK result.
            if ($transaction) $db->rollback();
            $this->note('bookkeeping_failed', $uid);
            \XF::logException($e, false, 'Forum Fortress repeat offender: ');
        }
    }

    /** Record the stricter, separate cleanup certificate without changing ban-only evidence. */
    private function recordCleanup(User $user, int $actorId, ?array $response,
        string $contentType, string $message, int $contentId): void
    {
        $options = $this->app->options();
        if (empty($options->ffProtectEnabled) || empty($options->ffProtectRepeatOffenderCleanup)
            || !$response || ($response['repeat_cleanup_eligible'] ?? null) !== true
            || DecisionMapper::toContentDecision($response) !== 'denied')
        {
            $this->note('certificate_or_reference_absent', (int) $user->user_id);
            return;
        }
        $reference = $response['decision_reference'] ?? null;
        if (is_string($reference) && trim($reference) !== '' && strlen($reference) <= 256)
        {
            $referenceHash = hash('sha256', 'cleanup-reference:' . trim($reference));
        }
        else
        {
            $id = $response['decision_id'] ?? null;
            if (!(is_int($id) || (is_string($id) && ctype_digit($id))) || (int) $id <= 0)
            { $this->note('certificate_or_reference_absent', (int) $user->user_id); return; }
            $referenceHash = hash('sha256', 'cleanup-decision:' . (int) $id);
        }
        $tables = ['post' => ['xf_post', 'post_id'], 'thread' => ['xf_thread', 'thread_id'],
            'profile_post' => ['xf_profile_post', 'profile_post_id'],
            'profile_post_comment' => ['xf_profile_post_comment', 'profile_post_comment_id']];
        if (!isset($tables[$contentType]) && !in_array($contentType, ['user', 'user_signature'], true))
        { $this->note('unsupported_content', (int) $user->user_id); return; }
        $uid = (int) $user->user_id;
        if ($uid <= 1 || $uid !== $actorId) { $this->note('actor_mismatch', $uid); return; }
        $db = $this->app->db();
        $transaction = false;
        try
        {
            if ($contentId > 0)
            {
                if (isset($tables[$contentType]))
                {
                    [$table, $key] = $tables[$contentType];
                    if ((int) $db->fetchOne("SELECT user_id FROM $table WHERE $key = ?", $contentId) !== $uid)
                    { $this->note('actor_mismatch', $uid); return; }
                }
                elseif ($contentId !== $uid) { $this->note('actor_mismatch', $uid); return; }
            }
            $now = time();
            $eventHash = hash('sha256', 'cleanup-body:' . $message);
            $db->beginTransaction(); $transaction = true;
            $db->query('INSERT IGNORE INTO xf_ff_protect_repeat_cleanup_state (user_id) VALUES (?)', $uid);
            $state = $db->fetchRow('SELECT * FROM xf_ff_protect_repeat_cleanup_state WHERE user_id = ? FOR UPDATE', $uid);
            $member = $db->fetchRow('SELECT * FROM xf_user WHERE user_id = ? FOR UPDATE', $uid);
            if (!$state || !empty($state['last_cleanup_at'])
                || !is_array($member) || !self::eligibleUser($member, $now, 5))
            {
                $db->commit();
                $this->note(!empty($state['last_cleanup_at']) ? 'moderation_completed' : 'protected_or_established_account', $uid);
                return;
            }
            $db->query('UPDATE xf_ff_protect_repeat_cleanup_event SET consumed = 1 WHERE user_id = ? AND observed_at <= ?',
                [$uid, $now - self::WINDOW]);
            $total = (int) $db->fetchOne('SELECT COUNT(*) FROM xf_ff_protect_repeat_cleanup_event WHERE user_id = ?', $uid);
            if ($total >= 128) { $db->commit(); $this->note('evidence_capacity_reached', $uid); return; }
            $db->query('INSERT IGNORE INTO xf_ff_protect_repeat_cleanup_event
                (user_id, event_hash, reference_hash, observed_at) VALUES (?, ?, ?, ?)',
                [$uid, $eventHash, $referenceHash, $now]);
            $count = (int) $db->fetchOne('SELECT COUNT(*) FROM xf_ff_protect_repeat_cleanup_event
                WHERE user_id = ? AND consumed = 0 AND observed_at > ?', [$uid, $now - self::WINDOW]);
            if ($count >= 3)
            {
                $this->enqueueUniqueJob('ffRepeatOffenderCleanup' . $uid,
                    'ForumFortress\\Protect:RepeatSpamCleanup', $uid);
            }
            $db->commit();
            $this->note($count >= 3 ? 'native_cleanup_queued' : 'evidence_recorded_or_duplicate', $uid);
        }
        catch (\Throwable $e)
        {
            if ($transaction) $db->rollback();
            $this->note('bookkeeping_failed', $uid);
            \XF::logException($e, false, 'Forum Fortress repeat-spammer cleanup evidence: ');
        }
    }

    /** XenForo may return null after swallowing a queue insert error; verify persistence in our transaction. */
    private function enqueueUniqueJob(string $uniqueId, string $jobClass, int $userId): void
    {
        $this->app->jobManager()->enqueueUnique($uniqueId, $jobClass, ['user_id' => $userId], false);
        if (!(int) $this->app->db()->fetchOne('SELECT COUNT(*) FROM xf_job WHERE unique_key = ?', $uniqueId))
        {
            throw new \RuntimeException('XenForo did not persist the repeat-offender job');
        }
    }
}
