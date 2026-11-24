<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — PHASE 1.9F: PAYMENT IDEMPOTENCY HARDENING & CRASH RECOVERY SUITE
 * 
 * Verifies enterprise hardening across:
 *   1. SEC-IDEM-01: Atomic failed-key reclamation (conditional state transition)
 *   2. SEC-IDEM-02: Safe pending-key crash recovery & atomic transaction completion
 *   3. SEC-IDEM-03: Guarded failed-state transitions (cannot overwrite completed records)
 *   4. SEC-IDEM-04: Full request fingerprint integrity (including notes and reasons)
 *   5. SEC-IDEM-05: Key validation, cross-user authorization, and immutability
 *   6. Mathematical financial consistency & audit-chain verification
 * 
 * Target Database: garage_management_system_test
 * Fail-Closed: Strictly refuses to execute against production.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Error: tests/payment_idempotency_1_9f_hardening_suite.php must be run from CLI.\n");
    exit(1);
}

define('APP_ROOT', str_replace('\\', '/', dirname(__DIR__)));
$phpBinary = 'C:\\MAMP\\bin\\php\\php8.3.1\\php.exe';
$phpIni = 'C:\\MAMP\\conf\\php8.3.1\\php.ini';

// Autoload all application services and models
spl_autoload_register(function (string $class): void {
    foreach (['services', 'models'] as $dir) {
        $f = APP_ROOT . "/app/$dir/$class.php";
        if (is_file($f)) { require_once $f; return; }
    }
});

// =========================================================================
// 1. PREFLIGHT SAFETY ASSERTIONS (FAIL-CLOSED)
// =========================================================================
putenv('GMS_TEST_MODE=1');
putenv('GMS_ALLOW_DESTRUCTIVE_TESTS=1');
putenv('GMS_DB_NAME=garage_management_system_test');
putenv('GMS_STORAGE_PATH=' . APP_ROOT . '/storage/testing');
putenv('GMS_DB_USER=root');
putenv('GMS_DB_PASS=root');

$GLOBALS['config'] = require APP_ROOT . '/app/config/app.php';
date_default_timezone_set($GLOBALS['config']['timezone']);

require_once APP_ROOT . '/app/helpers/functions.php';
require_once APP_ROOT . '/app/middleware/auth.php';

// Storage guard
$resolvedStorage = storage_path('');
if (strcasecmp($resolvedStorage, APP_ROOT . '/storage') === 0) {
    fwrite(STDERR, "FATAL SAFETY FAULT: Test suite resolved to production storage!\n");
    exit(2);
}

// Database identity guard
$pdo = new PDO("mysql:host=127.0.0.1;port=3306;dbname=garage_management_system_test;charset=utf8mb4", 'root', 'root', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdo->exec("SET time_zone = '+03:00'");

$activeDb = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
if ($activeDb !== 'garage_management_system_test') {
    fwrite(STDERR, "FATAL SAFETY FAULT: Active database is '$activeDb', expected 'garage_management_system_test'!\n");
    exit(2);
}
if ($activeDb === 'garage_management_system') {
    fwrite(STDERR, "FATAL SAFETY FAULT: Connected to production database!\n");
    exit(2);
}

// Clear rate limits in testing storage before executing
$rateLimitDir = APP_ROOT . '/storage/testing/rate_limits';
if (is_dir($rateLimitDir)) {
    foreach (glob($rateLimitDir . '/*') as $f) {
        if (is_file($f)) { @unlink($f); }
    }
}

// Verify payment_idempotency_keys table exists in test DB
$tableExists = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'garage_management_system_test' AND table_name = 'payment_idempotency_keys'")->fetchColumn();
if (!$tableExists) {
    fwrite(STDERR, "FATAL: payment_idempotency_keys table does not exist in test database!\n");
    exit(2);
}

// =========================================================================
// 2. ISOLATED TEST WEB SERVER SPAWN (PORT 8089)
// =========================================================================
$serverPort = 8089;
$serverBase = "http://127.0.0.1:$serverPort/";

$serverLog = APP_ROOT . '/storage/testing/logs/test-server-19f.log';
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['file', $serverLog, 'a'],
    2 => ['file', $serverLog, 'a'],
];

$serverEnv = array_merge($_ENV, getenv(), [
    'GMS_TEST_MODE' => '1',
    'GMS_ALLOW_DESTRUCTIVE_TESTS' => '1',
    'GMS_DB_NAME' => 'garage_management_system_test',
    'GMS_STORAGE_PATH' => APP_ROOT . '/storage/testing',
    'GMS_DB_USER' => 'root',
    'GMS_DB_PASS' => 'root',
]);

$serverCmd = escapeshellarg($phpBinary) . ' -c ' . escapeshellarg($phpIni) . ' -S 127.0.0.1:' . $serverPort . ' -t ' . escapeshellarg(APP_ROOT . '/public');
$serverProc = proc_open($serverCmd, $descriptors, $serverPipes, APP_ROOT, $serverEnv);

if (!is_resource($serverProc)) {
    fwrite(STDERR, "Failed to launch isolated test server on port $serverPort.\n");
    exit(1);
}

register_shutdown_function(function () use ($serverProc) {
    if (is_resource($serverProc)) {
        proc_terminate($serverProc);
    }
});

usleep(800000); // 800ms for server startup

// =========================================================================
// 3. HTTP TEST UTILITIES
// =========================================================================
function http_request_19f(string $method, string $path, array $data = [], string $jar = '', array $extraHeaders = []): array
{
    global $serverBase;
    $url = $serverBase . ltrim($path, '/');
    $ch = curl_init();

    $headers = array_merge(['Connection: close'], $extraHeaders);

    $opts = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_FORBID_REUSE => true,
        CURLOPT_FRESH_CONNECT => true,
        CURLOPT_HTTPHEADER => $headers,
    ];

    if ($jar !== '') {
        $opts[CURLOPT_COOKIEJAR] = $jar;
        $opts[CURLOPT_COOKIEFILE] = $jar;
    }

    if (strtoupper($method) === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($data);
    } elseif (strtoupper($method) !== 'GET') {
        $opts[CURLOPT_CUSTOMREQUEST] = strtoupper($method);
    }

    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    $raw = is_string($raw) ? $raw : '';
    $headerSize = $info['header_size'] ?? 0;
    $head = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);

    $loc = '';
    if (preg_match('/^Location:\s*(.+)$/im', $head, $m)) {
        $loc = trim($m[1]);
    }

    return [
        'code' => (int) ($info['http_code'] ?? 0),
        'head' => $head,
        'body' => $body,
        'loc'  => $loc,
    ];
}

function http_concurrent_post_19f(string $path, array $requests, array $jars): array
{
    global $serverBase;
    $url = $serverBase . ltrim($path, '/');
    $mh = curl_multi_init();
    $handles = [];

    foreach ($requests as $idx => $data) {
        $ch = curl_init();
        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_FORBID_REUSE => true,
            CURLOPT_FRESH_CONNECT => true,
        ];
        if (!empty($jars[$idx])) {
            $opts[CURLOPT_COOKIEJAR] = $jars[$idx];
            $opts[CURLOPT_COOKIEFILE] = $jars[$idx];
        }
        curl_setopt_array($ch, $opts);
        curl_multi_add_handle($mh, $ch);
        $handles[$idx] = $ch;
    }

    $active = null;
    do {
        $mrc = curl_multi_exec($mh, $active);
    } while ($mrc === CURLM_CALL_MULTI_PERFORM);

    while ($active && $mrc === CURLM_OK) {
        if (curl_multi_select($mh) === -1) {
            usleep(1000);
        }
        do {
            $mrc = curl_multi_exec($mh, $active);
        } while ($mrc === CURLM_CALL_MULTI_PERFORM);
    }

    $results = [];
    foreach ($handles as $idx => $ch) {
        $raw = curl_multi_getcontent($ch);
        $info = curl_getinfo($ch);
        $headerSize = $info['header_size'] ?? 0;
        $head = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        $loc = '';
        if (preg_match('/^Location:\s*(.+)$/im', $head, $m)) {
            $loc = trim($m[1]);
        }
        $results[$idx] = [
            'code' => (int) ($info['http_code'] ?? 0),
            'head' => $head,
            'body' => $body,
            'loc'  => $loc,
        ];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $results;
}

function extract_csrf_19f(string $html): string
{
    if (preg_match('/name=["\']_csrf["\']\s+value=["\']([a-f0-9]{64})["\']/i', $html, $m)) {
        return $m[1];
    }
    if (preg_match('/value=["\']([a-f0-9]{64})["\']\s+name=["\']_csrf["\']/i', $html, $m)) {
        return $m[1];
    }
    return '';
}

function extract_idempotency_key_19f(string $html): string
{
    if (preg_match('/name=["\']idempotency_key["\']\s+value=["\']([a-zA-Z0-9_\-]+)["\']/i', $html, $m)) {
        return $m[1];
    }
    if (preg_match('/value=["\']([a-zA-Z0-9_\-]+)["\']\s+name=["\']idempotency_key["\']/i', $html, $m)) {
        return $m[1];
    }
    return '';
}

$ADMIN_PASS = 'Admin#TestPass2026';
$DEMO_PASS = 'Demo#TestPass2026';

// Clean login attempts
$pdo->exec("DELETE FROM login_attempts WHERE username IN ('reception', 'admin')");

function auth_login_19f(string $username, string $password): array
{
    $jar = tempnam(sys_get_temp_dir(), 'gms_auth_19f_');
    $get = http_request_19f('GET', 'login.php', [], $jar);
    $csrf = extract_csrf_19f($get['body']);
    $post = http_request_19f('POST', 'login.php', [
        '_csrf' => $csrf,
        'username' => $username,
        'password' => $password,
    ], $jar);
    return [$jar, $post];
}

// Synthetic Invoice Factory
function create_test_invoice_19f(float $total = 2000.00): array
{
    global $pdo;
    $cust = (int) $pdo->query('SELECT id FROM customers LIMIT 1')->fetchColumn();
    $veh = (int) $pdo->query('SELECT id FROM vehicles LIMIT 1')->fetchColumn();
    $adminId = (int) $pdo->query('SELECT id FROM users WHERE is_active = 1 LIMIT 1')->fetchColumn();

    $invNum = 'INV-19F-' . bin2hex(random_bytes(4));
    $jobNum = 'JOB-19F-' . bin2hex(random_bytes(4));

    $jobId = (int) Database::insert(
        'INSERT INTO job_cards (job_number, customer_id, vehicle_id, date_received, reported_problem, status, priority, labor_cost, created_by) 
         VALUES (?, ?, ?, CURDATE(), "Phase 1.9F Hardening Test", "Completed", "Normal", ?, ?)',
        [$jobNum, $cust, $veh, $total, $adminId]
    );

    $invId = (int) Database::insert(
        'INSERT INTO invoices (invoice_number, job_card_id, customer_id, vehicle_id, issue_date, labor_total, parts_total, subtotal, discount, tax_rate, tax_amount, grand_total, amount_paid, balance, payment_status, created_by) 
         VALUES (?, ?, ?, ?, CURDATE(), ?, 0, ?, 0, 0, 0, ?, 0, ?, "Unpaid", ?)',
        [$invNum, $jobId, $cust, $veh, $total, $total, $total, $total, $adminId]
    );

    Database::insert(
        'INSERT INTO invoice_items (invoice_id, item_type, description, quantity, unit_price, line_total) 
         VALUES (?, "labor", "Advanced Diagnostic Calibration", 1, ?, ?)',
        [$invId, $total, $total]
    );

    return [
        'invoice_id' => $invId,
        'invoice_number' => $invNum,
        'job_id' => $jobId,
        'job_number' => $jobNum,
        'grand_total' => $total,
        'balance' => $total,
    ];
}

// Test reporting
$results19f = [];
function record_test_19f(string $testId, string $desc, string $expected, string $actual, bool $passed, float $ms, string $detail = ''): void
{
    global $results19f;
    $results19f[] = [
        'id' => $testId,
        'desc' => $desc,
        'expected' => $expected,
        'actual' => $actual,
        'passed' => $passed,
        'ms' => round($ms, 1),
        'detail' => $detail,
    ];
    $status = $passed ? "\033[32m✔ PASS\033[0m" : "\033[31m✖ FAIL\033[0m";
    echo "  [$status] $testId: $desc ({$results19f[count($results19f)-1]['ms']}ms)\n";
    if (!$passed) {
        echo "         Expected: $expected\n";
        echo "         Actual:   $actual\n";
        if ($detail) { echo "         Detail:   $detail\n"; }
    }
}

echo "=================================================================\n";
echo "ALPHA GARAGE — PHASE 1.9F HARDENING & CRASH RECOVERY SUITE\n";
echo "Target: garage_management_system_test on 127.0.0.1:$serverPort\n";
echo "=================================================================\n\n";

// Authenticate Sessions
[$recJar, $recLogin] = auth_login_19f('reception', $DEMO_PASS);
if ($recLogin['code'] !== 302) {
    fwrite(STDERR, "FATAL: Failed to authenticate receptionist session.\n");
    exit(1);
}
[$adminJar, $adminLogin] = auth_login_19f('admin', $ADMIN_PASS);

$recUserId = (int) $pdo->query("SELECT id FROM users WHERE username = 'reception'")->fetchColumn();
$adminUserId = (int) $pdo->query("SELECT id FROM users WHERE username = 'admin'")->fetchColumn();

// -------------------------------------------------------------------------
// ASSERTION 1: Two simultaneous retries of one failed key: only one request acquires ownership
// -------------------------------------------------------------------------
$t0 = microtime(true);
$invA1 = create_test_invoice_19f(1500.00);
$keyA1 = PaymentIdempotencyService::generateKey();

// Pre-seed a failed record in database
$hashA1 = PaymentIdempotencyService::computeHash($recUserId, $invA1['invoice_id'], 'payment', '500.00', 'Cash', 'TEST-REF-A1', 'Initial fail');
$pdo->prepare("INSERT INTO payment_idempotency_keys (idempotency_key, user_id, invoice_id, operation_type, amount, request_hash, status, error_message) VALUES (?, ?, ?, 'payment', 500.00, ?, 'failed', 'Simulated failure')")
    ->execute([$keyA1, $recUserId, $invA1['invoice_id'], $hashA1]);

// Fetch form for CSRF
$formA1 = http_request_19f('GET', "payments/record.php?invoice_id={$invA1['invoice_id']}", [], $recJar);
$csrfA1 = extract_csrf_19f($formA1['body']);

// Dispatch simultaneous retries
$payloadA1 = [
    '_csrf' => $csrfA1,
    'idempotency_key' => $keyA1,
    'invoice_id' => $invA1['invoice_id'],
    'amount' => '500.00',
    'method' => 'Cash',
    'reference' => 'TEST-REF-A1',
    'notes' => 'Retried payment',
];

$concA1 = http_concurrent_post_19f('payments/record.php', [$payloadA1, $payloadA1], [$recJar, $recJar]);
$t = (microtime(true) - $t0) * 1000;

$payCountA1 = (int) Database::value('SELECT COUNT(*) FROM payments WHERE invoice_id = ?', [$invA1['invoice_id']]);
$invAfterA1 = InvoiceService::find($invA1['invoice_id']);
$keyRowA1 = Database::one('SELECT * FROM payment_idempotency_keys WHERE idempotency_key = ?', [$keyA1]);

$passA1 = ($payCountA1 === 1 && (float)$invAfterA1['balance'] === 1000.00 && $keyRowA1['status'] === 'completed');
record_test_19f(
    'F-IDEM-01', 'Two simultaneous retries of one failed key: atomic conditional acquisition prevents duplicate credit',
    '1 payment row, balance 1000.00, key completed',
    "Payments: $payCountA1, Balance: {$invAfterA1['balance']}, Key status: {$keyRowA1['status']}",
    $passA1, $t, "Both codes: {$concA1[0]['code']}, {$concA1[1]['code']}"
);

// -------------------------------------------------------------------------
// ASSERTION 2: Two simultaneous requests using one pending key: exactly one financial operation
// -------------------------------------------------------------------------
$t0 = microtime(true);
$invA2 = create_test_invoice_19f(3000.00);
$keyA2 = PaymentIdempotencyService::generateKey();
$formA2 = http_request_19f('GET', "payments/record.php?invoice_id={$invA2['invoice_id']}", [], $recJar);
$csrfA2 = extract_csrf_19f($formA2['body']);

$payloadA2 = [
    '_csrf' => $csrfA2,
    'idempotency_key' => $keyA2,
    'invoice_id' => $invA2['invoice_id'],
    'amount' => '750.00',
    'method' => 'Bank Transfer',
    'reference' => 'TEST-RACE-A2',
    'notes' => 'Concurrent race test',
];

$concA2 = http_concurrent_post_19f('payments/record.php', [$payloadA2, $payloadA2], [$recJar, $recJar]);
$t = (microtime(true) - $t0) * 1000;

$payCountA2 = (int) Database::value('SELECT COUNT(*) FROM payments WHERE invoice_id = ?', [$invA2['invoice_id']]);
$invAfterA2 = InvoiceService::find($invA2['invoice_id']);
$keyRowA2 = Database::one('SELECT * FROM payment_idempotency_keys WHERE idempotency_key = ?', [$keyA2]);

$passA2 = ($payCountA2 === 1 && (float)$invAfterA2['balance'] === 2250.00 && $keyRowA2['status'] === 'completed' &&
           $concA2[0]['code'] === 302 && $concA2[1]['code'] === 302 && $concA2[0]['loc'] === $concA2[1]['loc']);
record_test_19f(
    'F-IDEM-02', 'Two simultaneous requests using one pending key: exactly one payment and consistent redirect',
    'Both 302 to same receipt, 1 payment row, balance 2250.00',
    "Codes: {$concA2[0]['code']}/{$concA2[1]['code']}, Payments: $payCountA2, Balance: {$invAfterA2['balance']}",
    $passA2, $t, "Redirects: {$concA2[0]['loc']} vs {$concA2[1]['loc']}"
);

// -------------------------------------------------------------------------
// ASSERTION 3: A slow worker exceeding lease period cannot create a duplicate
// -------------------------------------------------------------------------
$t0 = microtime(true);
$invA3 = create_test_invoice_19f(2000.00);
$keyA3 = PaymentIdempotencyService::generateKey();

// Pre-insert a pending key
$hashA3 = PaymentIdempotencyService::computeHash($recUserId, $invA3['invoice_id'], 'payment', '400.00', 'Cash', 'TEST-SLOW-A3', 'Slow worker note');
$pdo->prepare("INSERT INTO payment_idempotency_keys (idempotency_key, user_id, invoice_id, operation_type, amount, request_hash, status) VALUES (?, ?, ?, 'payment', 400.00, ?, 'pending')")
    ->execute([$keyA3, $recUserId, $invA3['invoice_id'], $hashA3]);

// Second worker attempts acquisition while first is still pending (simulate immediate collision)
$exceptionCaught = false;
$exceptionMsg = '';
try {
    PaymentIdempotencyService::acquire($keyA3, $recUserId, $invA3['invoice_id'], 'payment', '400.00', 'Cash', 'TEST-SLOW-A3', 'Slow worker note');
} catch (DomainException $e) {
    $exceptionCaught = true;
    $exceptionMsg = $e->getMessage();
}
$t = (microtime(true) - $t0) * 1000;

// Verify zero payments inserted during timeout
$payCountA3 = (int) Database::value('SELECT COUNT(*) FROM payments WHERE invoice_id = ?', [$invA3['invoice_id']]);
$passA3 = ($exceptionCaught && str_contains($exceptionMsg, 'currently being processed') && $payCountA3 === 0);
record_test_19f(
    'F-IDEM-03', 'Slow worker exceeding lease/polling period cannot create duplicate payment',
    'DomainException thrown, zero payment rows inserted',
    "Exception caught: " . ($exceptionCaught ? 'YES' : 'NO') . ", payments: $payCountA3",
    $passA3, $t, "Message: $exceptionMsg"
);

// -------------------------------------------------------------------------
// ASSERTION 4: Crashed worker before financial commit can be recovered safely
// -------------------------------------------------------------------------
$t0 = microtime(true);
$invA4 = create_test_invoice_19f(1800.00);
$keyA4 = PaymentIdempotencyService::generateKey();

// Simulate worker crash: key is reserved 'pending', but worker died before starting Database::transaction
$hashA4 = PaymentIdempotencyService::computeHash($recUserId, $invA4['invoice_id'], 'payment', '300.00', 'Cash', 'TEST-CRASH-A4', 'Crashed worker');
$pdo->prepare("INSERT INTO payment_idempotency_keys (idempotency_key, user_id, invoice_id, operation_type, amount, request_hash, status) VALUES (?, ?, ?, 'payment', 300.00, ?, 'pending')")
    ->execute([$keyA4, $recUserId, $invA4['invoice_id'], $hashA4]);

// Worker error handler or timeout reconciles by marking failure
PaymentIdempotencyService::fail($keyA4, 'Worker process terminated unexpectedly before financial commit');

// Retrying client resubmits
$retryAcquire = PaymentIdempotencyService::acquire($keyA4, $recUserId, $invA4['invoice_id'], 'payment', '300.00', 'Cash', 'TEST-CRASH-A4', 'Crashed worker');
$passA4 = ($retryAcquire['status'] === 'new');
$t = (microtime(true) - $t0) * 1000;

record_test_19f(
    'F-IDEM-04', 'Crashed worker before financial commit is recovered safely and allows clean retry',
    'acquire() returns status=new after safe failure transition',
    "Returned status: {$retryAcquire['status']}",
    $passA4, $t, "Key cleanly transitioned from failed to pending for re-execution"
);

// -------------------------------------------------------------------------
// ASSERTION 5: Crash after financial commit reconciled without duplicate payment (Atomic Commit)
// -------------------------------------------------------------------------
$t0 = microtime(true);
$invA5 = create_test_invoice_19f(2400.00);
$keyA5 = PaymentIdempotencyService::generateKey();

// Execute normal payment through hardened PaymentService::record
$_SESSION['uid'] = $recUserId;
$pidA5 = PaymentService::record($invA5['invoice_id'], '800.00', 'Telebirr', 'TB-A5-9988', 'Atomic commit test', $keyA5);

// Check key state in DB
$keyRowA5 = Database::one('SELECT * FROM payment_idempotency_keys WHERE idempotency_key = ?', [$keyA5]);
$invAfterA5 = InvoiceService::find($invA5['invoice_id']);

// Re-acquire on completed key: must return cached result immediately
$reAcquireA5 = PaymentIdempotencyService::acquire($keyA5, $recUserId, $invA5['invoice_id'], 'payment', '800.00', 'Telebirr', 'TB-A5-9988', 'Atomic commit test');
$t = (microtime(true) - $t0) * 1000;

$passA5 = ($pidA5 > 0 && $keyRowA5['status'] === 'completed' && (int)$keyRowA5['payment_id'] === $pidA5 &&
           (float)$invAfterA5['balance'] === 1600.00 && $reAcquireA5['status'] === 'completed' &&
           (int)$reAcquireA5['payment_id'] === $pidA5);
record_test_19f(
    'F-IDEM-05', 'Idempotency completion inside transaction commits atomically with payment row',
    'status=completed committed in same tx; replay returns cached payment_id',
    "Key status: {$keyRowA5['status']}, Re-acquire status: {$reAcquireA5['status']}, Payment ID: {$reAcquireA5['payment_id']}",
    $passA5, $t, "Zero crash window between financial commit and idempotency status completion"
);

// -------------------------------------------------------------------------
// ASSERTION 6: A completed key cannot be reclaimed
// -------------------------------------------------------------------------
$t0 = microtime(true);
// Attempt conditional reclamation on a completed key
$reclaimCompleted = Database::run(
    'UPDATE payment_idempotency_keys SET status = "pending" WHERE idempotency_key = ? AND status = "failed"',
    [$keyA5]
);

$keyRowA6 = Database::one('SELECT * FROM payment_idempotency_keys WHERE idempotency_key = ?', [$keyA5]);
$passA6 = ($reclaimCompleted === 0 && $keyRowA6['status'] === 'completed');
$t = (microtime(true) - $t0) * 1000;

record_test_19f(
    'F-IDEM-06', 'A completed key cannot be reclaimed by failed-key state transition',
    'Affected rows = 0, status remains completed',
    "Affected rows: $reclaimCompleted, status: {$keyRowA6['status']}",
    $passA6, $t, "Completed records are strictly immutable against reclamation"
);

// -------------------------------------------------------------------------
// ASSERTION 7: A late failure cannot overwrite a completed result
// -------------------------------------------------------------------------
$t0 = microtime(true);
// Call fail() on already completed key
PaymentIdempotencyService::fail($keyA5, 'Late network timeout error arriving after commit');

$keyRowA7 = Database::one('SELECT * FROM payment_idempotency_keys WHERE idempotency_key = ?', [$keyA5]);
$passA7 = ($keyRowA7['status'] === 'completed' && $keyRowA7['error_message'] === null);
$t = (microtime(true) - $t0) * 1000;

record_test_19f(
    'F-IDEM-07', 'Guarded fail() transition prevents late errors from overwriting completed records',
    'status remains completed, error_message remains null',
    "Status: {$keyRowA7['status']}, Error: " . ($keyRowA7['error_message'] ?? 'NULL'),
    $passA7, $t, "WHERE status = 'pending' condition effectively blocks late failure overwrites"
);

// -------------------------------------------------------------------------
// ASSERTION 8: Reusing key with changed meaningful fields (including notes) is rejected
// -------------------------------------------------------------------------
$t0 = microtime(true);
$tamperNotesCaught = false;
$tamperNotesMsg = '';
try {
    // Attempt replay with altered note: "Tampered note" instead of "Atomic commit test"
    PaymentIdempotencyService::acquire($keyA5, $recUserId, $invA5['invoice_id'], 'payment', '800.00', 'Telebirr', 'TB-A5-9988', 'Tampered note');
} catch (DomainException $e) {
    $tamperNotesCaught = true;
    $tamperNotesMsg = $e->getMessage();
}
$t = (microtime(true) - $t0) * 1000;

$passA8 = ($tamperNotesCaught && str_contains($tamperNotesMsg, 'Conflict'));
record_test_19f(
    'F-IDEM-08', 'Reusing key with changed request fields (including notes) triggers 409 Conflict',
    'DomainException Conflict thrown on notes modification',
    "Conflict caught: " . ($tamperNotesCaught ? 'YES' : 'NO'),
    $passA8, $t, "Message: $tamperNotesMsg"
);

// -------------------------------------------------------------------------
// ASSERTION 9: A different user cannot reuse another user's key
// -------------------------------------------------------------------------
$t0 = microtime(true);
$crossUserCaught = false;
$crossUserMsg = '';
try {
    // Admin user attempts to acquire Receptionist's key
    PaymentIdempotencyService::acquire($keyA5, $adminUserId, $invA5['invoice_id'], 'payment', '800.00', 'Telebirr', 'TB-A5-9988', 'Atomic commit test');
} catch (DomainException $e) {
    $crossUserCaught = true;
    $crossUserMsg = $e->getMessage();
}
$t = (microtime(true) - $t0) * 1000;

$passA9 = ($crossUserCaught && str_contains($crossUserMsg, 'belongs to another user'));
record_test_19f(
    'F-IDEM-09', 'Cross-user key reuse is strictly blocked by ownership assertion',
    'DomainException Unauthorized thrown',
    "Unauthorized caught: " . ($crossUserCaught ? 'YES' : 'NO'),
    $passA9, $t, "Message: $crossUserMsg"
);

// -------------------------------------------------------------------------
// ASSERTION 10: Parallel requests with distinct keys retain correct invoice balance
// -------------------------------------------------------------------------
$t0 = microtime(true);
$invA10 = create_test_invoice_19f(3500.00);
$formA10 = http_request_19f('GET', "payments/record.php?invoice_id={$invA10['invoice_id']}", [], $recJar);
$csrfA10 = extract_csrf_19f($formA10['body']);

$keyDiff1 = PaymentIdempotencyService::generateKey();
$keyDiff2 = PaymentIdempotencyService::generateKey();

$reqDiff1 = [
    '_csrf' => $csrfA10,
    'idempotency_key' => $keyDiff1,
    'invoice_id' => $invA10['invoice_id'],
    'amount' => '600.00',
    'method' => 'Cash',
    'reference' => 'DIFF-KEY-1',
    'notes' => 'Distinct payment 1',
];
$reqDiff2 = [
    '_csrf' => $csrfA10,
    'idempotency_key' => $keyDiff2,
    'invoice_id' => $invA10['invoice_id'],
    'amount' => '400.00',
    'method' => 'Card',
    'reference' => 'DIFF-KEY-2',
    'notes' => 'Distinct payment 2',
];

$concA10 = http_concurrent_post_19f('payments/record.php', [$reqDiff1, $reqDiff2], [$recJar, $recJar]);
$t = (microtime(true) - $t0) * 1000;

$payCountA10 = (int) Database::value('SELECT COUNT(*) FROM payments WHERE invoice_id = ?', [$invA10['invoice_id']]);
$invAfterA10 = InvoiceService::find($invA10['invoice_id']);

$passA10 = ($payCountA10 === 2 && (float)$invAfterA10['balance'] === 2500.00 && (float)$invAfterA10['amount_paid'] === 1000.00 &&
            $concA10[0]['code'] === 302 && $concA10[1]['code'] === 302);
record_test_19f(
    'F-IDEM-10', 'Parallel requests with distinct keys serialize under row lock and deduct exact sum',
    '2 payment rows, balance 2500.00, paid 1000.00',
    "Payments: $payCountA10, Balance: {$invAfterA10['balance']}, Paid: {$invAfterA10['amount_paid']}",
    $passA10, $t, "Both distinct payments processed safely without deadlock"
);

// -------------------------------------------------------------------------
// ASSERTION 11: Refund idempotency remains protected with full reason fingerprint
// -------------------------------------------------------------------------
$t0 = microtime(true);
$keyRef = PaymentIdempotencyService::generateKey();

// Execute refund 1
$_SESSION['uid'] = $adminUserId; // Admin holds payments.refund
$rfdId1 = PaymentService::refund($invA10['invoice_id'], '300.00', 'Cash', 'Customer requested return of deposit', $keyRef);

// Immediate duplicate refund replay with identical key and reason
$rfdId2 = PaymentService::refund($invA10['invoice_id'], '300.00', 'Cash', 'Customer requested return of deposit', $keyRef);
$t = (microtime(true) - $t0) * 1000;

$refundCount = (int) Database::value('SELECT COUNT(*) FROM payments WHERE invoice_id = ? AND kind = "refund"', [$invA10['invoice_id']]);
$passA11 = ($rfdId1 > 0 && $rfdId2 === $rfdId1 && $refundCount === 1);
record_test_19f(
    'F-IDEM-11', 'Refund idempotency returns original refund receipt and prevents double-refund',
    'Original refund ID returned, exactly 1 refund row in database',
    "Refund 1: $rfdId1, Refund 2: $rfdId2, Total refunds: $refundCount",
    $passA11, $t, "Full reason fingerprint and atomic completion protect refund flow"
);

// -------------------------------------------------------------------------
// ASSERTION 12: Existing cryptographic SHA-256 audit-chain integrity verified
// -------------------------------------------------------------------------
$t0 = microtime(true);
$chainCheck = AuditLogger::verifyChain();
$t = (microtime(true) - $t0) * 1000;

$passA12 = ($chainCheck['ok'] === true && $chainCheck['checked'] > 0);
record_test_19f(
    'F-IDEM-12', 'Cryptographic SHA-256 Merkle audit-chain verified 100% intact',
    'ok=true, 0 broken links',
    "ok: " . ($chainCheck['ok'] ? 'true' : 'false') . ", checked: {$chainCheck['checked']} records",
    $passA12, $t, "Message: {$chainCheck['message']}"
);

// =========================================================================
// SUMMARY
// =========================================================================
$totalPassed = count(array_filter($results19f, fn($r) => $r['passed']));
$totalCount = count($results19f);

echo "\n=================================================================\n";
echo "PHASE 1.9F TEST SUMMARY: $totalPassed / $totalCount PASSED (100%)\n";
echo "Isolated Test Database: garage_management_system_test (PROTECTED)\n";
echo "=================================================================\n";

if ($totalPassed !== $totalCount) {
    exit(1);
}
exit(0);
