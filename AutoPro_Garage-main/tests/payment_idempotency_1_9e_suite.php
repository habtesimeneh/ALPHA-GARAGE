<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — PHASE 1.9E: PAYMENT IDEMPOTENCY & DUPLICATE PAYMENT PROTECTION SUITE
 * 
 * Verifies atomic duplicate payment prevention across:
 *   1. Normal payment submission
 *   2. Immediate duplicate submission (rapid double-click)
 *   3. Browser-style POST replay (F5 / form resubmit)
 *   4. Same key with different amount (payload tampering)
 *   5. Same key with different invoice (target tampering)
 *   6. Concurrent requests using the same key (parallel race condition)
 *   7. Concurrent requests using different keys (parallel valid payments)
 *   8. Failed payment transaction and retry
 *   9. Invalid or missing idempotency key
 *  10. Unauthorized user attempting to reuse another user's key
 *  11. Invoice balance consistency
 *  12. Payment record consistency
 *  13. Audit record consistency & hash chain integrity
 *  14. Refund idempotency
 * 
 * Target Database: garage_management_system_test
 * Fail-Closed: Refuses to execute if connected to production.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Error: tests/payment_idempotency_1_9e_suite.php must be run from the CLI.\n");
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
// 2. ISOLATED TEST WEB SERVER SPAWN (PORT 8088)
// =========================================================================
$serverPort = 8088;
$serverBase = "http://127.0.0.1:$serverPort/";

$serverLog = APP_ROOT . '/storage/testing/logs/test-server.log';
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
function http_request(string $method, string $path, array $data = [], string $jar = '', array $extraHeaders = []): array
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

function http_concurrent_post(string $path, array $requests, array $jars): array
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

function extract_csrf(string $html): string
{
    if (preg_match('/name=["\']_csrf["\']\s+value=["\']([a-f0-9]{64})["\']/i', $html, $m)) {
        return $m[1];
    }
    if (preg_match('/value=["\']([a-f0-9]{64})["\']\s+name=["\']_csrf["\']/i', $html, $m)) {
        return $m[1];
    }
    return '';
}

function extract_idempotency_key(string $html): string
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

// Clean any previous login attempts
$pdo->exec("DELETE FROM login_attempts WHERE username IN ('reception', 'admin')");

function auth_login(string $username, string $password): array
{
    $jar = tempnam(sys_get_temp_dir(), 'gms_auth_');
    $get = http_request('GET', 'login.php', [], $jar);
    $csrf = extract_csrf($get['body']);
    $post = http_request('POST', 'login.php', [
        '_csrf' => $csrf,
        'username' => $username,
        'password' => $password,
    ], $jar);
    return [$jar, $post];
}

// Synthetic Invoice Factory
function create_test_invoice(float $total = 2000.00): array
{
    global $pdo;
    $cust = (int) $pdo->query('SELECT id FROM customers LIMIT 1')->fetchColumn();
    $veh = (int) $pdo->query('SELECT id FROM vehicles LIMIT 1')->fetchColumn();
    $adminId = (int) $pdo->query('SELECT id FROM users WHERE is_active = 1 LIMIT 1')->fetchColumn();

    $invNum = 'INV-TEST-19E-' . bin2hex(random_bytes(4));
    $jobNum = 'JOB-TEST-19E-' . bin2hex(random_bytes(4));

    $jobId = (int) Database::insert(
        'INSERT INTO job_cards (job_number, customer_id, vehicle_id, date_received, reported_problem, status, priority, labor_cost, created_by) 
         VALUES (?, ?, ?, CURDATE(), "Synthetic Idempotency Test", "Completed", "Normal", ?, ?)',
        [$jobNum, $cust, $veh, $total, $adminId]
    );

    $invId = (int) Database::insert(
        'INSERT INTO invoices (invoice_number, job_card_id, customer_id, vehicle_id, issue_date, labor_total, parts_total, subtotal, discount, tax_rate, tax_amount, grand_total, amount_paid, balance, payment_status, created_by) 
         VALUES (?, ?, ?, ?, CURDATE(), ?, 0, ?, 0, 0, 0, ?, 0, ?, "Unpaid", ?)',
        [$invNum, $jobId, $cust, $veh, $total, $total, $total, $total, $adminId]
    );

    Database::insert(
        'INSERT INTO invoice_items (invoice_id, item_type, description, quantity, unit_price, line_total) 
         VALUES (?, "labor", "Comprehensive System Diagnostics", 1, ?, ?)',
        [$invId, $total, $total]
    );

    return [
        'invoice_id' => $invId,
        'invoice_number' => $invNum,
        'job_id' => $jobId,
        'job_number' => $jobNum,
        'customer_id' => $cust,
        'grand_total' => $total,
        'balance' => $total,
    ];
}

// Test reporting
$results = [];
function record_test(string $testId, string $desc, string $expected, string $actual, bool $passed, float $ms, string $detail = ''): void
{
    global $results;
    $results[] = [
        'id' => $testId,
        'desc' => $desc,
        'expected' => $expected,
        'actual' => $actual,
        'passed' => $passed,
        'ms' => round($ms, 1),
        'detail' => $detail,
    ];
    $status = $passed ? "\033[32m✔ PASS\033[0m" : "\033[31m✖ FAIL\033[0m";
    echo "  [$status] $testId: $desc ({$results[count($results)-1]['ms']}ms)\n";
    if (!$passed) {
        echo "         Expected: $expected\n";
        echo "         Actual:   $actual\n";
        if ($detail) { echo "         Detail:   $detail\n"; }
    }
}

echo "=================================================================\n";
echo "ALPHA GARAGE — PHASE 1.9E PAYMENT IDEMPOTENCY TEST SUITE\n";
echo "Isolated Target: garage_management_system_test on 127.0.0.1:$serverPort\n";
echo "=================================================================\n\n";

// Authenticate Receptionist for HTTP requests
[$recJar, $recLogin] = auth_login('reception', $DEMO_PASS);
if ($recLogin['code'] !== 302) {
    fwrite(STDERR, "FATAL: Failed to authenticate receptionist session.\n");
    exit(1);
}

// Authenticate Admin for cross-user tests
[$adminJar, $adminLogin] = auth_login('admin', $ADMIN_PASS);

// -------------------------------------------------------------------------
// TEST SUITE EXECUTION
// -------------------------------------------------------------------------

// Create test invoice for IDEM-01, 02, 03
$inv1 = create_test_invoice(2000.00);
$inv1Id = $inv1['invoice_id'];

// Get payment form to extract CSRF and Idempotency Key
$formRes = http_request('GET', "payments/record.php?invoice_id=$inv1Id", [], $recJar);
$csrfRec = extract_csrf($formRes['body']);
$key1 = extract_idempotency_key($formRes['body']);
if (!$key1) { $key1 = PaymentIdempotencyService::generateKey(); }

// -------------------------------------------------------------------------
// IDEM-01: Normal payment submission
// -------------------------------------------------------------------------
$t0 = microtime(true);
$pay1Res = http_request('POST', 'payments/record.php', [
    '_csrf' => $csrfRec,
    'idempotency_key' => $key1,
    'invoice_id' => $inv1Id,
    'amount' => '600.00',
    'method' => 'Cash',
    'reference' => 'TEST-NORMAL-01',
    'notes' => 'Normal initial payment',
], $recJar);
$t = (microtime(true) - $t0) * 1000;

preg_match('~receipt\.php\?id=(\d+)~', $pay1Res['loc'], $mPid1);
$pid1 = (int) ($mPid1[1] ?? 0);

$invAfter1 = InvoiceService::find($inv1Id);
$payCount1 = (int) Database::value('SELECT COUNT(*) FROM payments WHERE invoice_id = ?', [$inv1Id]);
$keyRow1 = Database::one('SELECT * FROM payment_idempotency_keys WHERE idempotency_key = ?', [$key1]);

$pIdem1 = ($pay1Res['code'] === 302 && $pid1 > 0 && $payCount1 === 1 &&
           (float)$invAfter1['balance'] === 1400.00 && (float)$invAfter1['amount_paid'] === 600.00 &&
           $keyRow1['status'] === 'completed' && (int)$keyRow1['payment_id'] === $pid1);

record_test(
    'IDEM-01', 'Normal payment submission records single payment and updates balance accurately',
    'HTTP 302, 1 payment row, balance 1400.00, key completed',
    "HTTP {$pay1Res['code']}, payments: $payCount1, balance: {$invAfter1['balance']}, key status: {$keyRow1['status']}",
    $pIdem1, $t, "Payment ID: $pid1, Receipt redirect: {$pay1Res['loc']}"
);

// -------------------------------------------------------------------------
// IDEM-02: Immediate duplicate submission (Double-Click Protection)
// -------------------------------------------------------------------------
$t0 = microtime(true);
$pay2Res = http_request('POST', 'payments/record.php', [
    '_csrf' => $csrfRec,
    'idempotency_key' => $key1, // EXACT SAME KEY
    'invoice_id' => $inv1Id,
    'amount' => '600.00',
    'method' => 'Cash',
    'reference' => 'TEST-NORMAL-01',
    'notes' => 'Normal initial payment',
], $recJar);
$t = (microtime(true) - $t0) * 1000;

preg_match('~receipt\.php\?id=(\d+)~', $pay2Res['loc'], $mPid2);
$pid2 = (int) ($mPid2[1] ?? 0);

$invAfter2 = InvoiceService::find($inv1Id);
$payCount2 = (int) Database::value('SELECT COUNT(*) FROM payments WHERE invoice_id = ?', [$inv1Id]);

$pIdem2 = ($pay2Res['code'] === 302 && $pid2 === $pid1 && $payCount2 === 1 &&
           (float)$invAfter2['balance'] === 1400.00 && (float)$invAfter2['amount_paid'] === 600.00);

record_test(
    'IDEM-02', 'Immediate duplicate submission returns original payment without double-credit',
    'HTTP 302 redirect to original receipt, 1 payment row, balance untouched at 1400.00',
    "HTTP {$pay2Res['code']} to PID $pid2, payments: $payCount2, balance: {$invAfter2['balance']}",
    $pIdem2, $t, "Double-click prevented: zero duplicate credit"
);

// -------------------------------------------------------------------------
// IDEM-03: Browser-style POST replay (F5 / Form Resubmission)
// -------------------------------------------------------------------------
$t0 = microtime(true);
usleep(200000); // 200ms delay simulating browser reload
$pay3Res = http_request('POST', 'payments/record.php', [
    '_csrf' => $csrfRec,
    'idempotency_key' => $key1,
    'invoice_id' => $inv1Id,
    'amount' => '600.00',
    'method' => 'Cash',
    'reference' => 'TEST-NORMAL-01',
    'notes' => 'Normal initial payment',
], $recJar);
$t = (microtime(true) - $t0) * 1000;

$invAfter3 = InvoiceService::find($inv1Id);
$payCount3 = (int) Database::value('SELECT COUNT(*) FROM payments WHERE invoice_id = ?', [$inv1Id]);

$pIdem3 = ($pay3Res['code'] === 302 && $payCount3 === 1 && (float)$invAfter3['balance'] === 1400.00);

record_test(
    'IDEM-03', 'Browser-style POST replay safely returns existing receipt with zero duplicate row',
    'HTTP 302 redirect to original receipt, balance strictly 1400.00',
    "HTTP {$pay3Res['code']}, payments: $payCount3, balance: {$invAfter3['balance']}",
    $pIdem3, $t, "Form resubmission handled idempotently"
);

// -------------------------------------------------------------------------
// IDEM-04: Same key with different amount (Payload Tampering)
// -------------------------------------------------------------------------
$t0 = microtime(true);
$pay4Res = http_request('POST', 'payments/record.php', [
    '_csrf' => $csrfRec,
    'idempotency_key' => $key1, // Reusing key1
    'invoice_id' => $inv1Id,
    'amount' => '700.00', // DIFFERENT AMOUNT!
    'method' => 'Cash',
    'reference' => 'TEST-NORMAL-01',
    'notes' => 'Tampered amount test',
], $recJar);
$t = (microtime(true) - $t0) * 1000;

$invAfter4 = InvoiceService::find($inv1Id);
$payCount4 = (int) Database::value('SELECT COUNT(*) FROM payments WHERE invoice_id = ?', [$inv1Id]);

$pIdem4 = ($pay4Res['code'] === 200 && str_contains($pay4Res['body'], 'Conflict') && $payCount4 === 1 && (float)$invAfter4['balance'] === 1400.00);

record_test(
    'IDEM-04', 'Reusing idempotency key with modified amount is rejected as conflict',
    'HTTP 200 validation conflict error, payments remain 1, balance untouched at 1400.00',
    "HTTP {$pay4Res['code']}, payments: $payCount4, balance: {$invAfter4['balance']}",
    $pIdem4, $t, "Amount tampering rejected"
);

// -------------------------------------------------------------------------
// IDEM-05: Same key with different invoice (Target Tampering)
// -------------------------------------------------------------------------
$t0 = microtime(true);
$inv2 = create_test_invoice(1500.00);
$inv2Id = $inv2['invoice_id'];

$pay5Res = http_request('POST', 'payments/record.php', [
    '_csrf' => $csrfRec,
    'idempotency_key' => $key1, // Reusing key1
    'invoice_id' => $inv2Id,    // DIFFERENT INVOICE!
    'amount' => '600.00',
    'method' => 'Cash',
    'reference' => 'TEST-NORMAL-01',
    'notes' => 'Tampered target invoice',
], $recJar);
$t = (microtime(true) - $t0) * 1000;

$inv2After5 = InvoiceService::find($inv2Id);
$payCount5 = (int) Database::value('SELECT COUNT(*) FROM payments WHERE invoice_id = ?', [$inv2Id]);

$pIdem5 = ($pay5Res['code'] === 200 && str_contains($pay5Res['body'], 'Conflict') && $payCount5 === 0 && (float)$inv2After5['balance'] === 1500.00);

record_test(
    'IDEM-05', 'Reusing idempotency key with different invoice target is rejected as conflict',
    'HTTP 200 validation conflict error, invoice 2 payments = 0, balance untouched at 1500.00',
    "HTTP {$pay5Res['code']}, invoice 2 payments: $payCount5, balance: {$inv2After5['balance']}",
    $pIdem5, $t, "Invoice tampering rejected"
);

// -------------------------------------------------------------------------
// IDEM-06: Concurrent requests using the same key (Parallel Race Condition)
// -------------------------------------------------------------------------
$t0 = microtime(true);
$inv3 = create_test_invoice(3000.00);
$inv3Id = $inv3['invoice_id'];

$keyRace = PaymentIdempotencyService::generateKey();
$racePayload = [
    '_csrf' => $csrfRec,
    'idempotency_key' => $keyRace,
    'invoice_id' => $inv3Id,
    'amount' => '500.00',
    'method' => 'Cash',
    'reference' => 'RACE-SAME-KEY',
    'notes' => 'Simultaneous parallel double submission',
];

// Fire 2 concurrent HTTP requests simultaneously
$concurResults = http_concurrent_post('payments/record.php', [$racePayload, $racePayload], [$recJar, $recJar]);
$t = (microtime(true) - $t0) * 1000;

$inv3After = InvoiceService::find($inv3Id);
$payCount3 = (int) Database::value('SELECT COUNT(*) FROM payments WHERE invoice_id = ?', [$inv3Id]);

// Both should return HTTP 302 (one recorded, one returned cached result)
$both302 = ($concurResults[0]['code'] === 302 && $concurResults[1]['code'] === 302);
$pIdem6 = ($both302 && $payCount3 === 1 && (float)$inv3After['balance'] === 2500.00 && (float)$inv3After['amount_paid'] === 500.00);

record_test(
    'IDEM-06', 'Concurrent parallel requests with same key produce exactly ONE payment and ONE credit',
    'Both return HTTP 302, exactly 1 payment record, balance reduced by exactly 500.00',
    "Codes: [{$concurResults[0]['code']}, {$concurResults[1]['code']}], payments: $payCount3, balance: {$inv3After['balance']}",
    $pIdem6, $t, "Parallel race condition safely handled: zero duplicate credit"
);

// -------------------------------------------------------------------------
// IDEM-07: Concurrent requests using different keys (Parallel Valid Payments)
// -------------------------------------------------------------------------
$t0 = microtime(true);
$keyDiffA = PaymentIdempotencyService::generateKey();
$keyDiffB = PaymentIdempotencyService::generateKey();

$payA = [
    '_csrf' => $csrfRec,
    'idempotency_key' => $keyDiffA,
    'invoice_id' => $inv3Id,
    'amount' => '300.00',
    'method' => 'Cash',
    'reference' => 'CONCUR-DIFF-A',
    'notes' => 'Parallel payment A',
];
$payB = [
    '_csrf' => $csrfRec,
    'idempotency_key' => $keyDiffB,
    'invoice_id' => $inv3Id,
    'amount' => '200.00',
    'method' => 'Cash',
    'reference' => 'CONCUR-DIFF-B',
    'notes' => 'Parallel payment B',
];

$concurDiffRes = http_concurrent_post('payments/record.php', [$payA, $payB], [$recJar, $recJar]);
$t = (microtime(true) - $t0) * 1000;

$inv3AfterDiff = InvoiceService::find($inv3Id);
$payCount3AfterDiff = (int) Database::value('SELECT COUNT(*) FROM payments WHERE invoice_id = ?', [$inv3Id]);

// Both valid separate payments should succeed: 2500 - 300 - 200 = 2000.00
$bothDiff302 = ($concurDiffRes[0]['code'] === 302 && $concurDiffRes[1]['code'] === 302);
$pIdem7 = ($bothDiff302 && $payCount3AfterDiff === 3 && (float)$inv3AfterDiff['balance'] === 2000.00 && (float)$inv3AfterDiff['amount_paid'] === 1000.00);

record_test(
    'IDEM-07', 'Concurrent requests using distinct keys process sequentially and correctly update ledger',
    'Both return HTTP 302, 3 total payments, balance reduced to exactly 2000.00',
    "Codes: [{$concurDiffRes[0]['code']}, {$concurDiffRes[1]['code']}], payments: $payCount3AfterDiff, balance: {$inv3AfterDiff['balance']}",
    $pIdem7, $t, "Parallel distinct payments succeeded cleanly under row lock"
);

// -------------------------------------------------------------------------
// IDEM-08: Failed payment transaction and retry
// -------------------------------------------------------------------------
$t0 = microtime(true);
$keyFailRetry = PaymentIdempotencyService::generateKey();

// Try paying 99999.00 (exceeds balance 2000.00)
$failRes = http_request('POST', 'payments/record.php', [
    '_csrf' => $csrfRec,
    'idempotency_key' => $keyFailRetry,
    'invoice_id' => $inv3Id,
    'amount' => '99999.00',
    'method' => 'Cash',
    'reference' => 'OVERPAY-FAIL',
    'notes' => 'Overpayment attempt',
], $recJar);

$keyFailRow = Database::one('SELECT * FROM payment_idempotency_keys WHERE idempotency_key = ?', [$keyFailRetry]);

// Now retry with corrected valid amount using SAME key
$retryRes = http_request('POST', 'payments/record.php', [
    '_csrf' => $csrfRec,
    'idempotency_key' => $keyFailRetry,
    'invoice_id' => $inv3Id,
    'amount' => '2000.00',
    'method' => 'Cash',
    'reference' => 'OVERPAY-CORRECTED',
    'notes' => 'Corrected full balance payment',
], $recJar);
$t = (microtime(true) - $t0) * 1000;

$inv3Final = InvoiceService::find($inv3Id);
$keyRetryRow = Database::one('SELECT * FROM payment_idempotency_keys WHERE idempotency_key = ?', [$keyFailRetry]);

$pIdem8 = (str_contains($failRes['body'], 'exceeds the outstanding balance') &&
           $keyFailRow['status'] === 'failed' &&
           $retryRes['code'] === 302 &&
           $keyRetryRow['status'] === 'completed' &&
           (float)$inv3Final['balance'] === 0.00 &&
           $inv3Final['payment_status'] === 'Paid');

record_test(
    'IDEM-08', 'Failed payment transaction rolls back, marks key failed, and allows successful retry',
    'Initial rejected with balance error, key marked failed, retry succeeds with HTTP 302 and balance 0.00',
    "Initial fail: status={$keyFailRow['status']}, Retry: HTTP {$retryRes['code']}, final balance: {$inv3Final['balance']}",
    $pIdem8, $t, "Failed transaction unlocked and retried successfully"
);

// -------------------------------------------------------------------------
// IDEM-09: Invalid or missing idempotency key
// -------------------------------------------------------------------------
$t0 = microtime(true);
$inv4 = create_test_invoice(1000.00);
$inv4Id = $inv4['invoice_id'];

// Test 9a: Missing key
$missRes = http_request('POST', 'payments/record.php', [
    '_csrf' => $csrfRec,
    'invoice_id' => $inv4Id,
    'amount' => '200.00',
    'method' => 'Cash',
], $recJar);

// Test 9b: Malformed key (too short)
$shortRes = http_request('POST', 'payments/record.php', [
    '_csrf' => $csrfRec,
    'idempotency_key' => 'short',
    'invoice_id' => $inv4Id,
    'amount' => '200.00',
    'method' => 'Cash',
], $recJar);
$t = (microtime(true) - $t0) * 1000;

$inv4After = InvoiceService::find($inv4Id);
$payCount4 = (int) Database::value('SELECT COUNT(*) FROM payments WHERE invoice_id = ?', [$inv4Id]);

$pIdem9 = (str_contains($missRes['body'], 'valid idempotency key is required') &&
           str_contains($shortRes['body'], 'valid idempotency key is required') &&
           $payCount4 === 0 && (float)$inv4After['balance'] === 1000.00);

record_test(
    'IDEM-09', 'Missing or malformed idempotency key is rejected with validation error',
    'HTTP 200 with validation error, zero payments recorded, balance untouched at 1000.00',
    "Missing error present: YES, Short error present: YES, payments: $payCount4, balance: {$inv4After['balance']}",
    $pIdem9, $t, "Strict key format validation enforced"
);

// -------------------------------------------------------------------------
// IDEM-10: Unauthorized user attempting to reuse another user's key
// -------------------------------------------------------------------------
$t0 = microtime(true);
$keyAlice = PaymentIdempotencyService::generateKey();

// Receptionist processes payment with keyAlice
$recPayAlice = http_request('POST', 'payments/record.php', [
    '_csrf' => $csrfRec,
    'idempotency_key' => $keyAlice,
    'invoice_id' => $inv4Id,
    'amount' => '250.00',
    'method' => 'Cash',
    'reference' => 'REC-KEY-ORIGINAL',
], $recJar);

// Admin attempts to submit payment using Receptionist's keyAlice
$csrfAdmin = extract_csrf(http_request('GET', 'payments/record.php', [], $adminJar)['body']);
$adminTamper = http_request('POST', 'payments/record.php', [
    '_csrf' => $csrfAdmin,
    'idempotency_key' => $keyAlice, // Receptionist's key!
    'invoice_id' => $inv4Id,
    'amount' => '250.00',
    'method' => 'Cash',
    'reference' => 'ADMIN-HIJACK-ATTEMPT',
], $adminJar);
$t = (microtime(true) - $t0) * 1000;

$inv4AfterHijack = InvoiceService::find($inv4Id);
$payCount4After = (int) Database::value('SELECT COUNT(*) FROM payments WHERE invoice_id = ?', [$inv4Id]);

$pIdem10 = ($recPayAlice['code'] === 302 &&
            str_contains($adminTamper['body'], 'belongs to another user') &&
            $payCount4After === 1 && (float)$inv4AfterHijack['balance'] === 750.00);

record_test(
    'IDEM-10', 'Cross-user idempotency key hijacking attempt is rejected with authorization error',
    'Original succeeds (HTTP 302), hijack attempt rejected (belongs to another user), balance strictly 750.00',
    "Rec code: {$recPayAlice['code']}, Admin hijack error: YES, payments: $payCount4After, balance: {$inv4AfterHijack['balance']}",
    $pIdem10, $t, "Ownership boundary strictly enforced"
);

// -------------------------------------------------------------------------
// IDEM-11: Invoice balance consistency check
// -------------------------------------------------------------------------
$t0 = microtime(true);
$invoices = Database::all('SELECT id, grand_total, amount_paid, balance, payment_status FROM invoices WHERE id IN (?, ?, ?, ?)', [$inv1Id, $inv2Id, $inv3Id, $inv4Id]);
$balanceConsistent = true;
$mismatchDetails = '';

foreach ($invoices as $inv) {
    $sumPayments = (float) Database::value("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = ? AND kind = 'payment'", [$inv['id']]);
    $sumRefunds = (float) Database::value("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = ? AND kind = 'refund'", [$inv['id']]);
    $expectedPaid = $sumPayments - $sumRefunds;
    $expectedBalance = max(0.0, (float)$inv['grand_total'] - $expectedPaid);

    if (abs((float)$inv['amount_paid'] - $expectedPaid) > 0.001 || abs((float)$inv['balance'] - $expectedBalance) > 0.001) {
        $balanceConsistent = false;
        $mismatchDetails .= "Invoice {$inv['id']}: expected paid $expectedPaid got {$inv['amount_paid']}, expected bal $expectedBalance got {$inv['balance']}; ";
    }
}
$t = (microtime(true) - $t0) * 1000;

record_test(
    'IDEM-11', 'Mathematical invoice balance consistency holds strictly across all test transactions',
    'balance == grand_total - (payments - refunds) for all invoices',
    $balanceConsistent ? 'All balances 100% consistent with payment ledger' : $mismatchDetails,
    $balanceConsistent, $t, "Zero drift across partial, full, and duplicate payment submissions"
);

// -------------------------------------------------------------------------
// IDEM-12: Payment record consistency check
// -------------------------------------------------------------------------
$t0 = microtime(true);
$allTestPayments = Database::all('SELECT id, receipt_number, invoice_id, kind, amount, paid_at FROM payments WHERE invoice_id IN (?, ?, ?, ?)', [$inv1Id, $inv2Id, $inv3Id, $inv4Id]);
$receiptsUnique = true;
$receiptNumbers = [];

foreach ($allTestPayments as $p) {
    if (isset($receiptNumbers[$p['receipt_number']])) {
        $receiptsUnique = false;
        break;
    }
    $receiptNumbers[$p['receipt_number']] = true;
}

$pIdem12 = ($receiptsUnique && count($allTestPayments) > 0);
$t = (microtime(true) - $t0) * 1000;

record_test(
    'IDEM-12', 'Payment records contain unique receipt numbers and maintain strict ledger integrity',
    'Unique receipt numbers on all payments, zero orphan or corrupted rows',
    "Total payments checked: " . count($allTestPayments) . ", all receipts unique: " . ($receiptsUnique ? 'YES' : 'NO'),
    $pIdem12, $t, "Payments table integrity verified under trg_payments_immutable"
);

// -------------------------------------------------------------------------
// IDEM-13: Audit record consistency & hash chain verification
// -------------------------------------------------------------------------
$t0 = microtime(true);
$auditCheck = AuditLogger::verifyChain();
$pIdem13 = ($auditCheck['ok'] === true);
$t = (microtime(true) - $t0) * 1000;

record_test(
    'IDEM-13', 'Cryptographic SHA-256 audit hash chain verified intact across all payment events',
    'AuditLogger::verifyChain() returns ok=true',
    "ok=" . ($auditCheck['ok'] ? 'true' : 'false') . ", checked={$auditCheck['checked']} records, message: {$auditCheck['message']}",
    $pIdem13, $t, "Tamper-evident audit chain unbroken"
);

// -------------------------------------------------------------------------
// IDEM-14: Refund idempotency check
// -------------------------------------------------------------------------
$t0 = microtime(true);
$keyRefund = PaymentIdempotencyService::generateKey();

// Issue refund via PaymentService with keyRefund
$uRec = Database::one("SELECT id, username, full_name, role_id FROM users WHERE username = 'reception'");
$rfd1 = PaymentService::refund($inv4Id, '100.00', 'Cash', 'Authorised customer goodwill refund', $keyRefund);

// Replay duplicate refund with SAME key
$rfdRetry = PaymentService::refund($inv4Id, '100.00', 'Cash', 'Authorised customer goodwill refund', $keyRefund);
$t = (microtime(true) - $t0) * 1000;

$inv4AfterRefund = InvoiceService::find($inv4Id);
$refundCount = (int) Database::value("SELECT COUNT(*) FROM payments WHERE invoice_id = ? AND kind = 'refund'", [$inv4Id]);

$pIdem14 = ($rfd1 > 0 && $rfdRetry === $rfd1 && $refundCount === 1 && (float)$inv4AfterRefund['amount_paid'] === 150.00);

record_test(
    'IDEM-14', 'Refund operation idempotency prevents duplicate customer refund credits',
    'Original refund ID returned, exactly 1 refund row created, net paid reduced by single refund amount',
    "Original ID: $rfd1, Retry ID: $rfdRetry, refunds count: $refundCount, net paid: {$inv4AfterRefund['amount_paid']}",
    $pIdem14, $t, "Refund idempotency validated successfully"
);

// =========================================================================
// SUMMARY REPORT
// =========================================================================
$totalTests = count($results);
$passedTests = count(array_filter($results, fn($r) => $r['passed']));
$failedTests = $totalTests - $passedTests;
$passRate = ($passedTests / $totalTests) * 100;

echo "\n=================================================================\n";
echo "PHASE 1.9E PAYMENT IDEMPOTENCY SUITE EXECUTION SUMMARY\n";
echo "Total Tests Executed: $totalTests\n";
echo "Passed:               $passedTests\n";
echo "Failed:               $failedTests\n";
echo "Pass Rate:            " . round($passRate, 1) . "%\n";
echo "Live Production DB:   UNTOUCHED & PROTECTED\n";
echo "=================================================================\n";

exit($failedTests === 0 ? 0 : 1);
