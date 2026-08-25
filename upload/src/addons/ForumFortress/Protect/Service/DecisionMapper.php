<?php

namespace ForumFortress\Protect\Service;

class DecisionMapper
{
	public static function isAboveLimit(?array $response): bool
	{
		return is_array($response) && strtoupper((string) ($response['status_code'] ?? '')) === 'ABOVELIMIT';
	}

	public static function toUserDecision(?array $response, bool $failOpen = true): string
	{
		if (!self::hasDefinitiveDecision($response))
		{
			return self::toUnavailableDecision($failOpen);
		}

		switch (strtolower(trim((string) $response['decision'])))
		{
			case 'allow':
				return 'allowed';
			case 'block':
				return 'denied';
			default:
				return self::toUnavailableDecision($failOpen);
		}
	}

	public static function toContentDecision(?array $response, bool $failOpen = true): string
	{
		return self::toUserDecision($response, $failOpen);
	}

	/**
	 * API availability failures must never escape as an application error. Fail-open
	 * permits the request, while fail-closed safely queues it for moderation rather
	 * than irreversibly rejecting content the API did not evaluate.
	 */
	public static function toUnavailableDecision(bool $failOpen): string
	{
		return $failOpen ? 'allowed' : 'moderated';
	}

	public static function hasDefinitiveDecision(?array $response): bool
	{
		if (!$response || self::isAboveLimit($response))
		{
			return false;
		}

		$decision = strtolower(trim((string) ($response['decision'] ?? '')));

		return in_array($decision, ['allow', 'block'], true);
	}

	public static function shouldRecordDecision(?array $response, string $mappedDecision): bool
	{
		return $mappedDecision !== 'denied'
			&& self::hasDefinitiveDecision($response)
			&& (int) ($response['decision_id'] ?? 0) > 0;
	}

	public static function requiresAvailabilityRecovery(?array $response, string $mappedDecision): bool
	{
		return $mappedDecision === 'moderated' && !self::hasDefinitiveDecision($response);
	}
}
