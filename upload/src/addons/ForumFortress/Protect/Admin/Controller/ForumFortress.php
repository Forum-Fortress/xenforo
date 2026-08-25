<?php

namespace ForumFortress\Protect\Admin\Controller;

use ForumFortress\Protect\Service\ApiClient;
use XF\Admin\Controller\AbstractController;
use XF\Mvc\ParameterBag;

class ForumFortress extends AbstractController
{
	protected function preDispatchController($action, ParameterBag $params)
	{
		$this->assertAdminPermission('ffProtect');
	}

	public function actionIndex()
	{
		$this->setSectionContext('ffProtect');

		$client = new ApiClient($this->app());
		$didBootstrap = false;
		$autoBootstrap = null;
		if ($client->isEnabled() && trim($client->getStringOption('ffProtectApiKey')) === '')
		{
			try
			{
				$autoBootstrap = $client->bootstrapIfNeeded();
				$didBootstrap = is_array($autoBootstrap);
			}
			catch (\Throwable $e)
			{
				$autoBootstrap = ['error' => $e->getMessage()];
			}
		}
		$siteStatus = $client->cachedSiteStatus();
		// Older installations may have a cached status payload from before the
		// additive stats field existed. Refresh only in that case so the normal
		// overview remains cache-backed.
		if (!is_array($siteStatus['stats'] ?? null))
		{
			$siteStatus = $client->siteStatus(2) ?: $siteStatus;
		}
		// Site status carries the additive usage summary; the direct stats call is
		// retained only as compatibility fallback for older API deployments.
		$forumStats = is_array($siteStatus) && is_array($siteStatus['stats'] ?? null)
			? $siteStatus['stats']
			: $client->forumStats(2);
		$pluginRelease = null;
		$moderationLaunchUrl = null;
		$endpointSummary = $client->endpointStateSummary();
		$endpointSnapshot = $client->endpointStateSnapshot();
		$endpointLatencyRows = $client->buildEndpointLatencyRows();

		$viewParams = [
			'enabled' => $client->isEnabled(),
			'apiBaseUrl' => $client->getStringOption('ffProtectApiBaseUrl'),
			'apiKeyMasked' => $this->maskKey($client->getStringOption('ffProtectApiKey')),
			'siteId' => $client->getStringOption('ffProtectSiteId'),
			'domain' => $client->getDomain(),
			'siteStatus' => $siteStatus,
			'forumStats' => $forumStats,
			'pluginRelease' => $pluginRelease,
			'pluginVersion' => ApiClient::PLUGIN_VERSION,
			'moderationLaunchUrl' => $moderationLaunchUrl,
			'preferredEndpoint' => (string) ($endpointSummary['preferred'] ?? ''),
			'preferredMissingEndpoint' => (string) ($endpointSummary['preferred_missing'] ?? ''),
			'lastRespondedEndpoint' => (string) ($endpointSummary['last_responded'] ?? ''),
			'endpointState' => $endpointSnapshot,
			'endpointLatencyRows' => $endpointLatencyRows,
			'result' => $this->session()->ffProtectLastTest ?: null,
			'attackModeResult' => $this->session()->ffProtectAttackModeResult ?: null,
			'registerResult' => $this->session()->ffProtectRegisterResult ?: null,
			'portalResult' => $this->session()->ffProtectPortalResult ?: null,
			'autoBootstrap' => $autoBootstrap,
		];
		$this->session()->ffProtectAttackModeResult = null;
		$this->session()->ffProtectRegisterResult = null;
		$this->session()->ffProtectPortalResult = null;

		return $this->view('', 'ff_protect_overview', $viewParams);
	}

	public function actionPortal()
	{
		$this->assertPostOnly();

		$client = new ApiClient($this->app());
		$result = [
			'status' => 'no_response',
			'error' => null,
		];

		try
		{
			$payload = $client->portalLaunch(2);
			$portalUrl = is_array($payload) ? trim((string) ($payload['portal_url'] ?? '')) : '';
			if ($this->isExpectedPortalUrl($portalUrl, $client))
			{
				return $this->redirect($portalUrl);
			}
			$result['error'] = 'Forum Fortress did not return a valid portal login URL.';
		}
		catch (\Throwable $e)
		{
			$result['status'] = 'error';
			$result['error'] = $e->getMessage();
		}

		$this->session()->ffProtectPortalResult = $result;
		return $this->redirect($this->buildLink('forum-fortress'));
	}

	protected function isExpectedPortalUrl(string $portalUrl, ApiClient $client): bool
	{
		if ($portalUrl === '' || filter_var($portalUrl, FILTER_VALIDATE_URL) === false)
		{
			return false;
		}

		$parts = parse_url($portalUrl);
		if (!is_array($parts))
		{
			return false;
		}

		$host = strtolower((string) ($parts['host'] ?? ''));
		$path = '/' . ltrim((string) ($parts['path'] ?? ''), '/');
		if (
			strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
			|| $host === ''
			|| isset($parts['user'])
			|| isset($parts['pass'])
			|| rtrim($path, '/') !== '/access'
		)
		{
			return false;
		}

		$query = [];
		parse_str((string) ($parts['query'] ?? ''), $query);
		if (!isset($query['token']) || !is_string($query['token']) || trim($query['token']) === '')
		{
			return false;
		}

		$expectedHosts = array_map(
			'strtolower',
			array_filter($this->candidatePortalHosts($client))
		);
		return in_array($host, $expectedHosts, true)
			|| hash_equals((string) $client->getAuthenticatedPortalUrl(), $portalUrl);
	}

	/** @return list<string> */
	protected function candidatePortalHosts(ApiClient $client): array
	{
		$hosts = [];
		foreach ([$client->getStringOption('ffProtectApiBaseUrl'), $client->getStringOption('ffProtectControlBaseUrl')] as $candidate)
		{
			$derived = $this->derivePortalHost((string) $candidate);
			if ($derived !== '')
			{
				$hosts[] = $derived;
			}
		}
		return array_values(array_unique($hosts));
	}

	protected function derivePortalHost(string $candidate): string
	{
		$candidate = trim($candidate);
		if ($candidate === '')
		{
			return '';
		}

		$parts = parse_url($candidate);
		if (!is_array($parts))
		{
			return '';
		}

		$host = strtolower(trim((string) ($parts['host'] ?? '')));
		if ($host === '')
		{
			return '';
		}
		if (str_starts_with($host, 'api.'))
		{
			return substr($host, 4);
		}
		if (str_starts_with($host, 'control.'))
		{
			return 'portal.' . substr($host, 8);
		}
		return $host;
	}

	public function actionTest()
	{
		$this->assertPostOnly();

		$client = new ApiClient($this->app());
		$result = [
			'bootstrap_status' => 'not_run',
			'health_status' => 'not_run',
			'capabilities_status' => 'not_run',
			'site_status_status' => 'not_run',
			'error' => null,
			'payloads' => [],
		];

		try
		{
			$bootstrap = $client->bootstrapIfNeeded();
			$result['bootstrap_status'] = $bootstrap ? 'ok' : ($client->getStringOption('ffProtectApiKey') !== '' ? 'already_configured' : 'no_response');
			$client->refreshEndpointsBeforeConnectionTest(1);

			$endpointSummary = $client->endpointStateSummary();
			$result['health_status'] = !empty($endpointSummary['preferred']) ? 'ok' : 'unknown_or_stale';

			$capabilities = $client->capabilities(1);
			$result['capabilities_status'] = $capabilities ? 'ok' : 'no_response';

			$sitePing = $client->sitePing(1);
			$result['site_ping_status'] = $sitePing ? 'ok' : 'no_response';

			if ($client->getStringOption('ffProtectApiKey') !== '')
			{
				$status = $client->siteStatus(1);
				$result['site_status_status'] = $status ? 'ok' : 'no_response';
			}
			else
			{
				$result['site_status_status'] = 'missing_api_key';
			}
			$result['preferred_endpoint'] = (string) ($endpointSummary['preferred'] ?? '');
			$result['preferred_missing'] = (string) ($endpointSummary['preferred_missing'] ?? '');
			$result['answered_endpoint'] = (string) ($endpointSummary['last_responded'] ?? '');
		}
		catch (\Throwable $e)
		{
			$result['error'] = $e->getMessage();
		}

		$this->session()->ffProtectLastTest = $result;
		return $this->redirect($this->buildLink('forum-fortress'));
	}

	public function actionAttackMode()
	{
		$this->assertPostOnly();

		$client = new ApiClient($this->app());
		$result = [
			'status' => 'no_response',
			'payload' => null,
			'error' => null,
		];

		try
		{
			$payload = $client->activateAttackMode();
			$payload = $this->assertAttackModeState($payload, true);
			$result['payload'] = $payload;
			$result['attack_mode_active'] = true;
			$result['status'] = 'ok';
		}
		catch (\Throwable $e)
		{
			$result['status'] = 'error';
			$result['error'] = $e->getMessage();
		}

		$this->session()->ffProtectAttackModeResult = $result;
		return $this->redirect($this->buildLink('forum-fortress'));
	}

	public function actionAttackModeEnd()
	{
		$this->assertPostOnly();

		$client = new ApiClient($this->app());
		$result = [
			'status' => 'no_response',
			'payload' => null,
			'error' => null,
		];

		try
		{
			$payload = $client->deactivateAttackMode();
			$payload = $this->assertAttackModeState($payload, false);
			$result['payload'] = $payload;
			$result['attack_mode_active'] = false;
			$result['status'] = 'ok';
		}
		catch (\Throwable $e)
		{
			$result['status'] = 'error';
			$result['error'] = $e->getMessage();
		}

		$this->session()->ffProtectAttackModeResult = $result;
		return $this->redirect($this->buildLink('forum-fortress'));
	}

	/** @return array<string, mixed> */
	protected function assertAttackModeState(?array $payload, bool $expected): array
	{
		$actual = null;
		if (is_array($payload) && array_key_exists('attack_mode_active', $payload))
		{
			$actual = (bool) $payload['attack_mode_active'];
		}
		elseif (is_array($payload) && array_key_exists('enabled', $payload))
		{
			$actual = (bool) $payload['enabled'];
		}
		elseif (is_array($payload) && is_array($payload['attack_mode'] ?? null) && array_key_exists('enabled', $payload['attack_mode']))
		{
			$actual = (bool) $payload['attack_mode']['enabled'];
		}

		if ($actual === null || $actual !== $expected)
		{
			throw new \RuntimeException(
				$expected
					? 'Forum Fortress did not confirm that attack mode is active.'
					: 'Forum Fortress did not confirm that attack mode has ended.'
			);
		}

		return $payload;
	}

	public function actionRegister()
	{
		$this->assertPostOnly();

		$email = trim((string) $this->filter('registration_email', 'str'));
		$result = [
			'status' => 'no_response',
			'payload' => null,
			'error' => null,
		];

		if ($email === '')
		{
			$result['status'] = 'error';
			$result['error'] = 'Registration email is required.';
			$this->session()->ffProtectRegisterResult = $result;
			return $this->redirect($this->buildLink('forum-fortress'));
		}

		$client = new ApiClient($this->app());

		try
		{
			// The portal's registration reset window authorises a fresh bootstrap
			// for this existing forum. Do this before sending the email so the
			// registration request uses the newly issued identity/key.
			$client->rebootstrapForRegistration();
			$payload = $client->registerSite($email);
			$result['status'] = $payload ? 'ok' : 'no_response';
		}
		catch (\Throwable $e)
		{
			$result['status'] = 'error';
			$result['error'] = $e->getMessage();
		}

		if ($this->request()->isXhr())
		{
			if ($result['status'] === 'ok')
			{
				return $this->message(\XF::phrase('ff_registration_completed'));
			}
			if ($result['error'])
			{
				return $this->error($result['error']);
			}
			return $this->error(\XF::phrase('ff_no_response'));
		}

		$this->session()->ffProtectRegisterResult = $result;
		return $this->redirect($this->buildLink('forum-fortress'));
	}

	protected function maskKey(string $key): string
	{
		$key = trim($key);
		if ($key === '')
		{
			return '';
		}

		if (strlen($key) <= 10)
		{
			return str_repeat('*', strlen($key));
		}

		return substr($key, 0, 6) . str_repeat('*', max(0, strlen($key) - 10)) . substr($key, -4);
	}
}
