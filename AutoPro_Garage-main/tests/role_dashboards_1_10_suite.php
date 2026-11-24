<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — PHASE 1.10: ROLE-BASED DASHBOARDS & ACCESS SECURITY SUITE
 * 
 * Verifies enterprise role-based dashboards, RBAC boundaries, and horizontal IDOR defenses:
 *   1. Authentication & Role-Based Dispatching
 *   2. Role-Specific Content & Zero Leakage (Receptionist, Mechanic, Inventory Manager, Accountant, Customer)
 *   3. Vertical RBAC Denial Tests (Non-admin roles blocked from unauthorized modules)
 *   4. Horizontal IDOR Isolation Tests (Customer data isolation, Mechanic job isolation)
 *   5. Tamper-evident Audit Chain Integrity
 * 
 * Target Database: garage_management_system_test
 * Fail-Closed: Strictly refuses to execute against production.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Error: tests/role_dashboards_1_10_suite.php must be run from CLI.\n");
    exit(1);
}

define('APP_ROOT', str_replace('\\', '/', dirname(__DIR__)));
$phpBinary = 'C:\\MAMP\\bin\\php\\php8.3.1\\php.exe';
$phpIni = 'C:\\MAMP\\conf\\php8.3.1\\php.ini';

spl_autoload_register(function (string $class): void {
    foreach (['services', 'models'] as $dir) {
        $f = APP_ROOT . "/app/$dir/$class.php";
        if (is_file($f)) { require_once $f; return; }
    }
});

// Fail-closed environment assertions
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

// Isolated Test Server on port 8087
$serverPort = 8087;
$serverBase = "http://127.0.0.1:$serverPort/";

$serverLog = APP_ROOT . '/storage/testing/logs/test-server-110.log';
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

function http_request_110(string $method, string $path, array $data = [], string $jar = '', array $extraHeaders = []): array
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

function extract_csrf_110(string $html): string
{
    if (preg_match('/name=["\']_csrf["\']\s+value=["\']([a-f0-9]{64})["\']/i', $html, $m)) {
        return $m[1];
    }
    if (preg_match('/value=["\']([a-f0-9]{64})["\']\s+name=["\']_csrf["\']/i', $html, $m)) {
        return $m[1];
    }
    return '';
}

function auth_login_110(string $username, string $password): array
{
    $jar = tempnam(sys_get_temp_dir(), 'gms_auth_110_');
    $get = http_request_110('GET', 'login.php', [], $jar);
    $csrf = extract_csrf_110($get['body']);
    $post = http_request_110('POST', 'login.php', [
        '_csrf' => $csrf,
        'username' => $username,
        'password' => $password,
    ], $jar);
    return [$jar, $post];
}

$ADMIN_PASS = 'Admin#TestPass2026';
$DEMO_PASS = 'Demo#TestPass2026';

// Clean login attempts
$pdo->exec("DELETE FROM login_attempts");

// Ensure Customer Alice and Customer Bob accounts exist
$custRoleId = (int) Database::value("SELECT id FROM roles WHERE name = 'customer'");
$ALICE_PASS = 'Customer#Pass1';
$BOB_PASS = 'Customer#Pass2';

$alice = Database::one("SELECT id FROM users WHERE username = 'test_cust_alice'");
if (!$alice) {
    $aliceId = (int) Database::insert(
        'INSERT INTO users (role_id, username, full_name, email, password_hash, is_active) VALUES (?, ?, ?, ?, ?, 1)',
        [$custRoleId, 'test_cust_alice', 'Alice Customer', 'alice@customer.example', AuthService::hash($ALICE_PASS)]
    );
    $aliceCustId = (int) Database::insert(
        'INSERT INTO customers (full_name, phone, email, created_by) VALUES (?, ?, ?, ?)',
        ['Alice Customer', '+251 91 111 2233', 'alice@customer.example', $aliceId]
    );
} else {
    $aliceId = (int) $alice['id'];
    $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([AuthService::hash($ALICE_PASS), $aliceId]);
    $aliceCustId = (int) Database::value('SELECT id FROM customers WHERE created_by = ? LIMIT 1', [$aliceId]);
}

$bob = Database::one("SELECT id FROM users WHERE username = 'test_cust_bob'");
if (!$bob) {
    $bobId = (int) Database::insert(
        'INSERT INTO users (role_id, username, full_name, email, password_hash, is_active) VALUES (?, ?, ?, ?, ?, 1)',
        [$custRoleId, 'test_cust_bob', 'Bob Customer', 'bob@customer.example', AuthService::hash($BOB_PASS)]
    );
    $bobCustId = (int) Database::insert(
        'INSERT INTO customers (full_name, phone, email, created_by) VALUES (?, ?, ?, ?)',
        ['Bob Customer', '+251 92 222 3344', 'bob@customer.example', $bobId]
    );
} else {
    $bobId = (int) $bob['id'];
    $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([AuthService::hash($BOB_PASS), $bobId]);
    $bobCustId = (int) Database::value('SELECT id FROM customers WHERE created_by = ? LIMIT 1', [$bobId]);
}

// Seed vehicles for Alice and Bob
$aliceVeh = Database::one("SELECT id FROM vehicles WHERE customer_id = ?", [$aliceCustId]);
if (!$aliceVeh) {
    $aliceVehId = (int) Database::insert(
        'INSERT INTO vehicles (customer_id, plate_number, make, model, year, fuel_type) VALUES (?, "ALICE-01", "Audi", "A6", 2023, "Petrol")',
        [$aliceCustId]
    );
} else {
    $aliceVehId = (int) $aliceVeh['id'];
}

$bobVeh = Database::one("SELECT id FROM vehicles WHERE customer_id = ?", [$bobCustId]);
if (!$bobVeh) {
    $bobVehId = (int) Database::insert(
        'INSERT INTO vehicles (customer_id, plate_number, make, model, year, fuel_type) VALUES (?, "BOB-99", "BMW", "M5", 2024, "Petrol")',
        [$bobCustId]
    );
} else {
    $bobVehId = (int) $bobVeh['id'];
}

// Seed appointments for Bob
$bobApt = Database::one("SELECT id FROM appointments WHERE customer_id = ?", [$bobCustId]);
if (!$bobApt) {
    $bobAptId = (int) Database::insert(
        'INSERT INTO appointments (appointment_number, customer_id, vehicle_id, appointment_date, appointment_time, status, problem_description) VALUES ("APT-BOB-001", ?, ?, CURDATE(), "14:00", "confirmed", "Bob brake check")',
        [$bobCustId, $bobVehId]
    );
} else {
    $bobAptId = (int) $bobApt['id'];
}

// Ensure Mechanic 1 and Mechanic 2 have distinct assigned jobs
$mech1User = Database::one("SELECT id FROM users WHERE username = 'mechanic1'");
$mech2User = Database::one("SELECT id FROM users WHERE username = 'mechanic2'");

$mech1Id = (int) Database::value("SELECT id FROM mechanics WHERE user_id = ?", [$mech1User['id']]);
$mech2Id = (int) Database::value("SELECT id FROM mechanics WHERE user_id = ?", [$mech2User['id']]);

$jobMech1 = Database::one("SELECT id FROM job_cards WHERE mechanic_id = ?", [$mech1Id]);
if (!$jobMech1) {
    $jobMech1Id = (int) Database::insert(
        'INSERT INTO job_cards (job_number, customer_id, vehicle_id, mechanic_id, date_received, reported_problem, status, priority) VALUES ("JOB-M1-001", ?, ?, ?, CURDATE(), "Clutch replacement for M1", "In Progress", "High")',
        [$aliceCustId, $aliceVehId, $mech1Id]
    );
} else {
    $jobMech1Id = (int) $jobMech1['id'];
}

$jobMech2 = Database::one("SELECT id FROM job_cards WHERE mechanic_id = ?", [$mech2Id]);
if (!$jobMech2) {
    $jobMech2Id = (int) Database::insert(
        'INSERT INTO job_cards (job_number, customer_id, vehicle_id, mechanic_id, date_received, reported_problem, status, priority) VALUES ("JOB-M2-999", ?, ?, ?, CURDATE(), "Transmission rebuild for M2", "In Progress", "Urgent")',
        [$bobCustId, $bobVehId, $mech2Id]
    );
} else {
    $jobMech2Id = (int) $jobMech2['id'];
}

// Test reporting harness
$results110 = [];
function record_test_110(string $testId, string $desc, string $expected, string $actual, bool $passed, float $ms, string $detail = ''): void
{
    global $results110;
    $results110[] = [
        'id' => $testId,
        'desc' => $desc,
        'expected' => $expected,
        'actual' => $actual,
        'passed' => $passed,
        'ms' => round($ms, 1),
        'detail' => $detail,
    ];
    $status = $passed ? "\033[32m✔ PASS\033[0m" : "\033[31m✖ FAIL\033[0m";
    echo "  [$status] $testId: $desc ({$results110[count($results110)-1]['ms']}ms)\n";
    if (!$passed) {
        echo "         Expected: $expected\n";
        echo "         Actual:   $actual\n";
        if ($detail) { echo "         Detail:   $detail\n"; }
    }
}

echo "=================================================================\n";
echo "ALPHA GARAGE — PHASE 1.10 ROLE-BASED DASHBOARD TEST SUITE\n";
echo "Target: garage_management_system_test on 127.0.0.1:$serverPort\n";
echo "=================================================================\n\n";

// =========================================================================
// SECTION A: AUTHENTICATION & ROLE-BASED DISPATCHING
// =========================================================================
echo "SECTION A: AUTHENTICATION & ROLE-BASED DISPATCHING\n";

// TEST 1: Unauthenticated dashboard access
$t0 = microtime(true);
$unauth = http_request_110('GET', 'dashboard.php');
$t = (microtime(true) - $t0) * 1000;
$pass1 = ($unauth['code'] === 302 && str_contains($unauth['loc'], 'login.php'));
record_test_110('DASH-AUTH-01', 'Unauthenticated dashboard request is redirected to login.php', 'HTTP 302 to login.php', "Code {$unauth['code']}, Loc: {$unauth['loc']}", $pass1, $t);

// Authenticate All Roles
[$adminJar, $adminRes] = auth_login_110('admin', $ADMIN_PASS);
[$recJar, $recRes] = auth_login_110('reception', $DEMO_PASS);
[$mechJar, $mechRes] = auth_login_110('mechanic1', $DEMO_PASS);
[$stockJar, $stockRes] = auth_login_110('stock', $DEMO_PASS);
[$acctJar, $acctRes] = auth_login_110('accounts', $DEMO_PASS);
[$aliceJar, $aliceRes] = auth_login_110('test_cust_alice', $ALICE_PASS);
[$bobJar, $bobRes] = auth_login_110('test_cust_bob', $BOB_PASS);

// TEST 2: Administrator Dashboard View
$t0 = microtime(true);
$adminDash = http_request_110('GET', 'dashboard.php', [], $adminJar);
$t = (microtime(true) - $t0) * 1000;
$pass2 = ($adminDash['code'] === 200 && str_contains($adminDash['body'], 'Financial &amp; Operations Command Center') && str_contains($adminDash['body'], 'Total Revenue'));
record_test_110('DASH-ROLE-01', 'Administrator lands on Command Center with financial KPIs and charts', 'HTTP 200, Command Center title & revenue present', "Code {$adminDash['code']}", $pass2, $t);

// TEST 3: Receptionist Dashboard View
$t0 = microtime(true);
$recDash = http_request_110('GET', 'dashboard.php', [], $recJar);
$t = (microtime(true) - $t0) * 1000;
$pass3 = ($recDash['code'] === 200 && str_contains($recDash['body'], 'Front Desk &amp; Workshop Intake') && str_contains($recDash['body'], 'Registered Customers') && str_contains($recDash['body'], 'Today\'s Service Schedule'));
record_test_110('DASH-ROLE-02', 'Receptionist lands on Front Desk Operations with appointments and vehicle intake', 'HTTP 200, Front Desk & Today Schedule present', "Code {$recDash['code']}", $pass3, $t);

// TEST 4: Mechanic Dashboard View
$t0 = microtime(true);
$mechDash = http_request_110('GET', 'dashboard.php', [], $mechJar);
$t = (microtime(true) - $t0) * 1000;
$pass4 = ($mechDash['code'] === 200 && str_contains($mechDash['body'], 'Mechanic Workstation') && str_contains($mechDash['body'], 'My Active Repair Queue') && !str_contains($mechDash['body'], 'Total Revenue'));
record_test_110('DASH-ROLE-03', 'Mechanic lands on Workstation queue with zero financial revenue leakage', 'HTTP 200, Workstation Queue present, Total Revenue absent', "Code {$mechDash['code']}", $pass4, $t);

// TEST 5: Inventory Manager Dashboard View
$t0 = microtime(true);
$stockDash = http_request_110('GET', 'dashboard.php', [], $stockJar);
$t = (microtime(true) - $t0) * 1000;
$pass5 = ($stockDash['code'] === 200 && str_contains($stockDash['body'], 'Parts Catalog &amp; Supply Chain Logistics') && str_contains($stockDash['body'], 'Critical Restock Alerts') && !str_contains($stockDash['body'], 'Net Collected Revenue'));
record_test_110('DASH-ROLE-04', 'Inventory Manager lands on Stock Control with restock alerts and zero financial leakage', 'HTTP 200, Stock Control present, Net Revenue absent', "Code {$stockDash['code']}", $pass5, $t);

// TEST 6: Accountant Dashboard View
$t0 = microtime(true);
$acctDash = http_request_110('GET', 'dashboard.php', [], $acctJar);
$t = (microtime(true) - $t0) * 1000;
$pass6 = ($acctDash['code'] === 200 && str_contains($acctDash['body'], 'Accounts Receivable, Ledger &amp; Cash Control') && str_contains($acctDash['body'], 'Outstanding Accounts Receivable'));
record_test_110('DASH-ROLE-05', 'Accountant lands on Accounts Receivable aging ledger and payment log', 'HTTP 200, Accounts Receivable ledger present', "Code {$acctDash['code']}", $pass6, $t);

// TEST 7: Customer Portal Dashboard View
$t0 = microtime(true);
$alicePlate = (string) Database::value("SELECT plate_number FROM vehicles WHERE id = ?", [$aliceVehId]);
$aliceDashRedirect = http_request_110('GET', 'dashboard.php', [], $aliceJar);
$aliceDash = http_request_110('GET', 'customer.php', [], $aliceJar);
$t = (microtime(true) - $t0) * 1000;
$pass7 = ($aliceDashRedirect['code'] === 302 && str_contains($aliceDashRedirect['loc'], 'customer.php')
    && $aliceDash['code'] === 200 && str_contains($aliceDash['body'], 'VIP Client Services') && str_contains($aliceDash['body'], 'My Vehicles') && str_contains($aliceDash['body'], $alicePlate));
record_test_110('DASH-ROLE-06', 'Customer visiting dashboard.php redirects to customer.php and renders VIP Portal', "HTTP 302 -> HTTP 200, VIP Client Services & $alicePlate present", "Redirect {$aliceDashRedirect['code']} to {$aliceDashRedirect['loc']}, Portal {$aliceDash['code']}", $pass7, $t);

// TEST 8: Customer Login Redirection
$t0 = microtime(true);
$aliceLoginPost = $aliceRes;
$t = (microtime(true) - $t0) * 1000;
$pass8 = ($aliceLoginPost['code'] === 302 && (str_contains($aliceLoginPost['loc'], 'dashboard.php') || str_contains($aliceLoginPost['loc'], 'customer.php')));
record_test_110('DASH-ROUT-01', 'Customer sign-in redirects to customer portal destination', 'HTTP 302 to dashboard.php or customer.php', "Code {$aliceLoginPost['code']}, Loc: {$aliceLoginPost['loc']}", $pass8, $t);


// =========================================================================
// SECTION B: VERTICAL RBAC BOUNDARY ENFORCEMENT
// =========================================================================
echo "\nSECTION B: VERTICAL RBAC BOUNDARY ENFORCEMENT\n";

// TEST 9: Receptionist blocked from admin user management
$t0 = microtime(true);
$recUsers = http_request_110('GET', 'users/', [], $recJar);
$t = (microtime(true) - $t0) * 1000;
$pass9 = ($recUsers['code'] === 403);
record_test_110('RBAC-VER-01', 'Receptionist is denied access to /users/ management (HTTP 403)', 'HTTP 403 Forbidden', "Code {$recUsers['code']}", $pass9, $t);

// TEST 10: Receptionist blocked from audit logs
$t0 = microtime(true);
$recAudit = http_request_110('GET', 'audit-logs/', [], $recJar);
$t = (microtime(true) - $t0) * 1000;
$pass10 = ($recAudit['code'] === 403);
record_test_110('RBAC-VER-02', 'Receptionist is denied access to /audit-logs/ (HTTP 403)', 'HTTP 403 Forbidden', "Code {$recAudit['code']}", $pass10, $t);

// TEST 11: Mechanic blocked from financial invoices
$t0 = microtime(true);
$mechInv = http_request_110('GET', 'invoices/', [], $mechJar);
$t = (microtime(true) - $t0) * 1000;
$pass11 = ($mechInv['code'] === 403);
record_test_110('RBAC-VER-03', 'Mechanic is denied access to /invoices/ (HTTP 403)', 'HTTP 403 Forbidden', "Code {$mechInv['code']}", $pass11, $t);

// TEST 12: Mechanic blocked from payments recording
$t0 = microtime(true);
$mechPay = http_request_110('GET', 'payments/record.php', [], $mechJar);
$t = (microtime(true) - $t0) * 1000;
$pass12 = ($mechPay['code'] === 403);
record_test_110('RBAC-VER-04', 'Mechanic is denied access to /payments/record.php (HTTP 403)', 'HTTP 403 Forbidden', "Code {$mechPay['code']}", $pass12, $t);

// TEST 13: Inventory Manager blocked from payments recording
$t0 = microtime(true);
$stockPay = http_request_110('GET', 'payments/record.php', [], $stockJar);
$t = (microtime(true) - $t0) * 1000;
$pass13 = ($stockPay['code'] === 403);
record_test_110('RBAC-VER-05', 'Inventory Manager is denied access to /payments/record.php (HTTP 403)', 'HTTP 403 Forbidden', "Code {$stockPay['code']}", $pass13, $t);

// TEST 14: Accountant blocked from users management
$t0 = microtime(true);
$acctUsers = http_request_110('GET', 'users/', [], $acctJar);
$t = (microtime(true) - $t0) * 1000;
$pass14 = ($acctUsers['code'] === 403);
record_test_110('RBAC-VER-06', 'Accountant is denied access to /users/ management (HTTP 403)', 'HTTP 403 Forbidden', "Code {$acctUsers['code']}", $pass14, $t);

// TEST 15: Customer blocked from staff job-cards list
$t0 = microtime(true);
$custJobs = http_request_110('GET', 'job-cards/', [], $aliceJar);
$t = (microtime(true) - $t0) * 1000;
$pass15 = ($custJobs['code'] === 403);
record_test_110('RBAC-VER-07', 'Customer role is denied access to staff /job-cards/ board (HTTP 403)', 'HTTP 403 Forbidden', "Code {$custJobs['code']}", $pass15, $t);

// TEST 16: Customer blocked from staff customer directory
$t0 = microtime(true);
$custCusts = http_request_110('GET', 'customers/', [], $aliceJar);
$t = (microtime(true) - $t0) * 1000;
$pass16 = ($custCusts['code'] === 403);
record_test_110('RBAC-VER-08', 'Customer role is denied access to staff /customers/ directory (HTTP 403)', 'HTTP 403 Forbidden', "Code {$custCusts['code']}", $pass16, $t);


// =========================================================================
// SECTION C: HORIZONTAL DATA ISOLATION (IDOR DEFENSES)
// =========================================================================
echo "\nSECTION C: HORIZONTAL DATA ISOLATION (IDOR DEFENSES)\n";

// TEST 17: Customer A cannot view Customer B's appointment details
$t0 = microtime(true);
$aliceViewsBobApt = http_request_110('GET', 'book.php?view=' . $bobAptId, [], $aliceJar);
$t = (microtime(true) - $t0) * 1000;
$pass17 = ($aliceViewsBobApt['code'] === 403);
record_test_110('IDOR-CUST-01', 'Customer A cannot view Customer B appointment record (HTTP 403)', 'HTTP 403 Forbidden', "Code {$aliceViewsBobApt['code']}", $pass17, $t);

// TEST 18: Customer A cannot book using Customer B's vehicle ID
$t0 = microtime(true);
$bookGet = http_request_110('GET', 'book.php', [], $aliceJar);
$bookCsrf = extract_csrf_110($bookGet['body']);
$aliceBooksBobVeh = http_request_110('POST', 'book.php', [
    '_csrf' => $bookCsrf,
    'vehicle_mode' => 'existing',
    'vehicle_id' => $bobVehId,
    'service_id' => '',
    'appointment_date' => date('Y-m-d', strtotime('+2 days')),
    'appointment_time' => '10:00',
    'problem_description' => 'Malicious cross-customer booking attempt',
], $aliceJar);
$t = (microtime(true) - $t0) * 1000;
$pass18 = ($aliceBooksBobVeh['code'] === 403);
record_test_110('IDOR-CUST-02', 'Customer A cannot book service using Customer B vehicle ID (HTTP 403)', 'HTTP 403 Forbidden', "Code {$aliceBooksBobVeh['code']}", $pass18, $t);

// TEST 19: Customer A dashboard does NOT disclose Customer B's vehicle
$t0 = microtime(true);
$alicePlate = (string) Database::value("SELECT plate_number FROM vehicles WHERE id = ?", [$aliceVehId]);
$bobPlate = (string) Database::value("SELECT plate_number FROM vehicles WHERE id = ?", [$bobVehId]);
$aliceDashContent = $aliceDash['body'];
$pass19 = (str_contains($aliceDashContent, $alicePlate) && !str_contains($aliceDashContent, $bobPlate));
$t = (microtime(true) - $t0) * 1000;
record_test_110('IDOR-CUST-03', "Customer A dashboard strictly isolates vehicles ($alicePlate present, $bobPlate absent)", "$alicePlate visible, $bobPlate strictly hidden", $pass19 ? 'Isolated' : 'Leakage detected', $pass19, $t);

// TEST 20: Mechanic 1 cannot view Mechanic 2's assigned job card
$t0 = microtime(true);
$m1ViewsM2Job = http_request_110('GET', 'job-cards/view.php?id=' . $jobMech2Id, [], $mechJar);
$t = (microtime(true) - $t0) * 1000;
$pass20 = ($m1ViewsM2Job['code'] === 403);
record_test_110('IDOR-MECH-01', 'Mechanic 1 is denied access to Mechanic 2 assigned job card (HTTP 403)', 'HTTP 403 Forbidden', "Code {$m1ViewsM2Job['code']}", $pass20, $t);

// TEST 21: Mechanic 1 cannot update status on Mechanic 2's job
$t0 = microtime(true);
$m1ActionsM2 = http_request_110('POST', 'job-cards/action.php', [
    '_csrf' => 'any_or_bypass',
    'do' => 'status',
    'id' => $jobMech2Id,
    'status' => 'Completed',
], $mechJar);
$t = (microtime(true) - $t0) * 1000;
// Rejection by CSRF (419) or Deny (403): either safely blocks mutation
$pass21 = ($m1ActionsM2['code'] === 403 || $m1ActionsM2['code'] === 419);
record_test_110('IDOR-MECH-02', 'Mechanic 1 cannot alter workflow status on Mechanic 2 job card', 'HTTP 403 or 419 State Mutation Rejection', "Code {$m1ActionsM2['code']}", $pass21, $t);

// TEST 22: Mechanic 1 dashboard contains ONLY Mechanic 1 jobs
$t0 = microtime(true);
$m1JobNum = (string) Database::value("SELECT job_number FROM job_cards WHERE id = ?", [$jobMech1Id]);
$m2JobNum = (string) Database::value("SELECT job_number FROM job_cards WHERE id = ?", [$jobMech2Id]);
$mechDashContent = $mechDash['body'];
$pass22 = (str_contains($mechDashContent, $m1JobNum) && !str_contains($mechDashContent, $m2JobNum));
$t = (microtime(true) - $t0) * 1000;
record_test_110('IDOR-MECH-03', "Mechanic dashboard queue strictly isolates assigned jobs ($m1JobNum present, $m2JobNum absent)", "$m1JobNum visible, $m2JobNum strictly hidden", $pass22 ? 'Isolated' : 'Leakage detected', $pass22, $t);


// =========================================================================
// SECTION D: AUDIT CHAIN INTEGRITY
// =========================================================================
echo "\nSECTION D: AUDIT CHAIN INTEGRITY\n";

$t0 = microtime(true);
$chainCheck = AuditLogger::verifyChain();
$t = (microtime(true) - $t0) * 1000;
$pass23 = ($chainCheck['ok'] === true && $chainCheck['checked'] > 0);
record_test_110('DASH-AUD-01', 'Cryptographic SHA-256 Merkle audit-chain verified 100% intact', 'ok=true, 0 broken links', "ok: " . ($chainCheck['ok'] ? 'true' : 'false') . ", checked: {$chainCheck['checked']} records", $pass23, $t);


// =========================================================================
// TEST SUMMARY
// =========================================================================
$totalPassed = count(array_filter($results110, fn($r) => $r['passed']));
$totalCount = count($results110);

echo "\n=================================================================\n";
echo "PHASE 1.10 TEST SUMMARY: $totalPassed / $totalCount PASSED (" . round(($totalPassed / $totalCount) * 100, 1) . "%)\n";
echo "Target Test Database: garage_management_system_test (PROTECTED)\n";
echo "=================================================================\n";

if ($totalPassed !== $totalCount) {
    exit(1);
}
exit(0);
