<?php
/**
 * Test runner.
 *
 *   php tests/run.php
 *
 * Exits non-zero on the first failing assertion set so it can gate a CI job.
 */

declare( strict_types=1 );

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/TestCase.php';

$cases = [
	'CryptoTest',
	'SettingsTest',
	'SchemaTest',
	'JwtTest',
	'BnplQuoteTest',
	'MoneyTest',
	'GatewayTest',
	'ModulesTest',
	'UpsertTest',
	'CronScheduleTest',
	'VipChannelTest',
	'LmsTest',
	'PostIdentityTest',
	'FxTest',
	'PhasesTest',
	'Phases2Test',
	'IpgAdaptersTest',
	'SecurityGapsTest',
	'DriftGuardTest',
	'SecretsTest',
	'DoorsTest',
	'TenantScopeTest',
	'UrlGuardTest',
	'ArchiveGuardTest',
	'BiometricGateTest',
	'OffboardingTest',
	'TenantResolutionTest',
	'TenantIsolationTest',
	'SignupTest',
	'DomainRoutingTest',
	'ThemeRoutingTest',
	'MigratorTest',
	'BatchTest',
	'HposOrderFlowTest',
	'JobQueueTest',
	'CronJobsTest',
	'HourlyJobsTest',
	'DailyJobsTest',
	'QueueLoadTest',
	'WalletLedgerTest',
	'WebhookInboxTest',
	'GatewayHardeningTest',
	'MasterPaymentServiceTest',
	'PlansLifecycleTest',
	'BnplHardeningTest',
	'NationalIdVerifierTest',
	'FxQuoteTest',
	'FxBillingHardenTest',
	'DomainOrderTest',
	'DomainRegistrationTest',
	'DomainLifecycleTest',
	'AffiliateHardenTest',
	'GamificationTest',
	'LmsVideoAccessTest',
	'LogisticsStateMachineTest',
	'CourierCodTest',
	'ShippingSyncTest',
	'MarketplaceSyncTest',
	'SeoAdsTest',
	'IntlCommerceTest',
	'ZernioConnectTest',
	'ZernioSocialAdapterTest',
	'SocialMigrationTest',
	'SocialArchitectureGuardTest',
	'InboxTest',
	'ProductRegistrationTest',
	'ContentPublishTest',
	'VipChannelTest',
	'GrowthIntelTest',
	'DeepInfraAdapterTest',
	'PermissionQueueTest',
	'SensitiveOpsTest',
	'ContentOpsTest',
	'ThemeContractTest',
	'ThemeReleaseTest',
	'PadoMemoryTest',
	'GrowthPlaybookTest',
	'AdversarialPadoTest',
	'ApiContractTest',
	'ApiAuthDeviceTest',
	'ApiMobileBehaviorTest',
	'FaLocaleTest',
];

foreach ( $cases as $case ) {
	require_once __DIR__ . '/' . $case . '.php';
}

$started = microtime( true );

foreach ( $cases as $case ) {
	$before = count( TestCase::$failures );
	/** @var TestCase $test */
	$test = new $case();

	try {
		$test->run();
	} catch ( \Throwable $e ) {
		TestCase::$failures[] = $case . ': threw ' . get_class( $e ) . ' - ' . $e->getMessage();
	}

	$new = count( TestCase::$failures ) - $before;
	printf( "%-20s %s\n", $case, 0 === $new ? 'ok' : sprintf( '%d FAILED', $new ) );
}

$elapsed = round( ( microtime( true ) - $started ) * 1000 );

echo str_repeat( '-', 60 ), "\n";

if ( TestCase::$failures ) {
	foreach ( TestCase::$failures as $failure ) {
		echo '  FAIL  ', $failure, "\n";
	}
	printf( "\n%d passed, %d failed (%dms)\n", TestCase::$passed, count( TestCase::$failures ), $elapsed );
	exit( 1 );
}

printf( "%d assertions passed in %d test cases (%dms)\n", TestCase::$passed, count( $cases ), $elapsed );
exit( 0 );
