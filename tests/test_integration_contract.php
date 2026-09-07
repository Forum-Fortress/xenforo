<?php

declare(strict_types=1);

final class IntegrationContractFakeDb
{
	/** @var list<string> */
	public array $queries = [];
	public bool $acquire = true;

	public function fetchOne(string $query, array $params = []): int
	{
		$this->queries[] = $query;
		if (str_contains($query, 'GET_LOCK'))
		{
			return $this->acquire ? 1 : 0;
		}
		return 1;
	}
}

final class XF
{
	public static IntegrationContractFakeDb $db;

	public static function db(): IntegrationContractFakeDb
	{
		return self::$db;
	}

	public static function logException(Throwable $e, bool $rollback = true, string $prefix = ''): void
	{
	}
}

function assertContract(bool $condition, string $message): void
{
	if (!$condition)
	{
		fwrite(STDERR, $message . "\n");
		exit(1);
	}
}

$addOnRoot = dirname(__DIR__) . '/upload/src/addons/ForumFortress/Protect';

$addOn = json_decode((string) file_get_contents($addOnRoot . '/addon.json'), true, 512, JSON_THROW_ON_ERROR);
assertContract(($addOn['version_id'] ?? null) === 1801100, 'add-on version_id must be 1801100');
assertContract(($addOn['version_string'] ?? null) === '1.8.11', 'add-on version_string must be 1.8.11');
assertContract(($addOn['require']['XF'][0] ?? null) === 2030070, 'stable XenForo 2.3.0 minimum must be declared');
assertContract(($addOn['require']['php'][0] ?? null) === '8.0.0', 'PHP 8.0 minimum must be declared');
$apiClientSource = (string) file_get_contents($addOnRoot . '/Service/ApiClient.php');
assertContract(str_contains($apiClientSource, "CONTROL_PLANE_BASE_URL = 'https://fortress.ffapi.net'"), 'control service URL must be fixed');
assertContract(strpos((string) file_get_contents($addOnRoot . '/_data/options.xml'), 'ffProtectControlBaseUrl') === false, 'control URL option must not be exported');

$classExtensions = (string) file_get_contents($addOnRoot . '/_data/class_extensions.xml');
foreach (['EmailConfirmationService', 'Service\\Post\\Editor', 'Service\\Thread\\Editor', 'MiscController'] as $obsolete)
{
	assertContract(!str_contains($classExtensions, $obsolete), 'obsolete direct check extension remains: ' . $obsolete);
}
assertContract(str_contains($classExtensions, 'XF\\Entity\\ApprovalQueue'), 'approval queue entity extension is missing');

$listeners = (string) file_get_contents($addOnRoot . '/_data/code_event_listeners.xml');
assertContract(!str_contains($listeners, 'app_pub_start_end'), 'public page-start network listener remains');
assertContract(!str_contains($listeners, 'app_admin_start_end'), 'admin page-start network listener remains');

$account = (string) file_get_contents($addOnRoot . '/XF/Pub/Controller/Account.php');
assertContract(str_contains($account, 'parent::accountDetailsSaveProcess($visitor)'), 'profile extension must delegate to XenForo');
assertContract(!str_contains($account, 'function actionSignature'), 'duplicate signature check remains');
assertContract(str_contains($account, "getBoolOption('ffProtectFailOpen', true)"), 'profile failure policy is not explicit');
assertContract(str_contains($account, 'DecisionMapper::toContentDecision($response, $failOpen)'), 'profile responses must use the shared decision mapper');
assertContract(!str_contains($account, '($response[\'decision\'] ?? \'allow\') === \'block\''), 'profile response uses brittle direct decision comparison');

foreach ([
	'/XF/Service/Post/Editor.php',
	'/XF/Service/Thread/Editor.php',
	'/XF/Service/User/EmailConfirmation.php',
	'/XF/Pub/Controller/Misc.php',
] as $obsoleteFile)
{
	assertContract(!file_exists($addOnRoot . $obsoleteFile), 'obsolete direct check file remains: ' . $obsoleteFile);
}

$admin = (string) file_get_contents($addOnRoot . '/Admin/Controller/ForumFortress.php');
assertContract(str_contains($admin, "assertAdminPermission('ffProtect')"), 'ACP controller permission is missing');
assertContract(!str_contains($admin, "portal.forumfortress.com"), 'hardcoded portal host should be removed');
$portalAction = explode('public function actionPortal()', $admin, 2)[1] ?? '';
$portalAction = explode('public function actionTest()', $portalAction, 2)[0] ?? '';
assertContract(str_contains($portalAction, 'assertPostOnly()'), 'portal launch is not POST-only');
assertContract(str_contains($portalAction, 'getAuthenticatedPortalUrl()'), 'portal launch should retain same-cycle authenticated URL fallback');

$template = (string) file_get_contents($addOnRoot . '/_data/templates.xml');
assertContract(str_contains($template, '<xf:form action="{{ link(\'forum-fortress/portal\') }}"'), 'portal launch is not rendered as a CSRF form');

$setup = (string) file_get_contents($addOnRoot . '/Setup.php');
assertContract(!str_contains($setup, 'createCodeEventListeners'), 'Setup duplicates exported listener data');
assertContract(!str_contains($setup, 'createClassExtensions'), 'Setup duplicates exported class extension data');
assertContract(!str_contains($setup, 'createPhrases'), 'Setup duplicates exported phrase data');
assertContract(!str_contains($setup, 'removeDeprecatedIntegrationArtifacts'), 'clean-sheet Setup must not contain legacy cleanup');
assertContract(!str_contains($setup, 'ApiClient'), 'Setup must not perform synchronous external API calls');
assertContract(!str_contains($setup, 'postInstall'), 'Setup must not run post-install network hooks');
assertContract(!str_contains($setup, 'postUpgrade'), 'Setup must not run post-upgrade network hooks');
assertContract(str_contains($setup, 'createApprovalQueueMetadataTable()'), 'approval metadata install hook is missing');
assertContract(str_contains($setup, 'dropApprovalQueueMetadataTable()'), 'approval metadata uninstall hook is missing');

$registration = (string) file_get_contents($addOnRoot . '/XF/Service/User/Registration.php');
assertContract(!str_contains($registration, 'prevent_create'), 'legacy registration mode alias remains');
assertContract(!str_contains($registration, 'ffProtectDeleteRejectedUsers'), 'legacy registration option fallback remains');

require_once $addOnRoot . '/Service/DecisionMapper.php';

use ForumFortress\Protect\Service\DecisionMapper;

assertContract(DecisionMapper::toContentDecision(['decision' => 'BLOCK'], true) === 'denied', 'explicit block must deny even in fail-open mode');
assertContract(DecisionMapper::toContentDecision(['status_code' => 'ABOVELIMIT'], true) === 'allowed', 'above-limit must allow in fail-open mode');
assertContract(DecisionMapper::toContentDecision(['status_code' => 'ABOVELIMIT'], false) === 'moderated', 'above-limit must fail closed');
assertContract(DecisionMapper::toContentDecision(['decision' => 'unexpected'], true) === 'allowed', 'invalid response must allow in fail-open mode');
assertContract(DecisionMapper::toContentDecision(['decision' => 'unexpected'], false) === 'moderated', 'invalid response must fail closed');
assertContract(DecisionMapper::toContentDecision(null, true) === 'allowed', 'unavailable response must allow in fail-open mode');
assertContract(DecisionMapper::toContentDecision(null, false) === 'moderated', 'unavailable response must fail closed');

require_once $addOnRoot . '/Cron/SyncLock.php';

XF::$db = new IntegrationContractFakeDb();
$called = false;
assertContract(\ForumFortress\Protect\Cron\SyncLock::run(static function () use (&$called): void
{
	$called = true;
}), 'sync lock should report an acquired lock');
assertContract($called, 'sync lock did not invoke the callback');
assertContract(count(XF::$db->queries) === 2, 'sync lock must acquire and release its advisory lock');

XF::$db = new IntegrationContractFakeDb();
XF::$db->acquire = false;
$called = false;
assertContract(!\ForumFortress\Protect\Cron\SyncLock::run(static function () use (&$called): void
{
	$called = true;
}), 'sync lock should report a busy lock');
assertContract(!$called, 'sync callback ran without acquiring the advisory lock');

fwrite(STDOUT, "XenForo integration contract tests passed.\n");
