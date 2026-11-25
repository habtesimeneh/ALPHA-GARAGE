<?php
declare(strict_types=1);

/**
 * Appointment & Service Scheduling Management.
 * Manages customer service appointments, schedule tracking, and 1-click conversion to Job Cards.
 */
final class AppointmentService
{
    public const STATUSES = [
        'scheduled'   => 'Scheduled',
        'confirmed'   => 'Confirmed',
        'in_progress' => 'In Progress',
        'completed'   => 'Completed',
        'cancelled'   => 'Cancelled',
    ];

    public static function all(array $filters = []): array
    {
        $params = [];
        $where = ['1=1'];

        if (!empty($filters['status'])) {
            $where[] = 'a.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['from'])) {
            $where[] = 'a.appointment_date >= ?';
            $params[] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'a.appointment_date <= ?';
            $params[] = $filters['to'];
        }
        if (!empty($filters['q'])) {
            $like = like_escape($filters['q']);
            $where[] = '(c.full_name LIKE ? OR c.phone LIKE ? OR v.plate_number LIKE ? OR a.appointment_number LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }

        $sql = 'SELECT a.*, c.full_name AS customer_name, c.phone AS customer_phone,
                       v.plate_number, v.make, v.model,
                       s.name AS service_name, s.standard_price,
                       j.job_number AS converted_job_number
                FROM appointments a
                JOIN customers c ON c.id = a.customer_id
                JOIN vehicles v ON v.id = a.vehicle_id
                LEFT JOIN services s ON s.id = a.service_id
                LEFT JOIN job_cards j ON j.id = a.converted_job_id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY a.appointment_date ASC, a.appointment_time ASC';

        return Database::all($sql, $params);
    }

    public static function upcoming(int $limit = 5): array
    {
        return Database::all(
            'SELECT a.*, c.full_name AS customer_name, c.phone AS customer_phone,
                    v.plate_number, v.make, v.model, s.name AS service_name
             FROM appointments a
             JOIN customers c ON c.id = a.customer_id
             JOIN vehicles v ON v.id = a.vehicle_id
             LEFT JOIN services s ON s.id = a.service_id
             WHERE a.appointment_date >= CURDATE() AND a.status IN ("scheduled", "confirmed")
             ORDER BY a.appointment_date ASC, a.appointment_time ASC LIMIT ?', [$limit]
        );
    }

    public static function find(int $id): ?array
    {
        return Database::one(
            'SELECT a.*, c.full_name AS customer_name, c.phone AS customer_phone, c.email AS customer_email,
                    v.plate_number, v.make, v.model, v.year AS vehicle_year, v.vin,
                    s.name AS service_name, s.standard_price,
                    j.job_number AS converted_job_number
             FROM appointments a
             JOIN customers c ON c.id = a.customer_id
             JOIN vehicles v ON v.id = a.vehicle_id
             LEFT JOIN services s ON s.id = a.service_id
             LEFT JOIN job_cards j ON j.id = a.converted_job_id
             WHERE a.id = ?', [$id]
        );
    }

    public static function create(array $d): int
    {
        return (int) Database::transaction(function () use ($d) {
            $custId = (int) $d['customer_id'];
            $vehId = (int) $d['vehicle_id'];
            $srvId = !empty($d['service_id']) ? (int) $d['service_id'] : null;
            $date = $d['appointment_date'];
            $time = $d['appointment_time'];
            $prob = trim((string)($d['problem_description'] ?? ($d['notes'] ?? '')));

            $cust = Database::one('SELECT id, is_active FROM customers WHERE id = ?', [$custId]);
            $veh = Database::one('SELECT id, customer_id, is_active FROM vehicles WHERE id = ?', [$vehId]);
            if (!$cust || !$veh || (int) $veh['customer_id'] !== $custId) {
                throw new DomainException('Invalid customer or vehicle selection.');
            }

            $id = Database::insert(
                'INSERT INTO appointments (appointment_number, customer_id, vehicle_id, service_id, appointment_date, appointment_time, status, workflow_stage, problem_description, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, "scheduled", "pending_reception", ?, ?)',
                [Numbering::placeholder(), $custId, $vehId, $srvId, $date, $time, $prob ?: null, current_user()['id'] ?? null]
            );
            $number = Numbering::make('APT', $id);
            Database::run('UPDATE appointments SET appointment_number = ? WHERE id = ?', [$number, $id]);

            AuditLogger::log('appointment_created', 'appointments', $id, null, [
                'appointment_number' => $number, 'customer_id' => $custId, 'vehicle_id' => $vehId, 'date' => $date, 'time' => $time
            ]);

            // Notify receptionists & customer via SMS and In-App
            $aptData = self::find($id);
            if ($aptData) {
                SmsService::notifyBookingReceived($aptData);
            }

            return $id;
        });
    }

    public static function updateStatus(int $id, string $status): void
    {
        if (!array_key_exists($status, self::STATUSES)) {
            throw new DomainException('Invalid appointment status.');
        }

        Database::transaction(function () use ($id, $status) {
            $apt = Database::one('SELECT * FROM appointments WHERE id = ? FOR UPDATE', [$id]);
            if (!$apt) {
                throw new DomainException('Appointment not found.');
            }
            Database::run('UPDATE appointments SET status = ? WHERE id = ?', [$status, $id]);
            AuditLogger::log('appointment_status_changed', 'appointments', $id,
                ['status' => $apt['status']], ['status' => $status]);
        });
    }

    /**
     * Step 2: Receptionist submits booking to Manager for approval
     */
    public static function sendToManager(int $id, ?string $notes = null): void
    {
        Database::transaction(function () use ($id, $notes) {
            $apt = self::find($id);
            if (!$apt) {
                throw new DomainException('Appointment not found.');
            }
            if ($apt['status'] === 'cancelled') {
                throw new DomainException('A cancelled appointment cannot be submitted to manager.');
            }

            Database::run(
                'UPDATE appointments SET workflow_stage = "pending_manager", manager_notes = ? WHERE id = ?',
                [$notes ?: ($apt['manager_notes'] ?? null), $id]
            );

            AuditLogger::log('appointment_submitted_to_manager', 'appointments', $id, null, [
                'appointment_number' => $apt['appointment_number'],
                'notes' => $notes
            ]);

            SmsService::notifySentToManager($apt);
        });
    }

    /**
     * Step 3 & 4: Manager approves service booking and returns to Reception
     */
    public static function managerApprove(int $id, ?string $notes = null): void
    {
        Database::transaction(function () use ($id, $notes) {
            $apt = self::find($id);
            if (!$apt) {
                throw new DomainException('Appointment not found.');
            }
            if ($apt['status'] === 'cancelled') {
                throw new DomainException('A cancelled appointment cannot be approved.');
            }

            $currentUserId = current_user()['id'] ?? 1;
            Database::run(
                'UPDATE appointments SET workflow_stage = "manager_approved", status = "confirmed", manager_approved_by = ?, manager_approved_at = NOW(), manager_notes = ? WHERE id = ?',
                [$currentUserId, $notes ?: ($apt['manager_notes'] ?? null), $id]
            );

            AuditLogger::log('appointment_manager_approved', 'appointments', $id, null, [
                'appointment_number' => $apt['appointment_number'],
                'approved_by' => $currentUserId,
                'notes' => $notes
            ]);

            $updatedApt = self::find($id);
            SmsService::notifyManagerApproved($updatedApt ?: $apt);
        });
    }

    /** 1-Click Conversion: Converts an Appointment into a Job Card */
    public static function convertToJob(int $id, ?int $mechanicId = null): int
    {
        return (int) Database::transaction(function () use ($id, $mechanicId) {
            $apt = self::find($id);
            if (!$apt) {
                throw new DomainException('Appointment not found.');
            }
            if ($apt['converted_job_id']) {
                throw new DomainException('This appointment has already been converted to Job Card #' . $apt['converted_job_number']);
            }
            if ($apt['status'] === 'cancelled') {
                throw new DomainException('A cancelled appointment cannot be converted to a job card.');
            }

            // Create job card
            $problem = $apt['problem_description'] ?: ($apt['service_name'] ? 'Scheduled service: ' . $apt['service_name'] : 'General service inspection');
            $laborCost = $apt['standard_price'] ?? '0.00';

            $jobId = JobService::create([
                'customer_id' => $apt['customer_id'],
                'vehicle_id' => $apt['vehicle_id'],
                'mechanic_id' => $mechanicId,
                'date_received' => date('Y-m-d'),
                'reported_problem' => $problem,
                'initial_condition' => 'Checked in via Appointment #' . $apt['appointment_number'],
                'required_repairs' => $apt['service_name'] ?? '',
                'labor_cost' => (string) $laborCost,
                'estimated_completion' => date('Y-m-d', strtotime('+1 day')),
                'mileage_in' => (string) ($apt['mileage'] ?? ''),
            ]);

            // If a specific catalog service was chosen in appointment, attach it to job_services
            if (!empty($apt['service_id'])) {
                try {
                    CatalogService::attachToJob($jobId, (int) $apt['service_id'], $mechanicId, 'Booked via appointment');
                } catch (Throwable $e) {
                    // non-fatal if service price already counted
                }
            }

            // Update appointment
            Database::run('UPDATE appointments SET status = "in_progress", workflow_stage = "converted_to_job", converted_job_id = ? WHERE id = ?', [$jobId, $id]);
            AuditLogger::log('appointment_converted_to_job', 'appointments', $id, null, [
                'appointment_number' => $apt['appointment_number'], 'job_id' => $jobId
            ]);

            return $jobId;
        });
    }
}

