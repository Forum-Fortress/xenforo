<?php

namespace ForumFortress\Protect\Service;

require_once __DIR__ . '/FfApiResilience.php';

use XF\App;
use XF\Entity\User;

use function array_merge, array_values, array_unique, array_map, array_filter, bin2hex, explode, in_array, is_array, json_encode, ksort, microtime, min, parse_url, preg_match, random_bytes, round, rtrim, strtolower, time, trim;

class ApiClient
{
	public const PLATFORM = 'xenforo';
	public const PLUGIN_VERSION = '1.9.1';
	public const CONTROL_PLANE_BASE_URL = 'https://api.ffapi.net';
	/** Add-on id string; must match {@see Setup::ADD_ON_ID} for simpleCache keys. */
	protected const ADDON_ID_FOR_CACHE = 'ForumFortress/Protect';
	protected const ENDPOINT_STATE_CACHE_KEY = 'endpointState';
	/** Minimum seconds between full hourly sync runs (cron + HTTP fallback share this gate). */
	protected const HOURLY_SYNC_MIN_INTERVAL = 540;
	protected const STANDARD_HEARTBEAT_INTERVAL_SECONDS = 3600;
	protected const PRO_HEARTBEAT_INTERVAL_SECONDS = 600;
	protected const CONNECTION_TEST_TIMEOUT_SECONDS = 2;
	protected const CONNECTION_TEST_TOTAL_BUDGET_SECONDS = 5;
	protected const PLAN_REFRESH_SECONDS = 86400;
	protected const MODERATION_SYNC_SECONDS = 600;
	protected const MODERATION_SYNC_TIMEOUT_SECONDS = 20;
	protected static bool $moderationSyncInProgress = false;
	protected ?string $authenticatedPortalUrl = null;

	/** Set when the active check() call exhausted retries due to HTTP timeout. */
	protected bool $lastCheckHadTimeout = false;

	protected App $app;

	public function __construct(App $app)
	{
		$this->app = $app;
	}

	public function isEnabled(): bool
	{
		return $this->getBoolOption('ffProtectEnabled', false);
	}

	public function check(string $endpoint, array $payload): ?array
	{
		if (!$this->isEnabled())
		{
			return null;
		}

		$this->lastCheckHadTimeout = false;
		$prepared = $this->withCheckRequestId($this->preparePayload($payload));
		try
		{
			if ($endpoint === 'register')
			{
				$timeout = max(1, $this->getIntOption('ffProtectTimeout', 3));
				$response = $this->requestWithRetry(
					'POST',
					'/v1/check/register',
					$prepared,
					true,
					$timeout,
					true
				);
			}
			else
			{
				$path = '/v1/check/' . $endpoint;
				if ($endpoint === 'contact_page')
				{
					$timeout = \FfApiResilience::contactPageCheckTimeoutSeconds(
						$this->getIntOption('ffProtectTimeout', 5)
					);
					$response = $this->requestWithRetry(
						'POST',
						$path,
						$prepared,
						true,
						$timeout,
						true
					);
				}
				else
				{
					$response = $this->requestWithRetry('POST', $path, $prepared, true, null, true);
				}
			}
		}
		catch (\Throwable $e)
		{
			// Spam checks are policy inputs. An upstream client/runtime failure must
			// be mapped by the provider, never become a XenForo 500 response.
			$this->log('error', 'Forum Fortress check failed before a decision was returned', [
				'endpoint' => $endpoint,
				'message' => $e->getMessage(),
			]);

			return null;
		}
		if (!$response)
		{
			return null;
		}

		$this->persistIdentityFromResponse($response);
		return $response;
	}

	public function report(string $endpoint, array $payload): ?array
	{
		if (!$this->isEnabled())
		{
			return null;
		}

		$prepared = $this->preparePayload($payload);
		$response = $this->request('POST', '/v1/report/' . $endpoint, $prepared);
		if ($response)
		{
			$this->persistIdentityFromResponse($response);
		}
		return $response;
	}

	public function capabilities(?int $timeoutOverride = null): ?array
	{
		if (!$this->isEnabled())
		{
			return null;
		}

		return $this->request('GET', '/v1/capabilities', [], $timeoutOverride);
	}

	public function siteStatus(?int $timeoutOverride = null): ?array
	{
		if (!$this->isEnabled())
		{
			return null;
		}

		$apiKey = trim($this->getStringOption('ffProtectApiKey'));
		if ($apiKey === '')
		{
			return null;
		}

		$status = $this->request('GET', '/v1/site/status', [
			'api_key' => $apiKey,
			'domain' => $this->getDomain(),
		], $timeoutOverride);
		if (is_array($status))
		{
			$this->persistIdentityFromResponse($status);
			$this->persistSiteStatus($status);
		}

		return $status;
	}

	public function cachedSiteStatus(): ?array
	{
		$state = $this->loadEndpointState();
		$status = $state['site_status'] ?? null;
		return is_array($status) ? $status : null;
	}

	public function forumStats(?int $timeoutOverride = null): ?array
	{
		if (!$this->isEnabled())
		{
			return null;
		}

		$apiKey = trim($this->getStringOption('ffProtectApiKey'));
		if ($apiKey === '')
		{
			return null;
		}

		return $this->request('GET', '/v1/forum/stats', [
			'api_key' => $apiKey,
			'domain' => $this->getDomain(),
		], $timeoutOverride);
	}

	public function pluginRelease(?int $timeoutOverride = null): ?array
	{
		if (!$this->isEnabled())
		{
			return null;
		}

		return $this->request('GET', '/v1/plugin-release', [
			'platform' => self::PLATFORM,
			'current_version' => self::PLUGIN_VERSION,
		], $timeoutOverride);
	}

	public function hourlySync(): void
	{
		if (!$this->isEnabled())
		{
			return;
		}

		$cache = $this->app->simpleCache();
		$lastAt = $cache->getValue(self::ADDON_ID_FOR_CACHE, 'hourlySyncLastAt');
		if ($lastAt !== null && is_numeric($lastAt) && (time() - (int) $lastAt) < self::HOURLY_SYNC_MIN_INTERVAL)
		{
			return;
		}
		$cache->setValue(self::ADDON_ID_FOR_CACHE, 'hourlySyncLastAt', time());

		try
		{
			$this->bootstrapIfNeeded();
		}
		catch (\Throwable $e)
		{
			$this->logBackgroundTaskFailure('automatic bootstrap failed', $e);
		}

		try
		{
			if ($this->shouldRunDailyTask('plugin_release_last_at'))
			{
				$this->pluginRelease();
				$this->markDailyTaskRun('plugin_release_last_at');
			}
		}
		catch (\Throwable $e)
		{
			$this->logBackgroundTaskFailure('plugin release check failed', $e);
		}

		if ($this->heartbeatIsDue())
		{
			$this->markHeartbeatAttempt();
			try
			{
				$sitePing = $this->sitePing();
				$this->noteBackgroundTaskResult('site ping failed', is_array($sitePing));
			}
			catch (\Throwable $e)
			{
				$this->logBackgroundTaskFailure('site ping failed', $e);
			}
		}

		try
		{
			$this->refreshPlanCacheIfStale(false);
		}
		catch (\Throwable $e)
		{
			$this->logBackgroundTaskFailure('plan refresh failed', $e);
		}

		try
		{
			$this->maybeMigrateFromOfflineBootstrap();
		}
		catch (\Throwable $e)
		{
			$this->logBackgroundTaskFailure('offline credential migration failed', $e);
		}

		$this->runModerationSyncCycle(true);
	}

	public function activateAttackMode(): ?array
	{
		if (!$this->isEnabled())
		{
			return null;
		}

		$siteId = trim($this->getStringOption('ffProtectSiteId'));
		$apiKey = trim($this->getStringOption('ffProtectApiKey'));
		if ($siteId === '' || $apiKey === '')
		{
			return null;
		}

		$response = $this->requestFromControlPlane('POST', '/v1/site/attack-mode', [
			'site_id' => $siteId,
			'api_key' => $apiKey,
			'domain' => $this->getDomain(),
		]);

		$response = $this->assertAttackModeResponse($response, true);
		$this->persistSiteStatus($response);

		return $response;
	}

	public function deactivateAttackMode(): ?array
	{
		if (!$this->isEnabled())
		{
			return null;
		}

		$siteId = trim($this->getStringOption('ffProtectSiteId'));
		$apiKey = trim($this->getStringOption('ffProtectApiKey'));
		if ($siteId === '' || $apiKey === '')
		{
			return null;
		}

		$response = $this->requestFromControlPlane('POST', '/v1/site/attack-mode/end', [
			'site_id' => $siteId,
			'api_key' => $apiKey,
			'domain' => $this->getDomain(),
		]);

		$response = $this->assertAttackModeResponse($response, false);
		$this->persistSiteStatus($response);

		return $response;
	}

	protected function assertAttackModeResponse(?array $response, bool $enabled): array
	{
		$actual = null;
		if (is_array($response) && array_key_exists('attack_mode_active', $response))
		{
			$actual = (bool) $response['attack_mode_active'];
		}
		elseif (is_array($response) && array_key_exists('enabled', $response))
		{
			$actual = (bool) $response['enabled'];
		}
		elseif (is_array($response) && is_array($response['attack_mode'] ?? null) && array_key_exists('enabled', $response['attack_mode']))
		{
			$actual = (bool) $response['attack_mode']['enabled'];
		}
		if (
			$actual === null
			|| $actual !== $enabled
		)
		{
			throw new \RuntimeException(
				$enabled
					? 'Forum Fortress did not confirm that attack mode is active.'
					: 'Forum Fortress did not confirm that attack mode has ended.'
			);
		}

		$response['attack_mode_active'] = $actual;
		return $response;
	}

	public function registerSite(string $email): ?array
	{
		if (!$this->isEnabled())
		{
			return null;
		}

		$siteId = trim($this->getStringOption('ffProtectSiteId'));
		if ($siteId === '')
		{
			$this->bootstrapIfNeeded();
			$siteId = trim($this->getStringOption('ffProtectSiteId'));
		}

		if ($siteId === '')
		{
			return null;
		}

		$apiKey = trim($this->getStringOption('ffProtectApiKey'));
		if ($apiKey === '')
		{
			return null;
		}

		$response = $this->request('POST', '/v1/site/register', [
			'domain' => $this->getDomain(),
			'email' => trim($email),
			'site_id' => $siteId,
			'api_key' => $apiKey,
		]);

		if ($response)
		{
			$this->persistIdentityFromResponse($response);
		}

		return $response;
	}

	public function portalLaunch(?int $timeoutOverride = null): ?array
	{
		if (!$this->isEnabled())
		{
			return null;
		}
		$this->authenticatedPortalUrl = null;

		$siteId = trim($this->getStringOption('ffProtectSiteId'));
		if ($siteId === '')
		{
			$this->bootstrapIfNeeded();
			$siteId = trim($this->getStringOption('ffProtectSiteId'));
		}

		$apiKey = trim($this->getStringOption('ffProtectApiKey'));
		if ($siteId === '' || $apiKey === '')
		{
			return null;
		}

		$response = $this->requestFromControlPlane('POST', '/v1/site/portal', [
			'api_key' => $apiKey,
			'site_id' => $siteId,
			'domain' => $this->getDomain(),
			'platform' => self::PLATFORM,
			'platform_version' => $this->getPlatformVersion(),
			'plugin_version' => self::PLUGIN_VERSION,
		], $timeoutOverride);
		$portalUrl = is_array($response) ? trim((string) ($response['portal_url'] ?? '')) : '';
		if ($this->isPortalUrlBearerShapeSafe($portalUrl))
		{
			$this->authenticatedPortalUrl = $portalUrl;
		}
		return $response;
	}

	public function getAuthenticatedPortalUrl(): ?string
	{
		return $this->authenticatedPortalUrl;
	}

	/** Validate bearer-token URL shape from /v1/site/portal without trusting host yet. */
	protected function isPortalUrlBearerShapeSafe(string $value): bool
	{
		if ($value === '' || filter_var($value, FILTER_VALIDATE_URL) === false)
		{
			return false;
		}
		$parts = parse_url($value);
		if (
			!is_array($parts)
			|| strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
			|| trim((string) ($parts['host'] ?? '')) === ''
			|| isset($parts['user'])
			|| isset($parts['pass'])
			|| isset($parts['fragment'])
		)
		{
			return false;
		}
		$path = '/' . ltrim((string) ($parts['path'] ?? ''), '/');
		if (rtrim($path, '/') !== '/access')
		{
			return false;
		}
		$query = [];
		parse_str((string) ($parts['query'] ?? ''), $query);
		return isset($query['token']) && is_string($query['token']) && trim($query['token']) !== '';
	}

	/**
	 * Lightweight check-in: refreshes forum row (domain, platform, XF + plugin versions, last_seen).
	 * Call from hourly sync and ACP connection tests so the control plane stays current.
	 */
	public function sitePing(?int $timeoutOverride = null): ?array
	{
		if (!$this->isEnabled())
		{
			return null;
		}

		$siteId = trim($this->getStringOption('ffProtectSiteId'));
		$apiKey = trim($this->getStringOption('ffProtectApiKey'));
		if ($siteId === '' || $apiKey === '')
		{
			return null;
		}

		$payload = $this->request('POST', '/v1/site/ping', [
			'api_key' => $apiKey,
			'site_id' => $siteId,
			'domain' => $this->getDomain(),
			'platform' => self::PLATFORM,
			'platform_version' => $this->getPlatformVersion(),
			'plugin_version' => self::PLUGIN_VERSION,
		], $timeoutOverride);
		if (is_array($payload))
		{
			$state = $this->loadEndpointState();
			$state['last_site_ping_at'] = time();
			$this->saveEndpointState($state);
		}
		return $payload;
	}

	protected function heartbeatIsDue(): bool
	{
		$state = $this->loadEndpointState();
		$lastAttempt = (int) ($state['heartbeat_last_attempt_at'] ?? 0);
		$plan = strtolower(trim((string) ($state['plan_name'] ?? ($state['site_status']['plan'] ?? ''))));
		$interval = $plan === 'pro'
			? self::PRO_HEARTBEAT_INTERVAL_SECONDS
			: self::STANDARD_HEARTBEAT_INTERVAL_SECONDS;

		return $lastAttempt <= 0 || (time() - $lastAttempt) >= $interval;
	}

	protected function markHeartbeatAttempt(): void
	{
		$state = $this->loadEndpointState();
		$state['heartbeat_last_attempt_at'] = time();
		$this->saveEndpointState($state);
	}

	public function runModerationSyncCycle(bool $force = false): void
	{
		if (!$this->isEnabled() || self::$moderationSyncInProgress)
		{
			return;
		}

		$siteId = trim($this->getStringOption('ffProtectSiteId'));
		$apiKey = trim($this->getStringOption('ffProtectApiKey'));
		if ($siteId === '' || $apiKey === '')
		{
			return;
		}

		$state = $this->loadEndpointState();
		$lastSyncAt = (int) ($state['moderation_last_sync_at'] ?? 0);
		$intervalSeconds = $this->getModerationSyncIntervalSeconds($state);
		if (!$force && (time() - $lastSyncAt) < $intervalSeconds)
		{
			return;
		}

		$bridge = new ModerationBridge($this->app);
		self::$moderationSyncInProgress = true;

		try
		{
			$syncPayload = $this->requestModeration('POST', '/v1/moderation-queue/sync', [
				'api_key' => $apiKey,
				'site_id' => $siteId,
				'domain' => $this->getDomain(),
				'platform' => self::PLATFORM,
				'platform_version' => $this->getPlatformVersion(),
				'plugin_version' => self::PLUGIN_VERSION,
				'block_reject_action' => $this->getBlockRejectAction(),
				'snapshot_complete' => true,
				'items' => $bridge->collectQueueItems(),
			]);
			if (is_array($syncPayload) && !empty($syncPayload['queue_notes']) && is_array($syncPayload['queue_notes']))
			{
				$bridge->applyQueueNotes($syncPayload['queue_notes']);
			}

			$pendingRemaining = 0;
			$syncCompleted = true;
			for ($pass = 0; $pass < 8; $pass++)
			{
				$actionsPayload = $this->requestModeration('POST', '/v1/moderation-actions/pull', [
					'api_key' => $apiKey,
					'site_id' => $siteId,
					'domain' => $this->getDomain(),
					'platform' => self::PLATFORM,
					'platform_version' => $this->getPlatformVersion(),
					'plugin_version' => self::PLUGIN_VERSION,
					'limit' => 25,
				]);
				if (!is_array($actionsPayload))
				{
					$syncCompleted = false;
					break;
				}
				$actions = is_array($actionsPayload['actions'] ?? null) ? $actionsPayload['actions'] : [];
				$pendingRemaining = (int) ($actionsPayload['pending_actions'] ?? 0);
				if (!$actions)
				{
					break;
				}
				$results = $bridge->executeActions($actions);
				$ackPayload = $this->requestModeration('POST', '/v1/moderation-actions/ack', [
					'api_key' => $apiKey,
					'site_id' => $siteId,
					'domain' => $this->getDomain(),
					'platform' => self::PLATFORM,
					'platform_version' => $this->getPlatformVersion(),
					'plugin_version' => self::PLUGIN_VERSION,
					'results' => $results,
				]);
				if (!is_array($ackPayload))
				{
					$syncCompleted = false;
					break;
				}
			}
			if (is_array($syncPayload))
			{
				$pendingRemaining = max($pendingRemaining, (int) ($syncPayload['pending_actions'] ?? 0));
			}
			$state['moderation_pending_actions'] = max(0, $pendingRemaining);
			if ($syncCompleted)
			{
				$state['moderation_last_sync_at'] = time();
				\FfApiResilience::shouldLogConsecutiveTransientFailure($state, 'bg:moderation sync failed', false);
			}
			$this->saveEndpointState($state);
		}
		catch (\Throwable $e)
		{
			$this->logBackgroundTaskFailure('moderation sync failed', $e);
		}
		finally
		{
			self::$moderationSyncInProgress = false;
		}
	}

	protected function withCheckRequestId(array $payload): array
	{
		if (!isset($payload['check_request_id']) || trim((string) $payload['check_request_id']) === '')
		{
			$payload['check_request_id'] = bin2hex(random_bytes(16));
		}

		return $payload;
	}

	public function preparePayload(array $payload): array
	{
		$domain = $payload['domain'] ?? $this->getDomain();
		$apiKey = trim($this->getStringOption('ffProtectApiKey'));

		$defaults = [
			'domain' => $domain,
			'platform' => self::PLATFORM,
			'platform_version' => $this->getPlatformVersion(),
			'plugin_version' => self::PLUGIN_VERSION,
		];

		if ($apiKey !== '')
		{
			$defaults['api_key'] = $apiKey;
		}

		return array_merge($defaults, $payload);
	}

	public function bootstrapIfNeeded(?int $timeoutOverride = null): ?array
	{
		if (!$this->isEnabled())
		{
			return null;
		}

		if (trim($this->getStringOption('ffProtectApiKey')) !== '')
		{
			if (trim($this->getStringOption('ffProtectSiteId')) === '')
			{
				return $this->siteStatus(self::CONNECTION_TEST_TIMEOUT_SECONDS);
			}
			return null;
		}

		$timeout = max(1, $timeoutOverride ?? $this->getIntOption('ffProtectTimeout', 3));
		$payload = [
			'domain' => $this->getBootstrapDomain(),
			'platform' => self::PLATFORM,
			'platform_version' => $this->getPlatformVersion(),
			'plugin_version' => self::PLUGIN_VERSION,
			'api_key' => null,
		];

		$response = null;
		$usedBase = '';
		$bootstrapResult = $this->tryBootstrapAcrossBases($payload, $timeout);
		if ($bootstrapResult)
		{
			$response = $bootstrapResult['data'];
			$usedBase = $bootstrapResult['base'];
		}
		if ($response)
		{
			$this->persistIdentityFromResponse($response, $usedBase);
			$state = $this->loadEndpointState();
			$state['last_responded'] = $usedBase;
			$state['last_responded_node'] = '';
			$state['last_response_at'] = time();
			$this->saveEndpointState($state);
		}

		return $response;
	}

	/**
	 * Re-bootstrap an existing installation after the portal opens its
	 * re-registration window. The existing key authenticates the request; the
	 * control plane may return a replacement identity/key for the same forum.
	 */
	public function rebootstrapForRegistration(?int $timeoutOverride = null): ?array
	{
		if (!$this->isEnabled())
		{
			return null;
		}

		$apiKey = trim($this->getStringOption('ffProtectApiKey'));
		if ($apiKey === '')
		{
			return $this->bootstrapIfNeeded($timeoutOverride);
		}

		$timeout = max(1, $timeoutOverride ?? $this->getIntOption('ffProtectTimeout', 3));
		$payload = [
			'domain' => $this->getBootstrapDomain(),
			'platform' => self::PLATFORM,
			'platform_version' => $this->getPlatformVersion(),
			'plugin_version' => self::PLUGIN_VERSION,
			'api_key' => $apiKey,
		];
		$result = $this->tryBootstrapAcrossBases($payload, $timeout);
		if (!$result)
		{
			return null;
		}

		$this->persistIdentityFromResponse($result['data'], $result['base']);
		return $result['data'];
	}

	/**
	 * @return array{data: array, base: string}|null
	 */
	protected function tryBootstrapAcrossBases(array $payload, int $timeout): ?array
	{
		$bases = $this->bootstrapBasesOrdered();
		foreach ($bases as $base)
		{
			$raw = $this->rawRequest('POST', $base, '/v1/site/bootstrap', $payload, $timeout);
			$status = (int) ($raw['status'] ?? 0);
			$data = is_array($raw['data'] ?? null) ? $raw['data'] : null;
			if ($status >= 200 && $status < 300 && is_array($data) && !empty($data['api_key']))
			{
				return [
					'data' => $data,
					'base' => $this->normaliseBaseUrl($base),
				];
			}
		}

		return null;
	}

	public function buildUserPayload(User $user, array $extra = []): array
	{
		$email = trim((string) $user->email);
		$emailDomain = $email && strpos($email, '@') !== false ? strtolower((string) substr(strrchr($email, '@'), 1)) : null;

		$payload = [
			'ip' => $this->app->request()->getIp(),
			'username' => (string) $user->username,
			'email' => $email ?: null,
			'account_age_seconds' => $user->register_date ? max(0, time() - (int) $user->register_date) : 0,
			'post_count' => (int) $user->message_count,
			'user_agent' => $this->app->request()->getUserAgent(),
		];

		if ($emailDomain)
		{
			$payload['email_domain'] = $emailDomain;
		}

		return array_merge($payload, $extra);
	}

	public function getDomain(): string
	{
		$boardUrl = trim((string) $this->app->options()->boardUrl);
		if ($boardUrl !== '')
		{
			$host = (string) parse_url($boardUrl, PHP_URL_HOST);
			if ($host !== '')
			{
				return \FfApiResilience::normaliseDomain($host);
			}
		}

		return \FfApiResilience::normaliseDomain((string) $this->app->request()->getServer('HTTP_HOST'));
	}

	protected function getBootstrapDomain(): string
	{
		$state = $this->loadEndpointState();
		$canonical = trim((string) ($state['offline_canonical_domain'] ?? ''));

		return $canonical !== '' ? $canonical : $this->getDomain();
	}

	protected function isOfflineApiKey(): bool
	{
		return \FfApiResilience::isOfflineBootstrapKey(
			$this->getStringOption('ffProtectApiKey'),
			null
		);
	}

	public function getPlatformVersion(): string
	{
		return (string) \XF::$version;
	}

	protected function normaliseBaseUrl(string $value): string
	{
		$value = \FfApiResilience::normaliseBaseUrl($value);
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

	protected function getManualBaseUrl(): string
	{
		return \FfApiResilience::apiBaseUrlForRegion($this->getApiRegion());
	}

	protected function getApiRegion(): string
	{
		$stored = $this->getStringOption('ffProtectApiRegion');
		return \FfApiResilience::normaliseApiRegion($stored !== '' ? $stored : \FfApiResilience::apiRegionFromLegacyBaseUrl($this->getStringOption('ffProtectApiBaseUrl')));
	}

	protected function allowGlobalEmergencyFallback(): bool
	{
		return $this->getBoolOption('ffProtectAllowGlobalFallback', false);
	}

	protected function getControlPlaneBaseUrl(): string
	{
		return self::CONTROL_PLANE_BASE_URL;
	}

	protected function getHotFailoverApiBaseUrl(): string
	{
		return \FfApiResilience::hotFailoverApiBaseUrl(
			$this->getManualBaseUrl(),
			$this->getControlPlaneBaseUrl()
		);
	}

	/** @return list<string> */
	protected function bootstrapBasesOrdered(): array
	{
		return $this->lookupBasesOrdered();
	}

	/** @return list<string> */
	protected function lookupBasesOrdered(): array
	{
		$primary = $this->getManualBaseUrl();
		$global = \FfApiResilience::GLOBAL_API_BASE_URL;
		$control = $this->getControlPlaneBaseUrl();

		if ($primary === '')
		{
			return [];
		}
		if (!\FfApiResilience::apiRegionIsLocked($this->getApiRegion()))
		{
			return \FfApiResilience::uniqueOrderedBases([$global, $control]);
		}
		if (!$this->allowGlobalEmergencyFallback())
		{
			return [$primary];
		}

		return \FfApiResilience::uniqueOrderedBases([$primary, $global, $control]);
	}

	/**
 * Lifecycle actions use the public API route, which owns central failover.
	 */
	protected function requestFromControlPlane(string $method, string $path, array $payload, ?int $timeoutOverride = null): ?array
	{
		$timeout = max(1, $timeoutOverride ?? $this->getIntOption('ffProtectTimeout', 3));
		$controlPlaneOnly = $this->isControlPlaneOnlyPath($path);
		$bases = $this->controlPlaneActionBases();
		if (!$bases)
		{
			$manual = $this->getManualBaseUrl();
			if ($manual !== '')
			{
				$bases = [$manual];
			}
		}
		if (!$bases)
		{
			return null;
		}
		$lastHttpError = null;
		foreach ($bases as $base)
		{
			$raw = $this->rawRequest($method, $base, $path, $payload, $timeout);
			$status = (int) ($raw['status'] ?? 0);
			if ($status < 200 || $status >= 300)
			{
				if ($status >= 400 && $status < 500)
				{
					$data = is_array($raw['data'] ?? null) ? $raw['data'] : [];
					$detail = $data['message'] ?? $data['detail'] ?? $data['error'] ?? null;
					$lastHttpError = is_scalar($detail) ? (string) $detail : ('HTTP ' . $status);
					// A stale control-plane deployment can briefly lack a newly
					// added action route. Let the configured hot failover answer
					// this specific case; authentication and validation errors must
					// still stop immediately.
					if ($controlPlaneOnly && $status !== 404)
					{
						break;
					}
				}
				continue;
			}
			$data = $raw['data'] ?? null;
			if (!is_array($data))
			{
				$body = (string) ($raw['body'] ?? '');
				$decoded = json_decode($body, true);
				$data = is_array($decoded) ? $decoded : null;
			}
			if (is_array($data))
			{
				return $data;
			}
		}
		if ($lastHttpError !== null)
		{
			throw new \RuntimeException('Forum Fortress API request failed: ' . $lastHttpError);
		}

		return null;
	}

	/** @return list<string> */
	protected function controlPlaneActionBases(): array
	{
		return array_values(array_unique(array_filter([
			$this->getControlPlaneBaseUrl(),
			$this->getHotFailoverApiBaseUrl(),
		], function ($base) {
			return $this->normaliseBaseUrl((string) $base) !== '';
		})));
	}

	protected function isControlPlaneOnlyPath(string $path): bool
	{
		return in_array($path, [
			'/v1/site/portal',
			'/v1/site/attack-mode',
			'/v1/site/attack-mode/end',
		], true);
	}

	/** @return array<string, mixed> */
	protected function loadEndpointState(): array
	{
		$cache = $this->app->simpleCache();
		$data = $cache->getValue(self::ADDON_ID_FOR_CACHE, self::ENDPOINT_STATE_CACHE_KEY);
		if (is_array($data))
		{
			return $data;
		}

		$migrationRaw = trim($this->getStringOption('ffProtectEndpointState'));
		$migratedState = $migrationRaw !== '' ? json_decode($migrationRaw, true) : null;
		$data = is_array($migratedState) ? $migratedState : [];
		$cache->setValue(self::ADDON_ID_FOR_CACHE, self::ENDPOINT_STATE_CACHE_KEY, $data);

		return $data;
	}

	/** @param array<string, mixed> $state */
	protected function saveEndpointState(array $state): void
	{
		ksort($state);
		$current = $this->app->simpleCache()->getValue(self::ADDON_ID_FOR_CACHE, self::ENDPOINT_STATE_CACHE_KEY);
		if (is_array($current))
		{
			ksort($current);
			if ($current === $state)
			{
				return;
			}
		}
		$this->app->simpleCache()->setValue(self::ADDON_ID_FOR_CACHE, self::ENDPOINT_STATE_CACHE_KEY, $state);
	}

	/** @param array<string, mixed> $status */
	protected function persistSiteStatus(array $status): void
	{
		$clean = [];
		foreach (['plan', 'registration_required', 'attack_mode_active', 'attack_mode_allowed', 'dataset_version'] as $key)
		{
			if (array_key_exists($key, $status))
			{
				$clean[$key] = $status[$key];
			}
		}
		foreach (['attack_mode', 'capabilities', 'plan_enforcement'] as $key)
		{
			if (isset($status[$key]) && is_array($status[$key]))
			{
				$clean[$key] = $status[$key];
			}
		}
		if (!$clean)
		{
			return;
		}
		$state = $this->loadEndpointState();
		$existing = is_array($state['site_status'] ?? null) ? $state['site_status'] : [];
		$state['site_status'] = array_merge($existing, $clean);
		$state['site_status_checked_at'] = time();
		if (!empty($clean['plan']))
		{
			$state['plan_name'] = strtolower(trim((string) $clean['plan']));
		}
		$this->saveEndpointState($state);
	}

	/** @return array<string, mixed> */
	public function endpointStateSnapshot(): array
	{
		$state = $this->loadEndpointState();
		$endpoints = $this->lookupBasesOrdered();
		$primary = $endpoints[0] ?? '';

		return [
			'endpoints' => $endpoints,
			'last_responded' => $this->normaliseBaseUrl((string) ($state['last_responded'] ?? '')),
			'last_responded_node' => trim((string) ($state['last_responded_node'] ?? '')),
			'last_response_at' => (int) ($state['last_response_at'] ?? 0),
			'last_site_ping_at' => (int) ($state['last_site_ping_at'] ?? 0),
			'last_failure' => is_array($state['last_failure'] ?? null) ? $state['last_failure'] : null,
			'preferred' => $primary,
		];
	}

	/** @return array{preferred: string, last_responded: string, endpoints_count: int} */
	public function endpointStateSummary(): array
	{
		$state = $this->endpointStateSnapshot();
		$preferred = (string) ($state['preferred'] ?? '');
		$lastRespondedNode = trim((string) ($state['last_responded_node'] ?? ''));
		$lastRespondedBase = $this->normaliseBaseUrl((string) ($state['last_responded'] ?? ''));
		$lastResponded = $lastRespondedNode !== '' ? $lastRespondedNode : $lastRespondedBase;
		$endpointsCount = is_array($state['endpoints'] ?? null) ? count($state['endpoints']) : 0;
		return [
			'preferred' => $preferred,
			'last_responded' => $lastResponded,
			'endpoints_count' => $endpointsCount,
		];
	}

	public function endpointHealthDisplayLabel(string $endpointUrl, ?array $state = null): string
	{
		$endpointUrl = $this->normaliseBaseUrl($endpointUrl);
		$ordered = $this->lookupBasesOrdered();
		if ($endpointUrl !== '' && $endpointUrl === ($ordered[0] ?? ''))
		{
			return 'Configured primary';
		}
		if ($endpointUrl === $this->getControlPlaneBaseUrl())
		{
			return 'Fortress fallback';
		}

		return 'global fallback';
	}
	/**
	 * @return list<array{endpoint: string, latency: string, is_preferred: bool}>
	 */
	public function buildEndpointLatencyRows(): array
	{
		$targets = $this->lookupBasesOrdered();
		$primary = $targets[0] ?? '';
		$rows = [];
		foreach ($targets as $endpointUrl)
		{
			$rows[] = [
				'endpoint' => $endpointUrl,
				'latency' => $this->endpointHealthDisplayLabel($endpointUrl),
				'is_preferred' => $endpointUrl === $primary,
			];
		}
		return $rows;
	}

	/** @return list<string> */
	protected function getOrderedBasesForRequests(?string $requestPath = null): array
	{
		return $this->lookupBasesOrdered();
	}

	/**
	 * @return array{status: int, body: string, data: ?array, error: ?string}
	 */
	protected function rawRequest(
		string $method,
		string $baseUrl,
		string $path,
		array $payload,
		int $timeout
	): array {
		$baseUrl = $this->normaliseBaseUrl($baseUrl);
		$out = [
			'status' => 0,
			'body' => '',
			'data' => null,
			'error' => null,
		];
		if ($baseUrl === '')
		{
			$out['error'] = 'empty_base';
			return $out;
		}
		try
		{
			$client = $this->app->http()->client();
			$options = [
				'timeout' => $timeout,
				'connect_timeout' => min(1, $timeout),
				'http_errors' => false,
				'headers' => [
					'Accept' => 'application/json',
				],
			];
			if (strtoupper($method) === 'GET')
			{
				$query = $payload;
				$this->moveQueryApiKeyToHeader($query, $options['headers']);
				$options['query'] = $query;
			}
			else
			{
				$options['json'] = $payload;
				$options['headers']['Content-Type'] = 'application/json';
			}
			$response = $client->request($method, $baseUrl . $path, $options);
			$out['status'] = (int) $response->getStatusCode();
			$out['body'] = (string) $response->getBody();
			if ($out['body'] !== '')
			{
				$decoded = json_decode($out['body'], true);
				$out['data'] = is_array($decoded) ? $decoded : null;
			}
		}
		catch (\Throwable $e)
		{
			$out['error'] = $this->redactSensitiveText($e->getMessage());
		}
		return $out;
	}

	/**
	 * GET credentials belong in headers, not URLs where access logs, proxies,
	 * browser history, and transport exception messages can retain them.
	 *
	 * @param array<string, mixed> $query
	 * @param array<string, string> $headers
	 */
	protected function moveQueryApiKeyToHeader(array &$query, array &$headers): void
	{
		if (!array_key_exists('api_key', $query))
		{
			return;
		}

		$apiKey = trim((string) $query['api_key']);
		unset($query['api_key']);
		if ($apiKey !== '')
		{
			$headers['X-FF-Key'] = $apiKey;
		}
	}

	protected function request(string $method, string $path, array $payload, ?int $timeoutOverride = null): ?array
	{
		return $this->requestWithRetry($method, $path, $payload, true, $timeoutOverride, false, false);
	}

	protected function requestModeration(string $method, string $path, array $payload): ?array
	{
		$timeout = max(
			self::MODERATION_SYNC_TIMEOUT_SECONDS,
			$this->getIntOption('ffProtectTimeout', 3)
		);

		return $this->requestWithRetry($method, $path, $payload, true, $timeout, false, false);
	}

	protected function requestWithRetry(
		string $method,
		string $path,
		array $payload,
		bool $allowRebootstrap,
		?int $timeoutOverride = null,
		bool $suppressTimeoutError = false,
		bool $timeoutRetryAttempted = false
	): ?array
	{
		return $this->requestWithRetryPass(
			$method,
			$path,
			$payload,
			$allowRebootstrap,
			$timeoutOverride,
			$suppressTimeoutError,
			$timeoutRetryAttempted
		);
	}

	/**
	 * Requests use the configured route followed only by its explicitly enabled
	 * global fallbacks. Runtime checks use a short per-base timeout and total budget.
	 */
	protected function requestWithRetryPass(
		string $method,
		string $path,
		array $payload,
		bool $allowRebootstrap,
		?int $timeoutOverride,
		bool $suppressTimeoutError,
		bool $timeoutRetryAttempted
	): ?array
	{
		$bases = $this->getOrderedBasesForRequests($path);
		if (!$bases)
		{
			return null;
		}
		$isCheck = strpos($path, '/v1/check') === 0;
		$isContactPageCheck = \FfApiResilience::shouldUseContactPageRouting($path);
		$enforceCheckBudget = $isCheck && !$isContactPageCheck;
		$attemptTimeout = $timeoutOverride;
		if ($enforceCheckBudget)
		{
			$configuredTimeout = max(1, $timeoutOverride ?? $this->getIntOption('ffProtectTimeout', 3));
			$attemptTimeout = min($configuredTimeout, \FfApiResilience::RUNTIME_CHECK_ENDPOINT_TIMEOUT_SECONDS);
		}
		$tried = [];
		$startedAt = microtime(true);

		foreach ($bases as $baseIndex => $baseUrl)
		{
			if ($this->runtimeCheckBudgetExceeded($startedAt, $enforceCheckBudget))
			{
				break;
			}
			$baseUrl = $this->normaliseBaseUrl($baseUrl);
			if ($baseUrl === '' || in_array($baseUrl, $tried, true))
			{
				continue;
			}
			$tried[] = $baseUrl;

			$attempt = $this->requestWithRetryOnBase(
				$method,
				$path,
				$payload,
				$baseUrl,
				$allowRebootstrap && $baseIndex === 0,
				$attemptTimeout,
				$suppressTimeoutError,
				$timeoutRetryAttempted
			);
			if ($attempt['outcome'] === 'success')
			{
				/** @var array $data */
				$data = $attempt['data'];
				return $data;
			}
			if (empty($attempt['failover']))
			{
				return null;
			}
		}
		return null;
	}

	protected function runtimeCheckBudgetExceeded(float $startedAt, bool $enforce): bool
	{
		return $enforce
			&& (microtime(true) - $startedAt) >= \FfApiResilience::RUNTIME_CHECK_TOTAL_BUDGET_SECONDS;
	}

	/**
	 * @return array{outcome: 'success'|'failed', data?: array, failover?: bool}
	 */
	protected function requestWithRetryOnBase(
		string $method,
		string $path,
		array $payload,
		string $baseUrl,
		bool $allowRebootstrap,
		?int $timeoutOverride = null,
		bool $suppressTimeoutError = false,
		bool $timeoutRetryAttempted = false
	): array
	{
		$baseUrl = $this->normaliseBaseUrl($baseUrl);
		if ($baseUrl === '')
		{
			return ['outcome' => 'failed', 'failover' => true];
		}

		try
		{
			$client = $this->app->http()->client();
			$options = [
				'timeout' => $timeoutOverride ?? $this->getIntOption('ffProtectTimeout', 3),
				'connect_timeout' => min(1, $timeoutOverride ?? $this->getIntOption('ffProtectTimeout', 3)),
				'http_errors' => false,
				'headers' => [
					'Accept' => 'application/json',
				],
			];
			if (strtoupper($method) === 'GET')
			{
				$query = $payload;
				$this->moveQueryApiKeyToHeader($query, $options['headers']);
				$options['query'] = $query;
			}
			else
			{
				$options['json'] = $payload;
				$options['headers']['Content-Type'] = 'application/json';
			}

			$response = $client->request($method, $baseUrl . $path, $options);

			$status = $response->getStatusCode();
			$body = (string) $response->getBody();
			if ($status < 200 || $status >= 300)
			{
				$decodedErr = json_decode($body, true);
				if (
					$this->isOfflineApiKey()
					&& \FfApiResilience::isNodeMismatchResponse(is_array($decodedErr) ? $decodedErr : null)
				)
				{
					$previousApiKey = $this->getStringOption('ffProtectApiKey');
					$previousSiteId = $this->getStringOption('ffProtectSiteId');
					$previousEndpointState = $this->loadEndpointState();
					$this->log('warning', 'Control plane unavailable; using temporary regional key', [
						'path' => $path,
						'base' => $baseUrl,
						'reason' => 'node_mismatch',
					]);
					$this->clearOfflinePinForRebootstrap();
					$bootstrap = $this->tryOfflineFailoverRebootstrap();
					if ($bootstrap)
					{
						$retriedPayload = $payload;
						if (array_key_exists('api_key', $retriedPayload))
						{
							$retriedPayload['api_key'] = $this->getStringOption('ffProtectApiKey');
						}
						if (array_key_exists('site_id', $retriedPayload))
						{
							$retriedPayload['site_id'] = $this->getStringOption('ffProtectSiteId');
						}
						if (array_key_exists('domain', $retriedPayload))
						{
							$retriedPayload['domain'] = $this->getBootstrapDomain();
						}
						return $this->requestWithRetryOnBase($method, $path, $retriedPayload, $baseUrl, false, $timeoutOverride, $suppressTimeoutError, $timeoutRetryAttempted);
					}
					$this->applyOptionUpdates([
						'ffProtectApiKey' => $previousApiKey,
						'ffProtectSiteId' => $previousSiteId,
					]);
					$this->saveEndpointState($previousEndpointState);
					return ['outcome' => 'failed', 'failover' => false];
				}
				if ($allowRebootstrap && $this->shouldRebootstrap($status, $body, $path))
				{
					$previousApiKey = $this->getStringOption('ffProtectApiKey');
					$previousSiteId = $this->getStringOption('ffProtectSiteId');
					if ($status === 409 && $this->responseErrorCode($body) === 'stale_site')
					{
						$this->applyOptionUpdates(['ffProtectSiteId' => '']);
					}
					else
					{
						$this->resetIdentity();
					}
					$bootstrap = $this->bootstrapIfNeeded();
					if ($bootstrap)
					{
						// Swap *all* identity fields the retry payload carries.
						// The freshly-minted credentials have a new site_id (and
						// possibly a normalised domain), so leaving stale values
						// here causes the server to return 409 stale_site.
						$retriedPayload = $payload;
						if (array_key_exists('api_key', $retriedPayload))
						{
							$retriedPayload['api_key'] = $this->getStringOption('ffProtectApiKey');
						}
						if (array_key_exists('site_id', $retriedPayload))
						{
							$retriedPayload['site_id'] = $this->getStringOption('ffProtectSiteId');
						}
						return $this->requestWithRetryOnBase($method, $path, $retriedPayload, $baseUrl, false, $timeoutOverride, $suppressTimeoutError, $timeoutRetryAttempted);
					}
					$this->applyOptionUpdates([
						'ffProtectApiKey' => $previousApiKey,
						'ffProtectSiteId' => $previousSiteId,
					]);
				}
				if (\FfApiResilience::shouldStopEndpointFailoverForStatus((int) $status))
				{
					return ['outcome' => 'failed', 'failover' => false];
				}

				$this->recordEndpointFailure('non_success_status', $baseUrl, $path, (int) $status);
				return ['outcome' => 'failed', 'failover' => \FfApiResilience::shouldFailoverOnEndpointStatus((int) $status)];
			}

			$data = json_decode($body, true);
			if (!is_array($data))
			{
				$this->recordEndpointFailure('invalid_json', $baseUrl, $path, (int) $status);
				return ['outcome' => 'failed', 'failover' => true];
			}

			$this->log('info', 'Forum Fortress API request completed', [
				'path' => $path,
				'status' => $status,
				'base' => $baseUrl,
			]);
			$nodeHeader = trim((string) $response->getHeaderLine('X-ForumFortress-Node'));
			$state = $this->loadEndpointState();
			$now = time();
			if (($state['last_responded'] ?? '') !== $baseUrl
				|| ($state['last_responded_node'] ?? '') !== $nodeHeader
				|| ($now - (int) ($state['last_response_at'] ?? 0)) >= 60)
			{
				$state['last_responded'] = $baseUrl;
				$state['last_responded_node'] = $nodeHeader;
				$state['last_response_at'] = $now;
				$this->saveEndpointState($state);
			}

			return ['outcome' => 'success', 'data' => $data];
		}
		catch (\Throwable $e)
		{
			$rawMessage = $e->getMessage();
			$message = $this->redactSensitiveText($rawMessage);
			$isTimeout = stripos($rawMessage, 'cURL error 28') !== false
				|| stripos($rawMessage, 'timed out') !== false
				|| stripos($rawMessage, 'timeout') !== false;
			if ($isTimeout)
			{
				$this->lastCheckHadTimeout = true;
			}
			$isConnectionFailure = $isTimeout
				|| stripos($rawMessage, 'cURL error 6') !== false
				|| stripos($rawMessage, 'cURL error 7') !== false
				|| stripos($rawMessage, 'could not resolve host') !== false
				|| stripos($rawMessage, 'failed to connect') !== false
				|| stripos($rawMessage, 'connection refused') !== false
				|| stripos($rawMessage, 'network is unreachable') !== false;
			if (
				$isTimeout
				&& !$timeoutRetryAttempted
				&& !\FfApiResilience::isContactPageCheckPath($path)
				&& (strpos($path, '/v1/check') !== 0 || \FfApiResilience::apiRegionIsLocked($this->getApiRegion()))
			)
			{
				$retryTimeout = max(
					(int) ($timeoutOverride ?? 0),
					max(1, $this->getIntOption('ffProtectTimeout', 3))
				);
				$this->log('info', 'Forum Fortress API timeout, retrying once', [
					'path' => $path,
					'base' => $baseUrl,
					'timeout' => $retryTimeout,
					'message' => $message,
				]);
				return $this->requestWithRetryOnBase(
					$method,
					$path,
					$payload,
					$baseUrl,
					$allowRebootstrap,
					$retryTimeout,
					$suppressTimeoutError,
					true
				);
			}

			if ($suppressTimeoutError && $isTimeout)
			{
				$this->lastCheckHadTimeout = true;
				$this->recordEndpointFailure('timeout', $baseUrl, $path, null, $message);
				$this->logContactPageTimeoutIfThrottled($path, $payload, $baseUrl, $message);
				return ['outcome' => 'failed', 'failover' => true];
			}

			if ($isConnectionFailure && \FfApiResilience::shouldUseContactPageRouting($path))
			{
				if ($isTimeout)
				{
					$this->lastCheckHadTimeout = true;
				}
				$this->recordEndpointFailure(
					$isTimeout ? 'timeout' : 'connection_failure',
					$baseUrl,
					$path,
					null,
					$message
				);
				$this->logContactPageTimeoutIfThrottled($path, $payload, $baseUrl, $message);
				return ['outcome' => 'failed', 'failover' => true];
			}

			if (!$isConnectionFailure)
			{
				$this->log('error', 'Forum Fortress API request failed', [
					'path' => $path,
					'base' => $baseUrl,
					'message' => $message,
				]);
			}

			// Register checks pass suppressTimeoutError so all ordered bases (and hot
			// failover) are tried before fail-closed handling in check('register').
			if ($isConnectionFailure)
			{
				$this->recordEndpointFailure('connection_failure', $baseUrl, $path, null, $message);
			}
			return ['outcome' => 'failed', 'failover' => true];
		}
	}

	protected function getModerationSyncIntervalSeconds(array $state): int
	{
		if ((int) ($state['moderation_pending_actions'] ?? 0) > 0)
		{
			return 60;
		}
		return self::MODERATION_SYNC_SECONDS;
	}

	protected function getBlockRejectAction(): string
	{
		$mode = strtolower(trim($this->getStringOption('ffProtectBlockRejectAction')));
		return $mode === 'spam_clean' ? 'spam_clean' : 'reject';
	}

	protected function refreshPlanCacheIfStale(bool $force): void
	{
		$state = $this->loadEndpointState();
		$lastPlanCheck = (int) ($state['plan_checked_at'] ?? 0);
		if (!$force && $lastPlanCheck > 0 && (time() - $lastPlanCheck) < self::PLAN_REFRESH_SECONDS)
		{
			return;
		}
		$status = $this->siteStatus();
		$state['plan_checked_at'] = time();
		if (is_array($status) && !empty($status['plan']))
		{
			$state['plan_name'] = strtolower(trim((string) $status['plan']));
		}
		$this->saveEndpointState($state);
	}

	protected function logContactPageTimeoutIfThrottled(string $path, array $payload, string $baseUrl, string $message): void
	{
		if (!\FfApiResilience::shouldUseContactPageRouting($path))
		{
			return;
		}
		$state = $this->loadEndpointState();
		$throttleKey = 'contact_page:' . $this->normaliseBaseUrl($baseUrl);
		if (!\FfApiResilience::shouldLogThrottledApiFailure($state, $throttleKey))
		{
			return;
		}
		$this->saveEndpointState($state);
		$this->log('warning', 'Forum Fortress contact_page check timed out (fail-open)', [
			'path' => $path,
			'base' => $this->normaliseBaseUrl($baseUrl),
			'message' => substr(trim($message), 0, 200),
		]);
	}

	protected function logBackgroundTaskFailure(string $taskLabel, \Throwable $e): void
	{
		$rawMessage = $e->getMessage();
		$message = $this->redactSensitiveText($rawMessage);
		if (\FfApiResilience::isTransientNetworkMessage($rawMessage))
		{
			$state = $this->loadEndpointState();
			if (!\FfApiResilience::shouldLogConsecutiveTransientFailure($state, 'bg:' . $taskLabel, true))
			{
				return;
			}
			$this->saveEndpointState($state);
			if (!$this->getBoolOption('ffProtectDebugLog', false))
			{
				return;
			}
			$this->log('warning', 'Forum Fortress ' . $taskLabel . ' (transient)', [
				'message' => substr(trim($message), 0, 200),
			]);

			return;
		}

		$this->log('error', 'Forum Fortress ' . $taskLabel, ['message' => $message]);
	}

	protected function noteBackgroundTaskResult(string $taskLabel, bool $success): void
	{
		$state = $this->loadEndpointState();
		$shouldLog = \FfApiResilience::shouldLogConsecutiveTransientFailure(
			$state,
			'bg:' . $taskLabel,
			!$success,
			1
		);
		if (!$success)
		{
			$state['last_failure'] = [
				'at' => time(),
				'reason' => 'no_response',
				'base' => $this->lookupBasesOrdered()[0] ?? '',
				'path' => '/v1/site/ping',
			];
		}
		$this->saveEndpointState($state);
		if ($shouldLog)
		{
			$this->log('error', 'Forum Fortress ' . $taskLabel, [
				'message' => 'No successful response was returned by the configured API route.',
			]);
		}
	}

	protected function recordEndpointFailure(
		string $reason,
		string $baseUrl,
		string $path,
		?int $status = null,
		string $message = ''
	): void {
		$failure = [
			'at' => time(),
			'reason' => $reason,
			'base' => $this->normaliseBaseUrl($baseUrl),
			'path' => (string) $path,
		];
		if ($status !== null)
		{
			$failure['status'] = $status;
		}
		$message = trim($this->redactSensitiveText($message));
		if ($message !== '')
		{
			$failure['message'] = substr($message, 0, 240);
		}

		$state = $this->loadEndpointState();
		$state['last_failure'] = $failure;
		$this->saveEndpointState($state);
	}

	protected function shouldRunDailyTask(string $key): bool
	{
		$state = $this->loadEndpointState();
		$last = (int) ($state[$key] ?? 0);
		return $last <= 0 || (time() - $last) >= 86400;
	}

	protected function markDailyTaskRun(string $key): void
	{
		$state = $this->loadEndpointState();
		$state[$key] = time();
		$this->saveEndpointState($state);
	}

	protected function shouldRebootstrap(int $status, string $body, string $path): bool
	{
		if ($status === 403)
		{
			$data = json_decode($body, true);
			if (\FfApiResilience::isNodeMismatchResponse(is_array($data) ? $data : null))
			{
				return $this->isOfflineApiKey();
			}
		}
		if ($path === '/v1/site/bootstrap' || trim($this->getStringOption('ffProtectApiKey')) === '')
		{
			return false;
		}
		$code = $this->responseErrorCode($body);
		if ($status === 409)
		{
			return $code === 'stale_site';
		}
		if ($status !== 401)
		{
			return false;
		}
		return in_array($code, ['invalid_key', 'invalid_api_key', 'unknown_site', 'invalid_key_format', 'site_not_found', 'invalid api key', 'site not found'], true);
	}

	protected function responseErrorCode(string $body): string
	{
		$data = json_decode($body, true);
		if (!is_array($data))
		{
			return '';
		}
		if (isset($data['error']))
		{
			return strtolower(trim((string) $data['error']));
		}
		$detail = $data['detail'] ?? null;
		if (is_array($detail) && isset($detail['error']))
		{
			return strtolower(trim((string) $detail['error']));
		}
		return is_string($detail) ? strtolower(trim($detail)) : '';
	}

	protected function resetIdentity(): void
	{
		$this->applyOptionUpdates([
			'ffProtectApiKey' => '',
			'ffProtectSiteId' => '',
		]);
	}

	protected function persistIdentityFromResponse(array $response, string $usedBase = ''): void
	{
		$keyType = isset($response['key_type']) ? (string) $response['key_type'] : '';
		$apiKey = isset($response['api_key']) ? (string) $response['api_key'] : '';
		$wasOffline = $this->isOfflineApiKey();
		$isOffline = \FfApiResilience::isOfflineBootstrapKey($apiKey, $keyType !== '' ? $keyType : null);

		$updates = [];
		if (!empty($response['api_key']) && $response['api_key'] !== $this->getStringOption('ffProtectApiKey'))
		{
			$updates['ffProtectApiKey'] = (string) $response['api_key'];
		}
		if (!empty($response['site_id']) && $response['site_id'] !== $this->getStringOption('ffProtectSiteId'))
		{
			$updates['ffProtectSiteId'] = (string) $response['site_id'];
		}

		if ($updates)
		{
			$this->applyOptionUpdates($updates);
		}

		$state = $this->loadEndpointState();
		if ($isOffline)
		{
			\FfApiResilience::applyOfflineBootstrapRouting($response, $state, $usedBase);
			$this->saveEndpointState($state);
			$this->log('warning', 'Control plane unavailable; using temporary regional key', [
				'issuer_node_id' => $state['issuer_node_id'] ?? '',
				'preferred_endpoint' => $state['offline_preferred_endpoint'] ?? '',
			]);
		}
		else
		{
			\FfApiResilience::applyOfflineBootstrapRouting($response, $state, $usedBase);
			$this->saveEndpointState($state);
			if ($wasOffline && $apiKey !== '' && !\FfApiResilience::isOfflineBootstrapKey($apiKey, null))
			{
				$this->log('info', 'Forum Fortress migrated to normal control-plane API key', []);
			}
		}
	}

	protected function clearOfflinePinForRebootstrap(): void
	{
		$this->resetIdentity();
		$state = $this->loadEndpointState();
		unset(
			$state['offline_pinned'],
			$state['issuer_node_id'],
			$state['offline_preferred_endpoint'],
			$state['offline_rebootstrap_at'],
			$state['offline_canonical_domain']
		);
		$this->saveEndpointState($state);
	}

	protected function tryOfflineFailoverRebootstrap(?int $timeoutOverride = null): ?array
	{
		if (!$this->isEnabled())
		{
			return null;
		}
		$timeout = max(1, $timeoutOverride ?? $this->getIntOption('ffProtectTimeout', 3));
		$payload = [
			'domain' => $this->getBootstrapDomain(),
			'platform' => self::PLATFORM,
			'platform_version' => $this->getPlatformVersion(),
			'plugin_version' => self::PLUGIN_VERSION,
			'api_key' => null,
		];

		$result = $this->tryBootstrapAcrossBases($payload, $timeout);
		if (!$result)
		{
			return null;
		}
		$this->persistIdentityFromResponse($result['data'], (string) ($result['base'] ?? ''));

		return $result['data'];
	}

	public function maybeMigrateFromOfflineBootstrap(): void
	{
		if (!$this->isEnabled() || !$this->isOfflineApiKey())
		{
			return;
		}
		$state = $this->loadEndpointState();
		if (!\FfApiResilience::shouldRebootstrapOfflineNow($state))
		{
			return;
		}
		$timeout = max(1, $this->getIntOption('ffProtectTimeout', 3));
		$payload = [
			'domain' => $this->getBootstrapDomain(),
			'platform' => self::PLATFORM,
			'platform_version' => $this->getPlatformVersion(),
			'plugin_version' => self::PLUGIN_VERSION,
			'api_key' => null,
		];
		$result = $this->tryBootstrapAcrossBases($payload, $timeout);
		if (!$result)
		{
			$state['offline_rebootstrap_at'] = time() + 600;
			$this->saveEndpointState($state);

			return;
		}
		$response = $result['data'];
		$keyType = isset($response['key_type']) ? (string) $response['key_type'] : '';
		$apiKey = isset($response['api_key']) ? (string) $response['api_key'] : '';
		if (\FfApiResilience::isOfflineBootstrapKey($apiKey, $keyType !== '' ? $keyType : null))
		{
			$this->persistIdentityFromResponse($response, (string) ($result['base'] ?? ''));
			return;
		}
		$this->persistIdentityFromResponse($response, (string) ($result['base'] ?? ''));
	}

	/**
	 * Persist option changes AND mirror them into the in-memory Options
	 * arrayobject so subsequent calls within the same request see the new
	 * values immediately. XF's OptionRepository::updateOptions() only writes
	 * to the database / data registry; the active $app->options() instance is
	 * not refreshed by save, which broke the post-401 rebootstrap chain
	 * (mint new key -> retry would still see the stale empty key).
	 *
	 * @param array<string, mixed> $values
	 */
	protected function applyOptionUpdates(array $values): void
	{
		if (!$values)
		{
			return;
		}

		$this->app->repository('XF:Option')->updateOptions($values);

		$options = $this->app->options();
		foreach ($values as $key => $value)
		{
			$options[$key] = $value;
		}
	}

	public static function extractLinks(string $text): array
	{
		if ($text === '')
		{
			return [];
		}

		preg_match_all('#https?://[^\s<>"\']+#i', $text, $matches);
		$links = [];
		foreach ($matches[0] ?? [] as $url)
		{
			$parsed = parse_url($url);
			if (!is_array($parsed) || empty($parsed['host']))
			{
				continue;
			}
			$scheme = isset($parsed['scheme']) ? strtolower((string) $parsed['scheme']) : 'https';
			$host = strtolower((string) $parsed['host']);
			$path = isset($parsed['path']) ? (string) $parsed['path'] : '';
			$links[] = $scheme . '://' . $host . $path;
		}

		return array_values(array_unique($links));
	}

	public static function filterExternalLinks(array $links, string $forumDomain): array
	{
		$normalizedForumDomain = self::normalizeDomain($forumDomain);
		if ($normalizedForumDomain === '')
		{
			return array_values(array_unique($links));
		}

		$filtered = [];
		foreach ($links as $link)
		{
			$domain = self::extractDomain((string) $link);
			if ($domain !== null && self::isForumOwnedDomain($domain, $normalizedForumDomain))
			{
				continue;
			}
			$filtered[] = (string) $link;
		}

		return array_values(array_unique($filtered));
	}

	public static function extractDomain(string $value): ?string
	{
		$value = trim($value);
		if ($value === '')
		{
			return null;
		}

		if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $value))
		{
			$value = 'https://' . $value;
		}

		$host = parse_url($value, PHP_URL_HOST);
		if (!$host)
		{
			return null;
		}

		$normalized = self::normalizeDomain((string) $host);
		return $normalized !== '' ? $normalized : null;
	}

	public static function emailDomain(?string $email): ?string
	{
		if (!$email || strpos($email, '@') === false)
		{
			return null;
		}

		$parts = explode('@', $email, 2);
		return strtolower(trim($parts[1]));
	}

	protected static function normalizeDomain(string $domain): string
	{
		$normalized = strtolower(rtrim(trim($domain), '.'));
		if (strpos($normalized, 'www.') === 0)
		{
			$normalized = substr($normalized, 4);
		}

		return $normalized;
	}

	protected static function isForumOwnedDomain(string $candidate, string $forumDomain): bool
	{
		$normalizedCandidate = self::normalizeDomain($candidate);
		$normalizedForumDomain = self::normalizeDomain($forumDomain);
		if ($normalizedCandidate === '' || $normalizedForumDomain === '')
		{
			return false;
		}

		return $normalizedCandidate === $normalizedForumDomain
			|| substr($normalizedCandidate, -strlen('.' . $normalizedForumDomain)) === '.' . $normalizedForumDomain;
	}

	protected function log(string $level, string $message, array $context = []): void
	{
		$debug = $this->getBoolOption('ffProtectDebugLog', false);
		if (!$debug && in_array($level, ['info', 'warning'], true))
		{
			return;
		}

		$parts = [];
		foreach ($context as $key => $value)
		{
			$keyName = strtolower((string) $key);
			if (in_array($keyName, ['api_key', 'x-ff-key', 'authorization'], true))
			{
				$value = '[redacted]';
			}
			if (is_array($value))
			{
				$value = json_encode($this->redactLogContext($value));
			}
			$value = $this->redactSensitiveText((string) $value);
			$parts[] = $key . '=' . $value;
		}
		$line = '[ForumFortress] ' . $this->redactSensitiveText($message);
		if ($parts)
		{
			$line .= ' | ' . implode(' ', $parts);
		}
		\XF::logError($line, false);
	}

	/** @param array<string|int, mixed> $context */
	protected function redactLogContext(array $context): array
	{
		foreach ($context as $key => $value)
		{
			$keyName = strtolower((string) $key);
			if (in_array($keyName, ['api_key', 'x-ff-key', 'authorization'], true))
			{
				$context[$key] = '[redacted]';
			}
			elseif (is_array($value))
			{
				$context[$key] = $this->redactLogContext($value);
			}
			elseif (is_scalar($value) || $value === null)
			{
				$context[$key] = $this->redactSensitiveText((string) $value);
			}
		}

		return $context;
	}

	protected function redactSensitiveText(string $value): string
	{
		try
		{
			$apiKey = trim($this->getStringOption('ffProtectApiKey'));
		}
		catch (\Throwable $e)
		{
			$apiKey = '';
		}
		if ($apiKey !== '')
		{
			$value = str_replace([$apiKey, rawurlencode($apiKey)], '[redacted]', $value);
		}

		$value = (string) preg_replace(
			'/((?:[?&]|\\b)api_key(?:=|%3D))[^&\\s]+/i',
			'$1[redacted]',
			$value
		);
		$value = (string) preg_replace(
			'/("api_key"\\s*:\\s*")[^"]*(")/i',
			'$1[redacted]$2',
			$value
		);
		$value = (string) preg_replace('/(Bearer\\s+)[^\\s,;]+/i', '$1[redacted]', $value);
		$value = (string) preg_replace('/(X-FF-Key\\s*[:=]\\s*)[^\\s,;]+/i', '$1[redacted]', $value);

		return $value;
	}

	public function getStringOption(string $key, string $default = ''): string
	{
		$options = $this->app->options();
		return $options->offsetExists($key) ? (string) $options[$key] : $default;
	}

	public function getBoolOption(string $key, bool $default = false): bool
	{
		$options = $this->app->options();
		return $options->offsetExists($key) ? (bool) $options[$key] : $default;
	}

	public function lastCheckHadTimeout(): bool
	{
		return $this->lastCheckHadTimeout;
	}

	public function getIntOption(string $key, int $default = 0): int
	{
		$options = $this->app->options();
		return $options->offsetExists($key) ? (int) $options[$key] : $default;
	}

}
