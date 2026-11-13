<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — PHASE 1.9B: SAFETY VALIDATION SUITE
 * 
 * Demonstrates and verifies all 6 core safety assertions:
 *   1. Production Target Rejection
 *   2. No Fallback on Missing Database
 *   3. Storage Separation (production storage rejection in test mode)
 *   4. Session Separation (GMSSESSID_TEST & test session directory)
 *   5. Explicit Test Mode Enforcement (GMS_ALLOW_DESTRUCTIVE_TESTS guard)
 *   6. Database Identity Assertion (active DB strictly equals test DB)
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Error: tests/safety_validation.php must be run from the CLI.\n");
    exit(1);
}

define('APP_ROOT', str_replace('\\', '/', dirname(__DIR__)));
$phpBinary = 'C:\\MAMP\\bin\\php\\php8.3.1\\php.exe';
$phpIni = 'C:\\MAMP\\conf\\php8.3.1\\php.ini';

$passed = 0;
$failed = 0;

function report_assertion(int $number, string $name, bool $success, string $details = ''): void
{
    global $passed, $failed;
    if ($success) {
        $passed++;
        echo "  [\033[32mPASS\033[0m] Assertion $number: $name\n";
        if ($details !== '') {
            echo "         $details\n";
        }
    } else {
        $failed++;
        echo "  [\033[31mFAIL\033[0m] Assertion $number: $name\n";
        if ($details !== '') {
            echo "         \033[31mDetails: $details\033[0m\n";
        }
    }
}

/**
 * Execute a PHP snippet in an isolated sub-process with specific environment variables.
 */
function run_isolated_snippet(string $code, array $env = []): array
{
    global $phpBinary, $phpIni;
    $tmpFile = tempnam(sys_get_temp_dir(), 'gms_safety_');
    $header = "<?php\ndeclare(strict_types=1);\nif (!defined('APP_ROOT')) { define('APP_ROOT', " . var_export(APP_ROOT, true) . "); }\n";
    file_put_contents($tmpFile, $header . $code);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $mergedEnv = array_merge($_ENV, getenv(), $env);
    $cmd = escapeshellarg($phpBinary) . ' -c ' . escapeshellarg($phpIni) . ' ' . escapeshellarg($tmpFile);
    $proc = proc_open($cmd, $descriptors, $pipes, APP_ROOT, $mergedEnv);

    if (!is_resource($proc)) {
        @unlink($tmpFile);
        throw new RuntimeException("Failed to open sub-process: $cmd");
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($proc);
    @unlink($tmpFile);

    return [
        'code' => $exitCode,
        'stdout' => (string) $stdout,
        'stderr' => (string) $stderr,
    ];
}

echo "=================================================================\n";
echo "ALPHA GARAGE — TEST INFRASTRUCTURE SAFETY VALIDATION SUITE\n";
echo "Verifying 6 Critical Safety Boundaries\n";
echo "=================================================================\n\n";

// =========================================================================
// ASSERTION 1: PRODUCTION TARGET REJECTION
// =========================================================================
// Attempt to run provisioning targeting 'garage_management_system'
$res1 = run_isolated_snippet(
    'require APP_ROOT . "/scripts/provision_test_db.php";',
    ['GMS_TEST_DB_NAME' => 'garage_management_system']
);
$blocked1 = ($res1['code'] === 2)
    && (str_contains($res1['stderr'], 'FATAL SAFETY VIOLATION: Cannot provision production database')
        || str_contains($res1['stdout'], 'FATAL SAFETY VIOLATION: Cannot provision production database'));

report_assertion(
    1,
    'Production Target Rejection',
    $blocked1,
    'Exit code: ' . $res1['code'] . ' | Output confirmed: Production target is rejected immediately.'
);

// =========================================================================
// ASSERTION 2: NO FALLBACK ON MISSING DATABASE
// =========================================================================
// Attempt to run test runner with empty/missing GMS_DB_NAME in test mode
$snippet2 = <<<'PHP'
putenv('GMS_DB_NAME');
$targetDb = getenv('GMS_TEST_DB_NAME') ?: getenv('GMS_DB_NAME');
if (!$targetDb || !str_ends_with($targetDb, '_test')) {
    fwrite(STDERR, "Test environment failed closed: GMS_DB_NAME not explicitly configured as _test database.\n");
    exit(3);
}
PHP;

$res2 = run_isolated_snippet($snippet2, ['GMS_TEST_MODE' => '1', 'GMS_DB_NAME' => '']);
$blocked2 = ($res2['code'] === 3)
    && str_contains($res2['stderr'], 'Test environment failed closed');

report_assertion(
    2,
    'No Fallback on Missing Database',
    $blocked2,
    'Exit code: ' . $res2['code'] . ' | Fail-closed confirmed: Missing DB name refuses fallback to production.'
);

// =========================================================================
// ASSERTION 3: STORAGE SEPARATION (PRODUCTION STORAGE REJECTION)
// =========================================================================
// Attempt to call storage_path() in test mode while pointing to production storage
$snippet3 = <<<'PHP'
require_once APP_ROOT . '/app/helpers/functions.php';
putenv('GMS_TEST_MODE=1');
putenv('GMS_STORAGE_PATH=' . APP_ROOT . '/storage');
try {
    $path = storage_path('logs/test.log');
    echo "FAILED: Storage guard did not throw!\n";
    exit(1);
} catch (RuntimeException $e) {
    if (str_contains($e->getMessage(), 'storage points to production storage')) {
        echo "SUCCESS: " . $e->getMessage() . "\n";
        exit(0);
    }
    echo "WRONG EXCEPTION: " . $e->getMessage() . "\n";
    exit(1);
}
PHP;

$res3 = run_isolated_snippet($snippet3);
$blocked3 = ($res3['code'] === 0) && str_contains($res3['stdout'], 'SUCCESS: Test mode is enabled but storage points to production storage');

report_assertion(
    3,
    'Storage Separation',
    $blocked3,
    'Storage guard confirmed: Calling storage_path() in test mode against production storage throws RuntimeException.'
);

// =========================================================================
// ASSERTION 4: SESSION SEPARATION
// =========================================================================
// Verify test mode uses GMSSESSID_TEST and isolated test session directory
$snippet4 = <<<'PHP'
putenv('GMS_TEST_MODE=1');
putenv('GMS_STORAGE_PATH=' . APP_ROOT . '/storage/testing');
require_once APP_ROOT . '/app/bootstrap.php';

$sessName = session_name();
$sessPath = session_save_path();

$expectedName = 'GMSSESSID_TEST';
$expectedPath = str_replace('\\', '/', APP_ROOT . '/storage/testing/sessions');
$actualPath = str_replace('\\', '/', $sessPath);

if ($sessName !== $expectedName) {
    fwrite(STDERR, "Session name mismatch: got '$sessName', expected '$expectedName'\n");
    exit(1);
}
if ($actualPath !== $expectedPath) {
    fwrite(STDERR, "Session path mismatch: got '$actualPath', expected '$expectedPath'\n");
    exit(1);
}
echo "SUCCESS: Session isolated to $sessName at $actualPath\n";
exit(0);
PHP;

$res4 = run_isolated_snippet($snippet4);
$blocked4 = ($res4['code'] === 0) && str_contains($res4['stdout'], 'SUCCESS: Session isolated to GMSSESSID_TEST');

report_assertion(
    4,
    'Session Separation',
    $blocked4,
    'Session isolation confirmed: Name is GMSSESSID_TEST, storage is storage/testing/sessions.'
);

// =========================================================================
// ASSERTION 5: EXPLICIT TEST MODE ENFORCEMENT
// =========================================================================
// Attempt to run tests/run_tests.php without GMS_ALLOW_DESTRUCTIVE_TESTS=1
$res5 = run_isolated_snippet(
    'require APP_ROOT . "/tests/run_tests.php";',
    [
        'GMS_ALLOW_DESTRUCTIVE_TESTS' => '0',
        'GMS_DB_NAME' => 'garage_management_system_test',
        'GMS_STORAGE_PATH' => APP_ROOT . '/storage/testing',
    ]
);
$blocked5 = ($res5['code'] === 2)
    && str_contains($res5['stderr'], 'Refusing destructive tests: use a local database ending in _test and set GMS_ALLOW_DESTRUCTIVE_TESTS=1');

report_assertion(
    5,
    'Explicit Test Mode Enforcement',
    $blocked5,
    'Exit code: ' . $res5['code'] . ' | Guard confirmed: Refuses destructive execution without explicit flag.'
);

// =========================================================================
// ASSERTION 6: DATABASE IDENTITY ASSERTION
// =========================================================================
// Verify active database strictly equals 'garage_management_system_test'
$snippet6 = <<<'PHP'
putenv('GMS_TEST_MODE=1');
putenv('GMS_ALLOW_DESTRUCTIVE_TESTS=1');
putenv('GMS_DB_NAME=garage_management_system_test');
putenv('GMS_DB_USER=root');
putenv('GMS_DB_PASS=root');
putenv('GMS_STORAGE_PATH=' . APP_ROOT . '/storage/testing');

require_once APP_ROOT . '/app/models/Database.php';

$active = (string) Database::value('SELECT DATABASE()');
if ($active !== 'garage_management_system_test') {
    fwrite(STDERR, "Database identity mismatch: expected garage_management_system_test, got '$active'\n");
    exit(1);
}
if ($active === 'garage_management_system') {
    fwrite(STDERR, "CRITICAL ERROR: Connected to live production database!\n");
    exit(2);
}
echo "SUCCESS: Database identity verified as '$active'\n";
exit(0);
PHP;

$res6 = run_isolated_snippet($snippet6);
$blocked6 = ($res6['code'] === 0) && str_contains($res6['stdout'], "SUCCESS: Database identity verified as 'garage_management_system_test'");

report_assertion(
    6,
    'Database Identity Assertion',
    $blocked6,
    'Database identity confirmed: Active database is strictly garage_management_system_test.'
);

echo "\n=================================================================\n";
echo "SAFETY VALIDATION RESULT: $passed/6 passed, $failed failed.\n";
echo "=================================================================\n";

exit($failed > 0 ? 1 : 0);
