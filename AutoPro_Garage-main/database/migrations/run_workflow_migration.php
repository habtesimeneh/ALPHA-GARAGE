<?php
declare(strict_types=1);

chdir(__DIR__ . '/../..');
require_once __DIR__ . '/../../app/bootstrap.php';

echo "Running migration for Operational Workshop Workflow...\n";

// 1. Alter appointments table
$appCols = Database::all("SHOW COLUMNS FROM appointments LIKE 'workflow_stage'");
if (empty($appCols)) {
    Database::run("ALTER TABLE appointments 
        ADD COLUMN workflow_stage VARCHAR(50) NOT NULL DEFAULT 'pending_reception' AFTER status,
        ADD COLUMN manager_approved_by INT UNSIGNED NULL AFTER workflow_stage,
        ADD COLUMN manager_approved_at DATETIME NULL AFTER manager_approved_by,
        ADD COLUMN manager_notes VARCHAR(500) NULL AFTER manager_approved_at,
        ADD KEY idx_workflow_stage (workflow_stage)");
    echo " + Added workflow columns to appointments table.\n";
} else {
    echo " . appointments table already has workflow_stage.\n";
}

// 2. Alter job_cards table
$jcCols = Database::all("SHOW COLUMNS FROM job_cards LIKE 'workflow_stage'");
if (empty($jcCols)) {
    Database::run("ALTER TABLE job_cards 
        ADD COLUMN workflow_stage VARCHAR(50) NOT NULL DEFAULT 'assigned' AFTER status,
        ADD COLUMN claim_ticket_number VARCHAR(50) NULL UNIQUE AFTER workflow_stage,
        ADD COLUMN work_completed_by INT UNSIGNED NULL AFTER claim_ticket_number,
        ADD COLUMN work_completed_at DATETIME NULL AFTER work_completed_by,
        ADD COLUMN work_completion_notes TEXT NULL AFTER work_completed_at,
        ADD COLUMN finance_status ENUM('Pending','Cleared','Payment Due') NOT NULL DEFAULT 'Pending' AFTER work_completion_notes,
        ADD COLUMN finance_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER finance_status,
        ADD COLUMN finance_reviewed_by INT UNSIGNED NULL AFTER finance_balance,
        ADD COLUMN finance_reviewed_at DATETIME NULL AFTER finance_reviewed_by,
        ADD COLUMN finance_notes VARCHAR(500) NULL AFTER finance_reviewed_at,
        ADD COLUMN customer_notified_at DATETIME NULL AFTER finance_notes,
        ADD COLUMN vehicle_released_by INT UNSIGNED NULL AFTER customer_notified_at,
        ADD COLUMN vehicle_released_at DATETIME NULL AFTER vehicle_released_by,
        ADD COLUMN delivered_by INT UNSIGNED NULL AFTER vehicle_released_at,
        ADD COLUMN delivered_at DATETIME NULL AFTER delivered_by,
        ADD KEY idx_jc_workflow_stage (workflow_stage),
        ADD KEY idx_claim_ticket (claim_ticket_number)");
    echo " + Added workflow and claim ticket columns to job_cards table.\n";
} else {
    echo " . job_cards table already has workflow_stage.\n";
}

// 3. Create sms_logs table
Database::run("ALTER TABLE job_updates MODIFY COLUMN update_type VARCHAR(40) NOT NULL DEFAULT 'status'");
echo " + Altered job_updates.update_type to VARCHAR(40).\n";

Database::run("CREATE TABLE IF NOT EXISTS sms_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recipient_phone VARCHAR(50) NOT NULL,
    recipient_name VARCHAR(150) NULL,
    recipient_role VARCHAR(50) NULL,
    message TEXT NOT NULL,
    event_type VARCHAR(80) NOT NULL,
    related_entity_type VARCHAR(50) NULL,
    related_entity_id INT UNSIGNED NULL,
    status ENUM('sent', 'queued', 'failed') NOT NULL DEFAULT 'sent',
    gateway VARCHAR(50) NOT NULL DEFAULT 'local_spool',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_phone (recipient_phone),
    KEY idx_event (event_type),
    KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo " + Ensured sms_logs table exists.\n";

// 4. Seed permissions
$newPerms = [
    ['appointments.manager_approve', 'appointments', 'Manager approval of service bookings'],
    ['jobs.finance_clear', 'job_cards', 'Review and clear job card financial billing'],
    ['jobs.release_vehicle', 'job_cards', 'Release finished vehicle to customer upon claim ticket'],
    ['jobs.manager_deliver', 'job_cards', 'Manager final sign-off and vehicle delivery completion'],
    ['sms.view_logs', 'notifications', 'View SMS notification dispatch logs'],
];

foreach ($newPerms as $np) {
    $existing = Database::one("SELECT id FROM permissions WHERE code = ?", [$np[0]]);
    if (!$existing) {
        Database::run("INSERT INTO permissions (code, module, label) VALUES (?, ?, ?)", [$np[0], $np[1], $np[2]]);
        $permId = (int) Database::pdo()->lastInsertId();
        echo " + Created permission: {$np[0]}\n";
    } else {
        $permId = (int) $existing['id'];
    }

    // Assign to administrator
    $hasAdmin = Database::one("SELECT 1 FROM role_permissions WHERE role_id = 1 AND permission_id = ?", [$permId]);
    if (!$hasAdmin) {
        Database::run("INSERT INTO role_permissions (role_id, permission_id) VALUES (1, ?)", [$permId]);
    }

    // Role-specific assignments
    if ($np[0] === 'jobs.finance_clear') {
        // Assign to accountant (role 5)
        $hasAcc = Database::one("SELECT 1 FROM role_permissions WHERE role_id = 5 AND permission_id = ?", [$permId]);
        if (!$hasAcc) {
            Database::run("INSERT INTO role_permissions (role_id, permission_id) VALUES (5, ?)", [$permId]);
        }
    }
    if ($np[0] === 'jobs.release_vehicle') {
        // Assign to receptionist (role 2)
        $hasRec = Database::one("SELECT 1 FROM role_permissions WHERE role_id = 2 AND permission_id = ?", [$permId]);
        if (!$hasRec) {
            Database::run("INSERT INTO role_permissions (role_id, permission_id) VALUES (2, ?)", [$permId]);
        }
    }
}

echo "Migration completed successfully!\n";
