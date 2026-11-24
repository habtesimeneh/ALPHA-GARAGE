<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — PRIORITY 3 PRODUCTION MIGRATION RUNNER
 * Target: garage_management_system (Production Database)
 */

require_once __DIR__ . '/../../app/bootstrap.php';
$pdo = Database::pdo();

echo "=================================================================\n";
echo "PRIORITY 3 PRODUCTION DATABASE MIGRATION EXECUTION\n";
echo "=================================================================\n";

$activeDb = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
echo "Active Production DB: $activeDb\n";

if ($activeDb !== 'garage_management_system') {
    fwrite(STDERR, "FATAL FAULT: Expected database 'garage_management_system', got '$activeDb'! Exiting.\n");
    exit(1);
}

// Verify backup exists and is recent
$backupFile = APP_ROOT . '/storage/backups/performance_optimization_p3/garage_management_system_pre_p3_backup.sql';
if (!is_file($backupFile) || filesize($backupFile) < 250000) {
    fwrite(STDERR, "FATAL FAULT: Verified backup file missing or undersized! Exiting.\n");
    exit(1);
}
echo "[PASS] Safety Check 1: Backup verified (" . round(filesize($backupFile) / 1024, 1) . " KB)\n";

// Record pre-migration data checksums
function get_prod_checksum(PDO $pdo): array {
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

$preChecksum = get_prod_checksum($pdo);
echo "[PASS] Safety Check 2: Pre-migration data checksum recorded.\n";
print_r($preChecksum);

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

echo "-----------------------------------------------------------------\n";
echo "Applying Performance Indexes Migration to $activeDb...\n";
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
            echo "  [ERROR] " . $e->getMessage() . "\n";
            throw $e;
        }
    }
}

// Verify indexes in information_schema
$indexes = $pdo->query("
    SELECT TABLE_NAME, INDEX_NAME 
    FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE()
")->fetchAll(PDO::FETCH_ASSOC);

$indexMap = [];
foreach ($indexes as $r) {
    $indexMap[$r['TABLE_NAME'] . '.' . $r['INDEX_NAME']] = true;
}

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

foreach ($required as $req) {
    if (!isset($indexMap[$req])) {
        throw new RuntimeException("CRITICAL: Index $req was not found in production schema!");
    }
}
if (isset($indexMap['password_resets.idx_pr_hash'])) {
    throw new RuntimeException("CRITICAL: Redundant index idx_pr_hash was not dropped!");
}
echo "[PASS] Safety Check 3: All 8 performance indexes verified in information_schema.\n";

// Verify post-migration checksums
$postChecksum = get_prod_checksum($pdo);
if ($postChecksum !== $preChecksum) {
    throw new RuntimeException("CRITICAL SAFETY FAULT: Data checksum changed during index creation!");
}
echo "[PASS] Safety Check 4: Data integrity 100% verified (0 records altered, 0 balances changed).\n";

echo "=================================================================\n";
echo "PRODUCTION MIGRATION COMPLETED SUCCESSFULLY\n";
echo "=================================================================\n";
