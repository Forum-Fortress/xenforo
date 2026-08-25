<?php

namespace ForumFortress\Protect\Service;

use XF\App;
use XF\Entity\ApprovalQueue;
use XF\Entity\User;
use XF\Repository\ApprovalQueueRepository;

use function in_array, method_exists, preg_match, rtrim, trim;

class ModerationBridge
{
	protected App $app;
	protected static int $lastSyncAt = 0;

	/** @var list<string> */
	protected array $supportedTypes = ['thread', 'post', 'profile_post', 'profile_post_comment', 'user'];

	public function __construct(App $app)
	{
		$this->app = $app;
	}

	public function collectQueueItems(): array
	{
		$repo = $this->app->repository(ApprovalQueueRepository::class);
		ApprovalQueueSpamTrigger::pruneOrphans();
		$output = [];
		$idsByType = [];
		$db = $this->app->db();
		$keys = $db->fetchAll(
			'SELECT content_type, content_id
			FROM xf_approval_queue
			WHERE content_type IN (' . $db->quote($this->supportedTypes) . ')'
		);
		foreach ($keys as $key)
		{
			$type = (string) ($key['content_type'] ?? '');
			$contentId = (int) ($key['content_id'] ?? 0);
			if ($type !== '' && $contentId > 0)
			{
				$idsByType[$type][] = $contentId;
			}
		}

		foreach ($idsByType as $type => $contentIds)
		{
			foreach (array_chunk($contentIds, 200) as $idBatch)
			{
				// The key list is captured first so queue cleanup cannot shift an
				// offset and silently omit later items from this complete snapshot.
				$items = $this->app->finder('XF:ApprovalQueue')
					->where('content_type', $type)
					->where('content_id', $idBatch)
					->fetch();
				$repo->addContentToUnapprovedItems($items);
				foreach ($items as $item)
				{
					if ($item->isInvalid())
					{
						continue;
					}

					$mapped = $this->mapQueueItem($item);
					if ($mapped)
					{
						$output[] = $mapped;
					}
				}
				$repo->cleanUpInvalidRecords($items);
			}
		}

		return $output;
	}

	public function executeActions(array $actions): array
	{
		$repo = $this->app->repository(ApprovalQueueRepository::class);
		$results = [];

		foreach ($actions as $action)
		{
			$actionId = (int) ($action['id'] ?? 0);
			$contentType = (string) ($action['remote_content_type'] ?? '');
			$contentId = (int) ($action['remote_content_id'] ?? 0);
			$requestedAction = (string) ($action['action'] ?? '');

			if (
				!$actionId
				|| !$contentId
				|| !in_array($contentType, $this->supportedTypes, true)
				|| !in_array($requestedAction, ['approve', 'reject', 'spam_clean'], true)
			)
			{
				$results[] = ['id' => $actionId, 'status' => 'failed', 'message' => 'Unsupported moderation action payload'];
				continue;
			}

			$handler = $repo->getApprovalQueueHandler($contentType);
			if (!$handler)
			{
				$results[] = ['id' => $actionId, 'status' => 'failed', 'message' => 'No approval queue handler available'];
				continue;
			}

			$db = null;
			try
			{
				$db = $this->app->db();
				$db->beginTransaction();
				$pending = $db->fetchOne(
					'SELECT 1 FROM xf_approval_queue WHERE content_type = ? AND content_id = ? FOR UPDATE',
					[$contentType, $contentId]
				);
				if (!$pending)
				{
					$db->commit();
					ApprovalQueueSpamTrigger::deleteByContent($contentType, $contentId);
					$results[] = ['id' => $actionId, 'status' => 'applied', 'message' => 'Queue item no longer pending'];
					continue;
				}

				$queue = $this->app->finder('XF:ApprovalQueue')
					->where('content_type', $contentType)
					->where('content_id', $contentId)
					->fetchOne();
				$content = $handler->getContent($contentId);
				if (!$queue || !$content)
				{
					if ($queue)
					{
						$queue->delete();
					}
					$db->commit();
					ApprovalQueueSpamTrigger::deleteByContent($contentType, $contentId);
					$results[] = ['id' => $actionId, 'status' => 'applied', 'message' => 'Queue item no longer pending'];
					continue;
				}

				$moderator = $this->performQueueActionAsModerator(
					$handler,
					$this->mapActionName($requestedAction, $contentType),
					$content,
					$contentType,
					$contentId
				);
				if ($repo->isContentAwaitingApproval($contentType, $contentId))
				{
					throw new \RuntimeException('XenForo did not resolve the approval queue row');
				}
				$db->commit();
				ApprovalQueueSpamTrigger::deleteByContent($contentType, $contentId);
				$results[] = [
					'id' => $actionId,
					'status' => 'applied',
					'message' => 'Action applied by XenForo moderator user ' . (int) $moderator->user_id,
				];
			}
			catch (\Throwable $e)
			{
				if ($db)
				{
					$db->rollback();
				}
				$results[] = ['id' => $actionId, 'status' => 'failed', 'message' => $e->getMessage()];
			}
		}

		return $results;
	}

	public function shouldRunSync(int $cooldownSeconds = 60): bool
	{
		return (time() - self::$lastSyncAt) >= $cooldownSeconds;
	}

	public function markSyncRun(): void
	{
		self::$lastSyncAt = time();
	}

	protected function mapQueueItem(ApprovalQueue $item): ?array
	{
		$content = $item->Content;
		if (!$content)
		{
			return null;
		}

		$title = null;
		$excerpt = null;
		$username = null;
		$remoteUserId = null;
		$contentDate = null;

		switch ((string) $item->content_type)
		{
			case 'thread':
				$title = trim((string) ($content->title ?? ''));
				$excerpt = trim((string) ($content->FirstPost->message ?? ''));
				$username = trim((string) ($content->username ?? ''));
				$remoteUserId = isset($content->user_id) ? (string) $content->user_id : null;
				$contentDate = isset($content->post_date) ? (int) $content->post_date : null;
				break;

			case 'post':
				$title = trim((string) ($content->Thread->title ?? 'Reply'));
				$excerpt = trim((string) ($content->message ?? ''));
				$username = trim((string) ($content->username ?? ''));
				$remoteUserId = isset($content->user_id) ? (string) $content->user_id : null;
				$contentDate = isset($content->post_date) ? (int) $content->post_date : null;
				break;

			case 'profile_post':
				$title = 'Profile post';
				$excerpt = trim((string) ($content->message ?? ''));
				$username = trim((string) ($content->username ?? ''));
				$remoteUserId = isset($content->user_id) ? (string) $content->user_id : null;
				$contentDate = isset($content->post_date) ? (int) $content->post_date : null;
				break;

			case 'profile_post_comment':
				$title = 'Profile post comment';
				$excerpt = trim((string) ($content->message ?? ''));
				$username = trim((string) ($content->username ?? ''));
				$remoteUserId = isset($content->user_id) ? (string) $content->user_id : null;
				$contentDate = isset($content->comment_date) ? (int) $content->comment_date : null;
				break;

			case 'user':
				$title = 'User registration';
				$excerpt = trim((string) ($content->email ?? ''));
				$username = trim((string) ($content->username ?? ''));
				$remoteUserId = (string) $content->user_id;
				$contentDate = isset($content->register_date) ? (int) $content->register_date : null;
				break;
		}

		$contentUrl = null;
		if (method_exists($content, 'getContentUrl'))
		{
			try
			{
				$contentUrl = $this->normaliseContentUrl((string) $content->getContentUrl());
			}
			catch (\Throwable $e)
			{
				$contentUrl = null;
			}
		}

		$availableActions = ['approve', 'reject'];
		if (!empty($content->User) && (string) $item->content_type !== 'user')
		{
			$availableActions[] = 'spam_clean';
		}

		$decisionId = null;
		$trigger = ApprovalQueueSpamTrigger::read($item);
		if (is_array($trigger) && isset($trigger['forum_fortress']['decision_id']) && is_numeric($trigger['forum_fortress']['decision_id']))
		{
			$candidate = (int) $trigger['forum_fortress']['decision_id'];
			if ($candidate > 0)
			{
				$decisionId = $candidate;
			}
		}

		$payload = ['content_type' => (string) $item->content_type];
		if ($decisionId !== null)
		{
			$payload['decision_id'] = $decisionId;
		}
		$ff = is_array($trigger) && isset($trigger['forum_fortress']) && is_array($trigger['forum_fortress'])
			? $trigger['forum_fortress']
			: [];
		$queueTag = trim((string) ($ff['challenge_tag'] ?? ''));
		if ($queueTag === TimeoutApprovalMirror::TIMEOUT_TAG)
		{
			$payload['source'] = 'ff_timeout';
			$payload['forumfortress_challenge_tag'] = TimeoutApprovalMirror::TIMEOUT_TAG;
			$endpoint = trim((string) ($ff['endpoint'] ?? ''));
			if ($endpoint !== '')
			{
				$payload['endpoint'] = $endpoint;
			}
			$unavailableReason = trim((string) ($ff['unavailable_reason'] ?? ''));
			if ($unavailableReason !== '')
			{
				$payload['unavailable_reason'] = $unavailableReason;
			}
			$checkPayload = $ff['check_payload'] ?? null;
			if (is_array($checkPayload))
			{
				$payload['check_payload'] = $checkPayload;
			}
			$publicReason = trim((string) ($ff['public_reason'] ?? ''));
			if ($publicReason !== '')
			{
				$payload['fortress_public_reason'] = $publicReason;
			}
		}
		return [
			'remote_content_type' => (string) $item->content_type,
			'remote_content_id' => (string) $item->content_id,
			'title' => $title ?: null,
			'excerpt' => $excerpt !== null ? mb_substr($excerpt, 0, 280) : null,
			'username' => $username ?: null,
			'remote_user_id' => $remoteUserId,
			'content_date' => $contentDate,
			'content_url' => $contentUrl ?: null,
			'available_actions' => $availableActions,
			'payload' => $payload,
		];
	}

	/**
	 * @param list<array<string, string>> $notes
	 */
	public function applyQueueNotes(array $notes): void
	{
		foreach ($notes as $note)
		{
			if (!is_array($note))
			{
				continue;
			}
			$type = (string) ($note['remote_content_type'] ?? '');
			$cidRaw = (string) ($note['remote_content_id'] ?? '');
			$reason = trim((string) ($note['fortress_public_reason'] ?? ''));
			if ($type === '' || $cidRaw === '' || $reason === '')
			{
				continue;
			}
			if (!in_array($type, $this->supportedTypes, true))
			{
				continue;
			}
			$cid = (int) $cidRaw;
			if ($cid <= 0)
			{
				continue;
			}
			$queue = $this->app->finder('XF:ApprovalQueue')
				->where('content_type', $type)
				->where('content_id', $cid)
				->fetchOne();
			if (!$queue)
			{
				continue;
			}
			if (mb_strlen($reason) > 2000)
			{
				$reason = mb_substr($reason, 0, 2000);
			}
			$challengeTag = trim((string) ($note['forumfortress_challenge_tag'] ?? ''));
			ApprovalQueueSpamTrigger::write($queue, static function (array &$data) use ($reason, $challengeTag): void {
				if (!isset($data['forum_fortress']) || !is_array($data['forum_fortress']))
				{
					$data['forum_fortress'] = [];
				}
				$data['forum_fortress']['public_reason'] = $reason;
				if ($challengeTag !== '')
				{
					$data['forum_fortress']['challenge_tag'] = $challengeTag;
				}
			});
		}
	}

	/**
	 * Approval handlers check moderator permissions against the current visitor; cron/API sync runs as guest.
	 */
	protected function performQueueActionAsModerator(
		$handler,
		string $action,
		$content,
		string $contentType,
		int $contentId
	): User {
		$moderator = $this->resolveSystemModerator($handler, $content, $action);
		if (!$moderator)
		{
			throw new \RuntimeException('No valid moderator account available to apply Forum Fortress queue actions');
		}

		$run = function () use ($handler, $action, $content, $contentType, $contentId): void {
			if ($action === 'spam_clean')
			{
				$spamUser = $this->getSpamCleanUser($content);
				if (!$spamUser || !\XF::visitor()->canCleanSpam() || !$spamUser->isPossibleSpammer())
				{
					throw new \RuntimeException('Spam clean is not permitted or the target is no longer eligible');
				}
				if ($this->app->spam()->cleaner($spamUser)->isRecentlyCleaned())
				{
					throw new \RuntimeException('Spam clean was already performed recently and the queue row remains pending');
				}
			}
			$handler->setInput($this->buildHandlerInput($contentType, $contentId));
			$handler->performAction($action, $content);
		};

		if ((int) \XF::visitor()->user_id === (int) $moderator->user_id)
		{
			$run();
			return $moderator;
		}

		\XF::asVisitor($moderator, $run);

		return $moderator;
	}

	protected function buildHandlerInput(string $contentType, int $contentId): array
	{
		return [
			'reason' => [
				$contentType => [
					(string) $contentId => '',
				],
			],
		];
	}

	protected function resolveSystemModerator($handler, $content, string $action): ?User
	{
		$userIds = $this->app->db()->fetchAllColumn(
			"SELECT user_id
			FROM xf_user
			WHERE user_state = 'valid' AND (is_moderator = 1 OR is_admin = 1)
			ORDER BY last_activity DESC, user_id
			LIMIT 100"
		);
		foreach ($userIds as $userId)
		{
			$candidate = $this->app->em()->find('XF:User', (int) $userId);
			try
			{
				if ($candidate instanceof User && $this->moderatorCanPerform($candidate, $handler, $content, $action))
				{
					return $candidate;
				}
			}
			catch (\Throwable $e)
			{
			}
		}

		return null;
	}

	protected function moderatorCanPerform(User $moderator, $handler, $content, string $action): bool
	{
		$permitted = false;
		$check = function () use (&$permitted, $handler, $content, $action): void {
			$error = null;
			if (!$handler->canView($content, $error))
			{
				return;
			}
			if ($action === 'spam_clean')
			{
				$spamUser = $this->getSpamCleanUser($content);
				if (!$spamUser || !\XF::visitor()->canCleanSpam() || !$spamUser->isPossibleSpammer())
				{
					return;
				}
			}
			$permitted = true;
		};

		if ((int) \XF::visitor()->user_id === (int) $moderator->user_id)
		{
			$check();
		}
		else
		{
			\XF::asVisitor($moderator, $check);
		}

		return $permitted;
	}

	protected function getSpamCleanUser($content): ?User
	{
		if ($content instanceof User)
		{
			return $content;
		}

		$user = $content->User ?? null;

		return $user instanceof User ? $user : null;
	}

	protected function mapActionName(string $action, string $contentType): string
	{
		switch ($action)
		{
			case 'reject':
				return $contentType === 'user' ? 'reject' : 'delete';
			case 'spam_clean':
				return 'spam_clean';
			default:
				return 'approve';
		}
	}

	protected function normaliseContentUrl(string $contentUrl): ?string
	{
		$contentUrl = trim($contentUrl);
		if ($contentUrl === '')
		{
			return null;
		}
		if (preg_match('#^https?://#i', $contentUrl))
		{
			return $contentUrl;
		}

		$boardUrl = rtrim((string) $this->app->options()->boardUrl, '/');
		if ($boardUrl === '')
		{
			return null;
		}

		return $boardUrl . '/' . ltrim($contentUrl, '/');
	}
}
