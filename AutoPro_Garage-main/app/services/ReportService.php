<?php
declare(strict_types=1);

/** Report definitions. Each report returns ['columns' => [...], 'rows' => [[...]], 'numeric' => [col indexes]] and shares one code path for screen + CSV. */
final class ReportService
{
    public const REPORTS = [
        'daily_revenue'   => ['Daily revenue',        'reports.financial', true],
        'monthly_revenue' => ['Monthly revenue',      'reports.financial', true],
        'outstanding'     => ['Outstanding invoices', 'reports.financial', false],
        'customers'       => ['Customer report',      'reports.view',      false],
        'vehicle_history' => ['Vehicle repair history', 'reports.view',    true],
        'mechanics'       => ['Mechanic performance', 'reports.view',      true],
        'completed_jobs'  => ['Completed jobs',       'reports.view',      true],
        'inventory'       => ['Inventory report',     'reports.inventory', false],
        'parts_usage'     => ['Spare parts usage',    'reports.inventory', true],
        'services'        => ['Services performance', 'reports.view',      true],
        'suppliers'       => ['Suppliers & purchasing', 'reports.inventory', true],
        'appointments'    => ['Appointments report',  'reports.view',      true],
        'audit'           => ['Audit activity',       'audit.view',        true],
    ];

    public static function available(): array
    {
        return array_filter(self::REPORTS, fn($r) => can($r[1]));
    }

    public static function build(string $key, string $from, string $to, string $q): array
    {
        $like = like_escape($q);
        switch ($key) {
            case 'daily_revenue':
                return ['columns' => ['Date', 'Payments', 'Received', 'Refunded', 'Net revenue'], 'numeric' => [1, 2, 3, 4], 'rows' => array_map(fn($r) => [$r['d'], $r['n'], $r['rec'], $r['ref'], $r['net']], Database::all(
                    "SELECT DATE(paid_at) d, SUM(kind='payment') n, SUM(CASE WHEN kind='payment' THEN amount ELSE 0 END) rec, SUM(CASE WHEN kind='refund' THEN amount ELSE 0 END) ref,
                            SUM(CASE WHEN kind='payment' THEN amount ELSE -amount END) net FROM payments WHERE DATE(paid_at) BETWEEN ? AND ? GROUP BY DATE(paid_at) ORDER BY d DESC", [$from, $to]))];
            case 'monthly_revenue':
                return ['columns' => ['Month', 'Payments', 'Received', 'Refunded', 'Net revenue'], 'numeric' => [1, 2, 3, 4], 'rows' => array_map(fn($r) => [$r['m'], $r['n'], $r['rec'], $r['ref'], $r['net']], Database::all(
                    "SELECT DATE_FORMAT(paid_at,'%Y-%m') m, SUM(kind='payment') n, SUM(CASE WHEN kind='payment' THEN amount ELSE 0 END) rec, SUM(CASE WHEN kind='refund' THEN amount ELSE 0 END) ref,
                            SUM(CASE WHEN kind='payment' THEN amount ELSE -amount END) net FROM payments WHERE DATE(paid_at) BETWEEN ? AND ? GROUP BY m ORDER BY m DESC", [$from, $to]))];
            case 'outstanding':
                return ['columns' => ['Invoice', 'Customer', 'Phone', 'Issued', 'Due', 'Days overdue', 'Total', 'Paid', 'Balance'], 'numeric' => [5, 6, 7, 8], 'rows' => array_map(fn($r) => [$r['invoice_number'], $r['full_name'], $r['phone'], $r['issue_date'], $r['due_date'], max(0, (int) $r['od']), $r['grand_total'], $r['amount_paid'], $r['balance']], Database::all(
                    "SELECT i.invoice_number, c.full_name, c.phone, i.issue_date, i.due_date, DATEDIFF(CURDATE(), i.due_date) od, i.grand_total, i.amount_paid, i.balance
                     FROM invoices i JOIN customers c ON c.id = i.customer_id WHERE i.payment_status IN ('Unpaid','Partially Paid') AND (c.full_name LIKE ? OR i.invoice_number LIKE ?) ORDER BY od DESC", [$like, $like]))];
            case 'customers':
                return ['columns' => ['Customer', 'Phone', 'Email', 'Registered', 'Vehicles', 'Jobs', 'Billed', 'Paid', 'Outstanding'], 'numeric' => [4, 5, 6, 7, 8], 'rows' => array_map(fn($r) => array_values($r), Database::all(
                    "SELECT c.full_name, c.phone, c.email, DATE(c.registered_at), (SELECT COUNT(*) FROM vehicles v WHERE v.customer_id = c.id), (SELECT COUNT(*) FROM job_cards j WHERE j.customer_id = c.id),
                            COALESCE((SELECT SUM(grand_total) FROM invoices i WHERE i.customer_id = c.id),0), COALESCE((SELECT SUM(amount_paid) FROM invoices i WHERE i.customer_id = c.id),0), COALESCE((SELECT SUM(balance) FROM invoices i WHERE i.customer_id = c.id),0)
                     FROM customers c WHERE DATE(c.registered_at) BETWEEN ? AND ? AND (c.full_name LIKE ? OR c.phone LIKE ?) ORDER BY c.full_name", [$from, $to, $like, $like]))];
            case 'vehicle_history':
                return ['columns' => ['Plate', 'Vehicle', 'Owner', 'Job card', 'Received', 'Problem', 'Mechanic', 'Status', 'Labor'], 'numeric' => [8], 'rows' => array_map(fn($r) => array_values($r), Database::all(
                    "SELECT v.plate_number, CONCAT(v.make,' ',v.model), c.full_name, j.job_number, j.date_received, j.reported_problem, COALESCE(m.full_name,'Unassigned'), j.status, j.labor_cost
                     FROM job_cards j JOIN vehicles v ON v.id = j.vehicle_id JOIN customers c ON c.id = j.customer_id LEFT JOIN mechanics m ON m.id = j.mechanic_id
                     WHERE j.date_received BETWEEN ? AND ? AND (v.plate_number LIKE ? OR c.full_name LIKE ? OR v.vin LIKE ?) ORDER BY v.plate_number, j.date_received DESC", [$from, $to, $like, $like, $like]))];
            case 'mechanics':
                return ['columns' => ['Mechanic', 'Jobs received', 'Completed', 'Open', 'Avg days to complete', 'Labor value'], 'numeric' => [1, 2, 3, 4, 5], 'rows' => array_map(fn($r) => array_values($r), Database::all(
                    "SELECT m.full_name, COUNT(j.id), SUM(j.status IN ('Completed','Delivered')), SUM(j.status IN ('Pending','Diagnosing','Waiting for Parts','In Progress')),
                            ROUND(AVG(CASE WHEN j.actual_completion IS NOT NULL THEN DATEDIFF(j.actual_completion, j.date_received) END),1), COALESCE(SUM(j.labor_cost),0)
                     FROM mechanics m LEFT JOIN job_cards j ON j.mechanic_id = m.id AND j.date_received BETWEEN ? AND ? WHERE m.full_name LIKE ? GROUP BY m.id, m.full_name ORDER BY 3 DESC", [$from, $to, $like]))];
            case 'completed_jobs':
                return ['columns' => ['Job card', 'Customer', 'Plate', 'Mechanic', 'Received', 'Completed', 'Days', 'Status', 'Labor'], 'numeric' => [6, 8], 'rows' => array_map(fn($r) => array_values($r), Database::all(
                    "SELECT j.job_number, c.full_name, v.plate_number, COALESCE(m.full_name,'—'), j.date_received, j.actual_completion, DATEDIFF(j.actual_completion, j.date_received), j.status, j.labor_cost
                     FROM job_cards j JOIN customers c ON c.id = j.customer_id JOIN vehicles v ON v.id = j.vehicle_id LEFT JOIN mechanics m ON m.id = j.mechanic_id
                     WHERE j.status IN ('Completed','Delivered') AND j.actual_completion BETWEEN ? AND ? AND (j.job_number LIKE ? OR c.full_name LIKE ? OR v.plate_number LIKE ?) ORDER BY j.actual_completion DESC", [$from, $to, $like, $like, $like]))];
            case 'inventory':
                return ['columns' => ['SKU', 'Part', 'Category', 'In stock', 'Reorder level', 'Unit cost', 'Stock value', 'Status'], 'numeric' => [3, 4, 5, 6], 'rows' => array_map(fn($r) => array_values($r), Database::all(
                    "SELECT sku, name, COALESCE(category,''), quantity, reorder_level, unit_cost, ROUND(quantity*unit_cost,2), CASE WHEN quantity = 0 THEN 'Out of stock' WHEN quantity <= reorder_level THEN 'Low stock' ELSE 'OK' END
                     FROM spare_parts WHERE is_active = 1 AND (sku LIKE ? OR name LIKE ?) ORDER BY name", [$like, $like]))];
            case 'parts_usage':
                return ['columns' => ['SKU', 'Part', 'Qty issued', 'Qty returned', 'Net used', 'Sales value', 'Cost value'], 'numeric' => [2, 3, 4, 5, 6], 'rows' => array_map(fn($r) => array_values($r), Database::all(
                    "SELECT sp.sku, sp.name, SUM(jp.quantity), SUM(CASE WHEN jp.returned=1 THEN jp.quantity ELSE 0 END), SUM(CASE WHEN jp.returned=0 THEN jp.quantity ELSE 0 END),
                            SUM(CASE WHEN jp.returned=0 THEN jp.quantity*jp.unit_price ELSE 0 END), SUM(CASE WHEN jp.returned=0 THEN jp.quantity*sp.unit_cost ELSE 0 END)
                     FROM job_parts jp JOIN spare_parts sp ON sp.id = jp.part_id WHERE DATE(jp.issued_at) BETWEEN ? AND ? AND (sp.sku LIKE ? OR sp.name LIKE ?) GROUP BY sp.id, sp.sku, sp.name ORDER BY 3 DESC", [$from, $to, $like, $like]))];
            case 'services':
                return ['columns' => ['Service Code', 'Service Name', 'Category', 'Times Billed', 'Standard Price', 'Total Revenue'], 'numeric' => [3, 4, 5], 'rows' => array_map(fn($r) => array_values($r), Database::all(
                    "SELECT s.code, s.name, s.category, COUNT(js.id), s.standard_price, COALESCE(SUM(js.price),0)
                     FROM services s LEFT JOIN job_services js ON js.service_id = s.id AND js.created_at BETWEEN ? AND ?
                     WHERE (s.name LIKE ? OR s.code LIKE ? OR s.category LIKE ?)
                     GROUP BY s.id, s.code, s.name, s.category, s.standard_price ORDER BY 6 DESC", [$from . ' 00:00:00', $to . ' 23:59:59', $like, $like, $like]))];
            case 'suppliers':
                return ['columns' => ['Code', 'Supplier Name', 'Contact', 'Phone', 'Purchases Made', 'Units Received', 'Total Spend'], 'numeric' => [4, 5, 6], 'rows' => array_map(fn($r) => array_values($r), Database::all(
                    "SELECT s.code, s.name, COALESCE(s.contact_person,'—'), COALESCE(s.phone,'—'), COUNT(it.id), COALESCE(SUM(it.quantity),0), COALESCE(SUM(it.quantity * COALESCE(it.unit_cost,0)),0)
                     FROM suppliers s LEFT JOIN inventory_transactions it ON (it.supplier = s.name OR it.supplier = s.code) AND it.txn_type = 'purchase' AND it.created_at BETWEEN ? AND ?
                     WHERE (s.name LIKE ? OR s.code LIKE ?)
                     GROUP BY s.id, s.code, s.name, s.contact_person, s.phone ORDER BY 7 DESC", [$from . ' 00:00:00', $to . ' 23:59:59', $like, $like]))];
            case 'appointments':
                return ['columns' => ['Booking #', 'Date & Time', 'Customer', 'Vehicle', 'Service', 'Status', 'Converted Job'], 'numeric' => [], 'rows' => array_map(fn($r) => array_values($r), Database::all(
                    "SELECT a.appointment_number, CONCAT(a.appointment_date,' ',a.appointment_time), c.full_name, v.plate_number, COALESCE(s.name,'General Inspection'), a.status, COALESCE(j.job_number,'—')
                     FROM appointments a JOIN customers c ON c.id = a.customer_id JOIN vehicles v ON v.id = a.vehicle_id LEFT JOIN services s ON s.id = a.service_id LEFT JOIN job_cards j ON j.id = a.converted_job_id
                     WHERE a.appointment_date BETWEEN ? AND ? AND (a.appointment_number LIKE ? OR c.full_name LIKE ? OR v.plate_number LIKE ?) ORDER BY a.appointment_date DESC", [$from, $to, $like, $like, $like]))];
            case 'audit':
                return ['columns' => ['Time', 'User', 'Role', 'Action', 'Module', 'Record', 'Result', 'IP'], 'numeric' => [], 'rows' => array_map(fn($r) => array_values($r), Database::all(
                    "SELECT created_at, COALESCE(username,'—'), COALESCE(user_role,'—'), action_type, module_name, COALESCE(record_id,''), action_result, COALESCE(ip_address,'')
                     FROM audit_logs WHERE DATE(created_at) BETWEEN ? AND ? AND (username LIKE ? OR action_type LIKE ? OR module_name LIKE ?) ORDER BY id DESC LIMIT 5000", [$from, $to, $like, $like, $like]))];
        }
        throw new DomainException('Unknown report.');
    }
}
