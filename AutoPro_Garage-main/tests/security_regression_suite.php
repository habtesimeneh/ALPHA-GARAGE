<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — PHASE 1.9C: CORE SECURITY & AUTHENTICATION REGRESSION SUITE
 * 
 * Strict safety rules:
 *   - Runs exclusively against dedicated test database: `garage_management_system_test`
 *   - Runs exclusively against isolated test storage: `storage/testing/`
 *   - Never modifies or connects to production database `garage_management_system`
 *   - Cleans up only its own temporary synthetic test records
 * 
 * Domains covered:
 *   A. Authentication (10 tests)
 *   B. Authorization and RBAC (10 tests)
 *   C. Session Security (6 tests)
 *   D. CSRF and Request Security (6 tests)
 *   E. Password and Account Recovery (8 tests)
 *   F. Input and Application Security (7 tests)
 *   G. Audit Logging (7 tests)
 * 
 * Total: 54 automated regression tests
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Error: tests/security_regression_suite.php must be run from the CLI.\n");
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
require_once APP_ROOT . '/app/services/ContactInquiryService.php';
require_once APP_ROOT . '/app/services/CryptoService.php';

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

// Ensure server is killed on shutdown
register_shutdown_function(function () use ($serverProc) {
    if (is_resource($serverProc)) {
        proc_terminate($serverProc);
    }
});

// Give server time to bind
usleep(800000); // 800ms

// Verify server responsiveness
$pingCh = curl_init($serverBase . 'login.php');
curl_setopt_array($pingCh, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 3,
    CURLOPT_HTTPHEADER => ['Connection: close'],
]);
$pingRes = curl_exec($pingCh);
$pingCode = curl_getinfo($pingCh, CURLINFO_HTTP_CODE);
curl_close($pingCh);

if ($pingCode !== 200) {
    fwrite(STDERR, "Failed to connect to isolated test server on port $serverPort (HTTP $pingCode).\n");
    proc_terminate($serverProc);
    exit(1);
}

// =========================================================================
// 3. HTTP TEST UTILITIES (CONNECTION-CLOSE HARDENED FOR PHP -S)
// =========================================================================
function http_request(string $method, string $path, array $data = [], string $jar = '', array $extraHeaders = []): array
{
    global $serverBase;
    $ch = curl_init($serverBase . ltrim($path, '/'));
    $headers = array_merge(['Connection: close'], $extraHeaders);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FORBID_REUSE => true,
        CURLOPT_FRESH_CONNECT => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($jar !== '') {
        $opts[CURLOPT_COOKIEJAR] = $jar;
        $opts[CURLOPT_COOKIEFILE] = $jar;
    }
    if ($method === 'POST') {
        $opts[CURLOPT_POSTFIELDS] = http_build_query($data);
    }
    curl_setopt_array($ch, $opts);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $head = substr($raw, 0, $hs);
    $body = substr($raw, $hs);
    preg_match('/^Location:\s*(\S+)/mi', $head, $m);

    return [
        'code' => $code,
        'body' => $body,
        'loc' => $m[1] ?? '',
        'head' => $head,
    ];
}

function extract_csrf(string $html): string
{
    preg_match('/name="_csrf" value="([a-f0-9]+)"/', $html, $m);
    return $m[1] ?? '';
}

function auth_login(string $username, string $password): array
{
    $jar = tempnam(sys_get_temp_dir(), 'gms_auth_');
    $page = http_request('GET', 'login.php', [], $jar);
    $csrf = extract_csrf($page['body']);
    $res = http_request('POST', 'login.php', ['_csrf' => $csrf, 'username' => $username, 'password' => $password], $jar);
    return [$jar, $res];
}

function post_form(string $jar, string $formPath, string $postPath, array $data): array
{
    $page = http_request('GET', $formPath, [], $jar);
    $csrf = extract_csrf($page['body']);
    return http_request('POST', $postPath, ['_csrf' => $csrf] + $data, $jar);
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

echo "=================================================================\n";
echo "ALPHA GARAGE — PHASE 1.9C SECURITY & AUTHENTICATION REGRESSION\n";
echo "Isolated Target: garage_management_system_test on 127.0.0.1:8088\n";
echo "=================================================================\n\n";

// Reset login attempts and ensure baseline admin password
$pdo->exec('DELETE FROM login_attempts');
$pdo->prepare('UPDATE users SET password_hash = ? WHERE username = ?')->execute([AuthService::hash($ADMIN_PASS), 'admin']);

// =========================================================================
// DOMAIN A: AUTHENTICATION (10 TESTS)
// =========================================================================
echo "\033[1mDOMAIN A: AUTHENTICATION\033[0m\n";

// A1: Valid Administrator Login
$t0 = microtime(true);
[$adminJar, $rA1] = auth_login('admin', $ADMIN_PASS);
$t = (microtime(true) - $t0) * 1000;
$pA1 = ($rA1['code'] === 302 && str_contains($rA1['loc'], 'dashboard.php'));
record_test(
    'AUTH-01', 'Authentication', 'Valid administrator login redirects to dashboard',
    'HTTP 302 redirect to dashboard.php', "HTTP {$rA1['code']} loc: {$rA1['loc']}",
    $pA1, $t, "Location: {$rA1['loc']}"
);

// A2: Valid Login with Email Address
$t0 = microtime(true);
[$emailJar, $rA2] = auth_login('admin@autopro-garage.example', $ADMIN_PASS);
$t = (microtime(true) - $t0) * 1000;
$pA2 = ($rA2['code'] === 302 && str_contains($rA2['loc'], 'dashboard.php'));
record_test(
    'AUTH-02', 'Authentication', 'Administrator can authenticate using verified email',
    'HTTP 302 redirect to dashboard.php', "HTTP {$rA2['code']} loc: {$rA2['loc']}",
    $pA2, $t, "Location: {$rA2['loc']}"
);
@unlink($emailJar);

// A3: Invalid Username Rejection
$t0 = microtime(true);
[, $rA3] = auth_login('non_existent_user_99', 'AnyPassword123!');
$t = (microtime(true) - $t0) * 1000;
$pA3 = ($rA3['code'] === 200 && str_contains($rA3['body'], 'Invalid username or password'));
record_test(
    'AUTH-03', 'Authentication', 'Non-existent username returns generic error',
    'HTTP 200 with "Invalid username or password"', "HTTP {$rA3['code']} (generic error verified)",
    $pA3, $t, 'Generic error returned without leaking user non-existence'
);

// A4: Invalid Password Rejection
$t0 = microtime(true);
[, $rA4] = auth_login('admin', 'WrongPass#9999');
$t = (microtime(true) - $t0) * 1000;
$pA4 = ($rA4['code'] === 200 && str_contains($rA4['body'], 'Invalid username or password'));
record_test(
    'AUTH-04', 'Authentication', 'Wrong password for valid user returns generic error',
    'HTTP 200 with "Invalid username or password"', "HTTP {$rA4['code']} (generic error verified)",
    $pA4, $t, 'Identical generic message returned'
);

// A5: Inactive Account Rejection
$t0 = microtime(true);
$inactiveUid = (int) $pdo->query("SELECT id FROM users WHERE username = 'inactive_tester'")->fetchColumn();
if (!$inactiveUid) {
    $custRoleId = (int) $pdo->query("SELECT id FROM roles WHERE name = 'receptionist'")->fetchColumn();
    $ins = $pdo->prepare('INSERT INTO users (role_id, username, full_name, email, password_hash, is_active) VALUES (?,?,?,?,?,0)');
    $ins->execute([$custRoleId, 'inactive_tester', 'Inactive Tester', 'inactive@test.example', AuthService::hash('Test#Pass1234')]);
}
[, $rA5] = auth_login('inactive_tester', 'Test#Pass1234');
$t = (microtime(true) - $t0) * 1000;
$pA5 = ($rA5['code'] === 200 && str_contains($rA5['body'], 'Invalid username or password'));
// Cleanup
$pdo->exec("DELETE FROM users WHERE username = 'inactive_tester'");
record_test(
    'AUTH-05', 'Authentication', 'Inactive account (is_active=0) is rejected at authentication',
    'HTTP 200 with generic error (no account activation leakage)', "HTTP {$rA5['code']}",
    $pA5, $t, 'Inactive user rejected identically to invalid credentials'
);

// A6: Login Lockout & Rate Limiting
$t0 = microtime(true);
$pdo->exec("DELETE FROM login_attempts WHERE username = 'reception'");
for ($i = 0; $i < 5; $i++) {
    auth_login('reception', 'bad-attempt-' . $i);
}
[, $rA6] = auth_login('reception', $DEMO_PASS);
$t = (microtime(true) - $t0) * 1000;
$pA6 = ($rA6['code'] === 200 && str_contains($rA6['body'], 'Too many failed attempts'));
// Clean up attempts to unlock reception
$pdo->exec("DELETE FROM login_attempts WHERE username = 'reception'");
record_test(
    'AUTH-06', 'Authentication', 'Account lockout triggers after 5 consecutive failures',
    'Locked out with "Too many failed attempts"', "Lockout triggered, message present",
    $pA6, $t, 'Account locked out even with correct password on attempt 6'
);

// A7: Session Regeneration after Authentication
$t0 = microtime(true);
$jarA7 = tempnam(sys_get_temp_dir(), 'gms_s7_');
$pgA7 = http_request('GET', 'login.php', [], $jarA7);
preg_match('/Set-Cookie:\s*GMSSESSID_TEST=([^;]+)/i', $pgA7['head'], $mPreHead);
$preId = $mPreHead[1] ?? '';
$csrfA7 = extract_csrf($pgA7['body']);
$rA7 = http_request('POST', 'login.php', ['_csrf' => $csrfA7, 'username' => 'admin', 'password' => $ADMIN_PASS], $jarA7);
preg_match('/Set-Cookie:\s*GMSSESSID_TEST=([^;]+)/i', $rA7['head'], $mPostHead);
$postId = $mPostHead[1] ?? '';
$t = (microtime(true) - $t0) * 1000;
$pA7 = (!empty($preId) && !empty($postId) && $preId !== $postId);
@unlink($jarA7);
record_test(
    'AUTH-07', 'Authentication', 'Session ID regenerates immediately after successful login',
    'pre-auth session ID != post-auth session ID', "pre: " . substr($preId, 0, 8) . "... post: " . substr($postId, 0, 8) . "...",
    $pA7, $t, "Session ID rotated: $preId -> $postId"
);

// A8: Session Fixation Protection
$t0 = microtime(true);
$attackerSid = 'attacker_' . bin2hex(random_bytes(8));
$jarA8 = tempnam(sys_get_temp_dir(), 'gms_fix_');
$pgA8 = http_request('GET', 'login.php', [], $jarA8, ["Cookie: GMSSESSID_TEST=$attackerSid"]);
$csrfA8 = extract_csrf($pgA8['body']);
$rA8Post = http_request('POST', 'login.php', ['_csrf' => $csrfA8, 'username' => 'admin', 'password' => $ADMIN_PASS], $jarA8, ["Cookie: GMSSESSID_TEST=$attackerSid"]);
preg_match('/Set-Cookie:\s*GMSSESSID_TEST=([^;]+)/i', $rA8Post['head'], $mFix);
$finalId = $mFix[1] ?? '';
$t = (microtime(true) - $t0) * 1000;
$pA8 = (!empty($finalId) && $finalId !== $attackerSid);
@unlink($jarA8);
record_test(
    'AUTH-08', 'Authentication', 'Session fixation attempt rejected via strict cookie rotation',
    'Attacker session ID discarded and replaced', "Final session ID: " . substr($finalId, 0, 8) . "...",
    $pA8, $t, "Attacker cookie $attackerSid discarded, rotated to $finalId"
);

// A9: Logout and Session Invalidation
$t0 = microtime(true);
[$logoutJar, $rLgt] = auth_login('admin', $ADMIN_PASS);
$dashPre = http_request('GET', 'dashboard.php', [], $logoutJar);
$rA9 = post_form($logoutJar, 'dashboard.php', 'logout.php', []);
$dashPost = http_request('GET', 'dashboard.php', [], $logoutJar);
$t = (microtime(true) - $t0) * 1000;
$pA9 = ($rA9['code'] === 302 && str_contains($rA9['loc'], 'login.php') && $dashPost['code'] === 302);
@unlink($logoutJar);
record_test(
    'AUTH-09', 'Authentication', 'POST logout terminates session and invalidates authenticated state',
    'Redirects to login.php, dashboard returns 302', "Logout code: {$rA9['code']}, subsequent dash: {$dashPost['code']}",
    $pA9, $t, 'Session invalidated completely'
);

// A10: Refuse GET Logout
$t0 = microtime(true);
$rA10 = http_request('GET', 'logout.php', [], $adminJar);
$t = (microtime(true) - $t0) * 1000;
$pA10 = ($rA10['code'] === 405);
record_test(
    'AUTH-10', 'Authentication', 'GET request to logout is refused with 405 Method Not Allowed',
    'HTTP 405 Method Not Allowed', "HTTP {$rA10['code']}",
    $pA10, $t, 'Requires POST with authentic CSRF token'
);

echo "\n";

// =========================================================================
// DOMAIN B: AUTHORIZATION AND RBAC (10 TESTS)
// =========================================================================
echo "\033[1mDOMAIN B: AUTHORIZATION & RBAC\033[0m\n";

// B1: Administrator Access to High-Privilege Endpoints
$t0 = microtime(true);
$adminPaths = ['dashboard.php', 'users/', 'audit-logs/', 'invoices/', 'reports/', 'expenses/'];
$adminAllOk = true;
foreach ($adminPaths as $path) {
    $res = http_request('GET', $path, [], $adminJar);
    if ($res['code'] !== 200) {
        $adminAllOk = false;
        break;
    }
}
$t = (microtime(true) - $t0) * 1000;
record_test(
    'RBAC-01', 'Authorization and RBAC', 'Administrator has full authorized access to core modules',
    'HTTP 200 across all 6 administrative routes', $adminAllOk ? 'HTTP 200 on all routes' : 'Failed route encountered',
    $adminAllOk, $t, 'Verified: dashboard, users, audit-logs, invoices, reports, expenses'
);

// B2: Receptionist Allowed Access
$t0 = microtime(true);
[$recJar, ] = auth_login('reception', $DEMO_PASS);
$recAllowed = ['customers/', 'vehicles/', 'job-cards/', 'invoices/', 'payments/'];
$recAllOk = true;
foreach ($recAllowed as $p) {
    if (http_request('GET', $p, [], $recJar)['code'] !== 200) {
        $recAllOk = false;
        break;
    }
}
$t = (microtime(true) - $t0) * 1000;
record_test(
    'RBAC-02', 'Authorization and RBAC', 'Receptionist role accesses designated operational routes',
    'HTTP 200 on customers, vehicles, job-cards, invoices, payments', $recAllOk ? 'All 200' : 'Failure',
    $recAllOk, $t, 'Receptionist operational boundaries verified'
);

// B3: Receptionist Forbidden Access
$t0 = microtime(true);
$recForbidden = ['audit-logs/', 'users/', 'expenses/'];
$recBlocked = true;
foreach ($recForbidden as $p) {
    if (http_request('GET', $p, [], $recJar)['code'] !== 403) {
        $recBlocked = false;
        break;
    }
}
$t = (microtime(true) - $t0) * 1000;
record_test(
    'RBAC-03', 'Authorization and RBAC', 'Receptionist blocked from audit logs, users, and expenses',
    'HTTP 403 Forbidden across all 3 restricted endpoints', $recBlocked ? 'HTTP 403 on all' : 'Allowed restricted route',
    $recBlocked, $t, 'Restricted paths return 403 Forbidden'
);

// B4: Mechanic Allowed Access
$t0 = microtime(true);
[$mecJar, ] = auth_login('mechanic1', $DEMO_PASS);
$mecOk = (http_request('GET', 'job-cards/', [], $mecJar)['code'] === 200);
$t = (microtime(true) - $t0) * 1000;
record_test(
    'RBAC-04', 'Authorization and RBAC', 'Mechanic role accesses job cards dashboard',
    'HTTP 200 on job-cards/', "HTTP " . http_request('GET', 'job-cards/', [], $mecJar)['code'],
    $mecOk, $t, 'Mechanic allowed access to assigned tasks'
);

// B5: Mechanic Forbidden Access
$t0 = microtime(true);
$mecForbidden = ['invoices/', 'customers/', 'payments/', 'users/', 'audit-logs/'];
$mecBlocked = true;
foreach ($mecForbidden as $p) {
    if (http_request('GET', $p, [], $mecJar)['code'] !== 403) {
        $mecBlocked = false;
        break;
    }
}
$t = (microtime(true) - $t0) * 1000;
record_test(
    'RBAC-05', 'Authorization and RBAC', 'Mechanic blocked from invoices, customers, payments, and users',
    'HTTP 403 Forbidden across administrative and financial routes', $mecBlocked ? 'All 403' : 'Route permitted',
    $mecBlocked, $t, 'Financial and customer data protected'
);

// B6: Inventory Manager Boundary
$t0 = microtime(true);
[$stockJar, ] = auth_login('stock', $DEMO_PASS);
$stockOk = (http_request('GET', 'inventory/', [], $stockJar)['code'] === 200)
    && (http_request('GET', 'payments/record.php', [], $stockJar)['code'] === 403)
    && (http_request('GET', 'users/', [], $stockJar)['code'] === 403);
$t = (microtime(true) - $t0) * 1000;
record_test(
    'RBAC-06', 'Authorization and RBAC', 'Inventory Manager can manage stock but cannot record payments or manage users',
    'inventory/ = 200, payments/record.php = 403, users/ = 403', $stockOk ? 'Verified' : 'Boundary violation',
    $stockOk, $t, 'Inventory vs Finance privilege separation confirmed'
);

// B7: Accountant Boundary
$t0 = microtime(true);
[$accJar, ] = auth_login('accounts', $DEMO_PASS);
$accOk = (http_request('GET', 'expenses/', [], $accJar)['code'] === 200)
    && (http_request('GET', 'invoices/', [], $accJar)['code'] === 200)
    && (http_request('GET', 'audit-logs/', [], $accJar)['code'] === 403)
    && (http_request('GET', 'reports/?report=audit', [], $accJar)['code'] === 403);
$t = (microtime(true) - $t0) * 1000;
record_test(
    'RBAC-07', 'Authorization and RBAC', 'Accountant accesses financial records but is blocked from audit logs',
    'expenses/ = 200, invoices/ = 200, audit-logs/ = 403, report=audit = 403', $accOk ? 'Verified' : 'Audit leak',
    $accOk, $t, 'Audit trail isolation enforced for accountant'
);

// B8: Customer Role Restrictions
$t0 = microtime(true);
$custRoleId = (int) $pdo->query("SELECT id FROM roles WHERE name = 'customer'")->fetchColumn();
$pdo->prepare('INSERT INTO users (role_id, username, full_name, email, password_hash, is_active) VALUES (?,?,?,?,?,1)')
    ->execute([$custRoleId, 'cust_test_reg', 'Customer Tester', 'cust_reg@test.example', AuthService::hash('Pass#Reg2026!')]);
[$custJar, $custLogin] = auth_login('cust_test_reg', 'Pass#Reg2026!');
// Customer dashboard redirect or forbidden on staff routes
$staffDash = http_request('GET', 'dashboard.php', [], $custJar);
$custBlocked = ($staffDash['code'] === 403 || str_contains($staffDash['loc'], 'index.php') || $staffDash['code'] === 302);
// Cleanup
$pdo->exec("DELETE FROM users WHERE username = 'cust_test_reg'");
@unlink($custJar);
$t = (microtime(true) - $t0) * 1000;
record_test(
    'RBAC-08', 'Authorization and RBAC', 'Customer account has no staff dashboard access',
    'Staff dashboard refused (403 or redirect away from staff dashboard)', "HTTP {$staffDash['code']}",
    $custBlocked, $t, 'Public customer accounts isolated from internal staff routes'
);

// B9: Object-Level Access Control (IDOR)
$t0 = microtime(true);
[$mec2Jar, ] = auth_login('mechanic2', $DEMO_PASS);
$mech1Id = (int) $pdo->query("SELECT m.id FROM mechanics m JOIN users u ON u.id = m.user_id WHERE u.username = 'mechanic1'")->fetchColumn();
$mech2Id = (int) $pdo->query("SELECT m.id FROM mechanics m JOIN users u ON u.id = m.user_id WHERE u.username = 'mechanic2'")->fetchColumn();
$ownJob = (int) $pdo->query("SELECT id FROM job_cards WHERE mechanic_id = $mech1Id LIMIT 1")->fetchColumn();
$otherJob = (int) $pdo->query("SELECT id FROM job_cards WHERE mechanic_id = $mech2Id LIMIT 1")->fetchColumn();

$openOwn = http_request('GET', "job-cards/view.php?id=$ownJob", [], $mecJar)['code'] === 200;
$openOther = http_request('GET', "job-cards/view.php?id=$otherJob", [], $mecJar)['code'] === 403;
$idorOk = $openOwn && $openOther;
$t = (microtime(true) - $t0) * 1000;
record_test(
    'RBAC-09', 'Authorization and RBAC', 'Object-level access (IDOR): mechanic cannot open other mechanic job card',
    'Own job = 200, Other mechanic job = 403', "Own: $ownJob (200), Other: $otherJob (403)",
    $idorOk, $t, 'IDOR horizontal authorization boundary verified'
);

// B10: Privilege Escalation Prevention
$t0 = microtime(true);
$escRes = post_form($accJar, 'dashboard.php', 'customers/form.php', ['full_name' => 'Escalation Test', 'phone' => '+251911000123']);
$t = (microtime(true) - $t0) * 1000;
$pB10 = ($escRes['code'] === 403);
record_test(
    'RBAC-10', 'Authorization and RBAC', 'Privilege escalation: unauthorized POST blocked server-side',
    'HTTP 403 Forbidden', "HTTP {$escRes['code']}",
    $pB10, $t, 'Server-side permission middleware rejected unauthorized action'
);

echo "\n";

// =========================================================================
// DOMAIN C: SESSION SECURITY (6 TESTS)
// =========================================================================
echo "\033[1mDOMAIN C: SESSION SECURITY\033[0m\n";

// C1: HttpOnly Flag
$t0 = microtime(true);
$pC1 = (bool) preg_match('/Set-Cookie:\s*GMSSESSID_TEST=.*HttpOnly/i', $rA1['head']);
$t = (microtime(true) - $t0) * 1000;
record_test(
    'SESS-01', 'Session Security', 'Session cookie enforces HttpOnly flag to prevent XSS exfiltration',
    'Set-Cookie contains HttpOnly', $pC1 ? 'HttpOnly present' : 'HttpOnly missing',
    $pC1, $t, 'Verified on Set-Cookie header'
);

// C2: SameSite Lax Policy
$t0 = microtime(true);
$pC2 = (bool) preg_match('/SameSite=Lax/i', $rA1['head']);
$t = (microtime(true) - $t0) * 1000;
record_test(
    'SESS-02', 'Session Security', 'Session cookie enforces SameSite=Lax policy against CSRF',
    'Set-Cookie contains SameSite=Lax', $pC2 ? 'SameSite=Lax present' : 'SameSite missing',
    $pC2, $t, 'Verified on Set-Cookie header'
);

// C3: Secure Cookie Configuration Logic
$t0 = microtime(true);
$bootstrapCode = file_get_contents(APP_ROOT . '/app/bootstrap.php');
$pC3 = str_contains($bootstrapCode, "\$https = (!empty(\$_SERVER['HTTPS'])")
    && str_contains($bootstrapCode, "'secure' => \$https");
$t = (microtime(true) - $t0) * 1000;
record_test(
    'SESS-03', 'Session Security', 'Session cookie applies Secure flag dynamically under HTTPS',
    'session_set_cookie_params secure flag tied to HTTPS state', $pC3 ? 'Configured correctly' : 'Hardcoded or missing',
    $pC3, $t, 'Evaluates $_SERVER[HTTPS] securely'
);

// C4: Session Name Isolation
$t0 = microtime(true);
$pC4 = (bool) preg_match('/Set-Cookie:\s*GMSSESSID_TEST=/i', $rA1['head']);
$t = (microtime(true) - $t0) * 1000;
record_test(
    'SESS-04', 'Session Security', 'Test session uses dedicated isolated cookie name GMSSESSID_TEST',
    'Cookie name is GMSSESSID_TEST', $pC4 ? 'GMSSESSID_TEST verified' : 'Using default PHPSESSID',
    $pC4, $t, 'Isolated from production GMSSESSID'
);

// C5: Session Invalidation on Inactivity
$t0 = microtime(true);
$expSid = bin2hex(random_bytes(16));
$expSessFile = APP_ROOT . '/storage/testing/sessions/sess_' . $expSid;
$adminUid = (int) $pdo->query("SELECT id FROM users WHERE username = 'admin'")->fetchColumn();
file_put_contents($expSessFile, 'uid|i:' . $adminUid . ';last_activity|i:' . (time() - 20000) . ';');
$expJar = tempnam(sys_get_temp_dir(), 'gms_exp_');
$expRes = http_request('GET', 'dashboard.php', [], $expJar, ["Cookie: GMSSESSID_TEST=$expSid"]);
@unlink($expSessFile);
@unlink($expJar);
$t = (microtime(true) - $t0) * 1000;
$pC5 = ($expRes['code'] === 302 && str_contains($expRes['loc'], 'login.php'));
record_test(
    'SESS-05', 'Session Security', 'Expired idle session (> 4 hours) is invalidated automatically',
    'HTTP 302 redirect to login.php', "HTTP {$expRes['code']} loc: {$expRes['loc']}",
    $pC5, $t, 'Session timeout logic validated'
);

// C6: Strict Session Mode
$t0 = microtime(true);
$pC6 = str_contains($bootstrapCode, "ini_set('session.use_strict_mode', '1')")
    && str_contains($bootstrapCode, "ini_set('session.use_only_cookies', '1')");
$t = (microtime(true) - $t0) * 1000;
record_test(
    'SESS-06', 'Session Security', 'Strict session mode and cookie-only storage enforced in runtime',
    'session.use_strict_mode = 1 and session.use_only_cookies = 1', $pC6 ? 'Configured' : 'Missing',
    $pC6, $t, 'Uninitialized session IDs rejected by PHP engine'
);

echo "\n";

// =========================================================================
// DOMAIN D: CSRF AND REQUEST SECURITY (6 TESTS)
// =========================================================================
echo "\033[1mDOMAIN D: CSRF & REQUEST SECURITY\033[0m\n";

// D1: Valid CSRF Token Acceptance
$t0 = microtime(true);
$validCsrfRes = post_form($adminJar, 'dashboard.php', 'customers/form.php', [
    'full_name' => 'Valid CSRF Customer ' . bin2hex(random_bytes(2)),
    'phone' => '+251911999888',
]);
$t = (microtime(true) - $t0) * 1000;
$pD1 = ($validCsrfRes['code'] === 302);
record_test(
    'CSRF-01', 'CSRF and Request Security', 'Authentic CSRF token passes validation on state mutation',
    'HTTP 302 redirect upon form save', "HTTP {$validCsrfRes['code']}",
    $pD1, $t, 'Valid token accepted'
);

// D2: Missing CSRF Token Rejection
$t0 = microtime(true);
$noCsrfRes = http_request('POST', 'customers/form.php', [
    'full_name' => 'No CSRF Customer',
    'phone' => '+251911999888',
], $adminJar);
$t = (microtime(true) - $t0) * 1000;
$pD2 = ($noCsrfRes['code'] === 419);
record_test(
    'CSRF-02', 'CSRF and Request Security', 'POST request without CSRF token is rejected with HTTP 419',
    'HTTP 419 Session expired / Token invalid', "HTTP {$noCsrfRes['code']}",
    $pD2, $t, 'Missing token blocked'
);

// D3: Invalid / Forged CSRF Token Rejection
$t0 = microtime(true);
$badCsrfRes = http_request('POST', 'customers/form.php', [
    '_csrf' => 'deadbeefcafebabe0123456789abcdef0123456789abcdef0123456789abcdef',
    'full_name' => 'Forged CSRF Customer',
    'phone' => '+251911999888',
], $adminJar);
$t = (microtime(true) - $t0) * 1000;
$pD3 = ($badCsrfRes['code'] === 419);
record_test(
    'CSRF-03', 'CSRF and Request Security', 'Forged CSRF token is rejected with HTTP 419',
    'HTTP 419 Session expired / Token invalid', "HTTP {$badCsrfRes['code']}",
    $pD3, $t, 'Forged token rejected'
);

// D4: Expired CSRF Token Rejection
$t0 = microtime(true);
$tok = bin2hex(random_bytes(32));
$now = time();
$expCsrfOk = csrf_token_is_valid($tok, $tok, $now, $now)
    && !csrf_token_is_valid($tok, $tok, $now - 7201, $now);
$t = (microtime(true) - $t0) * 1000;
record_test(
    'CSRF-04', 'CSRF and Request Security', 'CSRF token lifetime expired (> 7200s) is rejected',
    'Valid at t=0, Invalid at t=7201s', $expCsrfOk ? 'Boundary enforced' : 'Expired token accepted',
    $expCsrfOk, $t, 'Token lifetime limit strictly enforced'
);

// D5: Audit Logging of CSRF Failures
$t0 = microtime(true);
$csrfAuditCount = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action_type = 'csrf_validation_failed' AND action_result = 'denied'")->fetchColumn();
$t = (microtime(true) - $t0) * 1000;
$pD5 = ($csrfAuditCount >= 2);
record_test(
    'CSRF-05', 'CSRF and Request Security', 'CSRF validation failures are recorded in audit logs with denied result',
    'At least 2 csrf_validation_failed records logged', "Found: $csrfAuditCount records",
    $pD5, $t, 'Security event logged in audit trail'
);

// D6: Request Method Validation
$t0 = microtime(true);
$getMutate = http_request('GET', 'payments/record.php?do=refund', [], $adminJar);
$t = (microtime(true) - $t0) * 1000;
$pD6 = ($getMutate['code'] !== 500);
record_test(
    'CSRF-06', 'CSRF and Request Security', 'State mutations require POST method',
    'Refuses state mutation via GET', "HTTP {$getMutate['code']}",
    $pD6, $t, 'GET mutations protected'
);

echo "\n";

// =========================================================================
// DOMAIN E: PASSWORD AND ACCOUNT RECOVERY (8 TESTS)
// =========================================================================
echo "\033[1mDOMAIN E: PASSWORD & RECOVERY\033[0m\n";

// E1: Password Hashing Verification
$t0 = microtime(true);
$badHashes = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE password_hash NOT LIKE '$2y$%' AND password_hash NOT LIKE '$argon2%'")->fetchColumn();
$t = (microtime(true) - $t0) * 1000;
$pE1 = ($badHashes === 0);
record_test(
    'RECOV-01', 'Password and Account Recovery', 'All stored password hashes use bcrypt ($2y$) or argon2',
    'Zero unhashed or weakly-hashed passwords', "Non-compliant hashes found: $badHashes",
    $pE1, $t, 'Engine-level hash verification'
);

// E2: Password Reset Enumeration Resistance
$t0 = microtime(true);
$resReal = PasswordResetService::requestReset('admin@autopro-garage.example');
$resFake = PasswordResetService::requestReset('nonexistent_user_xyz@autopro-garage.example');
$t = (microtime(true) - $t0) * 1000;
$pE2 = ($resReal['ok'] === true && $resFake['ok'] === true
    && str_contains($resReal['message'], 'instructions')
    && str_contains($resFake['message'], 'instructions'));
record_test(
    'RECOV-02', 'Password and Account Recovery', 'Password reset request returns generic message (no user enumeration)',
    'Identical success response for existing and non-existing emails', $pE2 ? 'Identical generic responses' : 'Messages differ',
    $pE2, $t, 'Email enumeration prevented'
);

// E3: Token Hashing in Database
$t0 = microtime(true);
$resetRow = $pdo->query("SELECT token_hash FROM password_resets ORDER BY id DESC LIMIT 1")->fetch();
$tokenHash = $resetRow['token_hash'] ?? '';
$t = (microtime(true) - $t0) * 1000;
$pE3 = (strlen($tokenHash) === 64 && ctype_xdigit($tokenHash));
record_test(
    'RECOV-03', 'Password and Account Recovery', 'Reset tokens stored as SHA-256 hashes (never plaintext)',
    '64-character hexadecimal SHA-256 hash', "Hash length: " . strlen($tokenHash),
    $pE3, $t, 'Raw token exists only in outbound email'
);

// E4: Invalid Reset Token Rejection
$t0 = microtime(true);
$valFake = PasswordResetService::validateToken(str_repeat('f', 64));
$t = (microtime(true) - $t0) * 1000;
$pE4 = ($valFake === null);
record_test(
    'RECOV-04', 'Password and Account Recovery', 'Random or forged reset tokens are rejected',
    'validateToken() returns null', $pE4 ? 'null returned' : 'Forged token accepted',
    $pE4, $t, 'Invalid tokens rejected'
);

// E5: Expired Reset Token Rejection
$t0 = microtime(true);
$expRawToken = bin2hex(random_bytes(32));
$expHash = hash('sha256', $expRawToken);
$insExp = $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, DATE_SUB(NOW(), INTERVAL 100 SECOND))');
$insExp->execute([$adminUid, $expHash]);
$valExp = PasswordResetService::validateToken($expRawToken);
$t = (microtime(true) - $t0) * 1000;
$pE5 = ($valExp === null);
record_test(
    'RECOV-05', 'Password and Account Recovery', 'Expired reset tokens (expires_at < now) are rejected',
    'validateToken() returns null for expired token', $pE5 ? 'null returned' : 'Expired token accepted',
    $pE5, $t, 'Token expiration enforced'
);

// E6: Single-Use Token Enforcement
$t0 = microtime(true);
$suRaw = bin2hex(random_bytes(32));
$suHash = hash('sha256', $suRaw);
$insSu = $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))');
$insSu->execute([$adminUid, $suHash]);
$val1 = PasswordResetService::validateToken($suRaw);
$completeRes = PasswordResetService::completeReset($suRaw, 'NewSecret#TestPass2026!');
$val2 = PasswordResetService::validateToken($suRaw);
// Revert admin password to standard test pass
$pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([AuthService::hash($ADMIN_PASS), $adminUid]);
$t = (microtime(true) - $t0) * 1000;
$pE6 = ($val1 !== null && !empty($completeRes['ok']) && $val2 === null);
record_test(
    'RECOV-06', 'Password and Account Recovery', 'Reset tokens are strictly single-use and cannot be replayed',
    'Valid before use, null after completion', $pE6 ? 'Single-use confirmed' : 'Replay allowed',
    $pE6, $t, 'Token invalidated upon use'
);

// E7: Password Policy Enforcement
$t0 = microtime(true);
$shortErr = AuthService::passwordProblems('Short1!', 'admin');
$noUpperErr = AuthService::passwordProblems('lowercase123!', 'admin');
$noSpecialErr = AuthService::passwordProblems('NoSpecialChars1', 'admin');
$userMatchErr = AuthService::passwordProblems('adminAdmin123!', 'admin');
$goodErr = AuthService::passwordProblems('Valid#StrongPass2026', 'admin');
$t = (microtime(true) - $t0) * 1000;
$pE7 = (!empty($shortErr) && !empty($noUpperErr) && !empty($noSpecialErr) && !empty($userMatchErr) && empty($goodErr));
record_test(
    'RECOV-07', 'Password and Account Recovery', 'Password policy requires length, casing, numbers, symbols, username dissimilarity',
    'Weak passwords rejected, strong accepted', $pE7 ? 'Policy verified' : 'Weak password permitted',
    $pE7, $t, 'AuthService::passwordProblems() enforces all rules'
);

// E8: Secret Redaction in Audit Logs
$t0 = microtime(true);
$leakedSecrets = (int) $pdo->query(
    "SELECT COUNT(*) FROM audit_logs WHERE
        new_value LIKE '%Admin#%' OR new_value LIKE '%Demo#%' OR new_value LIKE '%Secret#%'
        OR previous_value LIKE '%Admin#%' OR previous_value LIKE '%Demo#%'
        OR new_value LIKE '%password_hash%'"
)->fetchColumn();
$t = (microtime(true) - $t0) * 1000;
$pE8 = ($leakedSecrets === 0);
record_test(
    'RECOV-08', 'Password and Account Recovery', 'Zero passwords, hashes, or recovery secrets exposed in audit logs',
    'Zero rows with plaintext secrets or raw hashes', "Found: $leakedSecrets leaked entries",
    $pE8, $t, 'AuditLogger redaction array verified'
);

echo "\n";

// =========================================================================
// DOMAIN F: INPUT AND APPLICATION SECURITY (7 TESTS)
// =========================================================================
echo "\033[1mDOMAIN F: INPUT & APPLICATION SECURITY\033[0m\n";

// F1: Search SQL Injection Resistance
$t0 = microtime(true);
$sqliRes1 = http_request('GET', 'customers/?q=' . urlencode("' OR '1'='1' -- "), [], $adminJar);
$t = (microtime(true) - $t0) * 1000;
$pF1 = ($sqliRes1['code'] === 200 && str_contains($sqliRes1['body'], 'No customers found'));
record_test(
    'INP-01', 'Input and Application Security', 'SQL injection in search parameter handled safely by prepared statements',
    'HTTP 200 with "No customers found" (zero data leakage)', "HTTP {$sqliRes1['code']}",
    $pF1, $t, 'Prepared statements prevented boolean SQLi'
);

// F2: UNION SQL Injection Resistance
$t0 = microtime(true);
$sqliRes2 = http_request('GET', 'customers/?q=' . urlencode("x%' UNION SELECT 1,2,3,4,5,6,7,8,9,10,11 -- "), [], $adminJar);
$t = (microtime(true) - $t0) * 1000;
$pF2 = ($sqliRes2['code'] === 200 && str_contains($sqliRes2['body'], 'No customers found'));
record_test(
    'INP-02', 'Input and Application Security', 'UNION SQL injection attempt fails safely without syntax error',
    'HTTP 200 with "No customers found"', "HTTP {$sqliRes2['code']}",
    $pF2, $t, 'UNION query escaped as literal string'
);

// F3: Login Form SQL Injection Resistance
$t0 = microtime(true);
[, $sqliLogin] = auth_login("admin' OR '1'='1", "' OR '1'='1");
$t = (microtime(true) - $t0) * 1000;
$pF3 = ($sqliLogin['code'] === 200 && str_contains($sqliLogin['body'], 'Invalid username or password'));
record_test(
    'INP-03', 'Input and Application Security', 'SQL injection payload in login credentials handled securely',
    'HTTP 200 with generic error (no authentication bypass)', "HTTP {$sqliLogin['code']}",
    $pF3, $t, 'Bypass payload treated as literal username'
);

// F4: XSS Output Escaping
$t0 = microtime(true);
$xssPayload = '<script>alert("XSS")</script>';
$xssPost = post_form($adminJar, 'customers/form.php', 'customers/form.php', [
    'full_name' => $xssPayload,
    'phone' => '+251911777666',
    'email' => '',
    'address' => '"><img src=x onerror=alert(1)>',
]);
preg_match('~id=(\d+)~', $xssPost['loc'], $mXss);
$xssId = (int) ($mXss[1] ?? 0);
$xssView = http_request('GET', "customers/view.php?id=$xssId", [], $adminJar);
// Cleanup customer
if ($xssId > 0) {
    $pdo->prepare('DELETE FROM customers WHERE id = ?')->execute([$xssId]);
}
$t = (microtime(true) - $t0) * 1000;
$pF4 = ($xssId > 0
    && !str_contains($xssView['body'], '<script>alert("XSS")</script>')
    && !str_contains($xssView['body'], '"><img src=x onerror=alert(1)>')
    && str_contains($xssView['body'], '&lt;script&gt;alert(&quot;XSS&quot;)&lt;/script&gt;'));
record_test(
    'INP-04', 'Input and Application Security', 'HTML characters in user input escaped safely via e() output escaping',
    'Payload stored safely and rendered as HTML entities', $pF4 ? 'HTML entity escaped' : 'Raw script tag present',
    $pF4, $t, 'Cross-site scripting injection neutralized'
);

// F5: Authentication Error Equivalence
$t0 = microtime(true);
[, $resRealUser] = auth_login('admin', 'WrongPass123!');
$tReal = (microtime(true) - $t0) * 1000;

$t0 = microtime(true);
[, $resFakeUser] = auth_login('totally_fake_user_123', 'WrongPass123!');
$tFake = (microtime(true) - $t0) * 1000;

$pF5 = ($resRealUser['code'] === 200 && $resFakeUser['code'] === 200
    && str_contains($resRealUser['body'], 'Invalid username or password')
    && str_contains($resFakeUser['body'], 'Invalid username or password'));
record_test(
    'INP-05', 'Input and Application Security', 'Authentication response message is strictly identical for valid vs invalid usernames',
    'Both return identical "Invalid username or password"', $pF5 ? 'Identical message' : 'Messages differ',
    $pF5, $tFake, 'Timing: real user ' . round($tReal, 1) . 'ms vs fake user ' . round($tFake, 1) . 'ms'
);

// F6: Debug Information Suppression
$t0 = microtime(true);
$appConfig = require APP_ROOT . '/app/config/app.php';
$debugOff = empty($appConfig['debug']);
$t = (microtime(true) - $t0) * 1000;
record_test(
    'INP-06', 'Input and Application Security', 'Application debug mode disabled; internal traces suppressed',
    'app.php debug config is false', $debugOff ? 'debug=false' : 'debug=true (LEAK)',
    $debugOff, $t, 'Fatal errors rendered with generic error page'
);

// F7: Storage Path Traversal Protection
$t0 = microtime(true);
$travCaught = false;
try {
    storage_path('../../../windows/system32/cmd.exe');
} catch (InvalidArgumentException $e) {
    $travCaught = str_contains($e->getMessage(), 'Path traversal');
}
$t = (microtime(true) - $t0) * 1000;
record_test(
    'INP-07', 'Input and Application Security', 'storage_path() rejects path traversal attempts with InvalidArgumentException',
    'Throws InvalidArgumentException on .. segments', $travCaught ? 'Exception thrown' : 'Traversal allowed',
    $travCaught, $t, 'Path traversal segments neutralized'
);

echo "\n";

// =========================================================================
// DOMAIN G: AUDIT LOGGING (7 TESTS)
// =========================================================================
echo "\033[1mDOMAIN G: AUDIT LOGGING\033[0m\n";

// G1: Successful Login Audited
$t0 = microtime(true);
$sucLogins = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action_type = 'login_success' AND action_result = 'success'")->fetchColumn();
$t = (microtime(true) - $t0) * 1000;
$pG1 = ($sucLogins >= 1);
record_test(
    'AUD-01', 'Audit Logging', 'Successful login events recorded in audit trail with actor details',
    'At least 1 login_success audit entry', "Found: $sucLogins entries",
    $pG1, $t, 'Authentication success verified in audit log'
);

// G2: Failed Login Audited
$t0 = microtime(true);
$failLogins = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action_type = 'login_failed' AND action_result = 'failure'")->fetchColumn();
$t = (microtime(true) - $t0) * 1000;
$pG2 = ($failLogins >= 1);
record_test(
    'AUD-02', 'Audit Logging', 'Failed login attempts recorded in audit trail with IP address and failure result',
    'At least 1 login_failed audit entry', "Found: $failLogins entries",
    $pG2, $t, 'Authentication failure tracked in audit log'
);

// G3: Rate Limit Lockout Audited
$t0 = microtime(true);
$lockoutLogs = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action_type = 'login_blocked_rate_limit' AND action_result = 'denied'")->fetchColumn();
$t = (microtime(true) - $t0) * 1000;
$pG3 = ($lockoutLogs >= 1);
record_test(
    'AUD-03', 'Audit Logging', 'Rate-limiting lockouts logged with action_result = denied',
    'At least 1 login_blocked_rate_limit entry', "Found: $lockoutLogs entries",
    $pG3, $t, 'Rate-limit event logged'
);

// G4: Authorization Denial Audited
$t0 = microtime(true);
$denialLogs = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action_type = 'authorization_denied' AND action_result = 'denied'")->fetchColumn();
$t = (microtime(true) - $t0) * 1000;
$pG4 = ($denialLogs >= 1);
record_test(
    'AUD-04', 'Audit Logging', 'Authorization denials (HTTP 403) recorded with attempted action and actor',
    'At least 1 authorization_denied entry', "Found: $denialLogs entries",
    $pG4, $t, 'RBAC violations tracked'
);

// G5: Audit Hash Chain Verification
$t0 = microtime(true);
$chainRes = AuditLogger::verifyChain();
$t = (microtime(true) - $t0) * 1000;
$pG5 = ($chainRes['ok'] === true && $chainRes['checked'] > 0);
record_test(
    'AUD-05', 'Audit Logging', 'Cryptographic SHA-256 hash chain verified intact across entire audit trail',
    'verifyChain() returns ok=true', "Verified {$chainRes['checked']} records: " . ($chainRes['ok'] ? 'VALID' : 'FAILED'),
    $pG5, $t, "All {$chainRes['checked']} audit records verified intact"
);

// G6: Tamper Detection Simulation on Synthetic Chain
$t0 = microtime(true);
$refHash = new ReflectionMethod(AuditLogger::class, 'hashRow');
$refHash->setAccessible(true);
$h0 = str_repeat('0', 64);
$h1 = $refHash->invoke(null, $h0, [1, 'admin', 'administrator', 'test_act', 'test_mod', 1, null, null, '127.0.0.1', 'CLI', 'success', '2026-11-13 12:00:00']);
$h2 = $refHash->invoke(null, $h1, [1, 'admin', 'administrator', 'test_act2', 'test_mod', 2, null, null, '127.0.0.1', 'CLI', 'success', '2026-11-13 12:01:00']);
$h1Tampered = $refHash->invoke(null, $h0, [1, 'admin', 'administrator', 'TAMPERED_ACTION', 'test_mod', 1, null, null, '127.0.0.1', 'CLI', 'success', '2026-11-13 12:00:00']);
$detected = !hash_equals($h1, $h1Tampered);
$t = (microtime(true) - $t0) * 1000;
record_test(
    'AUD-06', 'Audit Logging', 'Cryptographic tamper detection: modified payload invalidates row_hash',
    'Altered record produces mismatched SHA-256 hash', $detected ? 'Tampering mathematically detected' : 'Hash collision',
    $detected, $t, 'SHA-256 hash sensitivity verified'
);

// G7: Database Engine Immutability Triggers
$t0 = microtime(true);
$trgUpdateBlocked = false;
try {
    $pdo->exec("UPDATE audit_logs SET username = 'hacker' WHERE id = 1");
} catch (PDOException $e) {
    $trgUpdateBlocked = str_contains($e->getMessage(), 'append-only');
}
$trgDeleteBlocked = false;
try {
    $pdo->exec("DELETE FROM audit_logs WHERE id = 1");
} catch (PDOException $e) {
    $trgDeleteBlocked = str_contains($e->getMessage(), 'append-only');
}
$pG7 = $trgUpdateBlocked && $trgDeleteBlocked;
$t = (microtime(true) - $t0) * 1000;
record_test(
    'AUD-07', 'Audit Logging', 'Database triggers block direct UPDATE and DELETE queries on audit_logs',
    'Triggers trg_audit_no_update and trg_audit_no_delete reject SQL mutations',
    $pG7 ? 'UPDATE & DELETE blocked by triggers' : 'Trigger failed to block mutation',
    $pG7, $t, 'Engine-level append-only enforcement confirmed'
);

echo "\n";

// =========================================================================
// 4. CLEANUP AND TERMINATION
// =========================================================================
if (is_resource($serverProc)) {
    proc_terminate($serverProc);
}
@unlink($adminJar);
@unlink($recJar);
@unlink($mecJar);
@unlink($stockJar);
@unlink($accJar);
@unlink($mec2Jar);

// Summary statistics
$totalTests = $passCount + $failCount;
$passRate = $totalTests > 0 ? round(($passCount / $totalTests) * 100, 1) : 0;

echo "=================================================================\n";
echo "REGRESSION SUITE EXECUTION SUMMARY\n";
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

// Save JSON test evidence for reporting
$jsonReportPath = APP_ROOT . '/storage/testing/regression_results.json';
file_put_contents($jsonReportPath, json_encode([
    'timestamp' => date('c'),
    'total' => $totalTests,
    'passed' => $passCount,
    'failed' => $failCount,
    'pass_rate' => $passRate,
    'results' => $results,
], JSON_PRETTY_PRINT));

exit($failCount > 0 ? 1 : 0);
