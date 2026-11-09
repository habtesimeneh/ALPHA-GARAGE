<?php
declare(strict_types=1);

/** Optional realistic sample data, created through the same services as the app (so stock, invoices and audit chain are genuine). */
final class DemoSeeder
{
    public static function run(string $password, int $adminId): void
    {
        $role = fn(string $n) => (int) Database::value('SELECT id FROM roles WHERE name = ?', [$n]);
        $mk = function (string $user, string $name, string $roleName) use ($password, $role): int {
            $id = Database::insert('INSERT INTO users (role_id, username, full_name, email, password_hash) VALUES (?,?,?,?,?)',
                [$role($roleName), $user, $name, $user . '@autopro-garage.example', AuthService::hash($password)]);
            AuditLogger::log('user_created', 'users', $id, null, ['username' => $user, 'role' => $roleName, 'demo' => true], 'success', ['user_id' => $id, 'username' => 'setup', 'role' => 'system']);
            return $id;
        };
        $mk('reception', 'Selam Tesfaye', 'receptionist'); $mk('stock', 'Dawit Bekele', 'inventory_manager'); $mk('accounts', 'Meron Alemu', 'accountant');
        $m1 = $mk('mechanic1', 'Abel Girma', 'mechanic'); $m2 = $mk('mechanic2', 'Yonas Haile', 'mechanic');
        Database::run('INSERT INTO mechanics (user_id, full_name, phone, specialty) VALUES (?,?,?,?), (?,?,?,?)', [$m1, 'Abel Girma', '+251911000111', 'Engine & transmission', $m2, 'Yonas Haile', '+251911000222', 'Electrical & AC']);

        $customers = [['Hana Mulugeta', '+251911234501', 'hana@example.com', 'Bole, Addis Ababa'], ['Tekle Gebre', '+251911234502', 'tekle@example.com', 'Piassa, Addis Ababa'], ['Rahel Assefa', '+251911234503', null, 'CMC, Addis Ababa'],
            ['Samuel Desta', '+251911234504', 'samuel@example.com', 'Kazanchis, Addis Ababa'], ['Birtukan Worku', '+251911234505', 'birtu@example.com', 'Megenagna, Addis Ababa'], ['Kebede Tadesse', '+251911234506', null, 'Sarbet, Addis Ababa'],
            ['Liya Negash', '+251911234507', 'liya@example.com', 'Ayat, Addis Ababa'], ['Mulu Transport PLC', '+251911234508', 'fleet@mulu.example', 'Kaliti, Addis Ababa']];
        $cid = [];
        foreach ($customers as $c) { $cid[] = Database::insert('INSERT INTO customers (full_name, phone, email, address, created_by) VALUES (?,?,?,?,?)', [...$c, $adminId]); }
        $vehicles = [[0, 'AA-10234', 'Toyota', 'Corolla', 2015, 'Silver', 'JTDBR32E720012345', 148200, 'Petrol'], [0, 'AA-20987', 'Suzuki', 'Dzire', 2019, 'White', null, 62000, 'Petrol'], [1, 'AA-33410', 'Toyota', 'Hilux', 2012, 'Blue', 'MR0FZ29G801234567', 276500, 'Diesel'],
            [2, 'ET-45521', 'Hyundai', 'i10', 2018, 'Red', null, 54300, 'Petrol'], [3, 'AA-51076', 'Nissan', 'X-Trail', 2016, 'Black', 'JN1TBNT32Z0012345', 121000, 'Petrol'], [4, 'AA-60877', 'Toyota', 'Vitz', 2010, 'Grey', null, 189000, 'Petrol'],
            [5, 'OR-71222', 'Isuzu', 'D-Max', 2014, 'White', null, 231000, 'Diesel'], [6, 'AA-80345', 'Honda', 'Fit', 2017, 'Blue', null, 88000, 'Hybrid'], [7, 'AA-90111', 'Mitsubishi', 'Canter', 2013, 'White', null, 345000, 'Diesel'], [7, 'AA-90112', 'Mitsubishi', 'Canter', 2015, 'White', null, 298000, 'Diesel']];
        $vid = [];
        foreach ($vehicles as $v) { $vid[] = Database::insert('INSERT INTO vehicles (customer_id, plate_number, make, model, year, color, vin, mileage, fuel_type) VALUES (?,?,?,?,?,?,?,?,?)', [$cid[$v[0]], ...array_slice($v, 1)]); }
        AuditLogger::log('demo_data_loaded', 'system', null, null, ['customers' => count($cid), 'vehicles' => count($vid)], 'success', ['user_id' => $adminId, 'username' => 'setup', 'role' => 'administrator']);

        $parts = [['OIL-5W30-4L', 'Engine oil 5W-30 (4 L)', 'Lubricants', 'can', 24, 6, 1450, 1950, 'Total Ethiopia'], ['FLT-OIL-TY', 'Oil filter (Toyota)', 'Filters', 'pcs', 30, 8, 180, 320, 'AutoParts ET'], ['FLT-AIR-TY', 'Air filter (Toyota)', 'Filters', 'pcs', 12, 5, 260, 450, 'AutoParts ET'],
            ['BRK-PAD-F', 'Front brake pads set', 'Brakes', 'set', 9, 4, 1100, 1700, 'Brembo Dealer'], ['BRK-DISC-F', 'Front brake disc', 'Brakes', 'pcs', 6, 4, 1600, 2400, 'Brembo Dealer'], ['SPK-NGK-4', 'Spark plug NGK (set of 4)', 'Ignition', 'set', 15, 5, 780, 1200, 'AutoParts ET'],
            ['BAT-60AH', 'Battery 60Ah', 'Electrical', 'pcs', 4, 3, 4800, 6500, 'Power House'], ['BLT-TIM-HY', 'Timing belt kit', 'Engine', 'kit', 3, 3, 3200, 4600, 'AutoParts ET'], ['CLT-KIT-TY', 'Clutch kit (Hilux)', 'Transmission', 'kit', 2, 2, 7800, 10500, 'Toyota Ethiopia'],
            ['SHK-ABS-R', 'Rear shock absorber', 'Suspension', 'pcs', 8, 4, 1900, 2800, 'AutoParts ET'], ['WPR-BLD-22', 'Wiper blade 22"', 'Body', 'pcs', 20, 6, 150, 280, 'AutoParts ET'], ['CLN-COOL-5L', 'Coolant 5 L', 'Lubricants', 'can', 10, 4, 520, 780, 'Total Ethiopia'],
            ['BLB-H7', 'Headlight bulb H7', 'Electrical', 'pcs', 2, 6, 120, 260, 'Power House'], ['FAN-BLT', 'Fan belt', 'Engine', 'pcs', 11, 4, 340, 560, 'AutoParts ET'], ['TIR-185-65', 'Tyre 185/65 R15', 'Tyres', 'pcs', 16, 8, 3400, 4300, 'Tyre Centre']];
        $pid = [];
        $_SESSION['uid'] = $adminId;   // let InventoryService attribute ledger rows to the admin during seeding
        foreach ($parts as $p) { $pid[$p[0]] = InventoryService::savePart(null, ['sku' => $p[0], 'name' => $p[1], 'category' => $p[2], 'unit' => $p[3], 'quantity' => $p[4], 'reorder_level' => $p[5], 'unit_cost' => (string) $p[6], 'unit_price' => (string) $p[7], 'supplier' => $p[8]]); }
        $mech = Database::all('SELECT id FROM mechanics ORDER BY id'); $mechId = [(int) $mech[0]['id'], (int) $mech[1]['id']];

        $jobs = [
            [0, 0, 0, 'Engine oil leak and noisy brakes', 'Scratch on rear bumper', ['In Progress'], [['OIL-5W30-4L', 1], ['FLT-OIL-TY', 1], ['BRK-PAD-F', 1]], '3.5', 'Gasket seeping, pads at 15%'],
            [2, 2, 1, 'Clutch slipping under load', 'Dent on left door', ['In Progress', 'Waiting for Parts'], [], '2', 'Clutch disc worn'],
            [3, 3, 1, 'AC not cooling, battery drains', 'Clean', ['Diagnosing'], [], '0', null],
            [4, 4, 0, 'Full service 120,000 km', 'Minor scratches', ['In Progress', 'Completed'], [['OIL-5W30-4L', 1], ['FLT-OIL-TY', 1], ['FLT-AIR-TY', 1], ['SPK-NGK-4', 1]], '4', 'Routine service'],
            [5, 5, 0, 'Timing belt replacement', 'Clean', ['In Progress', 'Completed', 'Delivered'], [['BLT-TIM-HY', 1], ['FAN-BLT', 1]], '6', 'Belt cracked'],
            [7, 7, 1, 'Headlights dim, check electrics', 'Clean', ['In Progress', 'Completed', 'Delivered'], [['BLB-H7', 2]], '1.5', 'Bulbs replaced'],
            [1, 1, null, 'Routine inspection', 'Clean', [], [], '0', null],
        ];
        foreach ($jobs as [, $vi, $mi, $problem, $cond, $path, $partLines, $hours, $diag]) {
            $jid = JobService::create(['customer_id' => $cid[$vehicles[$vi][0]], 'vehicle_id' => $vid[$vi], 'mechanic_id' => $mi === null ? '' : $mechId[$mi], 'date_received' => date('Y-m-d', strtotime('-' . random_int(2, 20) . ' days')),
                'reported_problem' => $problem, 'initial_condition' => $cond, 'required_repairs' => null, 'labor_cost' => '0', 'estimated_completion' => date('Y-m-d', strtotime('+3 days')), 'mileage_in' => '']);
            if ($diag) { $_SESSION['uid'] = $adminId; JobService::updateDiagnosis($jid, $diag, ''); }
            foreach ($path as $st) {
                if ($st === 'Completed') {
                    foreach ($partLines as [$sku, $qty]) { InventoryService::issue($jid, $pid[$sku], $qty); }
                    if ((float) $hours > 0) { JobService::addLabor($jid, $hours, 'Labor performed'); $hours = '0'; }
                }
                if ($st === 'Delivered') { $inv = InvoiceService::createForJob($jid); PaymentService::record($inv, (string) Database::value('SELECT balance FROM invoices WHERE id = ?', [$inv]), 'Cash', '', 'Paid in full'); }
                JobService::changeStatus($jid, $st, '');
            }
            if ($path && in_array('Completed', $path, true) && !in_array('Delivered', $path, true)) {   // completed, invoiced, partially paid
                $inv = InvoiceService::createForJob($jid);
                $bal = (int) to_cents(Database::value('SELECT balance FROM invoices WHERE id = ?', [$inv]));
                PaymentService::record($inv, from_cents(intdiv($bal, 2)), 'Telebirr', 'TB' . random_int(100000, 999999), 'Deposit');
            }
            if ($path === ['In Progress'] && $partLines) { foreach ($partLines as [$sku, $qty]) { InventoryService::issue($jid, $pid[$sku], $qty); } JobService::addLabor($jid, $hours, 'Diagnosis and repair'); }
        }
        unset($_SESSION['uid']);
        // a pending mechanic request for the inventory manager
        $j2 = (int) Database::value("SELECT id FROM job_cards WHERE status = 'Waiting for Parts' LIMIT 1");
        if ($j2) { Database::insert('INSERT INTO part_requests (job_id, part_id, quantity, note, requested_by) VALUES (?,?,?,?,?)', [$j2, $pid['CLT-KIT-TY'], 1, 'Needed for clutch replacement', $m2]); }

        // Demo upcoming appointments
        try {
            AppointmentService::create([
                'customer_id' => (string) $cid[0],
                'vehicle_id' => (string) $vid[0],
                'service_id' => '',
                'appointment_date' => date('Y-m-d', strtotime('+1 day')),
                'appointment_time' => '09:30',
                'problem_description' => 'Annual preventative maintenance checkup'
            ]);
            AppointmentService::create([
                'customer_id' => (string) $cid[1],
                'vehicle_id' => (string) $vid[1],
                'service_id' => '',
                'appointment_date' => date('Y-m-d', strtotime('+3 days')),
                'appointment_time' => '14:00',
                'problem_description' => 'Front suspension vibration inspection'
            ]);
        } catch (Throwable $e) { /* ignore in demo seed */ }
    }
}
