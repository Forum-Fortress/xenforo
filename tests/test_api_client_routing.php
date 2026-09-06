<?php

declare(strict_types=1);

namespace XF {
	class App
	{
	}
}

namespace {
	require_once __DIR__ . '/../upload/src/addons/ForumFortress/Protect/Service/ApiClient.php';
	require_once __DIR__ . '/../upload/src/addons/ForumFortress/Protect/Service/DecisionMapper.php';

	use ForumFortress\Protect\Service\ApiClient;
	use ForumFortress\Protect\Service\DecisionMapper;

	final class RoutingProbeClient extends ApiClient
	{
		/** @var list<string> */
		public array $bases = [];
		/** @var array<string, array{outcome: string, data?: array, failover?: bool}> */
		public array $responses = [];
		/** @var list<array{base: string, timeout: ?int}> */
		public array $attempts = [];
		/** @var list<array{base: string, path: string}> */
		public array $rawAttempts = [];
		/** @var array<string, array{status: int, data?: array}> */
		public array $rawResponses = [];
		/** @var array<string, mixed> */
		public array $state = [];
		public int $budgetAfterAttempts = PHP_INT_MAX;
		public bool $failOpen = true;
		public string $apiKey = 'ff_test_super_secret';
		public string $apiRegion = 'global';
		public bool $allowGlobalFallback = false;

		public function runPass(string $path, array $payload = [], ?int $timeout = null): ?array
		{
			return $this->requestWithRetryPass('POST', $path, $payload, true, $timeout, false, false);
		}

		public function runHealth(?int $timeout = null): ?array
		{
			return $this->health($timeout);
		}

		/** @return list<string> */
		public function orderedCheckBases(array $state, string $manual): array
		{
			$this->state = $state;
			return parent::getOrderedBasesForRequests('/v1/check/register');
		}

		/** @return list<string> */
		public function configuredCheckBases(): array
		{
			return parent::getOrderedBasesForRequests('/v1/check/register');
		}

		/** @return array{query: array<string, mixed>, headers: array<string, string>} */
		public function getAuthTransportParts(array $query): array
		{
			$headers = ['Accept' => 'application/json'];
			$this->moveQueryApiKeyToHeader($query, $headers);

			return ['query' => $query, 'headers' => $headers];
		}

		public function redact(string $value): string
		{
			return $this->redactSensitiveText($value);
		}

		public function normaliseEndpoint(string $value): string
		{
			return $this->normaliseBaseUrl($value);
		}

		protected function getOrderedBasesForRequests(?string $requestPath = null): array
		{
			return $this->bases;
		}

		protected function requestWithRetryOnBase(
			string $method,
			string $path,
			array $payload,
			string $baseUrl,
			bool $allowRebootstrap,
			?int $timeoutOverride = null,
			bool $suppressTimeoutError = false,
			bool $timeoutRetryAttempted = false
		): array {
			$this->attempts[] = ['base' => $baseUrl, 'timeout' => $timeoutOverride];

			return $this->responses[$baseUrl] ?? ['outcome' => 'failed', 'failover' => true];
		}

		protected function rawRequest(
			string $method,
			string $baseUrl,
			string $path,
			array $payload,
			int $timeout
		): array {
			$this->rawAttempts[] = ['base' => $baseUrl, 'path' => $path];
			$response = $this->rawResponses[$baseUrl . $path] ?? ['status' => 0];
			return ['status' => $response['status'], 'data' => $response['data'] ?? null, 'body' => '', 'error' => null];
		}

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
			return null;
		}

		protected function runtimeCheckBudgetExceeded(float $startedAt, bool $enforce): bool
		{
			return $enforce && count($this->attempts) >= $this->budgetAfterAttempts;
		}

		protected function loadEndpointState(): array
		{
			return $this->state;
		}

		protected function saveEndpointState(array $state): void
		{
			$this->state = $state;
		}

		protected function isTrustedEndpointBase(string $baseUrl): bool
		{
			return str_starts_with($baseUrl, 'https://');
		}

		protected function catalogBackupCallable(): callable
		{
			return static fn (string $base, ?string $role): bool => $role === 'backup';
		}

		protected function getHotFailoverApiBaseUrl(): string
		{
			return 'https://api.ffapi.net';
		}

		protected function getControlPlaneBaseUrl(): string
		{
			return 'https://fortress.ffapi.net';
		}

		protected function baseUrlMayServeCheckTraffic(string $baseUrl): bool
		{
			return true;
		}

		protected function log(string $level, string $message, array $context = []): void
		{
		}

		public function getIntOption(string $key, int $default = 0): int
		{
			return 5;
		}

		public function getBoolOption(string $key, bool $default = false): bool
		{
			if ($key === 'ffProtectEnabled')
			{
				return true;
			}
			if ($key === 'ffProtectFailOpen')
			{
				return $this->failOpen;
			}
			if ($key === 'ffProtectAllowGlobalFallback')
			{
				return $this->allowGlobalFallback;
			}
			return $default;
		}

		public function getStringOption(string $key, string $default = ''): string
		{
			if ($key === 'ffProtectApiKey')
			{
				return $this->apiKey;
			}
			if ($key === 'ffProtectApiRegion')
			{
				return $this->apiRegion;
			}
			return $default;
		}
	}

	function assertSameValue(mixed $expected, mixed $actual, string $label): void
	{
		if ($expected !== $actual)
		{
			fwrite(STDERR, $label . "\nexpected: " . var_export($expected, true) . "\nactual: " . var_export($actual, true) . "\n");
			exit(1);
		}
	}

	$client = new RoutingProbeClient(new \XF\App());
	$client->bases = ['https://edge-a.ffapi.net', 'https://edge-b.ffapi.net', 'https://api.ffapi.net'];
	$client->responses['https://edge-b.ffapi.net'] = [
		'outcome' => 'success',
		'data' => ['decision' => 'allow'],
	];
	$client->failOpen = false;
	assertSameValue(['decision' => 'allow'], $client->runPass('/v1/check/post'), 'fail-closed mode still reaches a healthy fallback');
	assertSameValue(
		[
			['base' => 'https://edge-a.ffapi.net', 'timeout' => 1],
			['base' => 'https://edge-b.ffapi.net', 'timeout' => 1],
		],
		$client->attempts,
		'standard checks use one-second attempts in order'
	);
	assertSameValue('', $client->state['preferred'] ?? '', 'successful fallback is not promoted');

	$budgetClient = new RoutingProbeClient(new \XF\App());
	$budgetClient->bases = [
		'https://edge-a.ffapi.net',
		'https://edge-b.ffapi.net',
		'https://edge-c.ffapi.net',
		'https://api.ffapi.net',
	];
	$budgetClient->budgetAfterAttempts = 2;
	assertSameValue(null, $budgetClient->runPass('/v1/check/post'), 'exhausted check budget returns no result');
	assertSameValue(2, count($budgetClient->attempts), 'total budget stops additional attempts');

	$orderClient = new RoutingProbeClient(new \XF\App());
	$state = [
		'preferred' => 'https://edge-a.ffapi.net',
		'endpoints' => [
			'https://edge-a.ffapi.net',
			'https://edge-b.ffapi.net',
			'https://edge-c.ffapi.net',
		],
		'health_ms' => [
			'https://edge-a.ffapi.net' => 50,
			'https://edge-b.ffapi.net' => 20,
			'https://edge-c.ffapi.net' => 35,
		],
		'endpoint_meta' => [
			'https://edge-a.ffapi.net' => ['check_ready' => true, 'role' => 'edge'],
			'https://edge-b.ffapi.net' => ['check_ready' => true, 'role' => 'edge'],
			'https://edge-c.ffapi.net' => ['check_ready' => true, 'role' => 'edge'],
		],
	];
	assertSameValue(
		[
			'https://api.ffapi.net',
			'https://edge-a.ffapi.net',
			'https://edge-b.ffapi.net',
			'https://edge-c.ffapi.net',
		],
		$orderClient->orderedCheckBases($state, 'https://api.ffapi.net'),
		'GeoDNS is first and catalog edges retain server order as fallbacks'
	);

	$state['suppressed_endpoints'] = ['https://edge-a.ffapi.net' => time() + 120];
	assertSameValue(
		'https://api.ffapi.net',
		$orderClient->orderedCheckBases($state, 'https://api.ffapi.net')[0] ?? '',
		'cached endpoint suppression cannot displace the GeoDNS primary'
	);

	$regionalClient = new RoutingProbeClient(new \XF\App());
	assertSameValue('', $regionalClient->normaliseEndpoint('http://api-eu.ffapi.net'), 'HTTP API endpoints are rejected');
	assertSameValue('', $regionalClient->normaliseEndpoint('http://127.0.0.1:9081'), 'local HTTP API endpoints are rejected');
	assertSameValue('https://api-eu.ffapi.net', $regionalClient->normaliseEndpoint('https://api-eu.ffapi.net/'), 'HTTPS API endpoints are normalized');
	$regionalClient->apiRegion = 'eu';
	assertSameValue(
		['https://api-eu.ffapi.net'],
		$regionalClient->configuredCheckBases(),
		'EU lock ignores the all-region catalog and control plane'
	);
	$regionalClient->allowGlobalFallback = true;
	assertSameValue(
		['https://api-eu.ffapi.net', 'https://api.ffapi.net'],
		$regionalClient->configuredCheckBases(),
		'EU emergency fallback adds only the global hostname after the regional hostname'
	);
	$regionalClient->rawResponses = [
		'https://api-eu.ffapi.net/health' => ['status' => 200, 'data' => ['node_id' => 'fra']],
	];
	assertSameValue(['node_id' => 'fra'], $regionalClient->runHealth(2), 'EU connection test probes the selected regional endpoint');
	assertSameValue(
		[
			['base' => 'https://api-eu.ffapi.net', 'path' => '/health'],
		],
		$regionalClient->rawAttempts,
		'EU connection test performs only the public health probe within the lock'
	);

	$transport = $orderClient->getAuthTransportParts([
		'api_key' => $orderClient->apiKey,
		'domain' => 'forum.example',
	]);
	assertSameValue(
		['domain' => 'forum.example'],
		$transport['query'],
		'GET API key is removed from the query string'
	);
	assertSameValue(
		$orderClient->apiKey,
		$transport['headers']['X-FF-Key'] ?? null,
		'GET API key is sent in the X-FF-Key header'
	);
	$redacted = $orderClient->redact(
		'https://api.ffapi.net/v1/site/status?api_key=' . $orderClient->apiKey
			. ' Authorization: Bearer another-secret X-FF-Key=third-secret'
	);
	assertSameValue(false, str_contains($redacted, $orderClient->apiKey), 'configured API key is redacted');
	assertSameValue(false, str_contains($redacted, 'another-secret'), 'bearer credential is redacted');
	assertSameValue(false, str_contains($redacted, 'third-secret'), 'X-FF-Key credential is redacted');

	assertSameValue('allowed', DecisionMapper::toUnavailableDecision(true), 'fail-open availability failure is allowed');
	assertSameValue('moderated', DecisionMapper::toUnavailableDecision(false), 'fail-closed availability failure is moderated');
	assertSameValue('moderated', DecisionMapper::toContentDecision(null, false), 'null content response is moderated fail-closed');
	assertSameValue('moderated', DecisionMapper::toUserDecision(['decision' => 'unexpected'], false), 'invalid API decision is moderated fail-closed');
	assertSameValue('allowed', DecisionMapper::toContentDecision(['status_code' => 'ABOVELIMIT', 'decision' => 'block'], true), 'above-limit response follows fail-open policy');
	assertSameValue('moderated', DecisionMapper::toContentDecision(['status_code' => 'ABOVELIMIT', 'decision' => 'block'], false), 'above-limit response is moderated fail-closed');
	assertSameValue('denied', DecisionMapper::toContentDecision(['decision' => 'block'], true), 'explicit API block remains denied');
	assertSameValue(true, DecisionMapper::shouldRecordDecision(['decision' => 'allow', 'decision_id' => 41], 'allowed'), 'allowed decision may correlate a later approval row');
	assertSameValue(false, DecisionMapper::shouldRecordDecision(['decision' => 'block', 'decision_id' => 42], 'denied'), 'denied decision is never deferred to an approval row');
	assertSameValue(false, DecisionMapper::shouldRecordDecision(['decision' => 'unexpected', 'decision_id' => 43], 'moderated'), 'malformed decision ID is not recorded');
	assertSameValue(true, DecisionMapper::requiresAvailabilityRecovery(['decision' => 'unexpected', 'decision_id' => 43], 'moderated'), 'malformed fail-closed response receives recovery metadata');
	assertSameValue(true, DecisionMapper::requiresAvailabilityRecovery(['status_code' => 'ABOVELIMIT', 'decision' => 'block', 'decision_id' => 44], 'moderated'), 'above-limit fail-closed response receives recovery metadata despite a decision ID');

	echo "OK XenForo ApiClient routing\n";
}
