<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
$pdo = Database::pdo();

echo "=================================================================\n";
echo "ALPHA GARAGE — QUERY PERFORMANCE & EXPLAIN BASELINE AUDIT\n";
echo "=================================================================\n\n";

$queries = [
    'ADMIN_REVENUE' => [
        'sql' => "SELECT COALESCE(SUM(CASE WHEN kind='payment' THEN amount ELSE 0 END),0) AS received, COALESCE(SUM(CASE WHEN kind='refund' THEN amount ELSE 0 END),0) AS refunded FROM payments WHERE paid_at >= '2026-11-01' AND paid_at < '2026-12-01'",
        'params' => []
    ],
    'ADMIN_UNPAID_INVOICES' => [
        'sql' => "SELECT COALESCE(SUM(balance),0) FROM invoices WHERE payment_status IN ('Unpaid','Partially Paid')",
        'params' => []
    ],
    'ADMIN_JOB_STATUS_COUNTS' => [
        'sql' => "SELECT status, COUNT(*) n FROM job_cards WHERE 1=1 AND date_received >= '2026-11-01' AND date_received < '2026-12-01' GROUP BY status",
        'params' => []
    ],
    'ADMIN_INV_PURCHASES' => [
        'sql' => "SELECT COALESCE(SUM(quantity * unit_cost),0) FROM inventory_transactions WHERE txn_type='purchase' AND COALESCE(reference,'') <> 'Opening stock' AND created_at >= '2026-11-01' AND created_at < '2026-12-01'",
        'params' => []
    ],
    'ADMIN_TOP_SERVICES' => [
        'sql' => "SELECT COALESCE(NULLIF(s.name,''), NULLIF(js.service_name,''), 'Service') AS label, COUNT(*) AS cnt, COALESCE(SUM(CASE WHEN j.status IN ('Completed','Delivered') THEN COALESCE(js.price,0) ELSE 0 END),0) AS revenue FROM job_services js JOIN job_cards j ON j.id = js.job_id LEFT JOIN services s ON s.id = js.service_id WHERE j.date_received >= '2026-11-01' AND j.date_received < '2026-12-01' GROUP BY label ORDER BY cnt DESC, revenue DESC LIMIT 8",
        'params' => []
    ],
    'RECEP_TODAY_APPTS' => [
        'sql' => "SELECT a.*, c.full_name AS customer_name, c.phone AS customer_phone, v.plate_number, v.make, v.model, s.name AS service_name FROM appointments a JOIN customers c ON c.id = a.customer_id JOIN vehicles v ON v.id = a.vehicle_id LEFT JOIN services s ON s.id = a.service_id WHERE a.appointment_date = CURDATE() ORDER BY a.appointment_time ASC LIMIT 15",
        'params' => []
    ],
    'RECEP_UPCOMING_COUNT' => [
        'sql' => "SELECT COUNT(*) FROM appointments WHERE status = 'scheduled' AND appointment_date >= CURDATE()",
        'params' => []
    ],
    'RECEP_RECENT_CUSTOMERS' => [
        'sql' => "SELECT c.id, c.full_name, c.phone, c.email, c.registered_at, (SELECT COUNT(*) FROM vehicles v WHERE v.customer_id = c.id AND v.is_active = 1) AS vehicle_count FROM customers c WHERE c.is_active = 1 ORDER BY c.id DESC LIMIT 6",
        'params' => []
    ],
    'MECH_ACTIVE_JOBS' => [
        'sql' => "SELECT j.id, j.job_number, j.date_received, j.estimated_completion, j.status, j.priority, j.reported_problem, j.diagnosis, j.labor_cost, v.plate_number, v.make, v.model, v.year, v.mileage, c.full_name AS customer_name FROM job_cards j JOIN vehicles v ON v.id = j.vehicle_id JOIN customers c ON c.id = j.customer_id WHERE j.mechanic_id = 1 AND j.status IN ('Pending','Diagnosing','Waiting for Parts','In Progress') ORDER BY CASE j.priority WHEN 'Urgent' THEN 1 WHEN 'High' THEN 2 WHEN 'Normal' THEN 3 ELSE 4 END, j.date_received ASC",
        'params' => []
    ],
    'MECH_COMPLETED_JOBS' => [
        'sql' => "SELECT j.id, j.job_number, j.date_received, j.actual_completion, j.status, j.priority, j.reported_problem, v.plate_number, v.make, v.model FROM job_cards j JOIN vehicles v ON v.id = j.vehicle_id WHERE j.mechanic_id = 1 AND j.status IN ('Completed','Delivered') ORDER BY j.actual_completion DESC, j.id DESC LIMIT 5",
        'params' => []
    ],
    'INV_SPARE_PARTS_METRICS' => [
        'sql' => "SELECT COUNT(*) FROM spare_parts WHERE is_active = 1",
        'params' => []
    ],
    'INV_JOBS_NEEDING_PARTS' => [
        'sql' => "SELECT j.id, j.job_number, j.status, j.priority, j.reported_problem, c.full_name AS customer_name, v.plate_number, v.make, v.model, (SELECT COUNT(*) FROM part_requests pr WHERE pr.job_id = j.id AND pr.status = 'pending') AS pending_requests FROM job_cards j JOIN customers c ON c.id = j.customer_id JOIN vehicles v ON v.id = j.vehicle_id WHERE j.status IN ('Waiting for Parts', 'In Progress', 'Diagnosing') ORDER BY CASE j.status WHEN 'Waiting for Parts' THEN 1 ELSE 2 END, j.id DESC LIMIT 6",
        'params' => []
    ],
    'ACCT_TODAY_PAYMENTS_UNSARGABLE' => [
        'sql' => "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE kind = 'payment' AND DATE(paid_at) = CURDATE()",
        'params' => []
    ],
    'ACCT_AGING_RECEIVABLES' => [
        'sql' => "SELECT COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), issue_date) <= 30 THEN balance ELSE 0 END), 0) AS age_30, COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), issue_date) BETWEEN 31 AND 60 THEN balance ELSE 0 END), 0) AS age_60, COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), issue_date) BETWEEN 61 AND 90 THEN balance ELSE 0 END), 0) AS age_90, COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), issue_date) > 90 THEN balance ELSE 0 END), 0) AS age_over_90 FROM invoices WHERE payment_status IN ('Unpaid', 'Partially Paid')",
        'params' => []
    ],
    'CUST_VEHICLES_COUNT' => [
        'sql' => "SELECT COUNT(*) FROM vehicles WHERE customer_id IN (1, 10, 12) AND is_active = 1",
        'params' => []
    ],
    'CUST_UPCOMING_APPTS' => [
        'sql' => "SELECT COUNT(*) FROM appointments WHERE customer_id IN (1, 10, 12) AND appointment_date >= CURDATE() AND status NOT IN ('completed','cancelled')",
        'params' => []
    ],
    'CUST_ACTIVE_REPAIRS' => [
        'sql' => "SELECT COUNT(*) FROM job_cards WHERE customer_id IN (1, 10, 12) AND status IN ('Pending','Diagnosing','Waiting for Parts','In Progress')",
        'params' => []
    ],
    'CUST_OUTSTANDING_INVOICES' => [
        'sql' => "SELECT COALESCE(SUM(balance), 0) FROM invoices WHERE customer_id IN (1, 10, 12) AND payment_status IN ('Unpaid','Partially Paid')",
        'params' => []
    ],
    'AUDIT_DATE_FILTER_UNSARGABLE' => [
        'sql' => "SELECT COUNT(*) FROM audit_logs WHERE DATE(created_at) >= '2026-11-01' AND DATE(created_at) <= '2026-11-22'",
        'params' => []
    ]
];

foreach ($queries as $label => $q) {
    echo "=================================================================\n";
    echo "QUERY: $label\n";
    echo "SQL:   {$q['sql']}\n";
    
    // Benchmark 10 iterations
    $times = [];
    for ($i = 0; $i < 10; $i++) {
        $t0 = microtime(true);
        $stmt = $pdo->prepare($q['sql']);
        $stmt->execute($q['params']);
        $res = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $times[] = (microtime(true) - $t0) * 1000;
    }
    $avgTime = array_sum($times) / count($times);
    $minTime = min($times);
    $maxTime = max($times);
    
    echo sprintf("EXECUTION TIME: Avg: %.3f ms | Min: %.3f ms | Max: %.3f ms\n", $avgTime, $minTime, $maxTime);
    
    // EXPLAIN plan
    $explainStmt = $pdo->prepare("EXPLAIN " . $q['sql']);
    $explainStmt->execute($q['params']);
    $plan = $explainStmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo sprintf("  %-5s %-15s %-15s %-10s %-25s %-20s %-6s %s\n", 'id', 'select_type', 'table', 'type', 'possible_keys', 'key', 'rows', 'Extra');
    echo "  " . str_repeat('-', 110) . "\n";
    foreach ($plan as $p) {
        echo sprintf(
            "  %-5s %-15s %-15s %-10s %-25s %-20s %-6s %s\n",
            $p['id'] ?? '',
            $p['select_type'] ?? '',
            $p['table'] ?? '',
            $p['type'] ?? '',
            substr($p['possible_keys'] ?? 'NULL', 0, 24),
            substr($p['key'] ?? 'NULL', 0, 19),
            $p['rows'] ?? '',
            $p['Extra'] ?? ''
        );
    }
    echo "\n";
}
