<?php

namespace ForumFortress\Protect\Service;

use XF\Entity\Post;
use XF\Entity\ProfilePost;
use XF\Entity\ProfilePostComment;
use XF\Entity\Thread;
use XF\Entity\User;

/**
 * Carries recoverable API availability failures into XenForo's approval queue.
 */
class TimeoutApprovalMirror
{
	public const TIMEOUT_TAG = 'FFTimeout';

	public function __construct(protected \XF\App $app)
	{
	}

	public function mirrorRegistrationUnavailable(
		User $user,
		array $checkPayload,
		string $endpoint = 'register',
		string $reason = 'api_unavailable'
	): void
	{
		$this->stampUnavailableOnQueue('user', (int) $user->user_id, $checkPayload, $endpoint, $reason);
	}

	public function mirrorContentUnavailable(
		array $extraParams,
		array $checkPayload,
		string $endpoint,
		string $reason = 'api_unavailable'
	): void
	{
		$content = $extraParams['content'] ?? null;
		if ($content instanceof Post)
		{
			$this->stampUnavailableOnQueue('post', (int) $content->post_id, $checkPayload, $endpoint, $reason);
			return;
		}
		if ($content instanceof Thread)
		{
			$this->stampUnavailableOnQueue('thread', (int) $content->thread_id, $checkPayload, $endpoint, $reason);
			return;
		}
		if ($content instanceof ProfilePost)
		{
			$this->stampUnavailableOnQueue('profile_post', (int) $content->profile_post_id, $checkPayload, $endpoint, $reason);
			return;
		}
		if ($content instanceof ProfilePostComment)
		{
			$this->stampUnavailableOnQueue('profile_post_comment', (int) $content->profile_post_comment_id, $checkPayload, $endpoint, $reason);
			return;
		}
		$type = (string) ($extraParams['content_type'] ?? 'post');
		$this->stampUnavailableOnQueue($type, (int) ($extraParams['content_id'] ?? 0), $checkPayload, $endpoint, $reason);
	}

	protected function stampUnavailableOnQueue(
		string $contentType,
		int $contentId,
		array $checkPayload,
		string $endpoint,
		string $reason
	): void
	{
		if ($contentId <= 0 || $contentType === '')
		{
			$this->deferUnavailableMetadata($contentType, $contentId, $checkPayload, $endpoint, $reason);
			return;
		}

		$queue = $this->app->finder('XF:ApprovalQueue')
			->where('content_type', $contentType)
			->where('content_id', $contentId)
			->fetchOne();
		if (!$queue)
		{
			$this->deferUnavailableMetadata($contentType, $contentId, $checkPayload, $endpoint, $reason);
			return;
		}

		$metadata = $this->buildUnavailableMetadata($checkPayload, $endpoint, $reason);
		ApprovalQueueSpamTrigger::write($queue, static function (array &$data) use ($metadata): void {
			if (!isset($data['forum_fortress']) || !is_array($data['forum_fortress']))
			{
				$data['forum_fortress'] = [];
			}
			$data['forum_fortress'] = array_replace($data['forum_fortress'], $metadata);
		});
	}

	protected function deferUnavailableMetadata(
		string $contentType,
		int $contentId,
		array $checkPayload,
		string $endpoint,
		string $reason
	): void
	{
		ApprovalQueueSpamTrigger::deferForQueueReference(
			$contentType,
			$contentId,
			$this->buildUnavailableMetadata($checkPayload, $endpoint, $reason)
		);
	}

	/** @return array<string, mixed> */
	protected function buildUnavailableMetadata(array $checkPayload, string $endpoint, string $reason): array
	{
		$endpointName = trim($endpoint) !== '' ? trim($endpoint) : 'register';
		$reasonName = trim($reason) !== '' ? trim($reason) : 'api_unavailable';
		$publicReason = $reasonName === 'timeout'
			? 'Forum Fortress check timed out; queued for automatic recovery on next sync (FFTimeout).'
			: 'Forum Fortress API was unavailable; queued for automatic recovery on next sync (FFTimeout).';

		return [
			'challenge_tag' => self::TIMEOUT_TAG,
			'endpoint' => $endpointName,
			'check_payload' => $checkPayload,
			'unavailable_reason' => $reasonName,
			'public_reason' => $publicReason,
		];
	}
}
