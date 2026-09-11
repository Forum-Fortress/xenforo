<?php
/**
 * Copyright (c) 2026 Marscastle Ltd trading as Forum Fortress
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * Deterministic Forum Fortress API routing and retry helpers for XenForo.
 */
declare(strict_types=1);

final class FfApiResilience
{
	public const OFFLINE_TOKEN_PREFIX = 'ff_ob_';
	public const DEFAULT_API_REGION = 'global';
	public const GLOBAL_API_BASE_URL = 'https://api.ffapi.net';
	public const RUNTIME_CHECK_ENDPOINT_TIMEOUT_SECONDS = 1;
	public const RUNTIME_CHECK_TOTAL_BUDGET_SECONDS = 5;
	public const CONTACT_PAGE_MIN_TIMEOUT_SECONDS = 6;
	public const CONTACT_PAGE_MAX_TIMEOUT_SECONDS = 12;
	public const API_TIMEOUT_LOG_THROTTLE_SECONDS = 300;
	public const CONSECUTIVE_TRANSIENT_LOG_THRESHOLD = 3;
	public const ENDPOINT_CATALOG_TTL_SECONDS = 14400;

	private const API_REGION_BASE_URLS = [
		'global' => self::GLOBAL_API_BASE_URL,
		'uk' => 'https://api-uk.ffapi.net',
		'eu' => 'https://api-eu.ffapi.net',
		'us' => 'https://api-us.ffapi.net',
	];

	public static function normaliseApiRegion(?string $region): string
	{
		$value = strtolower(trim((string) $region));
		return array_key_exists($value, self::API_REGION_BASE_URLS) ? $value : self::DEFAULT_API_REGION;
	}

	public static function apiBaseUrlForRegion(?string $region): string
	{
		return self::API_REGION_BASE_URLS[self::normaliseApiRegion($region)];
	}

	public static function apiRegionFromLegacyBaseUrl(?string $baseUrl): string
	{
		$normalised = strtolower(self::normaliseBaseUrl((string) $baseUrl));
		foreach (self::API_REGION_BASE_URLS as $region => $url)
		{
			if ($normalised === strtolower($url))
			{
				return $region;
			}
		}

		return self::DEFAULT_API_REGION;
	}

	public static function apiRegionIsLocked(?string $region): bool
	{
		return self::normaliseApiRegion($region) !== self::DEFAULT_API_REGION;
	}

	/** @return list<string> */
	public static function regionLockedCheckBases(?string $region, bool $allowGlobalFallback): array
	{
		$region = self::normaliseApiRegion($region);
		$primary = self::apiBaseUrlForRegion($region);
		return self::uniqueOrderedBases(
			[$primary],
			$region !== self::DEFAULT_API_REGION && $allowGlobalFallback ? [self::GLOBAL_API_BASE_URL] : []
		);
	}

	public static function normaliseBaseUrl(string $value): string
	{
		$value = rtrim(trim($value), '/');
		$parts = parse_url($value);
		if (!is_array($parts)
			|| strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
			|| trim((string) ($parts['host'] ?? '')) === ''
			|| isset($parts['user'])
			|| isset($parts['pass'])
			|| isset($parts['query'])
			|| isset($parts['fragment']))
		{
			return '';
		}

		return $value;
	}

	public static function normaliseDomain(string $domain): string
	{
		$domain = strtolower(rtrim(trim($domain), '.'));
		return str_starts_with($domain, 'www.') ? substr($domain, 4) : $domain;
	}

	public static function isOfflineBootstrapKey(?string $apiKey, ?string $keyType = null): bool
	{
		if ($keyType === 'offline_bootstrap')
		{
			return true;
		}

		$apiKey = trim((string) $apiKey);
		return $apiKey !== '' && str_starts_with($apiKey, self::OFFLINE_TOKEN_PREFIX);
	}

	/** @param array<string, mixed> $bootstrapResponse @param array<string, mixed> $state */
	public static function applyOfflineBootstrapRouting(array $bootstrapResponse, array &$state, string $usedBase): void
	{
		$keyType = isset($bootstrapResponse['key_type']) ? (string) $bootstrapResponse['key_type'] : '';
		$apiKey = isset($bootstrapResponse['api_key']) ? (string) $bootstrapResponse['api_key'] : '';
		if ($apiKey === '' && $keyType === '')
		{
			return;
		}
		if (!self::isOfflineBootstrapKey($apiKey, $keyType !== '' ? $keyType : null))
		{
			unset(
				$state['offline_pinned'],
				$state['issuer_node_id'],
				$state['offline_preferred_endpoint'],
				$state['offline_rebootstrap_at'],
				$state['offline_canonical_domain'],
				$state['fallback_bootstrap_endpoints']
			);

			return;
		}

		$rebootstrapAfter = max(60, (int) ($bootstrapResponse['rebootstrap_after_seconds'] ?? 600));
		$jitter = random_int(0, min(120, (int) floor($rebootstrapAfter / 4)));
		$state['offline_pinned'] = true;
		$state['issuer_node_id'] = trim((string) ($bootstrapResponse['issuer_node_id'] ?? ''));
		$state['offline_preferred_endpoint'] = self::normaliseBaseUrl(
			(string) ($bootstrapResponse['preferred_endpoint'] ?? $usedBase)
		);
		$state['offline_canonical_domain'] = self::normaliseDomain(
			(string) ($bootstrapResponse['canonical_domain'] ?? '')
		);
		$state['offline_rebootstrap_at'] = time() + $rebootstrapAfter + $jitter;
		$fallbacks = is_array($bootstrapResponse['fallback_bootstrap_endpoints'] ?? null)
			? $bootstrapResponse['fallback_bootstrap_endpoints']
			: [];
		$state['fallback_bootstrap_endpoints'] = self::uniqueOrderedBases($fallbacks);
	}

	/** @param array<string, mixed> $state @return list<string> */
	public static function offlinePinnedCheckBases(array $state): array
	{
		if (empty($state['offline_pinned']))
		{
			return [];
		}
		$preferred = self::normaliseBaseUrl((string) ($state['offline_preferred_endpoint'] ?? ''));
		return $preferred !== '' ? [$preferred] : [];
	}

	/** @param array<string, mixed> $state @param list<string> $edgeBases @return list<string> */
	public static function offlineRebootstrapBases(array $state, string $controlBase, string $apiBase, array $edgeBases, string $manualBase): array
	{
		$fallbacks = is_array($state['fallback_bootstrap_endpoints'] ?? null)
			? $state['fallback_bootstrap_endpoints']
			: [];
		return self::uniqueOrderedBases(
			$fallbacks,
			self::bootstrapBasesOrdered($controlBase, $apiBase, $manualBase, $edgeBases)
		);
	}

	/** @param array<string, mixed> $state */
	public static function shouldRebootstrapOfflineNow(array $state): bool
	{
		$at = (int) ($state['offline_rebootstrap_at'] ?? 0);
		return !empty($state['offline_pinned']) && $at > 0 && time() >= $at;
	}

	/** @param array<string, mixed>|null $decodedBody */
	public static function isNodeMismatchResponse(?array $decodedBody): bool
	{
		if (!is_array($decodedBody))
		{
			return false;
		}
		$candidates = [];
		if (isset($decodedBody['error']) && is_scalar($decodedBody['error']))
		{
			$candidates[] = strtolower(trim((string) $decodedBody['error']));
		}
		$detail = $decodedBody['detail'] ?? null;
		if (is_array($detail) && isset($detail['error']) && is_scalar($detail['error']))
		{
			$candidates[] = strtolower(trim((string) $detail['error']));
		}

		return in_array('node_mismatch', $candidates, true);
	}

	public static function hotFailoverApiBaseUrl(string $manualBase, string $controlBase): string
	{
		return self::GLOBAL_API_BASE_URL;
	}

	/** @param list<list<string>> $lists @return list<string> */
	public static function uniqueOrderedBases(array ...$lists): array
	{
		$out = [];
		foreach ($lists as $list)
		{
			foreach ($list as $base)
			{
				$base = self::normaliseBaseUrl((string) $base);
				if ($base !== '' && !in_array($base, $out, true))
				{
					$out[] = $base;
				}
			}
		}

		return $out;
	}

	/** @param array<string, array<string, mixed>> $metadata @return array<string, mixed> */
	public static function endpointMetaForBase(array $metadata, string $base): array
	{
		$base = self::normaliseBaseUrl($base);
		$row = $base !== '' ? ($metadata[$base] ?? null) : null;
		return is_array($row) ? $row : [];
	}

	/** @param list<string> $bases @return list<string> */
	public static function orderCheckBasesControlLast(array $bases, string $controlBase): array
	{
		$controlBase = self::normaliseBaseUrl($controlBase);
		$ordered = self::uniqueOrderedBases($bases);
		$out = array_values(array_filter($ordered, static fn (string $base): bool => $base !== $controlBase));
		if ($controlBase !== '' && in_array($controlBase, $ordered, true))
		{
			$out[] = $controlBase;
		}
		return $out;
	}

	/** @param list<string> $edgeBases @return list<string> */
	public static function bootstrapBasesOrdered(string $controlBase, string $apiBase, string $manualBase, array $edgeBases): array
	{
		return self::uniqueOrderedBases([$controlBase, $apiBase], $edgeBases, [$manualBase]);
	}

	/** @param array<string, mixed> $state */
	public static function isEndpointCatalogStale(array $state, ?int $ttlSeconds = null): bool
	{
		$fetchedAt = (int) ($state['catalog_fetched_at'] ?? 0);
		$endpoints = is_array($state['endpoints'] ?? null) ? $state['endpoints'] : [];
		return $fetchedAt <= 0 || !$endpoints || (time() - $fetchedAt) >= max(1, $ttlSeconds ?? self::ENDPOINT_CATALOG_TTL_SECONDS);
	}

	public static function shouldFailoverOnEndpointStatus(int $status): bool
	{
		return in_array($status, [500, 502, 503, 504], true);
	}

	public static function shouldStopEndpointFailoverForStatus(int $status): bool
	{
		return $status >= 400 && $status < 500;
	}

	public static function isContactPageCheckPath(?string $requestPath): bool
	{
		return is_string($requestPath)
			&& ($requestPath === '/v1/check/contact_page' || str_starts_with($requestPath, '/v1/check/contact_page'));
	}

	public static function shouldUseContactPageRouting(?string $requestPath): bool
	{
		return self::isContactPageCheckPath($requestPath);
	}

	public static function contactPageCheckTimeoutSeconds(int $configuredTimeoutSeconds): int
	{
		return max(
			self::CONTACT_PAGE_MIN_TIMEOUT_SECONDS,
			min(self::CONTACT_PAGE_MAX_TIMEOUT_SECONDS, max(1, $configuredTimeoutSeconds) * 2)
		);
	}

	public static function isTransientNetworkMessage(?string $message): bool
	{
		if (!is_string($message) || $message === '')
		{
			return false;
		}
		$lower = strtolower($message);
		foreach (['curl error 28', 'timed out', 'timeout', 'curl error 6', 'curl error 7', 'could not resolve host', 'failed to connect', 'connection refused', 'network is unreachable'] as $needle)
		{
			if (str_contains($lower, $needle))
			{
				return true;
			}
		}

		return false;
	}

	/** @param array<string, mixed> $state */
	public static function shouldLogThrottledApiFailure(
		array &$state,
		string $throttleKey,
		int $intervalSeconds = self::API_TIMEOUT_LOG_THROTTLE_SECONDS,
		?int $now = null
	): bool {
		$now = $now ?? time();
		$key = 'log_throttle_' . preg_replace('/[^a-z0-9_]+/i', '_', strtolower($throttleKey));
		$last = (int) ($state[$key] ?? 0);
		if ($last > 0 && ($now - $last) < max(60, $intervalSeconds))
		{
			return false;
		}
		$state[$key] = $now;

		return true;
	}

	/** @param array<string, mixed> $state */
	public static function shouldLogConsecutiveTransientFailure(
		array &$state,
		string $failureKey,
		bool $failed,
		int $threshold = self::CONSECUTIVE_TRANSIENT_LOG_THRESHOLD
	): bool {
		$key = 'fail_streak_' . preg_replace('/[^a-z0-9_]+/i', '_', strtolower($failureKey));
		if (!$failed)
		{
			unset($state[$key]);
			return false;
		}
		$state[$key] = (int) ($state[$key] ?? 0) + 1;
		return $state[$key] >= max(1, $threshold)
			&& self::shouldLogThrottledApiFailure($state, $failureKey);
	}
}
