<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — Isolated Automated Test Runner
 * 
 * Safely executes unit, safety, and database integrity suites strictly within
 * the dedicated test environment (`garage_management_system_test` + `storage/testing/`).
 * 
 * Usage:
 *   php scripts/run_isolated_tests.php [--provision] [--help]
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Error: scripts/run_isolated_tests.php must be run from the CLI.\n");
    exit(1);
}

define('APP_ROOT', str_replace('\\', '/', dirname(__DIR__)));
$phpBinary = 'C:\\MAMP\\bin\\php\\php8.3.1\\php.exe';
$phpIni = 'C:\\MAMP\\conf\\php8.3.1\\php.ini';

$startTime = microtime(true);
$options = getopt('', ['provision', 'help']);

if (isset($options['help'])) {
    echo "Usage: php scripts/run_isolated_tests.php [OPTIONS]\n\n";
    echo "Options:\n";
    echo "  --provision    Re-provision and seed the test database before running tests\n";
    echo "  --help         Display this help message\n";
    exit(0);
}

echo "=================================================================\n";
echo "ALPHA GARAGE — ISOLATED AUTOMATED TEST RUNNER\n";
echo "Execution Environment: PHP 8.3 CLI (Isolated Mode)\n";
echo "Target Test Database: garage_management_system_test\n";
echo "Target Test Storage:  storage/testing/\n";
echo "=================================================================\n\n";

// =========================================================================
// 1. ENVIRONMENT CONFIGURATION & SAFETY PREFLIGHT
// =========================================================================
$testDbName = 'garage_management_system_test';
$testStorage = APP_ROOT . '/storage/testing';
$dbUser = getenv('GMS_DB_USER') ?: 'root';
$dbPass = getenv('GMS_DB_PASS') ?: 'root';
$dbHost = getenv('GMS_DB_HOST') ?: '127.0.0.1';
$dbPort = (int) (getenv('GMS_DB_PORT') ?: 3306);

// Apply isolated test environment to current process
putenv("GMS_TEST_MODE=1");
putenv("GMS_ALLOW_DESTRUCTIVE_TESTS=1");
putenv("GMS_DB_NAME=$testDbName");
putenv("GMS_STORAGE_PATH=$testStorage");
putenv("GMS_DB_USER=$dbUser");
putenv("GMS_DB_PASS=$dbPass");
putenv("GMS_DB_HOST=$dbHost");
putenv("GMS_DB_PORT=$dbPort");

require_once APP_ROOT . '/app/helpers/functions.php';

// Safety Guard: Storage path validation
$resolvedStorage = storage_path('');
if (strcasecmp($resolvedStorage, APP_ROOT . '/storage') === 0) {
    fwrite(STDERR, "\nFATAL SAFETY FAULT: Test runner storage resolved to production storage!\n");
    exit(2);
}

// Safety Guard: Preflight Database Identity check
try {
    $preflightPdo = new PDO("mysql:host=$dbHost;port=$dbPort;dbname=$testDbName;charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $activeDatabase = (string) $preflightPdo->query('SELECT DATABASE()')->fetchColumn();
    if ($activeDatabase !== $testDbName) {
        fwrite(STDERR, "\nFATAL SAFETY FAULT: Connected to database '$activeDatabase', expected '$testDbName'!\n");
        exit(2);
    }
    if ($activeDatabase === 'garage_management_system') {
        fwrite(STDERR, "\nFATAL SAFETY FAULT: Connected to LIVE PRODUCTION database!\n");
        exit(2);
    }
} catch (Throwable $e) {
    echo "Notice: Test database not yet provisioned or unreachable: " . $e->getMessage() . "\n";
    echo "Running automated provisioning...\n\n";
    $options['provision'] = true;
}

// Auto-provision if requested or required
if (isset($options['provision'])) {
    echo ">>> Running automated test database provisioning...\n";
    $provisionCmd = escapeshellarg($phpBinary) . ' -c ' . escapeshellarg($phpIni) . ' ' . escapeshellarg(APP_ROOT . '/scripts/provision_test_db.php');
    passthru($provisionCmd, $provCode);
    if ($provCode !== 0) {
        fwrite(STDERR, "\nFATAL ERROR: Test database provisioning failed with code $provCode!\n");
        exit(1);
    }
    echo "\n";
}

// =========================================================================
// 2. TEST SUITE EXECUTION ENGINE
// =========================================================================
$suites = [
    [
        'name' => 'Authentication Unit Suite',
        'file' => 'tests/auth_unit.php',
    ],
    [
        'name' => 'Inventory Business Logic Suite',
        'file' => 'tests/inventory_unit.php',
    ],
    [
        'name' => 'Security Hardening Suite',
        'file' => 'tests/security_hardening_unit.php',
    ],
    [
        'name' => 'Infrastructure Safety Validation Suite (6 Assertions)',
        'file' => 'tests/safety_validation.php',
    ],
];

$suitePassed = 0;
$suiteFailed = 0;
$totalAssertions = 0;

foreach ($suites as $suite) {
    echo "-----------------------------------------------------------------\n";
    echo "SUITE: {$suite['name']}\n";
    echo "File:  {$suite['file']}\n";
    echo "-----------------------------------------------------------------\n";

    $suiteStart = microtime(true);
    $testFile = APP_ROOT . '/' . $suite['file'];

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $env = array_merge($_ENV, getenv(), [
        'GMS_TEST_MODE' => '1',
        'GMS_ALLOW_DESTRUCTIVE_TESTS' => '1',
        'GMS_DB_NAME' => $testDbName,
        'GMS_STORAGE_PATH' => $testStorage,
        'GMS_DB_USER' => $dbUser,
        'GMS_DB_PASS' => $dbPass,
    ]);

    $cmd = escapeshellarg($phpBinary) . ' -c ' . escapeshellarg($phpIni) . ' ' . escapeshellarg($testFile);
    $proc = proc_open($cmd, $descriptors, $pipes, APP_ROOT, $env);

    if (!is_resource($proc)) {
        fwrite(STDERR, "Error: Could not spawn test runner for {$suite['file']}\n");
        $suiteFailed++;
        continue;
    }

    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $code = proc_close($proc);
    $elapsed = round(microtime(true) - $suiteStart, 3);

    if ($out !== '') {
        echo trim($out) . "\n";
    }
    if ($err !== '') {
        fwrite(STDERR, "\033[31m" . trim($err) . "\033[0m\n");
    }

    if ($code === 0) {
        $suitePassed++;
        echo "Status: \033[32mPASSED\033[0m ({$elapsed}s)\n\n";
    } else {
        $suiteFailed++;
        echo "Status: \033[31mFAILED (exit code $code)\033[0m ({$elapsed}s)\n\n";
    }
}

// =========================================================================
// 3. DATABASE INTEGRITY & FIXTURES VERIFICATION SUITE
// =========================================================================
echo "-----------------------------------------------------------------\n";
echo "SUITE: Test Database State & Hash Chain Verification\n";
echo "Target: $testDbName\n";
echo "-----------------------------------------------------------------\n";

$dbCheckStart = microtime(true);
$dbCheckPassed = true;
$dbErrors = [];

try {
    require_once APP_ROOT . '/app/models/Database.php';
    require_once APP_ROOT . '/app/services/AuditLogger.php';

    // Verify database connection identity
    $liveCheck = (string) Database::value('SELECT DATABASE()');
    if ($liveCheck !== $testDbName) {
        throw new RuntimeException("Database mismatch: expected $testDbName, got $liveCheck");
    }

    // Verify table count
    $tables = (int) Database::value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$testDbName'");
    if ($tables < 28) {
        $dbCheckPassed = false;
        $dbErrors[] = "Expected at least 28 tables, found $tables";
    } else {
        echo "  [\033[32m✔\033[0m] Tables in test database: $tables (expected 29)\n";
    }

    // Verify users count & roles
    $userCount = (int) Database::value("SELECT COUNT(*) FROM users");
    if ($userCount < 6) {
        $dbCheckPassed = false;
        $dbErrors[] = "Expected 6 users (admin + 5 demo), found $userCount";
    } else {
        echo "  [\033[32m✔\033[0m] Test users present: $userCount users\n";
    }

    // Verify triggers
    $triggers = Database::all("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = '$testDbName'");
    $triggerNames = array_column($triggers, 'TRIGGER_NAME');
    $requiredTriggers = ['trg_audit_no_update', 'trg_audit_no_delete', 'trg_payments_immutable'];
    foreach ($requiredTriggers as $rt) {
        if (!in_array($rt, $triggerNames, true)) {
            $dbCheckPassed = false;
            $dbErrors[] = "Missing trigger in test DB: $rt";
        } else {
            echo "  [\033[32m✔\033[0m] Verified trigger: $rt\n";
        }
    }

    // Verify audit chain
    $chain = AuditLogger::verifyChain();
    if (!$chain['ok']) {
        $dbCheckPassed = false;
        $dbErrors[] = "Audit chain verification failed: " . $chain['message'];
    } else {
        echo "  [\033[32m✔\033[0m] Audit hash chain: VALID ({$chain['checked']} records verified)\n";
    }

} catch (Throwable $e) {
    $dbCheckPassed = false;
    $dbErrors[] = "Database check exception: " . $e->getMessage();
}

$dbCheckElapsed = round(microtime(true) - $dbCheckStart, 3);
if ($dbCheckPassed) {
    $suitePassed++;
    echo "Status: \033[32mPASSED\033[0m ({$dbCheckElapsed}s)\n\n";
} else {
    $suiteFailed++;
    foreach ($dbErrors as $err) {
        echo "  \033[31m✘ $err\033[0m\n";
    }
    echo "Status: \033[31mFAILED\033[0m ({$dbCheckElapsed}s)\n\n";
}

// =========================================================================
// 4. SUMMARY REPORT
// =========================================================================
$totalElapsed = round(microtime(true) - $startTime, 3);
$totalSuites = $suitePassed + $suiteFailed;

echo "=================================================================\n";
echo "ISOLATED TEST RUN SUMMARY\n";
echo "Suites Run:    $totalSuites\n";
echo "Suites Passed: \033[32m$suitePassed\033[0m\n";
if ($suiteFailed > 0) {
    echo "Suites Failed: \033[31m$suiteFailed\033[0m\n";
} else {
    echo "Suites Failed: 0\n";
}
echo "Execution Time: {$totalElapsed}s\n";
echo "Live Database `garage_management_system`: UNTOUCHED & PROTECTED\n";
echo "=================================================================\n";

exit($suiteFailed > 0 ? 1 : 0);
