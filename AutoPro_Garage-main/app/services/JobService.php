<?php
declare(strict_types=1);

final class JobService
{
    public const STATUSES = ['Pending', 'Diagnosing', 'Waiting for Parts', 'In Progress', 'Completed', 'Delivered', 'Cancelled'];
    public const TRANSITIONS = [
        'Pending'           => ['Diagnosing', 'In Progress', 'Cancelled'],
        'Diagnosing'        => ['Waiting for Parts', 'In Progress', 'Cancelled'],
        'Waiting for Parts' => ['Diagnosing', 'In Progress', 'Cancelled'],
        'In Progress'       => ['Waiting for Parts', 'Completed', 'Cancelled'],
        'Completed'         => ['Delivered', 'In Progress'],
        'Delivered'         => [],
        'Cancelled'         => [],
    ];
    public const OPEN = ['Pending', 'Diagnosing', 'Waiting for Parts', 'In Progress'];

    public static function find(int $id): ?array
    {
        return Database::one(
            'SELECT j.*, c.full_name AS customer_name, c.phone AS customer_phone, c.email AS customer_email,
                    v.plate_number, v.make, v.model, v.year AS vehicle_year, v.color, v.vin, v.mileage, v.fuel_type,
                    m.full_name AS mechanic_name, m.user_id AS mechanic_user_id, u.full_name AS created_by_name,
                    (SELECT id FROM invoices WHERE job_card_id = j.id) AS invoice_id
             FROM job_cards j
             JOIN customers c ON c.id = j.customer_id
             JOIN vehicles v ON v.id = j.vehicle_id
             LEFT JOIN mechanics m ON m.id = j.mechanic_id
             LEFT JOIN users u ON u.id = j.created_by
             WHERE j.id = ?', [$id]);
    }

    /** Object-level authorisation (IDOR protection): mechanics only reach their own jobs. */
    public static function canAccess(array $job, array $user): bool
    {
        if (in_array('jobs.view_all', $user['permissions'], true)) { return true; }
        return in_array('jobs.view_assigned', $user['permissions'], true)
            && $user['mechanic_id'] !== null && (int) $job['mechanic_id'] === (int) $user['mechanic_id'];
    }

    public static function canChangeStatus(array $job, array $user, string $target): bool
    {
        if (!self::canAccess($job, $user)) { return false; }
        $perms = $user['permissions'];
        if (in_array('jobs.progress', $perms, true)) { return true; }
        return in_array('jobs.edit', $perms, true) && in_array($target, ['Delivered', 'Cancelled'], true);
    }

    public static function create(array $d): int
    {
        return (int) Database::transaction(function () use ($d) {
            $veh = Database::one('SELECT id, customer_id, is_active, plate_number, make, model FROM vehicles WHERE id = ? FOR UPDATE', [(int) $d['vehicle_id']]);
            $cust = Database::one('SELECT id, is_active, full_name, phone FROM customers WHERE id = ?', [(int) $d['customer_id']]);
            if (!$veh || !$cust) { throw new DomainException('Customer or vehicle not found.'); }
            if ((int) $veh['customer_id'] !== (int) $cust['id']) { throw new DomainException('The selected vehicle does not belong to the selected customer.'); }
            if (!$veh['is_active'] || !$cust['is_active']) { throw new DomainException('Inactive customers or vehicles cannot receive new job cards.'); }
            $mech = !empty($d['mechanic_id']) ? self::activeMechanic((int) $d['mechanic_id']) : null;

            $serviceIds = self::normalizeServiceIds($d['service_ids'] ?? []);
            $services = self::activeServicesByIds($serviceIds);
            $baseLaborCents = to_cents($d['labor_cost'] ?: '0');
            $serviceCents = 0;
            foreach ($services as $s) { $serviceCents += to_cents($s['standard_price']); }
            $totalLaborCents = $baseLaborCents + $serviceCents;

            $priority = self::normalizePriority($d['priority'] ?? null);
            $hasPriority = self::hasPriorityColumn();

            if ($hasPriority) {
                $id = Database::insert(
                    'INSERT INTO job_cards (job_number, customer_id, vehicle_id, mechanic_id, date_received, reported_problem, initial_condition,
                         required_repairs, labor_cost, estimated_completion, mileage_in, status, priority, created_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [Numbering::placeholder(), $cust['id'], $veh['id'], $mech['id'] ?? null, $d['date_received'], $d['reported_problem'],
                     $d['initial_condition'] ?: null, $d['required_repairs'] ?: null, from_cents($totalLaborCents),
                     $d['estimated_completion'] ?: null, $d['mileage_in'] !== '' ? (int) $d['mileage_in'] : null, 'Pending', $priority, current_user()['id'] ?? null]);
            } else {
                $id = Database::insert(
                    'INSERT INTO job_cards (job_number, customer_id, vehicle_id, mechanic_id, date_received, reported_problem, initial_condition,
                         required_repairs, labor_cost, estimated_completion, mileage_in, created_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                    [Numbering::placeholder(), $cust['id'], $veh['id'], $mech['id'] ?? null, $d['date_received'], $d['reported_problem'],
                     $d['initial_condition'] ?: null, $d['required_repairs'] ?: null, from_cents($totalLaborCents),
                     $d['estimated_completion'] ?: null, $d['mileage_in'] !== '' ? (int) $d['mileage_in'] : null, current_user()['id'] ?? null]);
            }
            $number = Numbering::make('JC', $id);
            $claimTicket = 'TKT-' . date('Ymd') . '-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
            Database::run('UPDATE job_cards SET job_number = ?, claim_ticket_number = ?, workflow_stage = "assigned" WHERE id = ?', [$number, $claimTicket, $id]);
            if ($d['mileage_in'] !== '') {
                Database::run('UPDATE vehicles SET mileage = GREATEST(mileage, ?) WHERE id = ?', [(int) $d['mileage_in'], $veh['id']]);
            }

            foreach ($services as $s) {
                Database::run(
                    'INSERT INTO job_services (job_id, service_id, service_name, price, status, assigned_mechanic_id, notes)
                     VALUES (?, ?, ?, ?, "Pending", ?, NULL)',
                    [$id, (int) $s['id'], $s['name'], $s['standard_price'], $mech['id'] ?? null]
                );
            }

            self::addUpdate($id, 'status', 'Job card created', null, 'Pending');
            $audit = ['job_number' => $number, 'customer_id' => $cust['id'], 'vehicle_id' => $veh['id'],
                'mechanic_id' => $mech['id'] ?? null, 'labor_cost' => from_cents($totalLaborCents), 'service_ids' => array_values($serviceIds), 'claim_ticket' => $claimTicket];
            if ($hasPriority) { $audit['priority'] = $priority; }
            AuditLogger::log('job_created', 'job_cards', $id, null, $audit);
            if ($mech) {
                AuditLogger::log('mechanic_assigned', 'job_cards', $id, ['mechanic_id' => null], ['mechanic_id' => $mech['id'], 'mechanic' => $mech['full_name']]);
                $fullJob = self::find($id);
                if ($fullJob) {
                    SmsService::notifyMechanicAssigned($fullJob, $mech, $claimTicket);
                }
            }
            return $id;
        });
    }

    /** @param mixed $raw @return array<int,int> unique positive IDs preserving order */
    private static function normalizeServiceIds($raw): array
    {
        if (!is_array($raw)) { return []; }
        $out = [];
        foreach ($raw as $v) {
            $id = is_numeric($v) ? (int) $v : 0;
            if ($id > 0 && !in_array($id, $out, true)) { $out[] = $id; }
        }
        if (count($out) > 30) { throw new DomainException('Too many services selected.'); }
        return $out;
    }

    /** @param array<int,int> $ids @return array<int,array<string,mixed>> */
    private static function activeServicesByIds(array $ids): array
    {
        if ($ids === []) { return []; }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::all("SELECT id, name, standard_price, is_active FROM services WHERE id IN ($ph)", $ids);
        $by = [];
        foreach ($rows as $r) { $by[(int) $r['id']] = $r; }
        $out = [];
        foreach ($ids as $id) {
            $r = $by[$id] ?? null;
            if (!$r) { throw new DomainException('One or more selected services are not available.'); }
            if (!(int) $r['is_active']) { throw new DomainException('The service "' . $r['name'] . '" is inactive.'); }
            $out[] = $r;
        }
        return $out;
    }

    private static function normalizePriority($v): string
    {
        $allowed = ['Low', 'Normal', 'High', 'Urgent'];
        $v = is_string($v) ? trim($v) : 'Normal';
        return in_array($v, $allowed, true) ? $v : 'Normal';
    }

    private static function hasPriorityColumn(): bool
    {
        static $has = null;
        if ($has !== null) { return $has; }
        try {
            $row = Database::one("SHOW COLUMNS FROM job_cards LIKE 'priority'");
            $has = $row !== null;
        } catch (Throwable $e) {
            $has = false;
        }
        return $has;
    }

    public static function update(int $id, array $d): void
    {
        Database::transaction(function () use ($id, $d) {
            $old = self::lock($id);
            if (!in_array($old['status'], self::OPEN, true) && $old['status'] !== 'Completed') {
                throw new DomainException('A ' . $old['status'] . ' job card can no longer be edited.');
            }
            $fields = ['reported_problem' => $d['reported_problem'], 'initial_condition' => $d['initial_condition'] ?: null,
                       'required_repairs' => $d['required_repairs'] ?: null, 'estimated_completion' => $d['estimated_completion'] ?: null,
                       'labor_cost' => from_cents(to_cents($d['labor_cost'] ?: '0')),
                       'mileage_in' => $d['mileage_in'] !== '' ? (int) $d['mileage_in'] : null];
            $sets = implode(', ', array_map(function ($k) { return "$k = ?"; }, array_keys($fields)));
            Database::run("UPDATE job_cards SET $sets WHERE id = ?", array_merge(array_values($fields), [$id]));
            $before = array_intersect_key($old, $fields);
            AuditLogger::log('job_updated', 'job_cards', $id, $before, $fields);
        });
    }

    /** Diagnosis + repair plan (mechanic). */
    public static function updateDiagnosis(int $id, string $diagnosis, string $repairs): void
    {
        Database::transaction(function () use ($id, $diagnosis, $repairs) {
            $old = self::lock($id);
            self::assertOpen($old);
            Database::run('UPDATE job_cards SET diagnosis = ?, required_repairs = ? WHERE id = ?', [$diagnosis ?: null, $repairs ?: null, $id]);
            self::addUpdate($id, 'note', 'Diagnosis / repair plan updated');
            AuditLogger::log('job_diagnosis_updated', 'job_cards', $id,
                ['diagnosis' => $old['diagnosis'], 'required_repairs' => $old['required_repairs']], ['diagnosis' => $diagnosis, 'required_repairs' => $repairs]);
        });
    }

    public static function assign(int $id, ?int $mechanicId): void
    {
        Database::transaction(function () use ($id, $mechanicId) {
            $old = self::lock($id);
            self::assertOpen($old);
            $mech = $mechanicId ? self::activeMechanic($mechanicId) : null;
            $claimTicket = $old['claim_ticket_number'] ?: ('TKT-' . date('Ymd') . '-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT));
            Database::run('UPDATE job_cards SET mechanic_id = ?, claim_ticket_number = ?, workflow_stage = "assigned" WHERE id = ?', [$mech['id'] ?? null, $claimTicket, $id]);
            self::addUpdate($id, 'assignment', $mech ? 'Assigned to ' . $mech['full_name'] : 'Mechanic unassigned');
            AuditLogger::log('mechanic_assigned', 'job_cards', $id, ['mechanic_id' => $old['mechanic_id']], ['mechanic_id' => $mech['id'] ?? null, 'mechanic' => $mech['full_name'] ?? null]);
            foreach (array_unique(array_filter([(int) $old['mechanic_id'], (int) ($mech['id'] ?? 0)])) as $mid) {
                self::syncMechanicAvailability($mid);
            }
            if ($mech) {
                $fullJob = self::find($id);
                if ($fullJob) {
                    SmsService::notifyMechanicAssigned($fullJob, $mech, $claimTicket);
                }
            }
        });
    }

    public static function changeStatus(int $id, string $new, string $note = ''): void
    {
        Database::transaction(function () use ($id, $new, $note) {
            $job = self::lock($id);
            $old = $job['status'];
            if (!in_array($new, self::TRANSITIONS[$old] ?? [], true)) {
                throw new DomainException("A job cannot move from \"$old\" to \"$new\".");
            }
            if (in_array($new, ['Diagnosing', 'In Progress', 'Waiting for Parts', 'Completed'], true) && !$job['mechanic_id']) {
                throw new DomainException('Assign a mechanic before starting work on this job.');
            }
            $invoice = Database::one('SELECT id, payment_status FROM invoices WHERE job_card_id = ? FOR UPDATE', [$id]);
            if ($new === 'Cancelled' && $invoice) {
                throw new DomainException('This job already has an invoice and can no longer be cancelled.');
            }
            if ($new === 'Delivered' && !$invoice) {
                throw new DomainException('Generate the invoice before delivering the vehicle.');
            }
            $completion = $job['actual_completion'];
            if ($new === 'Completed') { $completion = date('Y-m-d'); }
            if ($old === 'Completed' && $new === 'In Progress') {
                if ($invoice) { throw new DomainException('This job already has an invoice and cannot be re-opened.'); }
                $completion = null;
            }
            if ($new === 'Delivered' && !$completion) { $completion = date('Y-m-d'); }

            Database::run('UPDATE job_cards SET status = ?, actual_completion = ? WHERE id = ?', [$new, $completion, $id]);
            $returned = $new === 'Cancelled' ? InventoryService::returnJobParts($id) : 0;
            self::addUpdate($id, 'status', $note ?: null, $old, $new);
            AuditLogger::log('job_status_changed', 'job_cards', $id, ['status' => $old], ['status' => $new, 'note' => $note, 'parts_returned_lines' => $returned]);
            if ($job['mechanic_id']) { self::syncMechanicAvailability((int) $job['mechanic_id']); }
        });
    }

    public static function addNote(int $id, string $note): void
    {
        Database::transaction(function () use ($id, $note) {
            $job = self::lock($id);
            self::assertNotCancelled($job);
            self::addUpdate($id, 'note', $note);
            AuditLogger::log('job_note_added', 'job_cards', $id, null, ['note' => mb_substr($note, 0, 500)]);
        });
    }

    /** Record labor performed; cost = hours × hourly rate from settings, added to the job's labor_cost. */
    public static function addLabor(int $id, string $hours, string $note): void
    {
        $h = (int) round(((float) $hours) * 100);   // hundredths of an hour
        if ($h < 25 || $h > 2400) { throw new DomainException('Hours must be between 0.25 and 24.'); }
        Database::transaction(function () use ($id, $h, $note) {
            $job = self::lock($id);
            self::assertOpen($job);
            $rate = to_cents(Setting::get('labor_hourly_rate', '0'));
            $amount = intdiv($h * $rate + 50, 100);
            $newTotal = to_cents($job['labor_cost']) + $amount;
            Database::run('UPDATE job_cards SET labor_cost = ? WHERE id = ?', [from_cents($newTotal), $id]);
            Database::run("INSERT INTO job_updates (job_id, user_id, update_type, note, hours, amount) VALUES (?,?, 'labor', ?, ?, ?)",
                [$id, current_user()['id'] ?? null, $note ?: 'Labor performed', number_format($h / 100, 2, '.', ''), from_cents($amount)]);
            AuditLogger::log('job_labor_recorded', 'job_cards', $id, ['labor_cost' => $job['labor_cost']],
                ['labor_cost' => from_cents($newTotal), 'hours' => number_format($h / 100, 2, '.', ''), 'rate' => from_cents($rate)]);
        });
    }

    public static function requestPart(int $id, int $partId, int $qty, string $note): void
    {
        if ($qty < 1 || $qty > 1000) { throw new DomainException('Quantity must be between 1 and 1000.'); }
        Database::transaction(function () use ($id, $partId, $qty, $note) {
            $job = self::lock($id);
            self::assertOpen($job);
            $part = Database::one('SELECT id, name, sku, is_active FROM spare_parts WHERE id = ?', [$partId]);
            if (!$part || !$part['is_active']) { throw new DomainException('Spare part not found or deactivated.'); }
            $rid = Database::insert('INSERT INTO part_requests (job_id, part_id, quantity, note, requested_by) VALUES (?,?,?,?,?)',
                [$id, $partId, $qty, $note ?: null, current_user()['id'] ?? null]);
            self::addUpdate($id, 'parts', 'Requested ' . $qty . ' × ' . $part['name'] . ' (' . $part['sku'] . ')' . ($note ? ' — ' . $note : ''));
            AuditLogger::log('part_requested', 'job_cards', $id, null, ['request_id' => $rid, 'part' => $part['sku'], 'quantity' => $qty]);
        });
    }

    public static function syncMechanicAvailability(int $mechanicId): void
    {
        $m = Database::one('SELECT id, availability FROM mechanics WHERE id = ? FOR UPDATE', [$mechanicId]);
        if (!$m || $m['availability'] === 'off_duty') { return; }
        $active = (int) Database::value("SELECT COUNT(*) FROM job_cards WHERE mechanic_id = ? AND status IN ('Diagnosing','In Progress')", [$mechanicId]);
        Database::run('UPDATE mechanics SET availability = ? WHERE id = ?', [$active > 0 ? 'busy' : 'available', $mechanicId]);
    }

    /* ------------------------------------------------------------ Operational Workshop Workflow */

    /**
     * Step 6: Mechanic completes work and notifies Receptionist
     */
    public static function completeWork(int $id, string $notes = ''): void
    {
        Database::transaction(function () use ($id, $notes) {
            $job = self::lock($id);
            if (!in_array($job['status'], self::OPEN, true) && $job['status'] !== 'Completed') {
                throw new DomainException('Only open jobs can be marked as completed.');
            }
            if (!$job['mechanic_id']) {
                throw new DomainException('Cannot complete work without an assigned mechanic.');
            }

            $currentUserId = current_user()['id'] ?? 1;
            $oldStatus = $job['status'];

            Database::run(
                'UPDATE job_cards SET status = "Completed", workflow_stage = "work_completed", actual_completion = CURDATE(), 
                    work_completed_by = ?, work_completed_at = NOW(), work_completion_notes = ? WHERE id = ?',
                [$currentUserId, $notes ?: null, $id]
            );

            self::addUpdate($id, 'status', 'Work completed by mechanic: ' . ($notes ?: 'Repairs finalized'), $oldStatus, 'Completed');
            AuditLogger::log('job_work_completed', 'job_cards', $id, ['status' => $oldStatus], ['status' => 'Completed', 'notes' => $notes]);

            self::syncMechanicAvailability((int) $job['mechanic_id']);

            $fullJob = self::find($id);
            if ($fullJob) {
                SmsService::notifyWorkCompleted($fullJob, $fullJob['mechanic_name'] ?? null);
            }
        });
    }

    /**
     * Step 7: Receptionist sends completed job to Finance Manager
     */
    public static function sendToFinance(int $id, ?string $notes = null): void
    {
        Database::transaction(function () use ($id, $notes) {
            $job = self::lock($id);
            if ($job['status'] !== 'Completed' && $job['workflow_stage'] !== 'work_completed') {
                throw new DomainException('Only completed jobs can be submitted to Finance for clearance.');
            }

            Database::run(
                'UPDATE job_cards SET workflow_stage = "pending_finance" WHERE id = ?',
                [$id]
            );

            self::addUpdate($id, 'finance', 'Submitted to Finance Manager for billing clearance: ' . ($notes ?: 'Awaiting invoice & payment review'));
            AuditLogger::log('job_submitted_to_finance', 'job_cards', $id, null, ['notes' => $notes]);

            $fullJob = self::find($id);
            if ($fullJob) {
                SmsService::notifySentToFinance($fullJob);
            }
        });
    }

    /**
     * Step 8: Finance Manager inspects billing, payment status, and sends clearance to Reception
     */
    public static function financeClearance(int $id, ?string $notes = null): array
    {
        return Database::transaction(function () use ($id, $notes) {
            $job = self::lock($id);

            // Ensure invoice exists; if not, create it
            $inv = Database::one('SELECT id, balance, payment_status, grand_total FROM invoices WHERE job_card_id = ?', [$id]);
            if (!$inv) {
                try {
                    $invId = InvoiceService::createForJob($id);
                    $inv = Database::one('SELECT id, balance, payment_status, grand_total FROM invoices WHERE id = ?', [$invId]);
                } catch (Throwable $e) {
                    // Invoice generation fallback
                }
            }

            $balance = (float) ($inv['balance'] ?? 0.00);
            $isPaid = ($balance <= 0.01 && ($inv['payment_status'] ?? '') === 'Paid');
            $status = $isPaid ? 'Cleared' : 'Payment Due';

            $currentUserId = current_user()['id'] ?? 1;
            Database::run(
                'UPDATE job_cards SET workflow_stage = "finance_cleared", finance_status = ?, finance_balance = ?,
                    finance_reviewed_by = ?, finance_reviewed_at = NOW(), finance_notes = ? WHERE id = ?',
                [$status, $balance, $currentUserId, $notes ?: null, $id]
            );

            self::addUpdate($id, 'finance', "Financial clearance issued: {$status} (Outstanding Balance: " . money((string) $balance) . ")");
            AuditLogger::log('job_finance_cleared', 'job_cards', $id, null, [
                'status' => $status,
                'balance' => $balance,
                'notes' => $notes
            ]);

            $fullJob = self::find($id);
            if ($fullJob) {
                SmsService::notifyFinanceCleared($fullJob, $status, (string) $balance);
            }

            return ['status' => $status, 'balance' => $balance, 'invoice' => $inv];
        });
    }

    /**
     * Step 9: Receptionist calls/sends SMS to Customer for vehicle pickup
     */
    public static function notifyCustomerPickup(int $id): void
    {
        Database::transaction(function () use ($id) {
            $job = self::lock($id);
            $ticket = $job['claim_ticket_number'] ?: ('TKT-' . date('Ymd') . '-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT));

            Database::run(
                'UPDATE job_cards SET workflow_stage = "customer_notified", customer_notified_at = NOW() WHERE id = ?',
                [$id]
            );

            self::addUpdate($id, 'customer_call', "Customer notified for vehicle pickup via SMS with Claim Ticket {$ticket}.");
            AuditLogger::log('job_customer_notified_pickup', 'job_cards', $id, null, ['ticket' => $ticket]);

            $fullJob = self::find($id);
            if ($fullJob) {
                SmsService::notifyCustomerPickupReady($fullJob, (string) ($fullJob['finance_balance'] ?? 0), $ticket);
            }
        });
    }

    /**
     * Step 10 & 11: Receptionist verifies Claim Ticket & releases vehicle (or directs unpaid to finance)
     */
    public static function releaseVehicle(int $id, string $presentedTicket): void
    {
        Database::transaction(function () use ($id, $presentedTicket) {
            $job = self::lock($id);
            $expectedTicket = trim($job['claim_ticket_number'] ?? '');

            // Verify claim ticket
            if ($expectedTicket !== '' && strtoupper(trim($presentedTicket)) !== strtoupper($expectedTicket)) {
                throw new DomainException("Invalid Claim Ticket presented ('{$presentedTicket}'). Expected ticket is '{$expectedTicket}'.");
            }

            // Verify payment
            $inv = Database::one('SELECT balance, payment_status FROM invoices WHERE job_card_id = ?', [$id]);
            if ($inv && (float) $inv['balance'] > 0.01 && $inv['payment_status'] !== 'Paid') {
                throw new DomainException('Vehicle cannot be released: Outstanding balance of ' . money($inv['balance']) . ' must be settled with Finance first.');
            }

            $currentUserId = current_user()['id'] ?? 1;
            Database::run(
                'UPDATE job_cards SET workflow_stage = "vehicle_released", vehicle_released_by = ?, vehicle_released_at = NOW() WHERE id = ?',
                [$currentUserId, $id]
            );

            self::addUpdate($id, 'release', "Claim Ticket {$presentedTicket} verified. Vehicle handed over to customer.");
            AuditLogger::log('job_vehicle_released', 'job_cards', $id, null, [
                'ticket' => $presentedTicket,
                'released_by' => $currentUserId
            ]);

            $fullJob = self::find($id);
            if ($fullJob) {
                SmsService::notifyVehicleReleased($fullJob);
            }
        });
    }

    /**
     * Step 12 & 13: Manager signs off final vehicle delivery
     */
    public static function managerDeliver(int $id, ?string $signoffNotes = null): void
    {
        Database::transaction(function () use ($id, $signoffNotes) {
            $job = self::lock($id);
            $currentUserId = current_user()['id'] ?? 1;

            Database::run(
                'UPDATE job_cards SET status = "Delivered", workflow_stage = "delivered", delivered_by = ?, delivered_at = NOW(), actual_completion = COALESCE(actual_completion, CURDATE()) WHERE id = ?',
                [$currentUserId, $id]
            );

            self::addUpdate($id, 'delivery', 'Manager signed off delivery: ' . ($signoffNotes ?: 'All repairs, payments, and vehicle handover verified.'));
            AuditLogger::log('job_manager_delivered', 'job_cards', $id, ['status' => $job['status']], [
                'status' => 'Delivered',
                'delivered_by' => $currentUserId,
                'notes' => $signoffNotes
            ]);

            $fullJob = self::find($id);
            if ($fullJob) {
                SmsService::notifyDeliveryFinalized($fullJob);
            }
        });
    }

    /* ------------------------------------------------------------ internals */
    private static function lock(int $id): array
    {
        $j = Database::one('SELECT * FROM job_cards WHERE id = ? FOR UPDATE', [$id]);
        if (!$j) { throw new DomainException('Job card not found.'); }
        return $j;
    }

    private static function activeMechanic(int $id): array
    {
        $m = Database::one('SELECT id, full_name FROM mechanics WHERE id = ? AND is_active = 1', [$id]);
        if (!$m) { throw new DomainException('The selected mechanic is not available.'); }
        return $m;
    }

    private static function assertOpen(array $job): void
    {
        if (!in_array($job['status'], self::OPEN, true)) {
            throw new DomainException('This action is not allowed while the job is ' . $job['status'] . '.');
        }
    }

    private static function assertNotCancelled(array $job): void
    {
        if ($job['status'] === 'Cancelled') { throw new DomainException('This job card is cancelled.'); }
    }

    public static function addUpdate(int $jobId, string $type, ?string $note, ?string $old = null, ?string $new = null, ?float $hours = null, ?float $amount = null): void
    {
        Database::run('INSERT INTO job_updates (job_id, user_id, update_type, old_status, new_status, note, hours, amount) VALUES (?,?,?,?,?,?,?,?)',
            [$jobId, current_user()['id'] ?? null, $type, $old, $new, $note, $hours, $amount]);
    }
}
