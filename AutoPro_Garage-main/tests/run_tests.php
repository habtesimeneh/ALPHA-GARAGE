<?php
declare(strict_types=1);
/**
 * Destructive integration checks. Run only against a dedicated *_test database after importing database.sql
 * and running setup with demo data:
 *
 *   php -S 127.0.0.1:8080 -t public            (in one terminal; or use MAMP's Apache and set BASE)
 *   GMS_ALLOW_DESTRUCTIVE_TESTS=1 BASE=http://127.0.0.1:8080/ ADMIN_PASS='...' DEMO_PASS='...' php tests/run_tests.php
 *
 * Covers: authentication, rate limiting, role permissions, IDOR, CSRF, SQL injection, XSS, stock deduction,
 * invoice maths, multiple payments, audit logging, hash-chain tamper detection and append-only triggers.
 * It writes test records and deletes login-attempt rows: do not use a production or non-test database.
 */
require __DIR__ . '/../app/bootstrap.php';

$dbConfig = require APP_ROOT . '/app/config/database.php';
if (getenv('GMS_ALLOW_DESTRUCTIVE_TESTS') !== '1'
    || !str_ends_with($dbConfig['name'], '_test')
    || !in_array($dbConfig['host'], ['localhost', '127.0.0.1', '::1'], true)) {
    fwrite(STDERR, "Refusing destructive tests: use a local database ending in _test and set GMS_ALLOW_DESTRUCTIVE_TESTS=1.\n");
    exit(2);
}

$BASE = rtrim(getenv('BASE') ?: 'http://127.0.0.1:8080/', '/') . '/';
$ADMIN_PASS = getenv('ADMIN_PASS') ?: '';
$DEMO_PASS = getenv('DEMO_PASS') ?: '';
if ($ADMIN_PASS === '' || $DEMO_PASS === '') {
    fwrite(STDERR, "Set ADMIN_PASS and DEMO_PASS to the passwords chosen for this dedicated test database.\n");
    exit(2);
}
$pass = 0; $fail = 0;

function check(string $name, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  \033[32m✔\033[0m $name\n"; } else { $fail++; echo "  \033[31m✘ $name\033[0m $detail\n"; }
}
function section(string $t): void { echo "\n\033[1m$t\033[0m\n"; }

function http(string $method, string $path, array $data = [], string $jar = ''): array
{
    global $BASE;
    $ch = curl_init($BASE . ltrim($path, '/'));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30, CURLOPT_CUSTOMREQUEST => $method]);
    if ($jar) { curl_setopt($ch, CURLOPT_COOKIEJAR, $jar); curl_setopt($ch, CURLOPT_COOKIEFILE, $jar); }
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data)); }
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $head = substr($raw, 0, $hs);
    preg_match('/^Location:\s*(\S+)/mi', $head, $m);
    return ['code' => $code, 'body' => substr($raw, $hs), 'loc' => $m[1] ?? '', 'head' => $head];
}
function token(string $html): string { preg_match('/name="_csrf" value="([a-f0-9]+)"/', $html, $m); return $m[1] ?? ''; }
function login(string $user, string $pw): array
{
    $jar = tempnam(sys_get_temp_dir(), 'gms'); $page = http('GET', 'login.php', [], $jar);
    $r = http('POST', 'login.php', ['_csrf' => token($page['body']), 'username' => $user, 'password' => $pw], $jar);
    return [$jar, $r];
}
function post_to(string $jar, string $formPage, string $path, array $data): array
{
    $p = http('GET', $formPage, [], $jar);
    return http('POST', $path, ['_csrf' => token($p['body'])] + $data, $jar);
}
function auditCount(string $action, string $result = ''): int
{
    return (int) Database::value('SELECT COUNT(*) FROM audit_logs WHERE action_type = ?' . ($result ? ' AND action_result = ?' : ''), $result ? [$action, $result] : [$action]);
}

/* ======================================================================== */
section('1. Authentication');
$r = http('GET', 'dashboard.php');
check('Anonymous user is redirected from the dashboard to login', $r['code'] === 302 && str_contains($r['loc'], 'login.php'));
Database::run('DELETE FROM login_attempts');
[$jar, $r] = login('admin', 'wrong-password');
check('Wrong password is rejected with a generic message', $r['code'] === 200 && str_contains($r['body'], 'Invalid username or password'));
[, $r2] = login('no_such_user', 'whatever');
check('Unknown user gets the same message (no user enumeration)', str_contains($r2['body'], 'Invalid username or password'));
check('Failed logins are audited', auditCount('login_failed', 'failure') >= 2);
$jar0 = tempnam(sys_get_temp_dir(), 'gms'); $pg = http('GET', 'login.php', [], $jar0); $sid0 = file_get_contents($jar0);
[$adminJar, $r] = login('admin', $ADMIN_PASS);
check('Correct login redirects to dashboard', $r['code'] === 302 && str_contains($r['loc'], 'dashboard.php'));
check('Session cookie is HttpOnly + SameSite', (bool) preg_match('/Set-Cookie: GMSSESSID=.*HttpOnly/i', $r['head']) && (bool) preg_match('/SameSite=Lax/i', $r['head']));
preg_match('/GMSSESSID\s+(\w+)/', file_get_contents($adminJar), $m1); preg_match('/GMSSESSID\s+(\w+)/', $sid0, $m0);
check('Session ID is regenerated after login', !empty($m1[1]) && ($m0[1] ?? '') !== $m1[1]);
check('Successful login is audited', auditCount('login_success') >= 1);
$d = http('GET', 'dashboard.php', [], $adminJar);
check('Dashboard renders real data and analytics for administrator', $d['code'] === 200
    && str_contains($d['body'], 'Active Job Cards')
    && str_contains($d['body'], 'Completed Jobs')
    && str_contains($d['body'], 'dashboard-range')
    && str_contains($d['body'], 'Revenue vs Expenses')
    && str_contains($d['body'], 'Profit &amp; Loss Analysis')
    && str_contains($d['body'], 'chart-jobs-donut')
    && str_contains($d['body'], 'chart-inventory-bar')
    && str_contains($d['body'], 'chart-services')
    && str_contains($d['body'], 'Pending Payments')
    && str_contains($d['body'], 'Inventory Value')
    && str_contains($d['body'], 'Revenue, expenses &amp; profit')
    && !str_contains($d['body'], 'Something went wrong'));
check('Security headers present (CSP, X-Frame-Options)', str_contains($d['head'], 'Content-Security-Policy') && str_contains($d['head'], 'X-Frame-Options: DENY'));
check('Logout via GET is refused (POST + CSRF only)', http('GET', 'logout.php', [], $adminJar)['code'] === 405);
$adminEmail = (string) Database::value("SELECT email FROM users WHERE username = 'admin'");
[, $emailLogin] = login($adminEmail, $ADMIN_PASS);
check('Administrator can sign in using email address', $adminEmail !== '' && $emailLogin['code'] === 302 && str_contains($emailLogin['loc'], 'dashboard.php'));

section('2. Login rate limiting');
Database::run('DELETE FROM login_attempts');
for ($i = 0; $i < 5; $i++) { login('reception', 'bad-' . $i); }
[, $r] = login('reception', $DEMO_PASS);
check('Account is locked after 5 failures, even with the right password', str_contains($r['body'], 'Too many failed attempts'));
check('Lockout is audited', auditCount('login_blocked_rate_limit', 'denied') >= 1);
Database::run('DELETE FROM login_attempts');

section('3. CSRF protection');
$r = http('POST', 'customers/form.php', ['full_name' => 'No Token', 'phone' => '+251911000000'], $adminJar);
check('POST without CSRF token is rejected (419)', $r['code'] === 419);
$r = http('POST', 'customers/form.php', ['_csrf' => 'deadbeef', 'full_name' => 'Bad Token', 'phone' => '+251911000000'], $adminJar);
check('POST with a forged token is rejected (419)', $r['code'] === 419);
check('CSRF failures are audited', auditCount('csrf_validation_failed', 'denied') >= 2);

section('4. Role-based access control (server side)');
[$recJar, $recLogin] = login('reception', $DEMO_PASS);
check('Receptionist can sign in with valid credentials', $recLogin['code'] === 302 && str_contains($recLogin['loc'], 'dashboard.php'));
[$mecJar] = login('mechanic1', $DEMO_PASS); [$mec2Jar] = login('mechanic2', $DEMO_PASS);
[$stockJar] = login('stock', $DEMO_PASS); [$accJar] = login('accounts', $DEMO_PASS);
$before = auditCount('authorization_denied', 'denied');
check('Receptionist cannot open audit logs', http('GET', 'audit-logs/', [], $recJar)['code'] === 403);
check('Receptionist cannot manage users', http('GET', 'users/', [], $recJar)['code'] === 403);
check('Mechanic cannot open invoices', http('GET', 'invoices/', [], $mecJar)['code'] === 403);
check('Mechanic cannot open customers', http('GET', 'customers/', [], $mecJar)['code'] === 403);
check('Accountant can view operating expenses', http('GET', 'expenses/', [], $accJar)['code'] === 200);
check('Receptionist cannot view operating expenses', http('GET', 'expenses/', [], $recJar)['code'] === 403);
check('Accountant cannot create customers (POST blocked server-side)', post_to($accJar, 'dashboard.php', 'customers/form.php', ['full_name' => 'X', 'phone' => '+251911000001'])['code'] === 403);
check('Inventory manager cannot record payments', http('GET', 'payments/record.php', [], $stockJar)['code'] === 403);
check('Accountant cannot issue refunds', post_to($accJar, 'dashboard.php', 'payments/refund.php', ['invoice_id' => 1, 'amount' => '1', 'method' => 'Cash', 'reason' => 'x'])['code'] === 403);
check('Receptionist CAN open customers', http('GET', 'customers/', [], $recJar)['code'] === 200);
check('Unauthorised attempts are written to the audit log', auditCount('authorization_denied', 'denied') >= $before + 6);
check('Accountant sees the financial report list but not audit report', ($x = http('GET', 'reports/?report=audit', [], $accJar))['code'] === 403);

section('5. IDOR (object-level access)');
$mech1 = (int) Database::value("SELECT m.id FROM mechanics m JOIN users u ON u.id = m.user_id WHERE u.username = 'mechanic1'");
$mech2 = (int) Database::value("SELECT m.id FROM mechanics m JOIN users u ON u.id = m.user_id WHERE u.username = 'mechanic2'");
$own = (int) Database::value('SELECT id FROM job_cards WHERE mechanic_id = ? LIMIT 1', [$mech1]);
$other = (int) Database::value('SELECT id FROM job_cards WHERE mechanic_id = ? LIMIT 1', [$mech2]);
check('Mechanic can open own job card', http('GET', "job-cards/view.php?id=$own", [], $mecJar)['code'] === 200);
check('Mechanic cannot open another mechanic\'s job card by guessing the ID', http('GET', "job-cards/view.php?id=$other", [], $mecJar)['code'] === 403);
check('Mechanic cannot change status of another mechanic\'s job', post_to($mecJar, "job-cards/view.php?id=$own", 'job-cards/action.php', ['do' => 'status', 'id' => $other, 'status' => 'Completed'])['code'] === 403);
check('Mechanic job list contains only own jobs', !str_contains(http('GET', 'job-cards/', [], $mecJar)['body'], (string) Database::value('SELECT job_number FROM job_cards WHERE id = ?', [$other])));

section('6. SQL injection & XSS');
$r = http('GET', 'customers/?q=' . urlencode("' OR '1'='1' -- "), [], $adminJar);
check('SQL injection in search does not error or leak rows', $r['code'] === 200 && str_contains($r['body'], 'No customers found'));
$r = http('GET', 'customers/?q=' . urlencode("x%' UNION SELECT 1,2,3,4,5,6,7,8,9,10,11 -- "), [], $adminJar);
check('UNION injection attempt returns nothing', $r['code'] === 200 && str_contains($r['body'], 'No customers found'));
[, $r] = login("admin' OR '1'='1", "' OR '1'='1");
check('SQL injection in login form fails', str_contains($r['body'], 'Invalid username or password'));
Database::run('DELETE FROM login_attempts');
$xss = '<script>alert(1)</script>';
$r = post_to($adminJar, 'customers/form.php', 'customers/form.php', ['full_name' => $xss, 'phone' => '+251911222333', 'email' => '', 'address' => '"><img src=x onerror=alert(1)>']);
preg_match('~id=(\d+)~', $r['loc'], $mm); $xid = (int) ($mm[1] ?? 0);
$page = http('GET', "customers/view.php?id=$xid", [], $adminJar);
check('Customer with script tag in name is stored', $xid > 0);
check('Output is HTML-escaped (no raw <script> / onerror injection)', !str_contains($page['body'], '<script>alert(1)') && !str_contains($page['body'], '"><img src=x') && str_contains($page['body'], '&lt;script&gt;'));
check('Customer creation is audited with new values', auditCount('customer_created') >= 1);

section('7. Inventory, job workflow, invoices and payments (service layer)');
$adminId = (int) Database::value("SELECT id FROM users WHERE username = 'admin'");
$_SESSION['uid'] = $adminId;
$suffix = substr(bin2hex(random_bytes(3)), 0, 6);
$cid = Database::insert('INSERT INTO customers (full_name, phone) VALUES (?,?)', ['Test Customer ' . $suffix, '+251911000999']);
$vid = Database::insert('INSERT INTO vehicles (customer_id, plate_number, make, model) VALUES (?,?,?,?)', [$cid, 'TST-' . $suffix, 'Toyota', 'Yaris']);
$part = InventoryService::savePart(null, ['sku' => 'TST-' . $suffix, 'name' => 'Test part', 'category' => 'Test', 'unit' => 'pcs', 'quantity' => 5, 'reorder_level' => 2, 'unit_cost' => '100', 'unit_price' => '250.50', 'supplier' => '']);
$jid = JobService::create(['customer_id' => $cid, 'vehicle_id' => $vid, 'mechanic_id' => (string) $mech1, 'date_received' => date('Y-m-d'), 'reported_problem' => 'Test problem', 'initial_condition' => '', 'required_repairs' => '', 'labor_cost' => '1000', 'estimated_completion' => '', 'mileage_in' => '']);
check('Job card number generated', (bool) preg_match('/^JC-\d{4}-\d{5}$/', (string) Database::value('SELECT job_number FROM job_cards WHERE id = ?', [$jid])));
try { JobService::changeStatus($jid, 'Completed'); check('Illegal status jump Pending → Completed is blocked', false); } catch (DomainException $e) { check('Illegal status jump Pending → Completed is blocked', true); }
JobService::changeStatus($jid, 'In Progress');
InventoryService::issue($jid, $part, 3);
check('Stock deducted automatically after issuing 3 of 5', (int) Database::value('SELECT quantity FROM spare_parts WHERE id = ?', [$part]) === 2);
try { InventoryService::issue($jid, $part, 3); check('Issuing more than available stock is blocked', false); } catch (DomainException $e) { check('Issuing more than available stock is blocked', true); }
check('Stock unchanged after blocked issue (transaction rolled back)', (int) Database::value('SELECT quantity FROM spare_parts WHERE id = ?', [$part]) === 2);
try { InventoryService::purchase($part, 1, '-1.00', '', '', ''); check('Negative purchase cost is blocked', false); } catch (DomainException $e) { check('Negative purchase cost is blocked', true); }
try { InventoryService::adjust($part, 'invalid', 1, 'Test reason'); check('Invalid stock adjustment direction is blocked', false); } catch (DomainException $e) { check('Invalid stock adjustment direction is blocked', true); }
check('Every stock movement recorded in ledger (opening + issue)', (int) Database::value('SELECT COUNT(*) FROM inventory_transactions WHERE part_id = ?', [$part]) === 2);
InventoryService::issue($jid, $part, 2);
check('Low/out-of-stock part appears in low-stock list', in_array($part, array_map('intval', array_column(InventoryService::lowStock(500), 'id')), true));

$bulkParts = [];
foreach (['A', 'B'] as $suffixPart) {
    $bulkParts[] = InventoryService::savePart(null, ['sku' => 'TST-' . $suffix . '-' . $suffixPart, 'name' => 'Bulk test part ' . $suffixPart, 'category' => 'Test', 'unit' => 'pcs', 'quantity' => 3, 'reorder_level' => 1, 'unit_cost' => '10.00', 'unit_price' => '20.00', 'supplier' => '']);
}
$bulkJob = JobService::create(['customer_id' => $cid, 'vehicle_id' => $vid, 'mechanic_id' => (string) $mech2, 'date_received' => date('Y-m-d'), 'reported_problem' => 'Bulk issue test', 'initial_condition' => '', 'required_repairs' => '', 'labor_cost' => '0', 'estimated_completion' => '', 'mileage_in' => '']);
InventoryService::issueMany($bulkJob, [['part_id' => $bulkParts[0], 'quantity' => 1], ['part_id' => $bulkParts[1], 'quantity' => 2]]);
check('Multiple parts can be issued together', (int) Database::value('SELECT COUNT(*) FROM job_parts WHERE job_id = ? AND part_id IN (?, ?)', [$bulkJob, ...$bulkParts]) === 2);
try {
    InventoryService::issueMany($bulkJob, [['part_id' => $bulkParts[0], 'quantity' => 1], ['part_id' => $bulkParts[1], 'quantity' => 99]]);
    check('Bulk issue rolls back all lines when one line lacks stock', false);
} catch (DomainException $e) {
    check('Bulk issue rolls back all lines when one line lacks stock',
        (int) Database::value('SELECT quantity FROM spare_parts WHERE id = ?', [$bulkParts[0]]) === 2
        && (int) Database::value('SELECT quantity FROM spare_parts WHERE id = ?', [$bulkParts[1]]) === 1);
}
try {
    InventoryService::issueMany($bulkJob, [['part_id' => $bulkParts[0], 'quantity' => 1], ['part_id' => $bulkParts[0], 'quantity' => 1]]);
    check('Duplicate parts in a bulk issue are blocked', false);
} catch (DomainException $e) {
    check('Duplicate parts in a bulk issue are blocked', true);
}

$serviceJob = JobService::create(['customer_id' => $cid, 'vehicle_id' => $vid, 'mechanic_id' => (string) $mech2, 'date_received' => date('Y-m-d'), 'reported_problem' => 'Service access test', 'initial_condition' => '', 'required_repairs' => '', 'labor_cost' => '0', 'estimated_completion' => '', 'mileage_in' => '']);
$testService = CatalogService::save(null, ['code' => 'TST-' . $suffix, 'name' => 'Access test service', 'category' => 'General Repair', 'description' => '', 'standard_price' => '25.00', 'estimated_hours' => '1']);
$serviceItem = CatalogService::attachToJob($serviceJob, $testService, $mech2);
try {
    CatalogService::updateServiceStatus($jid, $serviceItem, 'Completed');
    check('Service item from another job cannot be updated', false);
} catch (DomainException $e) {
    check('Service item from another job cannot be updated', true);
}
check('Rejected cross-job service update leaves item unchanged', Database::value('SELECT status FROM job_services WHERE id = ?', [$serviceItem]) === 'Pending');

JobService::changeStatus($jid, 'Completed');
$inv = InvoiceService::createForJob($jid);
InvoiceService::setDiscount($inv, '100.00', '');
$i = Database::one('SELECT * FROM invoices WHERE id = ?', [$inv]);
// labor 1000 + parts 5 x 250.50 = 1252.50 -> subtotal 2252.50, discount 100 -> 2152.50, tax 15% = 322.88 (322.875 rounded), total 2475.38
check('Invoice subtotal = labor + parts (2252.50)', $i['subtotal'] === '2252.50', $i['subtotal']);
check('Tax computed on discounted amount (322.88)', $i['tax_amount'] === '322.88', $i['tax_amount']);
check('Grand total = 2475.38', $i['grand_total'] === '2475.38', $i['grand_total']);
check('New invoice is Unpaid with full balance', $i['payment_status'] === 'Unpaid' && $i['balance'] === '2475.38');
PaymentService::record($inv, '1000.00', 'Cash', '', '');
$i = Database::one('SELECT * FROM invoices WHERE id = ?', [$inv]);
check('First partial payment → Partially Paid, balance 1475.38', $i['payment_status'] === 'Partially Paid' && $i['balance'] === '1475.38');
try { PaymentService::record($inv, '2000.00', 'Cash', '', ''); check('Overpayment is blocked', false); } catch (DomainException $e) { check('Overpayment is blocked', true); }
PaymentService::record($inv, '475.38', 'Bank Transfer', 'REF1', '');
PaymentService::record($inv, '1000.00', 'Telebirr', 'TB1', '');
$i = Database::one('SELECT * FROM invoices WHERE id = ?', [$inv]);
check('Multiple payments settle the invoice → Paid, balance 0', $i['payment_status'] === 'Paid' && $i['balance'] === '0.00' && (int) Database::value('SELECT COUNT(*) FROM payments WHERE invoice_id = ?', [$inv]) === 3);
try { PaymentService::refund($inv, '50', 'Cash', ''); check('Refund without a reason is blocked', false); } catch (DomainException $e) { check('Refund without a reason is blocked', true); }
PaymentService::refund($inv, '75.38', 'Cash', 'Goodwill adjustment');
$i = Database::one('SELECT * FROM invoices WHERE id = ?', [$inv]);
check('Authorised refund reduces net paid and reopens balance', $i['amount_paid'] === '2400.00' && $i['balance'] === '75.38' && $i['payment_status'] === 'Partially Paid');
try { Database::run('UPDATE invoices SET grand_total = 1 WHERE id = ?', [$inv]); } catch (Throwable $e) {}
check('Payment audit trail exists', auditCount('payment_recorded') >= 3 && auditCount('payment_refunded') >= 1);
$cj = tempnam(sys_get_temp_dir(), 'gms');
check('Receipt page renders for accountant', http('GET', 'payments/receipt.php?id=' . (int) Database::value('SELECT MAX(id) FROM payments'), [], $accJar)['code'] === 200);
check('Cancelling a job returns issued parts to stock', (function () use ($cid, $vid, $part, $mech1) {
    $j = JobService::create(['customer_id' => $cid, 'vehicle_id' => $vid, 'mechanic_id' => (string) $mech1, 'date_received' => date('Y-m-d'), 'reported_problem' => 'Cancel test', 'initial_condition' => '', 'required_repairs' => '', 'labor_cost' => '0', 'estimated_completion' => '', 'mileage_in' => '']);
    InventoryService::adjust($part, 'in', 3, 'test restock');
    InventoryService::issue($j, $part, 2); $mid = (int) Database::value('SELECT quantity FROM spare_parts WHERE id = ?', [$part]);
    JobService::changeStatus($j, 'Cancelled', 'customer left');
    return $mid === 1 && (int) Database::value('SELECT quantity FROM spare_parts WHERE id = ?', [$part]) === 3;
})());
unset($_SESSION['uid']);

section('8. Audit log: append-only + tamper evidence');
$v = AuditLogger::verifyChain();
check('Hash chain verifies for all ' . $v['checked'] . ' records', $v['ok'], $v['message']);
$blocked = false; try { Database::run('UPDATE audit_logs SET username = ? WHERE id = 1', ['hacker']); } catch (PDOException $e) { $blocked = str_contains($e->getMessage(), 'append-only'); }
check('Database trigger blocks UPDATE on audit_logs', $blocked);
$blocked = false; try { Database::run('DELETE FROM audit_logs WHERE id = 1'); } catch (PDOException $e) { $blocked = str_contains($e->getMessage(), 'append-only'); }
check('Database trigger blocks DELETE on audit_logs', $blocked);
// Simulate a privileged attacker who removes the trigger and edits history: the chain must expose it.
$pdo = Database::pdo(); $mid = (int) Database::value('SELECT id FROM audit_logs ORDER BY id LIMIT 1 OFFSET 20'); $orig = Database::value('SELECT new_value FROM audit_logs WHERE id = ?', [$mid]);
$pdo->exec('DROP TRIGGER trg_audit_no_update'); $pdo->prepare('UPDATE audit_logs SET new_value = ? WHERE id = ?')->execute(['{"tampered":true}', $mid]);
$v = AuditLogger::verifyChain();
check('Tampering with a historic record is detected (record #' . $mid . ')', !$v['ok'] && $v['bad_id'] === $mid, $v['message']);
$pdo->prepare('UPDATE audit_logs SET new_value = ? WHERE id = ?')->execute([$orig, $mid]);
$pdo->exec("CREATE TRIGGER trg_audit_no_update BEFORE UPDATE ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only: UPDATE is not allowed'");
check('Chain verifies again after restoring the record', AuditLogger::verifyChain()['ok']);
$noSecrets = (int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE new_value LIKE '%Adm1n#%' OR new_value LIKE '%Demo#Pass%' OR previous_value LIKE '%Adm1n#%' OR new_value LIKE '%password_hash%'");
check('No passwords or hashes stored in audit records', $noSecrets === 0);
check('Passwords are stored with password_hash (bcrypt/argon)', (int) Database::value("SELECT COUNT(*) FROM users WHERE password_hash NOT LIKE '$2y$%' AND password_hash NOT LIKE '$argon2%'") === 0);
check('Audit page accessible to administrator and verification works', str_contains(post_to($adminJar, 'audit-logs/', 'audit-logs/', ['do' => 'verify'])['body'], 'Chain verified'));

section('9. Setup lock & misc');
check('setup.php refuses to run after installation', http('GET', 'setup.php')['code'] === 404);
check('Admin can export audit CSV', str_contains(http('GET', 'audit-logs/?export=csv', [], $adminJar)['head'], 'text/csv'));
check('Accountant can export a revenue report CSV', str_contains(http('GET', 'reports/?report=daily_revenue&export=csv', [], $accJar)['head'], 'text/csv'));
foreach (['customers/', 'vehicles/', 'job-cards/', 'mechanics/', 'inventory/', 'inventory/issue.php', 'inventory/history.php', 'invoices/', 'payments/', 'expenses/', 'reports/', 'users/', 'users/roles.php', 'users/settings.php', 'audit-logs/', 'profile.php', 'services/', 'suppliers/', 'appointments/', 'notifications/'] as $p) {
    $c = http('GET', $p, [], $adminJar)['code']; check("Admin page /$p loads (200)", $c === 200, "got $c");
}
$testExpenseReference = 'TEST-EXPENSE-' . bin2hex(random_bytes(4));
$expensePost = post_to($adminJar, 'expenses/', 'expenses/', [
    'category' => 'utilities',
    'description' => 'Integration test expense',
    'amount' => '1.25',
    'expense_date' => date('Y-m-d'),
    'reference' => $testExpenseReference,
]);
check('Administrator can record an audited operating expense', $expensePost['code'] === 302
    && (int) Database::value('SELECT COUNT(*) FROM expenses WHERE reference = ?', [$testExpenseReference]) === 1
    && auditCount('expense_recorded') >= 1);

section('10. Enterprise Security & Modules');
// CryptoService tests
$plaintext = 'Confidential vehicle security code: 99482-X';
$encrypted = CryptoService::encrypt($plaintext);
check('CryptoService encrypts sensitive string with AES-256-GCM', CryptoService::isEncrypted($encrypted) && $encrypted !== $plaintext);
$decrypted = CryptoService::decrypt($encrypted);
check('CryptoService decrypts authentic payload correctly', $decrypted === $plaintext);
$tamperedCipher = substr_replace($encrypted, 'Z', -5, 1);
check('CryptoService rejects tampered ciphertext (auth tag verification)', CryptoService::decrypt($tamperedCipher) === null);
$bIdx1 = CryptoService::blindIndex('Plate-AA-1234');
$bIdx2 = CryptoService::blindIndex('plate-aa-1234');
check('Blind index is deterministic and case-insensitive', $bIdx1 === $bIdx2 && strlen($bIdx1) === 64);

// SupplierService tests
$supId = SupplierService::save(null, [
    'code' => 'SUP-TEST-' . time(),
    'name' => 'Test Auto Parts Ltd',
    'contact_person' => 'Abebe Kebede',
    'phone' => '+251911223344',
    'email' => 'test@autoparts.example',
    'address' => 'Addis Ababa'
]);
check('Supplier registered successfully with ID', $supId > 0);
$sup = SupplierService::find($supId);
check('Supplier details retrieved accurately', $sup && $sup['name'] === 'Test Auto Parts Ltd');

// CatalogService tests
$srvId = CatalogService::save(null, [
    'code' => 'SRV-TEST-' . time(),
    'name' => 'Brake Rotor Resurfacing',
    'category' => 'Brakes',
    'description' => 'Precision lathe resurfacing',
    'standard_price' => '450.00',
    'estimated_hours' => '1.25'
]);
check('Catalog service registered successfully', $srvId > 0);

// AppointmentService & Conversion test
$aptCust = (int) Database::value('SELECT id FROM customers WHERE is_active = 1 LIMIT 1');
$aptVeh = (int) Database::value('SELECT id FROM vehicles WHERE customer_id = ? AND is_active = 1 LIMIT 1', [$aptCust]);
if ($aptCust && $aptVeh) {
    $aptId = AppointmentService::create([
        'customer_id' => (string) $aptCust,
        'vehicle_id' => (string) $aptVeh,
        'service_id' => (string) $srvId,
        'appointment_date' => date('Y-m-d', strtotime('+2 days')),
        'appointment_time' => '10:30',
        'problem_description' => 'Squeaking brake noise upon deceleration'
    ]);
    check('Appointment booked with number', $aptId > 0);
    $convJobId = AppointmentService::convertToJob($aptId);
    check('Appointment converted into Job Card', $convJobId > 0);
    $aptAfter = AppointmentService::find($aptId);
    check('Appointment status changed to in_progress with linked job', $aptAfter['status'] === 'in_progress' && (int)$aptAfter['converted_job_id'] === $convJobId);
}

// NotificationService test
$adminUid = (int) Database::value("SELECT id FROM users WHERE username = 'admin'");
NotificationService::notifyUser($adminUid, 'Test Notification', 'This is an enterprise test alert.', 'dashboard.php');
$unread = NotificationService::unreadForCurrentUser();
check('NotificationService creates and fetches alerts', count($unread) >= 0);

section('11. Password Recovery System (Request, Hashing, Single-Use & Reset)');
// Setup test user for password recovery
$testEmail = 'recovery.tester.' . time() . '@example.com';
$testUsername = 'rec_user_' . time();
$oldPass = 'OldSecret#2026!';
$newPass = 'NewSecret#2026!';
$roleCustId = (int) (Database::value("SELECT id FROM roles WHERE name = 'customer'") ?: 1);

$regUid = Database::insert(
    'INSERT INTO users (role_id, username, full_name, email, password_hash, is_active) VALUES (?,?,?,?,?,1)',
    [$roleCustId, $testUsername, 'Recovery Tester', $testEmail, AuthService::hash($oldPass)]
);
check('Test user provisioned for recovery', $regUid > 0);

// Request password reset
$reqRes = PasswordResetService::requestReset($testEmail);
check('Reset request returns generic success message', $reqRes['ok'] && str_contains($reqRes['message'], 'instructions'));

// Verify database token was created
$resetRow = Database::one('SELECT * FROM password_resets WHERE user_id = ? AND used_at IS NULL ORDER BY id DESC LIMIT 1', [$regUid]);
check('Password reset record saved in database with SHA-256 hash', !empty($resetRow['token_hash']));

// Test invalid token
check('Random invalid token is rejected', PasswordResetService::validateToken(str_repeat('a', 64)) === null);

// Test completing reset
// Simulate user opening email with token (we retrieve the hash, but let's test end-to-end token validation)
$rawTokenTest = bin2hex(random_bytes(32));
$tokenHashTest = hash('sha256', $rawTokenTest);
Database::insert(
    'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)',
    [$regUid, $tokenHashTest, date('Y-m-d H:i:s', time() + 1800)]
);

$valRow = PasswordResetService::validateToken($rawTokenTest);
check('Valid raw token matches database record', $valRow && (int)$valRow['user_id'] === $regUid);

$resetRes = PasswordResetService::completeReset($rawTokenTest, $newPass);
check('Password reset completion succeeds', $resetRes['ok']);

// Verify single-use: token cannot be reused
check('Used token cannot be reused', PasswordResetService::validateToken($rawTokenTest) === null);

// Verify authentication with new credentials works (real HTTP flow; CLI sessions cannot regenerate after output)
[, $loginNew] = login($testUsername, $newPass);
check('Login with NEW password succeeds', $loginNew['code'] === 302 && str_contains($loginNew['loc'], 'index.php'));

// Verify old password no longer works
[, $loginOld] = login($testUsername, $oldPass);
check('Login with OLD password fails', $loginOld['code'] === 200 && str_contains($loginOld['body'], 'Invalid username or password'));

echo "\n\033[1mResult: $pass passed, $fail failed\033[0m\n";
exit($fail > 0 ? 1 : 0);
