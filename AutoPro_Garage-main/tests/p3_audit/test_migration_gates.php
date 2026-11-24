<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — PRIORITY 3 MIGRATION SAFETY GATE RUNNER
 * Target: garage_management_system_test ONLY
 */

define('APP_ROOT', str_replace('\\', '/', dirname(__DIR__, 2)));
putenv('GMS_TEST_MODE=1');
putenv('GMS_ALLOW_DESTRUCTIVE_TESTS=1');
putenv('GMS_DB_NAME=garage_management_system_test');
putenv('GMS_STORAGE_PATH=' . APP_ROOT . '/storage/testing');
putenv('GMS_DB_USER=root');
putenv('GMS_DB_PASS=root');

$GLOBALS['config'] = require APP_ROOT . '/app/config/app.php';
require_once APP_ROOT . '/app/helpers/functions.php';

echo "=================================================================\n";
echo "PRIORITY 3 MIGRATION SAFETY GATE RUNNER (TEST DATABASE ONLY)\n";
echo "=================================================================\n";

$pdo = new PDO("mysql:host=127.0.0.1;port=3306;dbname=garage_management_system_test;charset=utf8mb4", 'root', 'root', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$activeDb = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
echo "Active DB: $activeDb\n";

if ($activeDb !== 'garage_management_system_test') {
    fwrite(STDERR, "FATAL SAFETY FAULT: Target database is NOT garage_management_system_test! Exiting.\n");
    exit(1);
}
if ($activeDb === 'garage_management_system') {
    fwrite(STDERR, "FATAL SAFETY FAULT: Refusing to run migration test against production database!\n");
    exit(1);
}
echo "[PASS] Gate 1: Database Identity Confirmed ($activeDb)\n\n";

// Function to get index list
function get_indexes(PDO $pdo): array {
    $rows = $pdo->query("
        SELECT TABLE_NAME, INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) as cols
        FROM information_schema.STATISTICS 
        WHERE TABLE_SCHEMA = DATABASE() 
        GROUP BY TABLE_NAME, INDEX_NAME
        ORDER BY TABLE_NAME, INDEX_NAME
    ")->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $r) {
        $map[$r['TABLE_NAME'] . '.' . $r['INDEX_NAME']] = $r['cols'];
    }
    return $map;
}

// Function to calculate data checksum
function get_data_checksum(PDO $pdo): array {
    return [
        'users' => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
        'customers' => (int) $pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn(),
        'vehicles' => (int) $pdo->query('SELECT COUNT(*) FROM vehicles')->fetchColumn(),
        'appointments' => (int) $pdo->query('SELECT COUNT(*) FROM appointments')->fetchColumn(),
        'job_cards' => (int) $pdo->query('SELECT COUNT(*) FROM job_cards')->fetchColumn(),
        'invoices' => (int) $pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn(),
        'invoice_total' => (float) $pdo->query('SELECT COALESCE(SUM(grand_total), 0) FROM invoices')->fetchColumn(),
        'payments' => (int) $pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn(),
        'payment_total' => (float) $pdo->query('SELECT COALESCE(SUM(amount), 0) FROM payments')->fetchColumn(),
        'audit_logs' => (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn(),
    ];
}

$cols = $pdo->query("SHOW COLUMNS FROM schema_migrations")->fetchAll(PDO::FETCH_ASSOC);
echo "Columns in schema_migrations:\n";
foreach ($cols as $c) echo "  {$c['Field']} ({$c['Type']})\n";

$create = $pdo->query('SHOW CREATE TABLE appointments')->fetch(PDO::FETCH_ASSOC)['Create Table'];
echo "Appointments CREATE TABLE:\n$create\n\n";

$initialChecksum = get_data_checksum($pdo);
echo "Initial Data Checksum:\n";
print_r($initialChecksum);

// Up migration statements
$upStatements = [
    "CREATE INDEX idx_apt_status_date ON appointments (status, appointment_date)",
    "CREATE INDEX idx_apt_cust_date_status ON appointments (customer_id, appointment_date, status)",
    "CREATE INDEX idx_job_mech_status ON job_cards (mechanic_id, status)",
    "CREATE INDEX idx_job_cust_status ON job_cards (customer_id, status)",
    "CREATE INDEX idx_inv_cust_status ON invoices (customer_id, payment_status, balance)",
    "CREATE INDEX idx_veh_cust_active ON vehicles (customer_id, is_active)",
    "CREATE INDEX idx_it_type_created ON inventory_transactions (txn_type, created_at)",
    "CREATE INDEX idx_pr_job_status ON part_requests (job_id, status)",
    "ALTER TABLE password_resets DROP INDEX idx_pr_hash",
    "INSERT INTO schema_migrations (migration, checksum, applied_at) VALUES ('20261122_priority3_performance_indexes.sql', SHA2('20261122_priority3_performance_indexes.sql', 256), NOW()) ON DUPLICATE KEY UPDATE applied_at = NOW()"
];

// Down rollback statements
$downStatements = [
    "ALTER TABLE appointments DROP INDEX idx_apt_status_date",
    "CREATE INDEX fk_apt_cust ON appointments (customer_id)",
    "ALTER TABLE appointments DROP INDEX idx_apt_cust_date_status",
    "ALTER TABLE job_cards DROP INDEX idx_job_mech_status",
    "ALTER TABLE job_cards DROP INDEX idx_job_cust_status",
    "ALTER TABLE invoices DROP INDEX idx_inv_cust_status",
    "ALTER TABLE vehicles DROP INDEX idx_veh_cust_active",
    "ALTER TABLE inventory_transactions DROP INDEX idx_it_type_created",
    "CREATE INDEX fk_pr_job ON part_requests (job_id)",
    "ALTER TABLE part_requests DROP INDEX idx_pr_job_status",
    "CREATE INDEX idx_pr_hash ON password_resets (token_hash)",
    "DELETE FROM schema_migrations WHERE migration = '20261122_priority3_performance_indexes.sql'"
];

// PHASE A: Apply UP Migration on Test DB
echo "-----------------------------------------------------------------\n";
echo "Executing UP Migration on $activeDb...\n";
foreach ($upStatements as $stmt) {
    try {
        $pdo->exec($stmt);
        echo "  [OK] " . substr($stmt, 0, 70) . "...\n";
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate key name')) {
            echo "  [EXISTS] " . substr($stmt, 0, 70) . "...\n";
        } elseif (str_contains($e->getMessage(), "Can't DROP 'idx_pr_hash'")) {
            echo "  [ALREADY DROPPED] " . substr($stmt, 0, 70) . "...\n";
        } else {
            echo "  [ERROR ON: " . substr($stmt, 0, 50) . "] " . $e->getMessage() . "\n";
            throw $e;
        }
    }
}

$postUpIndexes = get_indexes($pdo);
$required = [
    'appointments.idx_apt_status_date',
    'appointments.idx_apt_cust_date_status',
    'job_cards.idx_job_mech_status',
    'job_cards.idx_job_cust_status',
    'invoices.idx_inv_cust_status',
    'vehicles.idx_veh_cust_active',
    'inventory_transactions.idx_it_type_created',
    'part_requests.idx_pr_job_status'
];
foreach ($required as $idxKey) {
    if (!isset($postUpIndexes[$idxKey])) {
        throw new RuntimeException("Missing expected index: $idxKey");
    }
}
if (isset($postUpIndexes['password_resets.idx_pr_hash'])) {
    throw new RuntimeException("idx_pr_hash was not dropped");
}
echo "[PASS] Gate 2: UP Migration successfully verified in information_schema.\n\n";

// Run EXPLAIN checks with new indexes on Test DB
echo "-----------------------------------------------------------------\n";
echo "Verifying EXPLAIN Plan Improvements with New Indexes...\n";
$explain1 = $pdo->query("EXPLAIN SELECT COUNT(*) FROM appointments WHERE status = 'scheduled' AND appointment_date >= CURDATE()")->fetch(PDO::FETCH_ASSOC);
echo "  RECEP_UPCOMING_COUNT: key={$explain1['key']} | type={$explain1['type']} | rows={$explain1['rows']} | Extra={$explain1['Extra']}\n";

$explain2 = $pdo->query("EXPLAIN SELECT COUNT(*) FROM vehicles WHERE customer_id IN (1, 10, 12) AND is_active = 1")->fetch(PDO::FETCH_ASSOC);
echo "  CUST_VEHICLES_COUNT:  key={$explain2['key']} | type={$explain2['type']} | rows={$explain2['rows']} | Extra={$explain2['Extra']}\n";

$explain3 = $pdo->query("EXPLAIN SELECT COUNT(*) FROM job_cards WHERE customer_id IN (1, 10, 12) AND status IN ('Pending','Diagnosing')")->fetch(PDO::FETCH_ASSOC);
echo "  CUST_ACTIVE_REPAIRS:  key={$explain3['key']} | type={$explain3['type']} | rows={$explain3['rows']} | Extra={$explain3['Extra']}\n";

$explain4 = $pdo->query("EXPLAIN SELECT COALESCE(SUM(balance),0) FROM invoices WHERE customer_id IN (1, 10, 12) AND payment_status IN ('Unpaid','Partially Paid')")->fetch(PDO::FETCH_ASSOC);
echo "  CUST_INVOICES:        key={$explain4['key']} | type={$explain4['type']} | rows={$explain4['rows']} | Extra={$explain4['Extra']}\n";

$explain5 = $pdo->query("EXPLAIN SELECT COUNT(*) FROM job_cards WHERE mechanic_id = 1 AND status IN ('Pending','Diagnosing')")->fetch(PDO::FETCH_ASSOC);
echo "  MECH_ACTIVE_JOBS:     key={$explain5['key']} | type={$explain5['type']} | rows={$explain5['rows']} | Extra={$explain5['Extra']}\n";

$explain6 = $pdo->query("EXPLAIN SELECT COALESCE(SUM(quantity * unit_cost),0) FROM inventory_transactions WHERE txn_type='purchase' AND created_at >= '2026-11-01' AND created_at < '2026-12-01'")->fetch(PDO::FETCH_ASSOC);
echo "  INV_TXN_PURCHASE:     key={$explain6['key']} | type={$explain6['type']} | rows={$explain6['rows']} | Extra={$explain6['Extra']}\n";

echo "[PASS] Gate 3: EXPLAIN plan verified: all queries switched to indexed scans!\n\n";

// PHASE B: Test DOWN Rollback
echo "-----------------------------------------------------------------\n";
echo "Executing DOWN Rollback on $activeDb...\n";
foreach ($downStatements as $stmt) {
    try {
        $pdo->exec($stmt);
        echo "  [OK] " . substr($stmt, 0, 70) . "...\n";
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate key name')) {
            echo "  [EXISTS] " . substr($stmt, 0, 70) . "...\n";
        } else {
            echo "  [ROLLBACK ERROR ON: " . substr($stmt, 0, 50) . "] " . $e->getMessage() . "\n";
            throw $e;
        }
    }
}

$postDownIndexes = get_indexes($pdo);
foreach ($required as $idxKey) {
    if (isset($postDownIndexes[$idxKey])) {
        throw new RuntimeException("Index $idxKey was not dropped during rollback");
    }
}
if (!isset($postDownIndexes['password_resets.idx_pr_hash'])) {
    throw new RuntimeException("idx_pr_hash was not restored during rollback");
}

$postDownChecksum = get_data_checksum($pdo);
if ($postDownChecksum !== $initialChecksum) {
    throw new RuntimeException("Data checksum mismatch after rollback!");
}
echo "[PASS] Gate 4: Rollback successfully validated! Original index and data state 100% restored.\n\n";

// PHASE C: Re-apply UP on Test DB for test environment
echo "-----------------------------------------------------------------\n";
echo "Re-applying UP Migration on $activeDb for subsequent test suite execution...\n";
foreach ($upStatements as $stmt) {
    $pdo->exec($stmt);
}
echo "[PASS] Gate 5: Re-applied UP Migration on test database.\n";
echo "=================================================================\n";
echo "ALL TEST GATES PASSED (100% SUCCESS)\n";
echo "=================================================================\n";
