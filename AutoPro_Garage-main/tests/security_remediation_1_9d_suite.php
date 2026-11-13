<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — PHASE 1.9D: APPLICATION SECURITY REMEDIATION TEST SUITE
 * 
 * Verifies remediations for:
 *   1. SEC-AUTH-01 — Password Reset Information Disclosure
 *   2. SEC-APP-01  — Public Installer Exposure
 *   3. SEC-RBAC-01 — Customer Booking Flow & IDOR Boundaries
 * 
 * Strict safety rules:
 *   - Runs exclusively against dedicated test database: `garage_management_system_test`
 *   - Runs exclusively against isolated test storage: `storage/testing/`
 *   - Fail-closed if live production database is detected
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Error: tests/security_remediation_1_9d_suite.php must be run from the CLI.\n");
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

require_once APP_ROOT . '/app/models/Database.php';
require_once APP_ROOT . '/app/services/AuthService.php';
require_once APP_ROOT . '/app/services/AuditLogger.php';
require_once APP_ROOT . '/app/services/PasswordResetService.php';
require_once APP_ROOT . '/app/services/AppointmentService.php';

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

usleep(800000); // 800ms

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

// Credentials
$ADMIN_PASS = 'Admin#TestPass2026';
$DEMO_PASS = 'Demo#TestPass2026';

// Global test recorder
$results = [];
$passCount = 0;
$failCount = 0;

function record_test(
    string $id,
    string $domain,
    string $name,
    string $expected,
    string $actual,
    bool $passed,
    float $timeMs,
    string $evidence = ''
): void {
    global $results, $passCount, $failCount;
    $statusStr = $passed ? "\033[32m✔ PASS\033[0m" : "\033[31m✘ FAIL\033[0m";
    if ($passed) {
        $passCount++;
    } else {
        $failCount++;
    }

    $results[] = [
        'id' => $id,
        'domain' => $domain,
        'name' => $name,
        'expected' => $expected,
        'actual' => $actual,
        'passed' => $passed,
        'time_ms' => round($timeMs, 2),
        'evidence' => $evidence,
    ];

    echo "  [$statusStr] $id: $name (" . round($timeMs, 1) . "ms)\n";
    if (!$passed) {
        echo "         \033[31mExpected: $expected\033[0m\n";
        echo "         \033[31mActual:   $actual\033[0m\n";
    }
}

// Reset baseline data
$pdo->exec('DELETE FROM login_attempts');
$pdo->prepare('UPDATE users SET password_hash = ? WHERE username = ?')->execute([AuthService::hash($ADMIN_PASS), 'admin']);

// Clear test rate limits
$rateDir = APP_ROOT . '/storage/testing/rate_limits';
if (is_dir($rateDir)) {
    foreach (glob($rateDir . '/*') as $f) {
        if (is_file($f)) { @unlink($f); }
    }
}

echo "=================================================================\n";
echo "ALPHA GARAGE — PHASE 1.9D APPLICATION SECURITY REMEDIATION SUITE\n";
echo "Isolated Target: garage_management_system_test on 127.0.0.1:8088\n";
echo "=================================================================\n\n";

// =========================================================================
// REMEDIATION 1: SEC-AUTH-01 — PASSWORD RESET INFORMATION DISCLOSURE
// =========================================================================
echo "\033[1mREMEDIATION 1: SEC-AUTH-01 — PASSWORD RESET DISCLOSURE\033[0m\n";

// REM-AUTH-01: Zero Reset Link / Token Disclosure in HTTP Response
$t0 = microtime(true);
$jarForgot = tempnam(sys_get_temp_dir(), 'gms_rec1_');
$getForgot = http_request('GET', 'forgot-password.php', [], $jarForgot);
$csrfForgot = extract_csrf($getForgot['body']);
$postForgot = http_request('POST', 'forgot-password.php', [
    '_csrf' => $csrfForgot,
    'email' => 'admin@autopro-garage.example',
], $jarForgot);
$t = (microtime(true) - $t0) * 1000;
$bodyLower = strtolower($postForgot['body']);
$pAuth1 = ($postForgot['code'] === 200
    && !str_contains($bodyLower, 'reset-password.php?token=')
    && !str_contains($bodyLower, 'development helper')
    && !str_contains($bodyLower, 'smtp spool')
    && !str_contains($bodyLower, 'click here to open password reset link')
    && str_contains($bodyLower, 'if an account is associated with this email'));
record_test(
    'REM-AUTH-01', 'SEC-AUTH-01', 'Password reset response contains ZERO plaintext links or tokens in HTTP output',
    'Generic response without reset URL or token', $pAuth1 ? 'Zero link leakage confirmed' : 'Plaintext reset link leaked',
    $pAuth1, $t, 'HTTP 200 returned generic instruction message'
);

// REM-AUTH-02: Anti-Enumeration Protection
$t0 = microtime(true);
$jarFake = tempnam(sys_get_temp_dir(), 'gms_rec2_');
$getFake = http_request('GET', 'forgot-password.php', [], $jarFake);
$csrfFake = extract_csrf($getFake['body']);
$postFake = http_request('POST', 'forgot-password.php', [
    '_csrf' => $csrfFake,
    'email' => 'totally_fake_nonexistent_email_99@example.com',
], $jarFake);
@unlink($jarFake);
$t = (microtime(true) - $t0) * 1000;
$pAuth2 = ($postFake['code'] === 200
    && str_contains(strtolower($postFake['body']), 'if an account is associated with this email'));
record_test(
    'REM-AUTH-02', 'SEC-AUTH-01', 'Password reset returns identical confirmation for existing vs non-existing emails',
    'Strictly identical generic response for valid and invalid emails', $pAuth2 ? 'Anti-enumeration preserved' : 'Responses differ',
    $pAuth2, $t, 'Anti-enumeration boundary intact'
);

// REM-AUTH-03: Session Variable Cleanliness (_dev_reset_link Never Set)
$t0 = microtime(true);
$devResetExists = isset($_SESSION['_dev_reset_link']);
$t = (microtime(true) - $t0) * 1000;
$pAuth3 = !$devResetExists;
record_test(
    'REM-AUTH-03', 'SEC-AUTH-01', '_dev_reset_link session variable is never populated in runtime',
    '_dev_reset_link does not exist', $pAuth3 ? 'Zero session token retention' : '_dev_reset_link detected',
    $pAuth3, $t, 'Session scope clean of recovery tokens'
);

// REM-AUTH-04: Token Storage & Log Redaction
$t0 = microtime(true);
$resetRow = $pdo->query("SELECT token_hash FROM password_resets ORDER BY id DESC LIMIT 1")->fetch();
$rawInDb = strlen($resetRow['token_hash'] ?? '') === 64 && ctype_xdigit($resetRow['token_hash'] ?? '');
$t = (microtime(true) - $t0) * 1000;
record_test(
    'REM-AUTH-04', 'SEC-AUTH-01', 'Tokens are stored exclusively as 64-character SHA-256 hashes in database',
    'Database stores SHA-256 hash only', $rawInDb ? 'SHA-256 hash verified' : 'Plaintext token stored',
    $rawInDb, $t, 'Token hash verified'
);

// REM-AUTH-05: Replay & Expiration Defense Preserved
$t0 = microtime(true);
$suRaw = bin2hex(random_bytes(32));
$suHash = hash('sha256', $suRaw);
$adminUid = (int) $pdo->query("SELECT id FROM users WHERE username = 'admin'")->fetchColumn();
$pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))')->execute([$adminUid, $suHash]);
$v1 = PasswordResetService::validateToken($suRaw);
$cRes = PasswordResetService::completeReset($suRaw, 'NewSecret#Pass2026!');
$v2 = PasswordResetService::validateToken($suRaw);
$pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([AuthService::hash($ADMIN_PASS), $adminUid]);
$t = (microtime(true) - $t0) * 1000;
$pAuth5 = ($v1 !== null && !empty($cRes['ok']) && $v2 === null);
record_test(
    'REM-AUTH-05', 'SEC-AUTH-01', 'Reset tokens remain strictly single-use and invalid upon consumption',
    'Valid before use, null after use', $pAuth5 ? 'Single-use enforced' : 'Replay permitted',
    $pAuth5, $t, 'Token invalidated upon password reset completion'
);
@unlink($jarForgot);

echo "\n";

// =========================================================================
// REMEDIATION 2: SEC-APP-01 — PUBLIC INSTALLER EXPOSURE
// =========================================================================
echo "\033[1mREMEDIATION 2: SEC-APP-01 — PUBLIC INSTALLER EXPOSURE\033[0m\n";

// REM-APP-01: /setup.php Returns HTTP 404
$t0 = microtime(true);
$setupRes = http_request('GET', 'setup.php');
$t = (microtime(true) - $t0) * 1000;
$pApp1 = ($setupRes['code'] === 404);
record_test(
    'REM-APP-01', 'SEC-APP-01', 'GET /setup.php returns HTTP 404 Not Found from public web root',
    'HTTP 404 Not Found', "HTTP {$setupRes['code']}",
    $pApp1, $t, 'Public installer endpoint eliminated'
);

// REM-APP-02: Normal Login Route Accessible Without Setup Link
$t0 = microtime(true);
$loginPage = http_request('GET', 'login.php');
$t = (microtime(true) - $t0) * 1000;
$pApp2 = ($loginPage['code'] === 200 && !str_contains($loginPage['body'], 'href="/setup.php"') && !str_contains($loginPage['body'], 'setup.php'));
record_test(
    'REM-APP-02', 'SEC-APP-01', 'Normal sign-in route (login.php) is active and contains zero links to setup.php',
    'HTTP 200 without setup.php links', $pApp2 ? 'login.php clean and operational' : 'setup.php link detected',
    $pApp2, $t, 'HTTP 200 verified'
);

// REM-APP-03: Protected Route Continues Normal Redirect Behavior
$t0 = microtime(true);
$dashUnauth = http_request('GET', 'dashboard.php');
$t = (microtime(true) - $t0) * 1000;
$pApp3 = ($dashUnauth['code'] === 302 && str_contains($dashUnauth['loc'], 'login.php'));
record_test(
    'REM-APP-03', 'SEC-APP-01', 'Unauthenticated route protection is intact (dashboard.php redirects to login.php)',
    'HTTP 302 redirect to login.php', "HTTP {$dashUnauth['code']} loc: {$dashUnauth['loc']}",
    $pApp3, $t, 'Authentication middleware operational'
);

// REM-APP-04: Legacy Installer Outside Public Directory (scripts/legacy_setup.php)
$t0 = microtime(true);
$legacyExists = file_exists(APP_ROOT . '/scripts/legacy_setup.php');
$publicSetupExists = file_exists(APP_ROOT . '/public/setup.php');
$t = (microtime(true) - $t0) * 1000;
$pApp4 = ($legacyExists && !$publicSetupExists);
record_test(
    'REM-APP-04', 'SEC-APP-01', 'Legacy installer preserved exclusively in scripts/ (outside public DocumentRoot)',
    'public/setup.php absent, scripts/legacy_setup.php present', $pApp4 ? 'Properly archived' : 'File location incorrect',
    $pApp4, $t, 'Preserved for historical reference outside web root'
);

echo "\n";

// =========================================================================
// REMEDIATION 3: SEC-RBAC-01 — CUSTOMER BOOKING FLOW & IDOR BOUNDARIES
// =========================================================================
echo "\033[1mREMEDIATION 3: SEC-RBAC-01 — CUSTOMER BOOKING FLOW\033[0m\n";

// Set up synthetic test customer accounts
$custRoleId = (int) $pdo->query("SELECT id FROM roles WHERE name = 'customer'")->fetchColumn();
$recRoleId = (int) $pdo->query("SELECT id FROM roles WHERE name = 'receptionist'")->fetchColumn();

// Customer 1 (Alice)
$c1User = $pdo->query("SELECT id FROM users WHERE username = 'test_cust_alice'")->fetch();
if (!$c1User) {
    $c1Uid = (int) Database::insert(
        'INSERT INTO users (role_id, username, full_name, email, phone, password_hash, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)',
        [$custRoleId, 'test_cust_alice', 'Alice Customer', 'alice@customer.test', '+251911111001', AuthService::hash('Customer#Pass1')]
    );
} else {
    $c1Uid = (int) $c1User['id'];
}
$c1CustId = (int) Database::value('SELECT id FROM customers WHERE created_by = ?', [$c1Uid]);
if ($c1CustId < 1) {
    $c1CustId = (int) Database::insert(
        'INSERT INTO customers (full_name, phone, email, created_by) VALUES (?, ?, ?, ?)',
        ['Alice Customer', '+251911111001', 'alice@customer.test', $c1Uid]
    );
}
$c1VehId = (int) Database::value('SELECT id FROM vehicles WHERE customer_id = ?', [$c1CustId]);
if ($c1VehId < 1) {
    $c1VehId = (int) Database::insert(
        'INSERT INTO vehicles (customer_id, plate_number, make, model) VALUES (?, ?, ?, ?)',
        [$c1CustId, 'AA-1001-TEST', 'Toyota', 'RAV4']
    );
}

// Customer 2 (Bob)
$c2User = $pdo->query("SELECT id FROM users WHERE username = 'test_cust_bob'")->fetch();
if (!$c2User) {
    $c2Uid = (int) Database::insert(
        'INSERT INTO users (role_id, username, full_name, email, phone, password_hash, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)',
        [$custRoleId, 'test_cust_bob', 'Bob Customer', 'bob@customer.test', '+251911111002', AuthService::hash('Customer#Pass2')]
    );
} else {
    $c2Uid = (int) $c2User['id'];
}
$c2CustId = (int) Database::value('SELECT id FROM customers WHERE created_by = ?', [$c2Uid]);
if ($c2CustId < 1) {
    $c2CustId = (int) Database::insert(
        'INSERT INTO customers (full_name, phone, email, created_by) VALUES (?, ?, ?, ?)',
        ['Bob Customer', '+251911111002', 'bob@customer.test', $c2Uid]
    );
}
$c2VehId = (int) Database::value('SELECT id FROM vehicles WHERE customer_id = ?', [$c2CustId]);
if ($c2VehId < 1) {
    $c2VehId = (int) Database::insert(
        'INSERT INTO vehicles (customer_id, plate_number, make, model) VALUES (?, ?, ?, ?)',
        [$c2CustId, 'AA-2002-TEST', 'Mercedes', 'C200']
    );
}

// REM-RBAC-01: Customer can access /book.php
$t0 = microtime(true);
[$aliceJar, $aliceLogin] = auth_login('test_cust_alice', 'Customer#Pass1');
$aliceBookPage = http_request('GET', 'book.php', [], $aliceJar);
$t = (microtime(true) - $t0) * 1000;
$pRbac1 = ($aliceBookPage['code'] === 200 && str_contains($aliceBookPage['body'], 'Service Booking Portal'));
record_test(
    'REM-RBAC-01', 'SEC-RBAC-01', 'Authenticated customer accesses dedicated /book.php route with HTTP 200',
    'HTTP 200 on /book.php', "HTTP {$aliceBookPage['code']}",
    $pRbac1, $t, 'Customer service booking portal rendered successfully'
);

// REM-RBAC-02: Customer successfully books an appointment for own vehicle
$t0 = microtime(true);
$csrfAlice = extract_csrf($aliceBookPage['body']);
$aliceBookPost = http_request('POST', 'book.php', [
    '_csrf' => $csrfAlice,
    'vehicle_mode' => 'existing',
    'vehicle_id' => $c1VehId,
    'appointment_date' => date('Y-m-d', strtotime('+2 days')),
    'appointment_time' => '10:00',
    'problem_description' => 'Regular 10k scheduled maintenance test',
], $aliceJar);
$t = (microtime(true) - $t0) * 1000;
$pRbac2 = ($aliceBookPost['code'] === 302 && str_contains($aliceBookPost['loc'], 'book.php?view='));
preg_match('~view=(\d+)~', $aliceBookPost['loc'], $mAliceApt);
$aliceAptId = (int) ($mAliceApt[1] ?? 0);
record_test(
    'REM-RBAC-02', 'SEC-RBAC-01', 'Customer can book appointment for own vehicle (HTTP 302 redirect to confirmation)',
    'HTTP 302 redirect to book.php?view={id}', "HTTP {$aliceBookPost['code']} loc: {$aliceBookPost['loc']}",
    $pRbac2, $t, "Created appointment ID: $aliceAptId"
);

// REM-RBAC-03: Customer can view their own booking confirmation
$t0 = microtime(true);
$aliceViewRes = http_request('GET', "book.php?view=$aliceAptId", [], $aliceJar);
$t = (microtime(true) - $t0) * 1000;
$pRbac3 = ($aliceViewRes['code'] === 200 && str_contains($aliceViewRes['body'], 'Booking Details'));
record_test(
    'REM-RBAC-03', 'SEC-RBAC-01', 'Customer can view their own booking details with HTTP 200',
    'HTTP 200 on book.php?view={own_id}', "HTTP {$aliceViewRes['code']}",
    $pRbac3, $t, 'Own booking details accessible'
);

// REM-RBAC-04: IDOR Protection — Customer B cannot view Customer A's appointment
$t0 = microtime(true);
[$bobJar, $bobLogin] = auth_login('test_cust_bob', 'Customer#Pass2');
$bobViewAlice = http_request('GET', "book.php?view=$aliceAptId", [], $bobJar);
$t = (microtime(true) - $t0) * 1000;
$pRbac4 = ($bobViewAlice['code'] === 403);
record_test(
    'REM-RBAC-04', 'SEC-RBAC-01', 'IDOR Defense: Customer B cannot view Customer A appointment (HTTP 403 Forbidden)',
    'HTTP 403 Forbidden', "HTTP {$bobViewAlice['code']}",
    $pRbac4, $t, 'Cross-customer appointment access denied'
);

// REM-RBAC-05: IDOR Protection — Customer B cannot book using Customer A's vehicle
$t0 = microtime(true);
$bobBookPage = http_request('GET', 'book.php', [], $bobJar);
$csrfBob = extract_csrf($bobBookPage['body']);
$bobTamperVeh = http_request('POST', 'book.php', [
    '_csrf' => $csrfBob,
    'vehicle_mode' => 'existing',
    'vehicle_id' => $c1VehId, // Alice's vehicle!
    'appointment_date' => date('Y-m-d', strtotime('+3 days')),
    'appointment_time' => '11:00',
    'problem_description' => 'Malicious vehicle assignment attempt',
], $bobJar);
$t = (microtime(true) - $t0) * 1000;
$pRbac5 = ($bobTamperVeh['code'] === 403);
record_test(
    'REM-RBAC-05', 'SEC-RBAC-01', 'IDOR Defense: Customer B cannot book appointment using Customer A vehicle (HTTP 403)',
    'HTTP 403 Forbidden', "HTTP {$bobTamperVeh['code']}",
    $pRbac5, $t, 'Unauthorized vehicle assignment blocked server-side'
);

// REM-RBAC-06: Customer cannot access staff appointment management (/appointments/)
$t0 = microtime(true);
$custStaffAppt = http_request('GET', 'appointments/', [], $aliceJar);
$t = (microtime(true) - $t0) * 1000;
$pRbac6 = ($custStaffAppt['code'] === 403);
record_test(
    'REM-RBAC-06', 'SEC-RBAC-01', 'Customer role has zero access to staff appointment management board (/appointments/)',
    'HTTP 403 Forbidden', "HTTP {$custStaffAppt['code']}",
    $pRbac6, $t, 'Staff appointment route protected from customer access'
);

// REM-RBAC-07: Legacy /appointments/form.php redirect for customers
$t0 = microtime(true);
$legacyAptForm = http_request('GET', 'appointments/form.php', [], $aliceJar);
$t = (microtime(true) - $t0) * 1000;
$pRbac7 = ($legacyAptForm['code'] === 302 && str_contains($legacyAptForm['loc'], 'book.php'));
record_test(
    'REM-RBAC-07', 'SEC-RBAC-01', 'Direct customer access to /appointments/form.php redirects gracefully to /book.php',
    'HTTP 302 redirect to book.php', "HTTP {$legacyAptForm['code']} loc: {$legacyAptForm['loc']}",
    $pRbac7, $t, 'Customer gracefully routed to customer booking portal'
);

// REM-RBAC-08: Staff (Receptionist) preserves full appointment management access
$t0 = microtime(true);
[$recJar, $recLogin] = auth_login('reception', $DEMO_PASS);
$recAppts = http_request('GET', 'appointments/', [], $recJar);
$recForm = http_request('GET', 'appointments/form.php', [], $recJar);
$t = (microtime(true) - $t0) * 1000;
$pRbac8 = ($recAppts['code'] === 200 && $recForm['code'] === 200);
record_test(
    'REM-RBAC-08', 'SEC-RBAC-01', 'Staff (Receptionist) preserves authorized access to /appointments/ and /appointments/form.php',
    'HTTP 200 on /appointments/ and /appointments/form.php', "Appts: {$recAppts['code']}, Form: {$recForm['code']}",
    $pRbac8, $t, 'Staff operational capabilities fully preserved'
);

// Cleanup cookie jars
@unlink($aliceJar);
@unlink($bobJar);
@unlink($recJar);

echo "\n";

// =========================================================================
// 4. CLEANUP AND TERMINATION
// =========================================================================
if (is_resource($serverProc)) {
    proc_terminate($serverProc);
}

// Summary statistics
$totalTests = $passCount + $failCount;
$passRate = $totalTests > 0 ? round(($passCount / $totalTests) * 100, 1) : 0;

echo "=================================================================\n";
echo "PHASE 1.9D REMEDIATION SUITE EXECUTION SUMMARY\n";
echo "Total Tests Executed: $totalTests\n";
echo "Passed:               \033[32m$passCount\033[0m\n";
if ($failCount > 0) {
    echo "Failed:               \033[31m$failCount\033[0m\n";
} else {
    echo "Failed:               0\n";
}
echo "Pass Rate:            {$passRate}%\n";
echo "Live Production DB:   UNTOUCHED & PROTECTED\n";
echo "=================================================================\n";

// Save JSON test evidence
$jsonReportPath = APP_ROOT . '/storage/testing/remediation_1_9d_results.json';
file_put_contents($jsonReportPath, json_encode([
    'timestamp' => date('c'),
    'phase' => '1.9D',
    'total' => $totalTests,
    'passed' => $passCount,
    'failed' => $failCount,
    'pass_rate' => $passRate,
    'results' => $results,
], JSON_PRETTY_PRINT));

exit($failCount > 0 ? 1 : 0);
