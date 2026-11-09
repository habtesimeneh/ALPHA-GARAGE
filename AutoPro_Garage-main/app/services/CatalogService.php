<?php
declare(strict_types=1);

/**
 * Service Catalog & Work Order Services Management.
 * Manages standard garage service catalog (diagnostics, oil change, brake work, etc.)
 * and allows associating multiple services to job cards with mechanic assignment and status tracking.
 */
final class CatalogService
{
    public const CATEGORIES = [
        'Diagnostics'    => 'Diagnostics & Scanning',
        'Maintenance'    => 'Routine Maintenance',
        'Brakes'         => 'Brake Systems',
        'Engine'         => 'Engine & Powertrain',
        'Transmission'   => 'Transmission & Drivetrain',
        'Suspension'     => 'Suspension & Steering',
        'Electrical'     => 'Auto Electrical & Battery',
        'AC & Heating'   => 'Air Conditioning & Heating',
        'Body & Paint'   => 'Bodywork & Paint',
        'General Repair' => 'General Mechanical Repair',
    ];

    public static function all(bool $activeOnly = false): array
    {
        $sql = 'SELECT s.*, 
                       (SELECT COUNT(*) FROM job_services js WHERE js.service_id = s.id) AS usage_count
                FROM services s';
        if ($activeOnly) {
            $sql .= ' WHERE s.is_active = 1';
        }
        $sql .= ' ORDER BY s.category ASC, s.name ASC';
        return Database::all($sql);
    }

    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM services WHERE id = ?', [$id]);
    }

    public static function save(?int $id, array $d): int
    {
        return (int) Database::transaction(function () use ($id, $d) {
            $code = mb_strtoupper(trim($d['code']));
            $name = trim($d['name']);
            $cat = trim($d['category']);
            $desc = trim($d['description'] ?? '');
            $price = from_cents(to_cents($d['standard_price'] ?? '0'));
            $hours = max(0.25, (float) ($d['estimated_hours'] ?? 1.0));

            $existing = Database::one('SELECT id FROM services WHERE code = ? AND id != ?', [$code, $id ?? 0]);
            if ($existing) {
                throw new DomainException('A service with code "' . $code . '" already exists.');
            }

            if ($id === null) {
                $id = Database::insert(
                    'INSERT INTO services (code, name, category, description, standard_price, estimated_hours, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, 1)',
                    [$code, $name, $cat, $desc ?: null, $price, $hours]
                );
                AuditLogger::log('service_created', 'services', $id, null, [
                    'code' => $code, 'name' => $name, 'category' => $cat, 'standard_price' => $price, 'estimated_hours' => $hours
                ]);
            } else {
                $old = self::find($id);
                if (!$old) {
                    throw new DomainException('Service not found.');
                }
                Database::run(
                    'UPDATE services SET code=?, name=?, category=?, description=?, standard_price=?, estimated_hours=? WHERE id=?',
                    [$code, $name, $cat, $desc ?: null, $price, $hours, $id]
                );
                $new = self::find($id);
                AuditLogger::log('service_updated', 'services', $id, $old, $new);
            }
            return $id;
        });
    }

    public static function toggleActive(int $id): bool
    {
        return (bool) Database::transaction(function () use ($id) {
            $s = Database::one('SELECT id, name, is_active FROM services WHERE id = ? FOR UPDATE', [$id]);
            if (!$s) {
                throw new DomainException('Service not found.');
            }
            $newActive = $s['is_active'] ? 0 : 1;
            Database::run('UPDATE services SET is_active = ? WHERE id = ?', [$newActive, $id]);
            AuditLogger::log($newActive ? 'service_reactivated' : 'service_deactivated', 'services', $id,
                ['is_active' => (int) $s['is_active']], ['is_active' => $newActive]);
            return (bool) $newActive;
        });
    }

    /** Attach a service to a job card */
    public static function attachToJob(int $jobId, int $serviceId, ?int $mechanicId = null, string $notes = ''): int
    {
        return (int) Database::transaction(function () use ($jobId, $serviceId, $mechanicId, $notes) {
            $job = Database::one('SELECT id, job_number, status, labor_cost FROM job_cards WHERE id = ? FOR UPDATE', [$jobId]);
            if (!$job) {
                throw new DomainException('Job card not found.');
            }
            if (!in_array($job['status'], JobService::OPEN, true)) {
                throw new DomainException('Services can only be attached to an open job card.');
            }
            $srv = self::find($serviceId);
            if (!$srv || !$srv['is_active']) {
                throw new DomainException('Service not found or inactive.');
            }
            if ($mechanicId !== null) {
                $mechanic = Database::one('SELECT id FROM mechanics WHERE id = ? AND is_active = 1 FOR UPDATE', [$mechanicId]);
                if (!$mechanic) {
                    throw new DomainException('The assigned mechanic is not available.');
                }
            }
            if (mb_strlen($notes) > 255) {
                throw new DomainException('Service notes must be at most 255 characters.');
            }

            $id = Database::insert(
                'INSERT INTO job_services (job_id, service_id, service_name, price, status, assigned_mechanic_id, notes)
                 VALUES (?, ?, ?, ?, "Pending", ?, ?)',
                [$jobId, $serviceId, $srv['name'], $srv['standard_price'], $mechanicId ?: null, $notes ?: null]
            );

            // Automatically update labor_cost on job card
            $newLabor = from_cents(to_cents($job['labor_cost']) + to_cents($srv['standard_price']));
            Database::run('UPDATE job_cards SET labor_cost = ? WHERE id = ?', [$newLabor, $jobId]);

            JobService::addUpdate($jobId, 'labor', 'Added service: ' . $srv['name'], null, null, null, null, (float) $srv['standard_price']);
            AuditLogger::log('job_service_attached', 'job_cards', $jobId, null, [
                'job_service_id' => $id, 'service' => $srv['name'], 'price' => $srv['standard_price']
            ]);

            return $id;
        });
    }

    /** Get services attached to a job */
    public static function forJob(int $jobId): array
    {
        return Database::all(
            'SELECT js.*, s.category, s.estimated_hours, m.full_name AS mechanic_name
             FROM job_services js
             LEFT JOIN services s ON s.id = js.service_id
             LEFT JOIN mechanics m ON m.id = js.assigned_mechanic_id
             WHERE js.job_id = ? ORDER BY js.id ASC', [$jobId]
        );
    }

    /** Update status of a job service */
    public static function updateServiceStatus(int $jobId, int $jobServiceId, string $status): void
    {
        if (!in_array($status, ['Pending', 'In Progress', 'Completed', 'Cancelled'], true)) {
            throw new DomainException('Invalid service status.');
        }

        Database::transaction(function () use ($jobId, $jobServiceId, $status) {
            $job = Database::one('SELECT id, status FROM job_cards WHERE id = ? FOR UPDATE', [$jobId]);
            if (!$job) {
                throw new DomainException('Job card not found.');
            }
            if (!in_array($job['status'], JobService::OPEN, true)) {
                throw new DomainException('Services can only be updated while the job card is open.');
            }
            $js = Database::one('SELECT * FROM job_services WHERE id = ? AND job_id = ? FOR UPDATE', [$jobServiceId, $jobId]);
            if (!$js) {
                throw new DomainException('Service item not found on this job card.');
            }
            Database::run('UPDATE job_services SET status = ? WHERE id = ?', [$status, $jobServiceId]);
            AuditLogger::log('job_service_status_changed', 'job_cards', (int) $js['job_id'],
                ['service' => $js['service_name'], 'old_status' => $js['status']],
                ['service' => $js['service_name'], 'new_status' => $status]
            );
        });
    }
}
