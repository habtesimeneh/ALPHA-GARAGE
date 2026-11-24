<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — PHASE 1.10C: CUSTOMER IDENTITY VERIFICATION & ACCOUNT LINKING SUITE
 * 
 * Verifies remediation of FIND-110B-02:
 *  - Enforces explicit `customers.user_id` linkage.
 *  - Prohibits unverified account email-matching claims on walk-in records.
 *  - Tests secure single-use expiring SHA-256 tokens.
 *  - Tests cross-account IDOR defenses.
 *  - Tests duplicate walk-in email ambiguity rejection.
 *  - Tests expired / reused token rejection.
 *  - Tests email change token invalidation.
 *  - Tests concurrent linking protection.
 *  - Tests backward compatibility for existing linked customer accounts.
 * 
 * Target: garage_management_system_test (PROTECTED)
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Error: tests must be run from the CLI.\n");
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

// Environment guards
putenv('GMS_TEST_MODE=1');
putenv('GMS_ALLOW_DESTRUCTIVE_TESTS=1');
putenv('GMS_DB_NAME=garage_management_system_test');
putenv('GMS_STORAGE_PATH=' . APP_ROOT . '/storage/testing');
putenv('GMS_DB_USER=root');
putenv('GMS_DB_PASS=root');

$GLOBALS['config'] = require APP_ROOT . '/app/config/app.php';
date_default_timezone_set($GLOBALS['config']['timezone']);

require_once APP_ROOT . '/app/helpers/functions.php';

// Fail-closed database guard
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

require_once APP_ROOT . '/app/models/Database.php';
require_once APP_ROOT . '/app/services/AuthService.php';
require_once APP_ROOT . '/app/services/AuditLogger.php';
require_once APP_ROOT . '/app/services/CustomerIdentityService.php';
require_once APP_ROOT . '/app/middleware/auth.php';

// Spawn isolated HTTP server on port 8086
$serverPort = 8086;
$serverBase = "http://127.0.0.1:$serverPort/";

$serverLog = APP_ROOT . '/storage/testing/logs/test-server-110c.log';
@mkdir(dirname($serverLog), 0777, true);
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
    fwrite(STDERR, "FATAL: Failed to spawn isolated test server on port $serverPort.\n");
    exit(3);
}

// Wait for server readiness
$ready = false;
for ($i = 0; $i < 40; $i++) {
    usleep(100000);
    $sock = @fsockopen('127.0.0.1', $serverPort, $errno, $errstr, 0.5);
    if (is_resource($sock)) {
        fclose($sock);
        $ready = true;
        break;
    }
}

if (!$ready) {
    fwrite(STDERR, "FATAL: Test server on port $serverPort did not start in time.\n");
    @proc_terminate($serverProc);
    exit(3);
}

// HTTP request helper
function http_req_110c(string $method, string $path, array $data = [], string $jar = '', array $extraHeaders = []): array
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

function extract_csrf_110c(string $html): string
{
    if (preg_match('/name=["\']_csrf["\']\s+value=["\']([a-f0-9]{64})["\']/i', $html, $m)) {
        return $m[1];
    }
    if (preg_match('/value=["\']([a-f0-9]{64})["\']\s+name=["\']_csrf["\']/i', $html, $m)) {
        return $m[1];
    }
    return '';
}

function auth_login_110c(string $username, string $password): array
{
    $jar = tempnam(sys_get_temp_dir(), 'gms_auth_110c_');
    $get = http_req_110c('GET', 'login.php', [], $jar);
    $csrf = extract_csrf_110c($get['body']);
    $post = http_req_110c('POST', 'login.php', [
        '_csrf' => $csrf,
        'username' => $username,
        'password' => $password,
    ], $jar);
    return [$jar, $post];
}

$results110c = [];
function record_110c(string $id, string $desc, string $expected, string $actual, bool $passed, float $ms, string $detail = ''): void
{
    global $results110c;
    $results110c[] = [
        'id' => $id,
        'desc' => $desc,
        'expected' => $expected,
        'actual' => $actual,
        'passed' => $passed,
        'ms' => round($ms, 1),
        'detail' => $detail,
    ];
    $status = $passed ? "\033[32m✔ PASS\033[0m" : "\033[31m✖ FAIL\033[0m";
    echo "  [$status] $id: $desc (" . round($ms, 1) . "ms)\n";
    if (!$passed) {
        echo "         Expected: $expected\n";
        echo "         Actual:   $actual\n";
        if ($detail) { echo "         Detail:   $detail\n"; }
    }
}

echo "=================================================================\n";
echo "ALPHA GARAGE — PHASE 1.10C CUSTOMER IDENTITY REMEDIATION SUITE\n";
echo "Target: garage_management_system_test on 127.0.0.1:$serverPort\n";
echo "=================================================================\n\n";

// =========================================================================
// TEST SETUP: SEED SYNTHETIC WALK-IN RECORDS & USERS
// =========================================================================
$custRoleId = (int) Database::value("SELECT id FROM roles WHERE name = 'customer'");
$pdo->exec("DELETE FROM login_attempts");

// Clean previous synthetic test fixtures
$pdo->exec("DELETE FROM customer_link_tokens WHERE email IN ('walkin_target@test.example', 'samuel_dup@test.example')");
$pdo->exec("DELETE FROM customers WHERE email IN ('walkin_target@test.example', 'samuel_dup@test.example')");
$pdo->exec("DELETE FROM users WHERE username IN ('user_walkin_claimant', 'user_attacker_charlie')");

// 1. Create a genuine walk-in customer record entered at the desk by staff (user_id IS NULL)
$walkinCustId = (int) Database::insert(
    'INSERT INTO customers (full_name, phone, email, address, created_by, user_id) VALUES (?, ?, ?, ?, 1, NULL)',
    ['Target Walkin Person', '+251911998877', 'walkin_target@test.example', 'Addis Ababa Bole']
);

// Add a vehicle to the walk-in record
$walkinVehId = (int) Database::insert(
    'INSERT INTO vehicles (customer_id, plate_number, make, model, year, fuel_type) VALUES (?, "WALKIN-888", "Toyota", "Land Cruiser", 2022, "Diesel")',
    [$walkinCustId]
);

// Add an appointment to the walk-in record
$walkinAptId = (int) Database::insert(
    'INSERT INTO appointments (appointment_number, customer_id, vehicle_id, appointment_date, appointment_time, status, problem_description) VALUES ("APT-WALKIN-888", ?, ?, CURDATE(), "10:30", "confirmed", "Original walk-in service history")',
    [$walkinCustId, $walkinVehId]
);

// 2. Create duplicate walk-in records with identical email for ambiguity test
$dupCustId1 = (int) Database::insert(
    'INSERT INTO customers (full_name, phone, email, created_by, user_id) VALUES ("Samuel First Record", "+251911000001", "samuel_dup@test.example", 1, NULL)'
);
$dupCustId2 = (int) Database::insert(
    'INSERT INTO customers (full_name, phone, email, created_by, user_id) VALUES ("Samuel Second Record", "+251911000002", "samuel_dup@test.example", 1, NULL)'
);

// 3. Register user account claiming the same email online
$CLAIMANT_PASS = 'Claimant#Pass2026!';
$claimantUserId = (int) Database::insert(
    'INSERT INTO users (role_id, username, full_name, email, phone, password_hash, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)',
    [$custRoleId, 'user_walkin_claimant', 'Claimant User', 'walkin_target@test.example', '+251911998877', AuthService::hash($CLAIMANT_PASS)]
);

// 4. Register third-party attacker account
$ATTACKER_PASS = 'Attacker#Pass2026!';
$attackerUserId = (int) Database::insert(
    'INSERT INTO users (role_id, username, full_name, email, phone, password_hash, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)',
    [$custRoleId, 'user_attacker_charlie', 'Charlie Attacker', 'charlie@attacker.test', '+251911666666', AuthService::hash($ATTACKER_PASS)]
);

// Authenticate sessions
[$claimantJar, ] = auth_login_110c('user_walkin_claimant', $CLAIMANT_PASS);
[$attackerJar, ] = auth_login_110c('user_attacker_charlie', $ATTACKER_PASS);
[$aliceJar, ] = auth_login_110c('test_cust_alice', 'Customer#Pass1');

// =========================================================================
// SECTION 1: UNVERIFIED CLAIMING PATH ENFORCEMENT
// =========================================================================
echo "SECTION 1: UNVERIFIED CLAIMING PATH ENFORCEMENT\n";

// TEST 1: Unverified newly registered account CANNOT claim or view walk-in record
$t0 = microtime(true);
$claimantPortal = http_req_110c('GET', 'customer.php', [], $claimantJar);
$t = (microtime(true) - $t0) * 1000;
$p1 = ($claimantPortal['code'] === 200 
    && !str_contains($claimantPortal['body'], 'WALKIN-888') 
    && !str_contains($claimantPortal['body'], 'Toyota Land Cruiser')
    && str_contains($claimantPortal['body'], 'In-Person Service Records'));
record_110c('CID-01', 'Unverified account matching walk-in email CANNOT see walk-in vehicle or service history', 'HTTP 200, WALKIN-888 absent, neutral linking prompt present', "Code {$claimantPortal['code']}", $p1, $t);

// TEST 2: Unverified account cannot view walk-in appointment directly (IDOR check)
$t0 = microtime(true);
$claimantViewsApt = http_req_110c('GET', 'book.php?view=' . $walkinAptId, [], $claimantJar);
$t = (microtime(true) - $t0) * 1000;
$p2 = ($claimantViewsApt['code'] === 403);
record_110c('CID-02', 'Unverified account accessing walk-in appointment directly returns HTTP 403 Forbidden', 'HTTP 403 Forbidden', "Code {$claimantViewsApt['code']}", $p2, $t);

// TEST 3: Unverified account cannot book using walk-in vehicle ID directly
$t0 = microtime(true);
$bookPage = http_req_110c('GET', 'book.php', [], $claimantJar);
$csrf = extract_csrf_110c($bookPage['body']);
$claimantBooksVeh = http_req_110c('POST', 'book.php', [
    '_csrf' => $csrf,
    'vehicle_mode' => 'existing',
    'vehicle_id' => $walkinVehId,
    'appointment_date' => date('Y-m-d', strtotime('+3 days')),
    'appointment_time' => '11:00',
    'problem_description' => 'Unverified vehicle booking attempt',
], $claimantJar);
$t = (microtime(true) - $t0) * 1000;
$p3 = ($claimantBooksVeh['code'] === 403);
record_110c('CID-03', 'Unverified account booking service using walk-in vehicle ID returns HTTP 403', 'HTTP 403 Forbidden', "Code {$claimantBooksVeh['code']}", $p3, $t);


// =========================================================================
// SECTION 2: VERIFICATION & AUTHORIZED LINKING FLOW
// =========================================================================
echo "\nSECTION 2: VERIFICATION & AUTHORIZED LINKING FLOW\n";

// TEST 4: Requesting linking generates secure SHA-256 token
$t0 = microtime(true);
$reqLink = CustomerIdentityService::requestLinking(['id' => $claimantUserId, 'email' => 'walkin_target@test.example', 'full_name' => 'Claimant User']);
$t = (microtime(true) - $t0) * 1000;
$p4 = ($reqLink['ok'] === true && !empty($reqLink['raw_token']) && strlen($reqLink['raw_token']) === 64);
$rawToken = $reqLink['raw_token'] ?? '';
record_110c('CID-04', 'Requesting service history link generates secure 256-bit expiring verification token', 'ok: true, 64-char raw token generated', "Token len: " . strlen($rawToken), $p4, $t);

// TEST 5: Token is stored exclusively as SHA-256 hash in database
$t0 = microtime(true);
$storedToken = Database::one('SELECT * FROM customer_link_tokens WHERE user_id = ? AND used_at IS NULL ORDER BY id DESC LIMIT 1', [$claimantUserId]);
$t = (microtime(true) - $t0) * 1000;
$p5 = ($storedToken && $storedToken['token_hash'] === hash('sha256', $rawToken));
record_110c('CID-05', 'Verification token is stored strictly as SHA-256 hash in database', 'Database token_hash matches hash(rawToken)', $p5 ? 'Hash match verified' : 'Mismatch', $p5, $t);

// TEST 6: User A (Charlie Attacker) CANNOT consume User B's verification token
$t0 = microtime(true);
$attackerConfirm = CustomerIdentityService::confirmLinking($rawToken, $attackerUserId);
$t = (microtime(true) - $t0) * 1000;
$p6 = ($attackerConfirm['ok'] === false && str_contains($attackerConfirm['error'], 'Security Error'));
record_110c('CID-06', 'Cross-user token consumption rejected: Attacker Charlie cannot use Claimant token', 'Security Error rejected', $attackerConfirm['error'] ?? 'None', $p6, $t);

// TEST 7: Authorized user confirms linking and claims walk-in record
$t0 = microtime(true);
$confirmRes = CustomerIdentityService::confirmLinking($rawToken, $claimantUserId);
$t = (microtime(true) - $t0) * 1000;
$walkinCheck = Database::one('SELECT user_id FROM customers WHERE id = ?', [$walkinCustId]);
$p7 = ($confirmRes['ok'] === true && (int) $walkinCheck['user_id'] === $claimantUserId);
record_110c('CID-07', 'Authorized claimant confirms token: customer.user_id successfully linked to user', 'ok: true, customers.user_id = claimantUserId', "Linked user_id: {$walkinCheck['user_id']}", $p7, $t);

// TEST 8: Linked vehicles and appointments now accessible in claimant portal
$t0 = microtime(true);
$portalPost = http_req_110c('GET', 'customer.php', [], $claimantJar);
$t = (microtime(true) - $t0) * 1000;
$p8 = ($portalPost['code'] === 200 && str_contains($portalPost['body'], 'WALKIN-888') && str_contains($portalPost['body'], 'Toyota'));
record_110c('CID-08', 'Post-verification claimant portal renders previously walk-in vehicles and history', 'HTTP 200, WALKIN-888 present', "Code {$portalPost['code']}", $p8, $t);


// =========================================================================
// SECTION 3: SECURITY GUARDS, EXPIRY, REPLAY & CONCURRENCY
// =========================================================================
echo "\nSECTION 3: SECURITY GUARDS, EXPIRY, REPLAY & CONCURRENCY\n";

// TEST 9: Reused verification token is rejected
$t0 = microtime(true);
$replayRes = CustomerIdentityService::confirmLinking($rawToken, $claimantUserId);
$t = (microtime(true) - $t0) * 1000;
$p9 = ($replayRes['ok'] === false && str_contains($replayRes['error'], 'already been used'));
record_110c('CID-09', 'Reused verification token is rejected with anti-replay error', 'Error: already been used', $replayRes['error'] ?? 'None', $p9, $t);

// TEST 10: Expired verification token is rejected
$t0 = microtime(true);
$expiredRawToken = bin2hex(random_bytes(32));
$expiredHash = hash('sha256', $expiredRawToken);
Database::insert(
    'INSERT INTO customer_link_tokens (user_id, customer_id, email, token_hash, expires_at) VALUES (?, ?, "walkin_target@test.example", ?, DATE_SUB(NOW(), INTERVAL 5 MINUTE))',
    [$claimantUserId, $walkinCustId, $expiredHash]
);
$expiredRes = CustomerIdentityService::confirmLinking($expiredRawToken, $claimantUserId);
$t = (microtime(true) - $t0) * 1000;
$p10 = ($expiredRes['ok'] === false && str_contains($expiredRes['error'], 'expired'));
record_110c('CID-10', 'Expired verification token (expires_at < NOW) is rejected', 'Error: expired', $expiredRes['error'] ?? 'None', $p10, $t);

// TEST 11: Duplicate walk-in customer records reject ambiguous automatic linking (neutral response)
$t0 = microtime(true);
$dupCheck = CustomerIdentityService::requestLinking(['id' => $claimantUserId, 'email' => 'samuel_dup@test.example', 'full_name' => 'Samuel Desta']);
$t = (microtime(true) - $t0) * 1000;
$p11 = ($dupCheck['ok'] === true && $dupCheck['raw_token'] === null && str_contains($dupCheck['message'], 'If in-person service history matching'));
record_110c('CID-11', 'Ambiguous duplicate walk-in records reject automatic linking with neutral message', 'raw_token: null, neutral message', $dupCheck['message'] ?? 'None', $p11, $t);

// TEST 12: Changing user email invalidates all pending link tokens
$t0 = microtime(true);
$testToken = bin2hex(random_bytes(32));
$testHash = hash('sha256', $testToken);
Database::insert(
    'INSERT INTO customer_link_tokens (user_id, customer_id, email, token_hash, expires_at) VALUES (?, ?, "walkin_target@test.example", ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))',
    [$claimantUserId, $walkinCustId, $testHash]
);
CustomerIdentityService::invalidateTokensForUser($claimantUserId);
$invalidated = Database::one('SELECT used_at FROM customer_link_tokens WHERE token_hash = ?', [$testHash]);
$t = (microtime(true) - $t0) * 1000;
$p12 = ($invalidated && $invalidated['used_at'] !== null);
record_110c('CID-12', 'Email change / invalidation routine consumes all pending tokens for user', 'used_at is not null', $p12 ? 'Token invalidated' : 'Token active', $p12, $t);

// TEST 13: Concurrent linking protection: second claim on already-linked record is rejected
$t0 = microtime(true);
$secondToken = bin2hex(random_bytes(32));
$secondHash = hash('sha256', $secondToken);
Database::insert(
    'INSERT INTO customer_link_tokens (user_id, customer_id, email, token_hash, expires_at) VALUES (?, ?, "walkin_target@test.example", ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))',
    [$claimantUserId, $walkinCustId, $secondHash]
);
$secondClaim = CustomerIdentityService::confirmLinking($secondRaw = $secondToken, $claimantUserId);
$t = (microtime(true) - $t0) * 1000;
$p13 = ($secondClaim['ok'] === false && str_contains($secondClaim['error'], 'already been linked'));
record_110c('CID-13', 'Concurrent linking defense: already-linked customer record rejects subsequent claims', 'Error: already been linked', $secondClaim['error'] ?? 'None', $p13, $t);


// =========================================================================
// SECTION 4: BACKWARD COMPATIBILITY & AUDIT INTEGRITY
// =========================================================================
echo "\nSECTION 4: BACKWARD COMPATIBILITY & AUDIT INTEGRITY\n";

// TEST 14: Existing customer accounts (Alice) retain full uninterrupted access
$t0 = microtime(true);
$alicePortal = http_req_110c('GET', 'customer.php', [], $aliceJar);
$t = (microtime(true) - $t0) * 1000;
$p14 = ($alicePortal['code'] === 200 && str_contains($alicePortal['body'], 'VIP Client Services') && str_contains($alicePortal['body'], 'My Vehicles'));
record_110c('CID-14', 'Existing linked customer accounts (Alice) retain full uninterrupted access', 'HTTP 200, VIP Client Services present', "Code {$alicePortal['code']}", $p14, $t);

// TEST 15: Cryptographic SHA-256 Merkle audit chain verified 100% intact
$t0 = microtime(true);
$chainRes = AuditLogger::verifyChain();
$t = (microtime(true) - $t0) * 1000;
$p15 = ($chainRes['ok'] === true && $chainRes['checked'] > 0);
record_110c('CID-15', 'Cryptographic SHA-256 Merkle audit-chain verified 100% intact across all link events', 'ok: true', "Verified {$chainRes['checked']} records: " . ($chainRes['ok'] ? 'VALID' : 'FAILED'), $p15, $t);


// =========================================================================
// CLEANUP & SUMMARY
// =========================================================================
@unlink($claimantJar);
@unlink($attackerJar);
@unlink($aliceJar);
if (is_resource($serverProc)) {
    proc_terminate($serverProc);
}

// Clean up temporary synthetic records
$pdo->exec("DELETE FROM customer_link_tokens WHERE email IN ('walkin_target@test.example', 'samuel_dup@test.example')");
$pdo->exec("DELETE FROM appointments WHERE appointment_number = 'APT-WALKIN-888'");
$pdo->exec("DELETE FROM vehicles WHERE plate_number = 'WALKIN-888'");
$pdo->exec("DELETE FROM customers WHERE email IN ('walkin_target@test.example', 'samuel_dup@test.example')");
$pdo->exec("DELETE FROM users WHERE username IN ('user_walkin_claimant', 'user_attacker_charlie')");

$total = count($results110c);
$passed = count(array_filter($results110c, fn($r) => $r['passed']));
$failed = $total - $passed;
$rate = $total > 0 ? round(($passed / $total) * 100, 1) : 0;

echo "\n=================================================================\n";
echo "PHASE 1.10C TEST SUMMARY: $passed / $total PASSED ($rate%)\n";
echo "Target Test Database: garage_management_system_test (PROTECTED)\n";
echo "=================================================================\n";

exit($failed > 0 ? 1 : 0);
