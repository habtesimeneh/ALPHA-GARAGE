<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — OPERATIONAL WORKFLOW TEST SUITE
 * 
 * Verifies the complete 13-step automotive workshop operational lifecycle:
 * 1. Customer booking -> Receptionist notification
 * 2. Receptionist submission -> Manager approval queue
 * 3. Manager approval -> Receptionist & Customer notification
 * 4. Receptionist mechanic assignment & Claim Ticket generation
 * 5. Mechanic work execution & completion notification to Receptionist
 * 6. Receptionist submission -> Finance Manager clearance queue
 * 7. Finance Manager billing & invoice clearance -> Receptionist
 * 8. Receptionist customer pickup SMS notification
 * 9. Customer arrival & Claim Ticket ownership verification
 * 10. Payment clearance gate (Unpaid blocked, Paid allowed)
 * 11. Vehicle release -> Exit notice to Manager
 * 12. Manager final delivery sign-off ('Delivered') & thank-you SMS
 * 13. Full SMS audit log & storage spool integrity
 */

chdir(__DIR__ . '/..');
require_once __DIR__ . '/../app/bootstrap.php';

$testPassed = 0;
$testTotal = 0;

function it(string $desc, callable $fn): void {
    global $testPassed, $testTotal;
    $testTotal++;
    try {
        $fn();
        echo "  [PASS] {$desc}\n";
        $testPassed++;
    } catch (Throwable $e) {
        echo "  [FAIL] {$desc}\n";
        echo "         Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
    }
}

echo "\n=======================================================\n";
echo "ALPHA GARAGE — OPERATIONAL WORKFLOW & SMS TEST SUITE\n";
echo "=======================================================\n\n";

try {
    Database::transaction(function () use (&$testPassed, &$testTotal) {
    // 0. Setup Test Data (Customer, Vehicle, Mechanic, Services)
    $phone = '+251911223344';
    $cust = Database::one('SELECT id FROM customers WHERE phone = ?', [$phone]);
    if (!$cust) {
        $custId = Database::insert(
            'INSERT INTO customers (full_name, phone, email, address, is_active) VALUES (?, ?, ?, ?, 1)',
            ['Abebe Kebede (Workflow Test)', $phone, 'abebe.test@alphagarage.et', 'Bole Atlas, Addis Ababa']
        );
    } else {
        $custId = (int) $cust['id'];
    }

    $plate = 'ET-3-B7788';
    $veh = Database::one('SELECT id FROM vehicles WHERE plate_number = ?', [$plate]);
    if (!$veh) {
        $vehId = Database::insert(
            'INSERT INTO vehicles (customer_id, plate_number, make, model, year, vin, mileage, fuel_type, is_active)
             VALUES (?, ?, "Mercedes-Benz", "E300 AMG", 2023, "WDD2130831A123456", 18500, "Petrol", 1)',
            [$custId, $plate]
        );
    } else {
        $vehId = (int) $veh['id'];
    }

    $mech = Database::one('SELECT id, full_name FROM mechanics WHERE full_name LIKE ?', ['Tewodros Master%']);
    if (!$mech) {
        $mechId = Database::insert(
            'INSERT INTO mechanics (full_name, phone, specialty, availability, is_active)
             VALUES (?, "+251922334455", "German Luxury Diagnostic & Engine Overhaul", "available", 1)',
            ['Tewodros Master Mechanic']
        );
        $mech = Database::one('SELECT id, full_name FROM mechanics WHERE id = ?', [$mechId]);
    } else {
        $mechId = (int) $mech['id'];
    }

    $srv = Database::one('SELECT id, standard_price FROM services WHERE is_active = 1 LIMIT 1');
    $srvId = $srv ? (int) $srv['id'] : null;

    // Simulate logged in admin user
    $adminUser = Database::one('SELECT * FROM users WHERE id = 1');
    if ($adminUser) {
        $adminUser['role_name'] = 'administrator';
        $adminUser['permissions'] = ['*'];
        $_SESSION['user'] = $adminUser;
    }

    // --- STEP 1: Customer Books Service -> Received at Reception
    $aptId = 0;
    it('Step 1: Customer books service -> appointment created in pending_reception stage with SMS', function () use (&$aptId, $custId, $vehId, $srvId, $phone) {
        $smsCountBefore = (int) Database::value('SELECT COUNT(*) FROM sms_logs WHERE event_type = "booking_received"');
        
        $aptId = AppointmentService::create([
            'customer_id'         => $custId,
            'vehicle_id'          => $vehId,
            'service_id'          => $srvId,
            'appointment_date'    => date('Y-m-d', strtotime('+1 day')),
            'appointment_time'    => '10:00:00',
            'problem_description' => 'Engine diagnostic warning lamp illuminated; periodic transmission shutter.',
        ]);

        if ($aptId <= 0) {
            throw new Exception('Failed to create appointment.');
        }

        $apt = AppointmentService::find($aptId);
        if (($apt['workflow_stage'] ?? '') !== 'pending_reception') {
            throw new Exception("Expected workflow_stage 'pending_reception', got '{$apt['workflow_stage']}'");
        }

        $smsCountAfter = (int) Database::value('SELECT COUNT(*) FROM sms_logs WHERE event_type = "booking_received"');
        if ($smsCountAfter <= $smsCountBefore) {
            throw new Exception('Customer booking SMS was not logged.');
        }
    });

    // --- STEP 2: Receptionist Submits Booking to Manager
    it('Step 2: Receptionist submits booking to Manager for approval with SMS', function () use ($aptId) {
        $smsBefore = (int) Database::value('SELECT COUNT(*) FROM sms_logs WHERE event_type = "submitted_to_manager"');
        
        AppointmentService::sendToManager($aptId, 'Customer requested urgent morning diagnostic slot.');

        $apt = AppointmentService::find($aptId);
        if ($apt['workflow_stage'] !== 'pending_manager') {
            throw new Exception("Expected stage 'pending_manager', got '{$apt['workflow_stage']}'");
        }

        $smsAfter = (int) Database::value('SELECT COUNT(*) FROM sms_logs WHERE event_type = "submitted_to_manager"');
        if ($smsAfter <= $smsBefore) {
            throw new Exception('Manager notification SMS was not logged.');
        }
    });

    // --- STEP 3 & 4: Manager Approves Booking -> Returns to Receptionist
    it('Step 3 & 4: Manager approves booking request -> status confirmed with dual SMS', function () use ($aptId) {
        $smsCustBefore = (int) Database::value('SELECT COUNT(*) FROM sms_logs WHERE event_type = "manager_approved_customer"');
        $smsStaffBefore = (int) Database::value('SELECT COUNT(*) FROM sms_logs WHERE event_type = "manager_approved_staff"');

        AppointmentService::managerApprove($aptId, 'Approved for workshop intake at Bay 2.');

        $apt = AppointmentService::find($aptId);
        if ($apt['workflow_stage'] !== 'manager_approved' || $apt['status'] !== 'confirmed') {
            throw new Exception("Expected 'manager_approved' and 'confirmed', got stage '{$apt['workflow_stage']}' status '{$apt['status']}'");
        }
        if (empty($apt['manager_approved_by'])) {
            throw new Exception('manager_approved_by was not recorded.');
        }

        $smsCustAfter = (int) Database::value('SELECT COUNT(*) FROM sms_logs WHERE event_type = "manager_approved_customer"');
        $smsStaffAfter = (int) Database::value('SELECT COUNT(*) FROM sms_logs WHERE event_type = "manager_approved_staff"');

        if ($smsCustAfter <= $smsCustBefore || $smsStaffAfter <= $smsStaffBefore) {
            throw new Exception('Manager approval SMS to customer or staff failed to log.');
        }
    });

    // --- STEP 5: Receptionist Converts to Job Card & Assigns Mechanic
    $jobId = 0;
    $claimTicket = '';
    it('Step 5: Receptionist assigns mechanic & Claim Ticket generated with SMS', function () use ($aptId, $mechId, &$jobId, &$claimTicket) {
        $smsMechBefore = (int) Database::value('SELECT COUNT(*) FROM sms_logs WHERE event_type = "mechanic_assigned"');
        $smsCustBefore = (int) Database::value('SELECT COUNT(*) FROM sms_logs WHERE event_type = "vehicle_checked_in_customer"');

        $jobId = AppointmentService::convertToJob($aptId, $mechId);
        if ($jobId <= 0) {
            throw new Exception('Failed to convert appointment to job card.');
        }

        $job = JobService::find($jobId);
        if (empty($job['claim_ticket_number'])) {
            throw new Exception('Claim ticket number was not generated on job card.');
        }
        $claimTicket = $job['claim_ticket_number'];

        if (!str_starts_with($claimTicket, 'TKT-')) {
            throw new Exception("Invalid claim ticket format: '{$claimTicket}'");
        }
        if ((int)$job['mechanic_id'] !== $mechId) {
            throw new Exception("Mechanic ID mismatch: expected {$mechId}, got {$job['mechanic_id']}");
        }

        $smsMechAfter = (int) Database::value('SELECT COUNT(*) FROM sms_logs WHERE event_type = "mechanic_assigned"');
        $smsCustAfter = (int) Database::value('SELECT COUNT(*) FROM sms_logs WHERE event_type = "vehicle_checked_in_customer"');

        if ($smsMechAfter <= $smsMechBefore || $smsCustAfter <= $smsCustBefore) {
            throw new Exception('Mechanic assignment or Customer Claim Ticket SMS failed.');
        }
    });

    // --- STEP 6: Mechanic Completes Work & Notifies Receptionist
    it('Step 6: Mechanic completes work and dispatches SMS notification to Receptionist', function () use ($jobId) {
        $smsBefore = (int) Database::value('SELECT COUNT(*) FROM sms_logs WHERE event_type = "work_completed_staff"');

        JobService::completeWork($jobId, 'Transmission sensor replaced and software recalibrated. Test drive OK.');

        $job = JobService::find($jobId);
        if ($job['status'] !== 'Completed' || $job['workflow_stage'] !== 'work_completed') {
            throw new Exception("Expected status 'Completed' and stage 'work_completed', got {$job['status']} / {$job['workflow_stage']}");
        }
        if (empty($job['work_completed_at'])) {
            throw new Exception('work_completed_at timestamp missing.');
        }

        $smsAfter = (int) Database::value('SELECT COUNT(*) FROM sms_logs WHERE event_type = "work_completed_staff"');
        if ($smsAfter <= $smsBefore) {
            throw new Exception('Mechanic work completion SMS to reception was not logged.');
        }
    });

    // --- STEP 7: Receptionist Sends to Finance Manager
    it('Step 7: Receptionist sends completed job to Finance Manager for clearance with SMS', function () use ($jobId) {
        $smsBefore = (int) Database::value('SELECT COUNT(*) FROM sms_logs WHERE event_type = "submitted_to_finance"');

        JobService::sendToFinance($jobId, 'Repairs finished; please prepare final invoice and payment status.');

        $job = JobService::find($jobId);
        if ($job['workflow_stage'] !== 'pending_finance') {
            throw new Exception("Expected stage 'pending_finance', got '{$job['workflow_stage']}'");
        }

        $smsAfter = (int) Database::value('SELECT COUNT(*) FROM sms_logs WHERE event_type = "submitted_to_finance"');
        if ($smsAfter <= $smsBefore) {
            throw new Exception('Finance clearance request SMS was not logged.');
        }
    });

    // --- STEP 8: Finance Manager Clears Billing & Returns to Reception
    it('Step 8: Finance Manager issues financial clearance (Paid or Payment Due) with SMS', function () use ($jobId) {
        $smsBefore = (int) Database::value('SELECT COUNT(*) FROM sms_logs WHERE event_type = "finance_cleared_staff"');

        $clearance = JobService::financeClearance($jobId, 'Parts and labor audited. 15% VAT computed.');

        if (!in_array($clearance['status'], ['Cleared', 'Payment Due'], true)) {
            throw new Exception("Unexpected clearance status: '{$clearance['status']}'");
        }

        $job = JobService::find($jobId);
        if ($job['workflow_stage'] !== 'finance_cleared') {
            throw new Exception("Expected stage 'finance_cleared', got '{$job['workflow_stage']}'");
        }

        $smsAfter = (int) Database::value('SELECT COUNT(*) FROM sms_logs WHERE event_type = "finance_cleared_staff"');
        if ($smsAfter <= $smsBefore) {
            throw new Exception('Finance clearance SMS to reception was not logged.');
        }
    });

    // --- STEP 9: Receptionist Notifies Customer via SMS to Pick Up Vehicle
    it('Step 9: Receptionist notifies customer via SMS for vehicle pickup with Claim Ticket', function () use ($jobId, $claimTicket) {
        $smsBefore = (int) Database::value('SELECT COUNT(*) FROM sms_logs WHERE event_type = "customer_pickup_ready"');

        JobService::notifyCustomerPickup($jobId);

        $job = JobService::find($jobId);
        if ($job['workflow_stage'] !== 'customer_notified') {
            throw new Exception("Expected stage 'customer_notified', got '{$job['workflow_stage']}'");
        }
        if (empty($job['customer_notified_at'])) {
            throw new Exception('customer_notified_at was not recorded.');
        }

        $smsAfter = (int) Database::value('SELECT COUNT(*) FROM sms_logs WHERE event_type = "customer_pickup_ready"');
        if ($smsAfter <= $smsBefore) {
            throw new Exception('Customer pickup SMS was not logged.');
        }

        // Verify the logged message contains the claim ticket
        $lastSms = Database::one('SELECT message FROM sms_logs WHERE event_type = "customer_pickup_ready" ORDER BY id DESC LIMIT 1');
        if (!str_contains($lastSms['message'], $claimTicket)) {
            throw new Exception("SMS message did not contain claim ticket '{$claimTicket}': {$lastSms['message']}");
        }
    });

    // --- STEP 10 & 11: Customer Arrives, Claim Ticket Verification & Payment Routing
    it('Step 10 & 11: Negative tests — rejects invalid ticket and blocks release if unpaid', function () use ($jobId, $claimTicket) {
        // Test 10a: Wrong ticket presented
        $caughtTicketError = false;
        try {
            JobService::releaseVehicle($jobId, 'TKT-WRONG-9999');
        } catch (DomainException $e) {
            $caughtTicketError = true;
        }
        if (!$caughtTicketError) {
            throw new Exception('System failed to reject invalid Claim Ticket.');
        }

        // Test 10b: Unpaid balance block
        $inv = Database::one('SELECT id, balance FROM invoices WHERE job_card_id = ?', [$jobId]);
        if ($inv && (float)$inv['balance'] > 0.01) {
            $caughtUnpaidError = false;
            try {
                JobService::releaseVehicle($jobId, $claimTicket);
            } catch (DomainException $e) {
                $caughtUnpaidError = true;
            }
            if (!$caughtUnpaidError) {
                throw new Exception('System failed to block vehicle release for unpaid balance.');
            }
        }
    });

    it('Step 10 & 11: Positive test — settles payment, verifies ticket, releases vehicle with SMS to Manager', function () use ($jobId, $claimTicket) {
        // Ensure invoice is cleared / paid
        $inv = Database::one('SELECT id FROM invoices WHERE job_card_id = ?', [$jobId]);
        if ($inv) {
            Database::run('UPDATE invoices SET balance = 0.00, amount_paid = grand_total, payment_status = "Paid" WHERE id = ?', [$inv['id']]);
        }
        Database::run('UPDATE job_cards SET finance_status = "Cleared", finance_balance = 0.00 WHERE id = ?', [$jobId]);

        $smsBefore = (int) Database::value('SELECT COUNT(*) FROM sms_logs WHERE event_type = "vehicle_released_to_customer"');

        JobService::releaseVehicle($jobId, $claimTicket);

        $job = JobService::find($jobId);
        if ($job['workflow_stage'] !== 'vehicle_released') {
            throw new Exception("Expected stage 'vehicle_released', got '{$job['workflow_stage']}'");
        }
        if (empty($job['vehicle_released_at'])) {
            throw new Exception('vehicle_released_at timestamp missing.');
        }

        $smsAfter = (int) Database::value('SELECT COUNT(*) FROM sms_logs WHERE event_type = "vehicle_released_to_customer"');
        if ($smsAfter <= $smsBefore) {
            throw new Exception('Exit notification SMS to Manager was not logged.');
        }
    });

    // --- STEP 12 & 13: Manager Signs Off Final Delivery
    it('Step 12 & 13: Manager signs off final delivery -> status Delivered with thank-you SMS', function () use ($jobId) {
        $smsBefore = (int) Database::value('SELECT COUNT(*) FROM sms_logs WHERE event_type = "delivery_finalized_customer"');

        JobService::managerDeliver($jobId, 'Executive delivery sign-off: Client received vehicle in pristine condition.');

        $job = JobService::find($jobId);
        if ($job['status'] !== 'Delivered' || $job['workflow_stage'] !== 'delivered') {
            throw new Exception("Expected status 'Delivered' and stage 'delivered', got {$job['status']} / {$job['workflow_stage']}");
        }
        if (empty($job['delivered_at']) || empty($job['delivered_by'])) {
            throw new Exception('delivered_at or delivered_by was not recorded.');
        }

        $smsAfter = (int) Database::value('SELECT COUNT(*) FROM sms_logs WHERE event_type = "delivery_finalized_customer"');
        if ($smsAfter <= $smsBefore) {
            throw new Exception('Final thank-you SMS to customer was not logged.');
        }
    });

    // --- STEP 14: SMS Spool and Audit Integrity
    it('Step 14: SMS Spool File & Database Log Integrity', function () {
        $spoolFile = APP_ROOT . '/storage/sms/sms_spool.log';
        if (!file_exists($spoolFile)) {
            throw new Exception("SMS spool file does not exist at {$spoolFile}");
        }
        $contents = file_get_contents($spoolFile);
        if (!str_contains($contents, 'EVENT=booking_received') || !str_contains($contents, 'EVENT=customer_pickup_ready')) {
            throw new Exception('SMS spool file missing expected event log records.');
        }
    });

    // Rollback test changes to preserve clean database state
    throw new RuntimeException('__CLEAN_ROLLBACK__');
});
} catch (RuntimeException $e) {
    if ($e->getMessage() !== '__CLEAN_ROLLBACK__') {
        throw $e;
    }
}

echo "\n-------------------------------------------------------\n";
echo "SUMMARY: {$testPassed} of {$testTotal} tests passed.\n";
if ($testPassed === $testTotal) {
    echo "STATUS: ALL 13 WORKFLOW & SMS TESTS PASSED SUCCESSFULLY!\n";
} else {
    echo "STATUS: SOME TESTS FAILED.\n";
    exit(1);
}
echo "-------------------------------------------------------\n\n";
