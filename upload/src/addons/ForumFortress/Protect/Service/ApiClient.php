<?php

namespace ForumFortress\Protect\Service;

require_once __DIR__ . '/FfApiResilience.php';

use XF\App;
use XF\Entity\User;

use function array_merge, array_values, array_unique, array_map, array_filter, bin2hex, explode, in_array, is_array, json_encode, ksort, microtime, min, parse_url, preg_match, random_bytes, round, rtrim, strtolower, time, trim;

class ApiClient
{
	public const PLATFORM = 'xenforo';
	public const PLUGIN_VERSION = '1.8.5';
	/** Add-on id string; must match {@see Setup::ADD_ON_ID} for simpleCache keys. */
	protected const ADDON_ID_FOR_CACHE = 'ForumFortress/Protect';
	/** Minimum seconds between full hourly sync runs (cron + HTTP fallback share this gate). */
	protected const HOURLY_SYNC_MIN_INTERVAL = 540;
	protected const ENDPOINT_HEALTH_REFRESH_SECONDS = 3600;
	protected const ENDPOINT_HEALTH_DEGRADED_REFRESH_SECONDS = 300;
	protected const ENDPOINT_HEALTH_SLOW_TRIGGER_MS = 100;
	protected const ENDPOINT_HEALTH_RECOVERY_MS = 80;
	protected const ENDPOINT_REFRESH_REQUEST_MAX_DELAY_SECONDS = 60;
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
		if ($response)
		{
			$this->maybeRefreshEndpointCatalogAfterCheckIn(
				$endpoint === 'register' ? '/v1/check/register' : '/v1/check/' . $endpoint
			);
		}
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

	public function health(?int $timeoutOverride = null): ?array
	{
		if (!$this->isEnabled())
		{
			return null;
		}

		return $this->request('GET', '/health', [], $timeoutOverride);
	}

	public function capabilities(?int $timeoutOverride = null): ?array
	{
		if (!$this->isEnabled())
		{
			return null;
		}

		return $this->requestFromControlPlane('GET', '/v1/capabilities', [], $timeoutOverride);
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

		return $this->requestFromControlPlane('GET', '/v1/plugin-release', [
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

		try
		{
			$this->refreshEndpointCatalogAndHealth();
		}
		catch (\Throwable $e)
		{
			$this->logBackgroundTaskFailure('endpoint catalog refresh failed', $e);
		}

		try
		{
			$this->sitePing();
		}
		catch (\Throwable $e)
		{
			$this->logBackgroundTaskFailure('site ping failed', $e);
		}

		try
		{
			$this->refreshPlanCacheIfStale(true);
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
			|| !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
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

		$payload = $this->requestFromControlPlane('POST', '/v1/site/ping', [
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
			$this->maybeRefreshEndpointCatalogAfterCheckIn('/v1/site/ping');
		}
		return $payload;
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
		if (!$response && $this->fetchNodeEndpointsCatalog(true))
		{
			$bootstrapResult = $this->tryBootstrapAcrossBases($payload, $timeout);
			if ($bootstrapResult)
			{
				$response = $bootstrapResult['data'];
				$usedBase = $bootstrapResult['base'];
			}
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
		$state = $this->loadEndpointState();
		$bases = !empty($state['offline_pinned']) && \FfApiResilience::shouldRebootstrapOfflineNow($state)
			? \FfApiResilience::offlineRebootstrapBases(
				$state,
				$this->getControlPlaneBaseUrl(),
				$this->getHotFailoverApiBaseUrl(),
				$this->edgeBasesFromState(),
				$this->getManualBaseUrl()
			)
			: $this->bootstrapBasesOrdered();
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
		return \FfApiResilience::normaliseBaseUrl($value);
	}

	protected function getManualBaseUrl(): string
	{
		$configuredBase = $this->getStringOption('ffProtectApiBaseUrl');
		if ($this->getStringOption('ffProtectApiRegion') === '' && \FfApiResilience::isLocalDevelopmentBaseUrl($configuredBase))
		{
			return $this->normaliseBaseUrl($configuredBase);
		}
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
		$configured = $this->normaliseBaseUrl($this->getStringOption('ffProtectControlBaseUrl'));
		if ($configured !== '')
		{
			return $configured;
		}

		return $this->deriveControlPlaneBaseFromManual($this->getManualBaseUrl());
	}

	protected function deriveControlPlaneBaseFromManual(string $manual): string
	{
		$manual = $this->normaliseBaseUrl($manual);
		if ($manual === '')
		{
			return '';
		}
		$host = parse_url($manual, PHP_URL_HOST);
		if (!is_string($host) || $host === '')
		{
			return '';
		}
		$host = strtolower($host);
		if (strpos($host, 'api.') === 0 && strpos($host, '.') !== false)
		{
			$root = substr($host, 4);

			return $this->normaliseBaseUrl('https://control.' . $root);
		}
		if (strpos($host, 'control.') === 0)
		{
			return $manual;
		}

		return '';
	}

	protected function getHotFailoverApiBaseUrl(): string
	{
		return \FfApiResilience::hotFailoverApiBaseUrl(
			$this->getManualBaseUrl(),
			$this->getControlPlaneBaseUrl()
		);
	}

	/** @return list<string> */
	protected function edgeBasesFromState(): array
	{
		$state = $this->loadEndpointState();
		$endpointList = is_array($state['endpoints'] ?? null) ? $state['endpoints'] : [];
		$edges = [];
		foreach ($endpointList as $row)
		{
			$edges[] = (string) $row;
		}

		return $edges;
	}

	/** @return list<string> */
	protected function bootstrapBasesOrdered(): array
	{
		if (\FfApiResilience::apiRegionIsLocked($this->getApiRegion()))
		{
			return \FfApiResilience::uniqueOrderedBases(
				\FfApiResilience::regionLockedCheckBases($this->getApiRegion(), $this->allowGlobalEmergencyFallback()),
				[$this->getControlPlaneBaseUrl()]
			);
		}
		return \FfApiResilience::bootstrapBasesOrdered(
			$this->getControlPlaneBaseUrl(),
			$this->getHotFailoverApiBaseUrl(),
			$this->getManualBaseUrl(),
			$this->edgeBasesFromState()
		);
	}

	/** @return list<string> */
	protected function catalogFetchBases(): array
	{
		return \FfApiResilience::catalogFetchBases(
			$this->getControlPlaneBaseUrl(),
			$this->getHotFailoverApiBaseUrl(),
			$this->edgeBasesFromState()
		);
	}

	/**
	 * @param list<string> $endpoints
	 * @return list<string>
	 */
	protected function normalisedEndpointList(array $endpoints): array
	{
		$normalised = array_values(array_unique(array_map(function ($u) {
			return $this->normaliseBaseUrl((string) $u);
		}, $endpoints)));
		$normalised = array_values(array_filter($normalised, function ($u) {
			return $u !== '';
		}));
		sort($normalised);

		return $normalised;
	}

	/**
	 * @param list<string> $previous
	 * @param list<string> $next
	 */
	protected function endpointCatalogChanged(array $previous, array $next): bool
	{
		return $this->normalisedEndpointList($previous) !== $this->normalisedEndpointList($next);
	}

	/**
	 * @param array<string, mixed> $state
	 */
	protected function invalidateEndpointHealthState(array &$state): void
	{
		$state['last_health_at'] = 0;
		$state['health_day'] = '';
	}

	/**
	 * GET /v1/node-endpoints from control, api.ffapi.net, or edges (edges may proxy to control).
	 */
	protected function fetchNodeEndpointsCatalog(bool $force = false, ?int $timeoutOverride = null): bool
	{
		$state = $this->loadEndpointState();
		$previousEndpoints = is_array($state['endpoints'] ?? null) ? $state['endpoints'] : [];
		$now = time();
		if (!$force && !\FfApiResilience::isEndpointCatalogStale($state))
		{
			return true;
		}
		if (!$force && \FfApiResilience::shouldBackoffEndpointCatalogRefresh($state, $now))
		{
			return false;
		}

		$urls = [];
		$endpointMeta = [];
		foreach ($this->catalogFetchBases() as $catalogBase)
		{
			$res = $this->rawRequest('GET', $catalogBase, '/v1/node-endpoints', [], max(1, $timeoutOverride ?? self::CONNECTION_TEST_TIMEOUT_SECONDS));
			if (($res['status'] ?? 0) < 200 || ($res['status'] ?? 0) >= 300 || !is_array($res['data'] ?? null))
			{
				continue;
			}
			$endpoints = $res['data']['endpoints'] ?? null;
			if (!is_array($endpoints))
			{
				continue;
			}
			$state['control_check_fallback'] = !empty($res['data']['control_check_fallback']);
			$endpointMeta = [];
			foreach ($endpoints as $row)
			{
				if (!is_array($row))
				{
					continue;
				}
				$url = $this->normaliseBaseUrl((string) ($row['url'] ?? ''));
				if ($url === '')
				{
					continue;
				}
				$urls[$url] = true;
				$endpointMeta[$url] = [
					'check_ready' => array_key_exists('check_ready', $row) ? (bool) $row['check_ready'] : null,
					'status' => isset($row['status']) ? (string) $row['status'] : '',
					'role' => isset($row['role']) ? (string) $row['role'] : '',
					'traffic_tier' => array_key_exists('traffic_tier', $row)
						? \FfApiResilience::normaliseTrafficTier($row['traffic_tier'])
						: null,
				];
			}
			if ($urls)
			{
				break;
			}
		}
		if (!$urls)
		{
			\FfApiResilience::noteEndpointCatalogRefreshFailure($state, $now);
			$this->saveEndpointState($state);

			return false;
		}
		$newEndpoints = array_values(array_keys($urls));
		if ($this->endpointCatalogChanged($previousEndpoints, $newEndpoints))
		{
			$this->invalidateEndpointHealthState($state);
		}
		$state['catalog_fetched_at'] = $now;
		$state['endpoints'] = $newEndpoints;
		if ($endpointMeta)
		{
			$state['endpoint_meta'] = $endpointMeta;
		}
		\FfApiResilience::noteEndpointCatalogRefreshSuccess($state);
		$this->saveEndpointState($state);

		return true;
	}

	/**
	 * Catalog-only refresh when TTL elapsed (no health probes). Used after check-in and by cron.
	 */
	public function refreshEndpointCatalogIfStale(): void
	{
		if (!$this->isEnabled() || $this->getManualBaseUrl() === '')
		{
			return;
		}

		try
		{
			$this->fetchNodeEndpointsCatalog(false);
		}
		catch (\Throwable $e)
		{
			// Stale routing must not block forum traffic.
		}
	}

	protected function maybeRefreshEndpointCatalogAfterCheckIn(string $requestPath): void
	{
		if (!\FfApiResilience::shouldRefreshEndpointCatalogOnCheckIn($requestPath))
		{
			return;
		}

		$this->refreshEndpointCatalogIfStale();
	}

	protected function isCatalogBackupRole(?string $role): bool
	{
		$role = strtolower(trim((string) $role));
		return in_array($role, ['backup', 'control-fallback', 'control'], true);
	}

	protected function isCatalogBackupEndpointUrl(string $baseUrl, ?string $role = null): bool
	{
		if ($this->isCatalogBackupRole($role))
		{
			return true;
		}
		$control = $this->getControlPlaneBaseUrl();

		return $control !== '' && $this->normaliseBaseUrl($baseUrl) === $control;
	}

	/** @return callable(string, ?string): bool */
	protected function catalogBackupCallable(): callable
	{
		return function (string $base, ?string $role): bool {
			return $this->isCatalogBackupEndpointUrl($base, $role);
		};
	}

	/** @return array<string, int|null> */
	protected function latencyMapFromState(array $state): array
	{
		$latencyByBase = [];
		foreach (is_array($state['health_ms'] ?? null) ? $state['health_ms'] : [] as $base => $ms)
		{
			$normalisedBase = $this->normaliseBaseUrl((string) $base);
			if ($normalisedBase === '')
			{
				continue;
			}
			$latencyByBase[$normalisedBase] = is_int($ms) ? $ms : null;
		}

		return $latencyByBase;
	}

	protected function isSharedApiRoundRobinBase(string $baseUrl): bool
	{
		if ($this->isOfflineApiKey())
		{
			return false;
		}
		$manual = $this->getManualBaseUrl();
		$baseUrl = $this->normaliseBaseUrl($baseUrl);
		if ($manual === '' || $baseUrl !== $manual)
		{
			return false;
		}
		$host = parse_url($manual, PHP_URL_HOST);

		return is_string($host) && strpos(strtolower($host), 'api.') === 0;
	}

	protected function shouldProbeEndpointHealth(string $baseUrl, array $state): bool
	{
		$baseUrl = $this->normaliseBaseUrl($baseUrl);
		if ($baseUrl === '')
		{
			return false;
		}
		$endpointMeta = is_array($state['endpoint_meta'] ?? null) ? $state['endpoint_meta'] : [];
		$meta = isset($endpointMeta[$baseUrl]) && is_array($endpointMeta[$baseUrl]) ? $endpointMeta[$baseUrl] : [];
		$role = isset($meta['role']) ? (string) $meta['role'] : null;
		if ($this->isCatalogBackupEndpointUrl($baseUrl, $role))
		{
			return !$this->edgesHealthyForCheckTraffic($state);
		}
		if (!$this->isSharedApiRoundRobinBase($baseUrl))
		{
			return true;
		}
		return false;
	}

	/**
	 * Bootstrap, catalog, capabilities, plugin-release: prefer control, hot-failover to api.ffapi.net, then edges.
	 */
	protected function requestFromControlPlane(string $method, string $path, array $payload, ?int $timeoutOverride = null): ?array
	{
		$timeout = max(1, $timeoutOverride ?? $this->getIntOption('ffProtectTimeout', 3));
		$controlPlaneOnly = $this->isControlPlaneOnlyPath($path);
		$bases = $controlPlaneOnly ? $this->controlPlaneActionBases() : $this->catalogFetchBases();
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
					$lastHttpError = (string) ($data['message'] ?? $data['detail'] ?? $data['error'] ?? ('HTTP ' . $status));
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
			'/v1/site/ping',
			'/v1/site/portal',
			'/v1/site/attack-mode',
			'/v1/site/attack-mode/end',
		], true);
	}

	protected function getPreferredBaseOverride(): string
	{
		return $this->normaliseBaseUrl($this->getStringOption('ffProtectPreferredEndpoint'));
	}

	/** @return array<string, mixed> */
	protected function loadEndpointState(): array
	{
		$raw = trim($this->getStringOption('ffProtectEndpointState'));
		if ($raw === '')
		{
			return [];
		}
		$data = json_decode($raw, true);
		return is_array($data) ? $data : [];
	}

	/** @param array<string, mixed> $state */
	protected function saveEndpointState(array $state): void
	{
		ksort($state);
		$encoded = json_encode($state, JSON_UNESCAPED_SLASHES);
		if (!is_string($encoded))
		{
			return;
		}
		$current = trim($this->getStringOption('ffProtectEndpointState'));
		if ($current === $encoded)
		{
			return;
		}
		$this->applyOptionUpdates(['ffProtectEndpointState' => $encoded]);
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

	/**
	 * @return array{
	 *   catalog_fetched_at: int,
	 *   endpoints: list<string>,
	 *   health_day: string,
	 *   health_ms: array<string, int|null>,
	 *   last_health_at: int,
	 *   last_responded: string,
	 *   last_responded_node: string,
	 *   last_response_at: int,
	 *   preferred: string,
	 *   preferred_missing: string,
	 *   preferred_missing_at: int
	 * }
	 */
	public function endpointStateSnapshot(): array
	{
		$this->hydrateEndpointStateIfStale();
		$state = $this->loadEndpointState();
		$manual = $this->getManualBaseUrl();
		$preferredOverride = $this->getPreferredBaseOverride();
		$endpoints = is_array($state['endpoints'] ?? null) ? $state['endpoints'] : [];
		$endpoints = $this->normaliseAndSanitiseEndpoints($endpoints, $manual);
		$healthMs = is_array($state['health_ms'] ?? null) ? $state['health_ms'] : [];
		$normalisedHealth = [];
		foreach ($healthMs as $base => $ms)
		{
			$normalizedBase = $this->normaliseBaseUrl((string) $base);
			if ($normalizedBase === '')
			{
				continue;
			}
			$normalisedHealth[$normalizedBase] = is_int($ms) ? $ms : null;
		}

		return [
			'catalog_fetched_at' => (int) ($state['catalog_fetched_at'] ?? 0),
			'endpoints' => $endpoints,
			'health_day' => (string) ($state['health_day'] ?? ''),
			'health_ms' => $normalisedHealth,
			'last_health_at' => (int) ($state['last_health_at'] ?? 0),
			'last_responded' => $this->normaliseBaseUrl((string) ($state['last_responded'] ?? '')),
			'last_responded_node' => trim((string) ($state['last_responded_node'] ?? '')),
			'last_response_at' => (int) ($state['last_response_at'] ?? 0),
			'last_site_ping_at' => (int) ($state['last_site_ping_at'] ?? 0),
			'last_failure' => is_array($state['last_failure'] ?? null) ? $state['last_failure'] : null,
			'preferred' => $this->normaliseBaseUrl((string) ($preferredOverride !== '' ? $preferredOverride : ($state['preferred'] ?? $manual))),
			'preferred_missing' => $this->normaliseBaseUrl((string) ($state['preferred_missing'] ?? '')),
			'preferred_missing_at' => (int) ($state['preferred_missing_at'] ?? 0),
		];
	}

	protected function hydrateEndpointStateIfStale(): void
	{
		$state = $this->loadEndpointState();
		$needsHydration = false;
		if (!is_array($state['endpoints'] ?? null) || !$state['endpoints'])
		{
			$needsHydration = true;
		}
		if (!is_array($state['health_ms'] ?? null))
		{
			$needsHydration = true;
		}
		if ((int) ($state['catalog_fetched_at'] ?? 0) <= 0 || (int) ($state['last_health_at'] ?? 0) <= 0)
		{
			$needsHydration = true;
		}
		if (!$needsHydration)
		{
			return;
		}
		$state['refresh_requested_at'] = time();
		$this->saveEndpointState($state);
	}

	/** @return array{preferred: string, last_responded: string, endpoints_count: int, last_health_at: int, preferred_missing: string} */
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
			'last_health_at' => (int) ($state['last_health_at'] ?? 0),
			'preferred_missing' => trim((string) ($state['preferred_missing'] ?? '')),
		];
	}

	protected function edgesHealthyForCheckTraffic(array $state): bool
	{
		$healthMs = is_array($state['health_ms'] ?? null) ? $state['health_ms'] : [];
		$endpointMeta = is_array($state['endpoint_meta'] ?? null) ? $state['endpoint_meta'] : [];

		return \FfApiResilience::edgesHealthyForCheckTraffic(
			$healthMs,
			$endpointMeta,
			$this->catalogBackupCallable()
		);
	}

	public function endpointHealthDisplayLabel(string $endpointUrl, ?array $state = null): string
	{
		$state = $state ?? $this->loadEndpointState();
		$endpointUrl = $this->normaliseBaseUrl($endpointUrl);
		if ($endpointUrl === '')
		{
			return 'unreachable';
		}
		$healthMs = is_array($state['health_ms'] ?? null) ? $state['health_ms'] : [];
		$ms = array_key_exists($endpointUrl, $healthMs) ? $healthMs[$endpointUrl] : null;
		if (is_int($ms) && $ms >= 0)
		{
			return $ms . ' ms';
		}
		$endpointMeta = is_array($state['endpoint_meta'] ?? null) ? $state['endpoint_meta'] : [];
		$meta = isset($endpointMeta[$endpointUrl]) && is_array($endpointMeta[$endpointUrl]) ? $endpointMeta[$endpointUrl] : [];
		$role = isset($meta['role']) ? (string) $meta['role'] : null;
		if (!$this->shouldProbeEndpointHealth($endpointUrl, $state))
		{
			if ($this->isSharedApiRoundRobinBase($endpointUrl))
			{
				return 'shared route';
			}
			if ($this->isCatalogBackupEndpointUrl($endpointUrl, $role))
			{
				if ($this->edgesHealthyForCheckTraffic($state))
				{
					if (!empty($meta['check_ready']))
					{
						return 'standby (check-ready backup)';
					}

					return 'standby (not used for checks)';
				}

				return 'standby (backup)';
			}

			return 'not probed';
		}

		return 'unreachable';
	}

	/**
	 * @return list<array{endpoint: string, latency: string, is_preferred: bool}>
	 */
	public function buildEndpointLatencyRows(): array
	{
		$this->hydrateEndpointStateIfStale();
		$state = $this->loadEndpointState();
		$preferred = $this->normaliseBaseUrl((string) ($this->endpointStateSummary()['preferred'] ?? ''));
		$endpointList = is_array($state['endpoints'] ?? null) ? $state['endpoints'] : [];
		$healthMs = is_array($state['health_ms'] ?? null) ? $state['health_ms'] : [];
		$displayTargets = array_values(array_unique(array_merge($endpointList, array_keys($healthMs))));
		sort($displayTargets);
		$rows = [];
		foreach ($displayTargets as $endpoint)
		{
			$endpointUrl = (string) $endpoint;
			$rows[] = [
				'endpoint' => $endpointUrl,
				'latency' => $this->endpointHealthDisplayLabel($endpointUrl, $state),
				'is_preferred' => $endpointUrl === $preferred,
			];
		}

		return $rows;
	}

	public function refreshEndpointCatalogAndHealth(bool $force = false, ?int $probeTimeout = null): void
	{
		if (!$this->isEnabled())
		{
			return;
		}

		$manual = $this->getManualBaseUrl();
		if ($manual === '')
		{
			return;
		}

		$state = $this->loadEndpointState();
		$now = time();
		$refreshRequestedAt = (int) ($state['refresh_requested_at'] ?? 0);
		$lastHealthAt = (int) ($state['last_health_at'] ?? 0);
		if (
			!$force
			&& $refreshRequestedAt > 0
			&& $refreshRequestedAt > $lastHealthAt
			&& ($now - $refreshRequestedAt) >= self::ENDPOINT_REFRESH_REQUEST_MAX_DELAY_SECONDS
		)
		{
			$force = true;
		}
		$forceHealthRefresh = false;
		if ($force)
		{
			$state['catalog_fetched_at'] = 0;
			$this->invalidateEndpointHealthState($state);
			$state['refresh_requested_at'] = 0;
			$forceHealthRefresh = true;
		}
		$previousEndpoints = is_array($state['endpoints'] ?? null) ? $state['endpoints'] : [];

		$dayKey = gmdate('Y-m-d', $now);

		if (\FfApiResilience::isEndpointCatalogStale($state))
		{
			if (!$this->fetchNodeEndpointsCatalog($force, $probeTimeout))
			{
				// Keep the last known edge list when discovery is temporarily unavailable.
				// Leaving the catalog stale allows another attempt after the short backoff.
				$state = $this->loadEndpointState();
				$state['catalog_fetched_at'] = 0;
				if (!is_array($state['endpoints'] ?? null) || !$state['endpoints'])
				{
					$state['endpoints'] = [$manual];
				}
			}
			else
			{
				$state = $this->loadEndpointState();
				if ($forceHealthRefresh)
				{
					$this->invalidateEndpointHealthState($state);
				}
			}
		}

		$preferredOverride = $this->getPreferredBaseOverride();
		$list = $state['endpoints'] ?? [];
		if (!is_array($list))
		{
			$list = [];
		}
		$state['endpoints'] = $this->normaliseAndSanitiseEndpoints($list, $manual);
		if ($preferredOverride !== '')
		{
			$withOverride = is_array($state['endpoints']) ? $state['endpoints'] : [];
			$withOverride[] = $preferredOverride;
			$state['endpoints'] = $this->normaliseAndSanitiseEndpoints($withOverride, $manual);
		}
		if (
			!$forceHealthRefresh
			&& $this->endpointCatalogChanged($previousEndpoints, is_array($state['endpoints']) ? $state['endpoints'] : [])
		)
		{
			$this->invalidateEndpointHealthState($state);
		}

		$lastHealth = (int) ($state['last_health_at'] ?? 0);
		$healthDay = (string) ($state['health_day'] ?? '');
		$healthRefreshInterval = $this->endpointHealthRefreshIntervalSeconds($state);

		if ($healthDay !== $dayKey || ($now - $lastHealth) > $healthRefreshInterval)
		{
			$latencies = [];
			$candidates = is_array($state['endpoints']) ? $state['endpoints'] : [];
			$candidates = array_values(array_unique(array_map(function ($u) {
				return $this->normaliseBaseUrl((string) $u);
			}, $candidates)));
			$candidates = array_filter($candidates, function ($u) {
				return $u !== '';
			});
			$candidates = array_values($candidates);
			if ($manual !== '' && !in_array($manual, $candidates, true) && $this->shouldProbeEndpointHealth($manual, $state))
			{
				$candidates[] = $manual;
			}
			$endpointMeta = is_array($state['endpoint_meta'] ?? null) ? $state['endpoint_meta'] : [];
			$probeHealthMs = $this->latencyMapFromState($state);
			$isBackup = $this->catalogBackupCallable();
			$candidates = \FfApiResilience::sortBasesByHealthyLatency($candidates, $probeHealthMs, $endpointMeta, $isBackup);

			$started = microtime(true);
			foreach ($candidates as $base)
			{
				if ((microtime(true) - $started) >= self::CONNECTION_TEST_TOTAL_BUDGET_SECONDS)
				{
					$state['health_timed_out'] = true;
					break;
				}
				if (!$this->shouldProbeEndpointHealth($base, $state))
				{
					continue;
				}
				$t0 = microtime(true);
				$timeout = max(1, $probeTimeout ?? \FfApiResilience::RUNTIME_CHECK_ENDPOINT_TIMEOUT_SECONDS);
				$hr = $this->rawRequest('GET', $base, '/health', [], $timeout);
				$ms = null;
				if (($hr['status'] ?? 0) >= 200 && ($hr['status'] ?? 0) < 300)
				{
					$ms = (int) round((microtime(true) - $t0) * 1000);
					$cr = $this->rawRequest('GET', $base, '/v1/check-ready', [], $timeout);
					$liveReady = ($cr['status'] ?? 0) >= 200 && ($cr['status'] ?? 0) < 300;
					if (!isset($endpointMeta[$base]) || !is_array($endpointMeta[$base]))
					{
						$endpointMeta[$base] = [];
					}
					$endpointMeta[$base]['check_ready'] = $liveReady;
					if (!$liveReady)
					{
						$ms = null;
					}
				}
				$latencies[$base] = $ms;
			}

			$state['endpoint_meta'] = $endpointMeta;
			$best = \FfApiResilience::resolvePreferredHealthyBase(
				array_keys($latencies),
				$latencies,
				$endpointMeta,
				$isBackup,
				$manual
			);
			$currentPreferred = $this->normaliseBaseUrl((string) ($state['preferred'] ?? $manual));
			$currentPreferredHealthy = \FfApiResilience::isHealthyLatency($latencies[$currentPreferred] ?? null);
			if ($currentPreferred !== '' && $best !== '' && $best !== $currentPreferred && $currentPreferredHealthy)
			{
				$candidate = $this->normaliseBaseUrl((string) ($state['preferred_candidate'] ?? ''));
				$streak = ($candidate === $best) ? ((int) ($state['preferred_candidate_streak'] ?? 0) + 1) : 1;
				$state['preferred_candidate'] = $best;
				$state['preferred_candidate_streak'] = $streak;
				if ($streak < 2)
				{
					$best = $currentPreferred;
				}
			}
			else
			{
				unset($state['preferred_candidate'], $state['preferred_candidate_streak']);
			}
			$bestMs = \FfApiResilience::isHealthyLatency($latencies[$best] ?? null)
				? (int) $latencies[$best]
				: 999999;
			$hasHealthy = false;
			foreach ($latencies as $ms)
			{
				if (\FfApiResilience::isHealthyLatency($ms))
				{
					$hasHealthy = true;
					break;
				}
			}
			$state['last_health_at'] = $now;
			$state['health_day'] = $dayKey;
			$state['health_ms'] = $latencies;
			$state['preferred'] = $best;
			$wasSlow = !empty($state['slow_health_mode']);
			$isSlow = !$hasHealthy
				|| $bestMs > self::ENDPOINT_HEALTH_SLOW_TRIGGER_MS
				|| ($wasSlow && $bestMs > self::ENDPOINT_HEALTH_RECOVERY_MS);
			$state['slow_health_mode'] = $isSlow;
			$state['best_latency_ms'] = $hasHealthy ? (int) $bestMs : 0;
			$state['refresh_requested_at'] = 0;
		}

		if ($preferredOverride !== '')
		{
			$state['preferred'] = $preferredOverride;
		}

		$this->saveEndpointState($state);
	}

	public function refreshEndpointsBeforeConnectionTest(?int $probeTimeout = null): void
	{
		$this->refreshEndpointCatalogAndHealth(true, $probeTimeout ?? self::CONNECTION_TEST_TIMEOUT_SECONDS);
	}

	/**
	 * Routing: catalog from control; health probes edges only; checks on check_ready edges;
	 * control for checks only when control_check_fallback or no healthy edge. api.ffapi.net is
	 * legacy shared DNS (edges proxy health/catalog); do not treat control as down when two edges
	 * are healthy (see edgesHealthyForCheckTraffic / shouldProbeEndpointHealth).
	 */
	protected function baseUrlMayServeCheckTraffic(string $baseUrl): bool
	{
		$baseUrl = $this->normaliseBaseUrl($baseUrl);
		if ($baseUrl === '')
		{
			return false;
		}
		$control = $this->getControlPlaneBaseUrl();
		if ($control !== '' && $baseUrl === $control)
		{
			$state = $this->loadEndpointState();
			return !empty($state['control_check_fallback']) || !$this->edgesHealthyForCheckTraffic($state);
		}
		$manual = $this->getManualBaseUrl();
		if ($manual !== '' && $baseUrl === $manual)
		{
			$host = parse_url($manual, PHP_URL_HOST);
			if (is_string($host) && strpos(strtolower($host), 'api.') === 0)
			{
				return false;
			}
		}

		return true;
	}

	protected function hasHealthyEdgeForSupernodeSync(array $state): bool
	{
		$endpointMeta = is_array($state['endpoint_meta'] ?? null) ? $state['endpoint_meta'] : [];
		foreach ($this->latencyMapFromState($state) as $base => $ms)
		{
			if (!is_int($ms))
			{
				continue;
			}
			$meta = isset($endpointMeta[$base]) && is_array($endpointMeta[$base]) ? $endpointMeta[$base] : [];
			$role = isset($meta['role']) ? (string) $meta['role'] : null;
			if ($this->isCatalogBackupEndpointUrl($base, $role))
			{
				continue;
			}
			if ($this->isSharedApiRoundRobinBase($base))
			{
				continue;
			}

			return true;
		}

		return false;
	}

	/**
	 * @return list<string>
	 */
	protected function getOrderedBasesForRequests(?string $requestPath = null): array
	{
		$manual = $this->getManualBaseUrl();
		if ($manual === '')
		{
			return [];
		}
		$state = $this->loadEndpointState();
		if (is_string($requestPath) && strpos($requestPath, '/v1/check') === 0 && \FfApiResilience::apiRegionIsLocked($this->getApiRegion()) && !$this->isOfflineApiKey())
		{
			return \FfApiResilience::regionLockedCheckBases($this->getApiRegion(), $this->allowGlobalEmergencyFallback());
		}
		if (
			is_string($requestPath)
			&& strpos($requestPath, '/v1/check') === 0
			&& $this->isOfflineApiKey()
		)
		{
			$pinned = \FfApiResilience::offlinePinnedCheckBases($state);
			if ($pinned)
			{
				return $pinned;
			}
		}
		if (
			is_string($requestPath)
			&& (
				\FfApiResilience::isStrictSupernodeSyncPath($requestPath)
				|| \FfApiResilience::isControlPlanePreferredPath($requestPath)
			)
		)
		{
			return \FfApiResilience::moderationSyncBasesOrdered(
				$this->getHotFailoverApiBaseUrl(),
				$this->getControlPlaneBaseUrl()
			) ?: [\FfApiResilience::hotFailoverApiBaseUrl('', '')];
		}
		if (is_string($requestPath) && strpos($requestPath, '/v1/check') === 0)
		{
			return $this->runtimeCheckBasesOrdered($state, $manual);
		}
		$endpoints = $state['endpoints'] ?? null;
		if (!is_array($endpoints) || !$endpoints)
		{
			$endpoints = [$manual];
		}
		$endpoints = array_values(array_unique(array_map(function ($u) {
			return $this->normaliseBaseUrl((string) $u);
		}, $endpoints)));
		$endpoints = array_filter($endpoints, function ($u) {
			return $u !== '';
		});
		$endpoints = array_values($endpoints);
		$endpointMeta = is_array($state['endpoint_meta'] ?? null) ? $state['endpoint_meta'] : [];
		$latencyByBase = $this->latencyMapFromState($state);
		$isBackup = $this->catalogBackupCallable();
		$sorted = \FfApiResilience::sortBasesByHealthyLatency($endpoints, $latencyByBase, $endpointMeta, $isBackup);
		$preferredOverride = $this->getPreferredBaseOverride();
		$routingFallback = in_array($manual, $endpoints, true) ? $manual : ($sorted[0] ?? $manual);
		$computedPreferred = \FfApiResilience::resolvePreferredHealthyBase(
			$sorted,
			$latencyByBase,
			$endpointMeta,
			$isBackup,
			$routingFallback
		);
		$preferred = $preferredOverride !== '' ? $preferredOverride : $computedPreferred;
		$preferredCandidate = $preferred;
		if ($preferred === '' || !in_array($preferred, $endpoints, true))
		{
			$state['preferred_missing'] = $preferredCandidate;
			$state['preferred_missing_at'] = time();
			$state['refresh_requested_at'] = time();
			$preferred = $sorted[0] ?? $manual;
			if ($preferredOverride === '')
			{
				$state['preferred'] = $preferred;
			}
			$this->saveEndpointState($state);
		}
		else if (isset($state['preferred_missing']))
		{
			unset($state['preferred_missing']);
			unset($state['preferred_missing_at']);
			$this->saveEndpointState($state);
		}

		$out = \FfApiResilience::uniqueOrderedBases(
			$sorted,
			$preferred !== '' ? [$preferred] : []
		);
		if (!in_array($manual, $out, true))
		{
			$out[] = $manual;
		}
		if (
			is_string($requestPath)
			&& \FfApiResilience::isEdgePreferredReadPath($requestPath)
			&& $this->hasHealthyEdgeForSupernodeSync($state)
		)
		{
			$out = \FfApiResilience::filterOrderedBasesForReachableEdges(
				$out,
				$this->catalogBackupCallable(),
				$this->getControlPlaneBaseUrl(),
				$this->getHotFailoverApiBaseUrl()
			);
		}
		return $out;
	}

	/** @return list<string> */
	protected function runtimeCheckBasesOrdered(array $state, string $manual): array
	{
		$preferredOverride = $this->getPreferredBaseOverride();
		$preferred = $this->normaliseBaseUrl((string) ($preferredOverride !== '' ? $preferredOverride : ($state['preferred'] ?? $manual)));
		if (!$this->isTrustedEndpointBase($preferred))
		{
			$preferred = $manual;
		}
		$existing = [];
		foreach (is_array($state['endpoints'] ?? null) ? $state['endpoints'] : [] as $base)
		{
			$base = $this->normaliseBaseUrl((string) $base);
			if ($base !== '' && $this->isTrustedEndpointBase($base))
			{
				$existing[] = $base;
			}
		}
		$healthMs = $this->latencyMapFromState($state);
		$endpointMeta = is_array($state['endpoint_meta'] ?? null) ? $state['endpoint_meta'] : [];
		$isBackup = $this->catalogBackupCallable();
		$existing = \FfApiResilience::sortBasesByHealthyLatency(
			$existing,
			$healthMs,
			$endpointMeta,
			$isBackup
		);
		$existing = array_values(array_filter($existing, function ($base) use ($healthMs, $endpointMeta) {
			$health = array_key_exists($base, $healthMs) && is_int($healthMs[$base])
				? $healthMs[$base]
				: null;
			return \FfApiResilience::endpointEligibleForCheckTraffic($endpointMeta, (string) $base, $health);
		}));

		$hotApi = $this->getHotFailoverApiBaseUrl();
		$control = $this->getControlPlaneBaseUrl();
		$controlFallback = $control !== '' && $this->baseUrlMayServeCheckTraffic($control)
			? [$control]
			: [];
		$ordered = \FfApiResilience::uniqueOrderedBases(
			$preferred !== '' ? [$preferred] : [],
			$existing,
			$hotApi !== '' ? [$hotApi] : [],
			$controlFallback
		);
		$unsuppressed = array_values(array_filter($ordered, function ($base) use ($state) {
			return !$this->isEndpointSuppressed((string) $base, $state);
		}));

		return $unsuppressed ?: $ordered;
	}

	protected function isEndpointSuppressed(string $baseUrl, ?array $state = null): bool
	{
		$baseUrl = $this->normaliseBaseUrl($baseUrl);
		if ($baseUrl === '')
		{
			return false;
		}
		$state = $state ?? $this->loadEndpointState();
		$suppressed = is_array($state['suppressed_endpoints'] ?? null) ? $state['suppressed_endpoints'] : [];
		$until = (int) ($suppressed[$baseUrl] ?? 0);
		return $until > time();
	}

	/**
	 * @param list<mixed> $endpoints
	 * @return list<string>
	 */
	protected function normaliseAndSanitiseEndpoints(array $endpoints, string $manualBase): array
	{
		$manualBase = $this->normaliseBaseUrl($manualBase);
		$normalised = array_values(array_unique(array_map(function ($u) {
			return $this->normaliseBaseUrl((string) $u);
		}, $endpoints)));
		$normalised = array_values(array_filter($normalised, function ($u) {
			return $u !== '' && $this->isTrustedEndpointBase($u);
		}));
		if ($manualBase !== '' && count($normalised) > 1)
		{
			$normalised = array_values(array_filter($normalised, function ($u) use ($manualBase) {
				return $u !== $manualBase;
			}));
		}
		if (!$normalised && $manualBase !== '')
		{
			$normalised = [$manualBase];
		}
		return $normalised;
	}

	protected function isTrustedEndpointBase(string $baseUrl): bool
	{
		$baseUrl = $this->normaliseBaseUrl($baseUrl);
		if ($baseUrl === '')
		{
			return false;
		}
		$host = parse_url($baseUrl, PHP_URL_HOST);
		if (!is_string($host) || trim($host) === '')
		{
			return false;
		}
		$host = strtolower(trim($host));
		foreach ([$this->getControlPlaneBaseUrl(), $this->getHotFailoverApiBaseUrl(), $this->getManualBaseUrl()] as $trustedBase)
		{
			$trustedHost = parse_url($this->normaliseBaseUrl((string) $trustedBase), PHP_URL_HOST);
			if (!is_string($trustedHost) || trim($trustedHost) === '')
			{
				continue;
			}
			$trustedHost = strtolower(trim($trustedHost));
			if ($host === $trustedHost)
			{
				return true;
			}
			$parts = explode('.', $trustedHost);
			if (count($parts) >= 2)
			{
				$root = implode('.', array_slice($parts, -2));
				if ($host === $root || str_ends_with($host, '.' . $root))
				{
					return true;
				}
			}
		}

		return false;
	}

	protected function markPreferredBaseAfterFailover(string $baseUrl): void
	{
		$baseUrl = $this->normaliseBaseUrl($baseUrl);
		if ($baseUrl === '')
		{
			return;
		}
		if ($this->getPreferredBaseOverride() !== '')
		{
			return;
		}
		$state = $this->loadEndpointState();
		$suppressed = is_array($state['suppressed_endpoints'] ?? null) ? $state['suppressed_endpoints'] : [];
		$clearedSuppression = array_key_exists($baseUrl, $suppressed);
		if ($clearedSuppression)
		{
			unset($suppressed[$baseUrl]);
			$state['suppressed_endpoints'] = $suppressed;
		}
		$currentPreferred = $this->normaliseBaseUrl((string) ($state['preferred'] ?? ''));
		if ($currentPreferred === $baseUrl)
		{
			if ($clearedSuppression)
			{
				$this->saveEndpointState($state);
			}
			return;
		}
		$state['preferred'] = $baseUrl;
		unset($state['preferred_candidate'], $state['preferred_candidate_streak']);
		$state['failover_at'] = time();
		$state['failover_base'] = $baseUrl;
		$this->saveEndpointState($state);
		$this->log('info', 'Forum Fortress API failover endpoint answered', [
			'base' => $baseUrl,
		]);
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
		$result = $this->requestWithRetryPass(
			$method,
			$path,
			$payload,
			$allowRebootstrap,
			$timeoutOverride,
			$suppressTimeoutError,
			$timeoutRetryAttempted
		);
		if ($result !== null)
		{
			return $result;
		}
		if (strpos($path, '/v1/check') === 0)
		{
			return null;
		}
		try
		{
			$this->refreshEndpointCatalogAndHealth(true);
		}
		catch (\Throwable $e)
		{
			// Second pass uses refreshed catalog; stale state must not block checks.
		}

		$result = $this->requestWithRetryPass(
			$method,
			$path,
			$payload,
			false,
			$timeoutOverride,
			$suppressTimeoutError,
			$timeoutRetryAttempted
		);
		if ($result !== null)
		{
			return $result;
		}
		return null;
	}

	/**
	 * All plugin API calls: preferred edge, remaining edges by measured latency, shared API,
	 * then an eligible control fallback. Runtime checks use a short per-base timeout and total budget.
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
		if ($isContactPageCheck && !\FfApiResilience::apiRegionIsLocked($this->getApiRegion()))
		{
			$bases = \FfApiResilience::contactPageCheckBasesOrdered($bases, $this->getHotFailoverApiBaseUrl());
		}
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
				if ($isCheck)
				{
					$this->markPreferredBaseAfterFailover($baseUrl);
				}
				return $data;
			}
			if (empty($attempt['failover']))
			{
				return null;
			}
		}
		if ($this->runtimeCheckBudgetExceeded($startedAt, $enforceCheckBudget))
		{
			return null;
		}

		if ($isCheck && \FfApiResilience::apiRegionIsLocked($this->getApiRegion()))
		{
			return null;
		}

		return $this->requestControlCheckFallbackAfterEdges(
			$method,
			$path,
			$payload,
			$tried,
			$attemptTimeout,
			$suppressTimeoutError,
			$timeoutRetryAttempted,
			$startedAt,
			$enforceCheckBudget
		);
	}

	protected function runtimeCheckBudgetExceeded(float $startedAt, bool $enforce): bool
	{
		return $enforce
			&& (microtime(true) - $startedAt) >= \FfApiResilience::RUNTIME_CHECK_TOTAL_BUDGET_SECONDS;
	}

	/**
	 * Last-resort control-plane check request when catalog allows fallback and edges did not answer.
	 *
	 * @param list<string> $tried
	 */
	protected function requestControlCheckFallbackAfterEdges(
		string $method,
		string $path,
		array $payload,
		array $tried,
		?int $timeoutOverride,
		bool $suppressTimeoutError,
		bool $timeoutRetryAttempted,
		float $startedAt,
		bool $enforceCheckBudget
	): ?array {
		if (strpos($path, '/v1/check') !== 0)
		{
			return null;
		}
		$state = $this->loadEndpointState();
		if (empty($state['control_check_fallback']))
		{
			return null;
		}
		$control = $this->normaliseBaseUrl($this->getControlPlaneBaseUrl());
		if ($control === '' || in_array($control, $tried, true))
		{
			return null;
		}
		if (!$this->baseUrlMayServeCheckTraffic($control))
		{
			return null;
		}
		if ($this->runtimeCheckBudgetExceeded($startedAt, $enforceCheckBudget))
		{
			return null;
		}
		$attempt = $this->requestWithRetryOnBase(
			$method,
			$path,
			$payload,
			$control,
			false,
			$timeoutOverride,
			$suppressTimeoutError,
			$timeoutRetryAttempted
		);
		if ($attempt['outcome'] === 'success')
		{
			/** @var array $data */
			$data = $attempt['data'];
			$this->markPreferredBaseAfterFailover($control);

			return $data;
		}

		return null;
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
					return ['outcome' => 'failed', 'failover' => false];
				}
				if (\FfApiResilience::shouldStopEndpointFailoverForStatus((int) $status))
				{
					return ['outcome' => 'failed', 'failover' => false];
				}
				if ($allowRebootstrap && $this->shouldRebootstrap($status, $body, $path))
				{
					$this->resetIdentity();
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
				}

				$this->recordEndpointFailureAndRequestRefresh('non_success_status', $baseUrl, $path, (int) $status);
				return ['outcome' => 'failed', 'failover' => \FfApiResilience::shouldFailoverOnEndpointStatus((int) $status)];
			}

			$data = json_decode($body, true);
			if (!is_array($data))
			{
				$this->recordEndpointFailureAndRequestRefresh('invalid_json', $baseUrl, $path, (int) $status);
				return ['outcome' => 'failed', 'failover' => true];
			}

			$this->log('info', 'Forum Fortress API request completed', [
				'path' => $path,
				'status' => $status,
				'base' => $baseUrl,
			]);
			$nodeHeader = trim((string) $response->getHeaderLine('X-ForumFortress-Node'));
			$state = $this->loadEndpointState();
			$state['last_responded'] = $baseUrl;
			$state['last_responded_node'] = $nodeHeader;
			$state['last_response_at'] = time();
			$this->saveEndpointState($state);

			$this->maybeRefreshEndpointCatalogAfterCheckIn($path);

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
				$this->recordEndpointFailureAndRequestRefresh('timeout', $baseUrl, $path, null, $message);
				$this->logContactPageTimeoutIfThrottled($path, $payload, $baseUrl, $message);
				return ['outcome' => 'failed', 'failover' => true];
			}

			if ($isConnectionFailure && \FfApiResilience::shouldUseContactPageRouting($path))
			{
				if ($isTimeout)
				{
					$this->lastCheckHadTimeout = true;
				}
				$this->recordEndpointFailureAndRequestRefresh(
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
				$this->recordEndpointFailureAndRequestRefresh('connection_failure', $baseUrl, $path, null, $message);
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

	protected function recordEndpointFailureAndRequestRefresh(
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
		$state['refresh_requested_at'] = $failure['at'];
		$suppressed = is_array($state['suppressed_endpoints'] ?? null) ? $state['suppressed_endpoints'] : [];
		$retryable = $status === null || \FfApiResilience::shouldFailoverOnEndpointStatus((int) $status);
		if ($retryable)
		{
			$suppressed[$failure['base']] = $failure['at'] + \FfApiResilience::ENDPOINT_SUPPRESSION_SECONDS;
		}
		foreach ($suppressed as $base => $until)
		{
			if ((int) $until <= $failure['at'])
			{
				unset($suppressed[$base]);
			}
		}
		$state['suppressed_endpoints'] = $suppressed;
		$this->saveEndpointState($state);
	}

	/** @param array<string, mixed> $state */
	protected function endpointHealthRefreshIntervalSeconds(array $state): int
	{
		$bestLatency = (int) ($state['best_latency_ms'] ?? 0);
		$slowMode = !empty($state['slow_health_mode']);
		if ($slowMode)
		{
			if ($bestLatency > 0 && $bestLatency <= self::ENDPOINT_HEALTH_RECOVERY_MS)
			{
				return self::ENDPOINT_HEALTH_REFRESH_SECONDS;
			}
			return self::ENDPOINT_HEALTH_DEGRADED_REFRESH_SECONDS;
		}
		if ($bestLatency > self::ENDPOINT_HEALTH_SLOW_TRIGGER_MS)
		{
			return self::ENDPOINT_HEALTH_DEGRADED_REFRESH_SECONDS;
		}
		return self::ENDPOINT_HEALTH_REFRESH_SECONDS;
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

		if ($status !== 401)
		{
			return false;
		}

		if ($path === '/v1/site/bootstrap' || trim($this->getStringOption('ffProtectApiKey')) === '')
		{
			return false;
		}

		$data = json_decode($body, true);
		if (!is_array($data))
		{
			return false;
		}

		// Backend can emit either a flat body ({"error": "...", "message": "..."},
		// produced by our HTTPException handler) or the FastAPI default
		// ({"detail": {...}} or {"detail": "..."}). Match both shapes.
		$candidates = [];
		if (isset($data['error']))
		{
			$candidates[] = strtolower(trim((string) $data['error']));
		}
		$detail = $data['detail'] ?? null;
		if (is_array($detail) && isset($detail['error']))
		{
			$candidates[] = strtolower(trim((string) $detail['error']));
		}
		foreach ($candidates as $candidate)
		{
			if (in_array($candidate, ['invalid_key', 'unknown_site', 'invalid_key_format'], true))
			{
				return true;
			}
		}

		$plainDetail = is_string($detail) ? strtolower(trim($detail)) : '';
		return in_array($plainDetail, ['invalid api key', 'site not found'], true);
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
