<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
$pdo = Database::pdo();

$queries = [
    'RECEP_UPCOMING_COUNT' => "EXPLAIN SELECT COUNT(*) FROM appointments WHERE status = 'scheduled' AND appointment_date >= CURDATE()",
    'MECH_ACTIVE_COUNT' => "EXPLAIN SELECT COUNT(*) FROM job_cards WHERE mechanic_id = 1 AND status IN ('Pending','Diagnosing','Waiting for Parts','In Progress')",
    'MECH_QUEUE' => "EXPLAIN SELECT j.id, j.job_number, j.date_received, j.estimated_completion, j.status, j.priority FROM job_cards j WHERE j.mechanic_id = 1 AND j.status IN ('Pending','Diagnosing','Waiting for Parts','In Progress')",
    'INV_TXN_PURCHASE' => "EXPLAIN SELECT COALESCE(SUM(quantity * unit_cost),0) FROM inventory_transactions WHERE txn_type='purchase' AND created_at >= '2026-11-01' AND created_at < '2026-12-01'",
    'INV_SPARE_PARTS_LOW' => "EXPLAIN SELECT id, sku, name, quantity, reorder_level FROM spare_parts WHERE is_active = 1 AND quantity <= reorder_level",
    'AUDIT_SARGABLE' => "EXPLAIN SELECT COUNT(*) FROM audit_logs WHERE created_at >= '2026-11-01 00:00:00' AND created_at <= '2026-11-22 23:59:59'",
    'AUDIT_UNSARGABLE' => "EXPLAIN SELECT COUNT(*) FROM audit_logs WHERE DATE(created_at) >= '2026-11-01' AND DATE(created_at) <= '2026-11-22'"
];

foreach ($queries as $label => $sql) {
    echo "=================================================================\n";
    echo "$label:\n$sql\n";
    $stmt = $pdo->query($sql);
    $plan = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($plan as $p) {
        echo "  table: {$p['table']} | type: {$p['type']} | possible_keys: {$p['possible_keys']} | key: {$p['key']} | rows: {$p['rows']} | Extra: {$p['Extra']}\n";
    }
}
