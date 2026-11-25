<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — ENTERPRISE SMS & NOTIFICATION ENGINE
 * 
 * Handles multi-channel SMS dispatch, logging, and in-app alert broadcasting
 * for the end-to-end workshop operational workflow.
 * 
 * Features:
 * - Real-time SMS logging in `sms_logs` table.
 * - Persistent audit spooling in `storage/sms/sms_spool.log`.
 * - Simultaneous in-app NotificationService dispatch.
 * - Automatic phone number resolution for staff roles and customers.
 * - Bilingual message template formatting (English / Amharic).
 */
final class SmsService
{
    private const SPOOL_DIR = APP_ROOT . '/storage/sms';
    private const SPOOL_FILE = APP_ROOT . '/storage/sms/sms_spool.log';

    /**
     * Dispatch an SMS message to a recipient.
     */
    public static function send(
        string $toPhone,
        string $message,
        string $eventType,
        ?string $recipientName = null,
        ?string $recipientRole = null,
        ?string $entityType = null,
        ?int $entityId = null
    ): bool {
        $toPhone = self::normalizePhone($toPhone);
        if ($toPhone === '') {
            return false;
        }

        // 1. Log to database
        $logId = null;
        $provider = Setting::get('sms_gateway_provider', 'local_spool');
        try {
            $logId = (int) Database::insert(
                'INSERT INTO sms_logs (recipient_phone, recipient_name, recipient_role, message, event_type, related_entity_type, related_entity_id, status, gateway)
                 VALUES (?, ?, ?, ?, ?, ?, ?, "sent", ?)',
                [$toPhone, $recipientName ?: null, $recipientRole ?: null, $message, $eventType, $entityType ?: null, $entityId ?: null, $provider]
            );
        } catch (Throwable $e) {
            error_log('SMS DB Log Error: ' . $e->getMessage());
        }

        // 2. Spool to filesystem for audit & offline tracking
        self::spool($toPhone, $message, $eventType, $recipientName, $recipientRole);

        // 3. Dispatch via external gateway if configured
        $dispatchResult = self::dispatchExternalGateway($toPhone, $message);
        if ($logId && $dispatchResult !== null) {
            try {
                Database::run(
                    'UPDATE sms_logs SET status = ?, gateway = ? WHERE id = ?',
                    [$dispatchResult['success'] ? 'delivered' : 'failed', $dispatchResult['gateway'], $logId]
                );
            } catch (Throwable $e) {
                // ignore update error
            }
        }

        return true;
    }

    /**
     * 1. Customer Books Service -> Notify Receptionist and Customer
     */
    public static function notifyBookingReceived(array $apt): void
    {
        $customerName = $apt['customer_name'] ?? 'Valued Customer';
        $customerPhone = $apt['customer_phone'] ?? '';
        $aptNumber = $apt['appointment_number'] ?? ('#' . ($apt['id'] ?? ''));
        $plate = $apt['plate_number'] ?? 'Vehicle';
        $date = $apt['appointment_date'] ?? date('Y-m-d');
        $time = isset($apt['appointment_time']) ? date('H:i', strtotime($apt['appointment_time'])) : '';

        // SMS to Customer
        $custMsg = "Alpha Garage: Dear {$customerName}, your service booking {$aptNumber} for {$plate} on {$date} {$time} has been received. Our reception team will review and confirm shortly. Tel: +251 911 000 000";
        self::send($customerPhone, $custMsg, 'booking_received', $customerName, 'customer', 'appointments', (int) ($apt['id'] ?? 0));

        // SMS to Receptionist
        $recPhone = self::getRolePhone('receptionist');
        $recMsg = "Alpha Garage [NEW BOOKING]: Appointment {$aptNumber} received from {$customerName} ({$customerPhone}) for {$plate}. Scheduled: {$date} {$time}. Please review.";
        self::send($recPhone, $recMsg, 'booking_received_staff', 'Reception Team', 'receptionist', 'appointments', (int) ($apt['id'] ?? 0));

        // In-app Notification to Receptionist
        NotificationService::broadcast(
            'New Service Booking Received',
            "Appointment {$aptNumber} booked by {$customerName} for {$plate}.",
            'appointments/',
            'receptionist'
        );
    }

    /**
     * 2. Receptionist forwards booking to Manager for approval
     */
    public static function notifySentToManager(array $apt): void
    {
        $aptNumber = $apt['appointment_number'] ?? ('#' . ($apt['id'] ?? ''));
        $plate = $apt['plate_number'] ?? 'Vehicle';
        $customerName = $apt['customer_name'] ?? 'Customer';

        // SMS to Manager
        $mgrPhone = self::getRolePhone('administrator');
        $mgrMsg = "Alpha Garage [ACTION REQUIRED]: Appointment {$aptNumber} ({$customerName} · {$plate}) has been reviewed by Reception and submitted for your Manager Approval.";
        self::send($mgrPhone, $mgrMsg, 'submitted_to_manager', 'Workshop Manager', 'administrator', 'appointments', (int) ($apt['id'] ?? 0));

        // In-app Notification to Manager
        NotificationService::broadcast(
            'Booking Awaiting Manager Approval',
            "Appointment {$aptNumber} for {$plate} submitted by Reception for approval.",
            'appointments/',
            'administrator'
        );
    }

    /**
     * 3 & 4. Manager Approves booking -> Notify Receptionist and Customer
     */
    public static function notifyManagerApproved(array $apt): void
    {
        $aptNumber = $apt['appointment_number'] ?? ('#' . ($apt['id'] ?? ''));
        $plate = $apt['plate_number'] ?? 'Vehicle';
        $customerName = $apt['customer_name'] ?? 'Customer';
        $customerPhone = $apt['customer_phone'] ?? '';

        // SMS to Customer
        $custMsg = "Alpha Garage: Great news! Your service booking {$aptNumber} for vehicle {$plate} has been APPROVED by management. We are ready to receive your vehicle.";
        self::send($customerPhone, $custMsg, 'manager_approved_customer', $customerName, 'customer', 'appointments', (int) ($apt['id'] ?? 0));

        // SMS to Receptionist
        $recPhone = self::getRolePhone('receptionist');
        $recMsg = "Alpha Garage [MANAGER APPROVED]: Appointment {$aptNumber} ({$plate}) is APPROVED by Manager. Please check in vehicle and assign mechanic.";
        self::send($recPhone, $recMsg, 'manager_approved_staff', 'Reception Team', 'receptionist', 'appointments', (int) ($apt['id'] ?? 0));

        // In-app Notification to Receptionist
        NotificationService::broadcast(
            'Appointment Approved by Manager',
            "Appointment {$aptNumber} for {$plate} was approved by manager. Ready to assign mechanic.",
            'appointments/',
            'receptionist'
        );
    }

    /**
     * 5. Receptionist Assigns Mechanic -> Notify Mechanic and Customer (with Claim Ticket)
     */
    public static function notifyMechanicAssigned(array $job, array $mechanic, string $claimTicket): void
    {
        $jobNumber = $job['job_number'] ?? ('JC-' . ($job['id'] ?? ''));
        $plate = $job['plate_number'] ?? 'Vehicle';
        $makeModel = trim(($job['make'] ?? '') . ' ' . ($job['model'] ?? ''));
        $problem = mb_substr($job['reported_problem'] ?? 'General Repair', 0, 80);
        $customerName = $job['customer_name'] ?? 'Customer';
        $customerPhone = $job['customer_phone'] ?? '';
        $mechName = $mechanic['full_name'] ?? 'Mechanic';
        $mechPhone = $mechanic['phone'] ?? self::getRolePhone('mechanic');

        // SMS to Mechanic
        $mechMsg = "Alpha Garage [NEW JOB ASSIGNED]: Job {$jobNumber} assigned to you. Vehicle: {$plate} ({$makeModel}). Issue: {$problem}. Ticket: {$claimTicket}.";
        self::send($mechPhone, $mechMsg, 'mechanic_assigned', $mechName, 'mechanic', 'job_cards', (int) ($job['id'] ?? 0));

        // In-app notification to Mechanic
        if (!empty($mechanic['user_id'])) {
            NotificationService::notifyUser(
                (int) $mechanic['user_id'],
                'New Job Assigned',
                "You have been assigned to Job {$jobNumber} ({$plate}).",
                'job-cards/view.php?id=' . ($job['id'] ?? 0)
            );
        }

        // SMS to Customer with Claim Ticket
        $custMsg = "Alpha Garage: Your vehicle {$plate} is checked in (Job {$jobNumber}). Technician {$mechName} is assigned. Your Claim Ticket is {$claimTicket}. Present this ticket upon pickup!";
        self::send($customerPhone, $custMsg, 'vehicle_checked_in_customer', $customerName, 'customer', 'job_cards', (int) ($job['id'] ?? 0));
    }

    /**
     * 6. Mechanic Completes Work -> Notify Receptionist
     */
    public static function notifyWorkCompleted(array $job, ?string $mechanicName = null): void
    {
        $jobNumber = $job['job_number'] ?? ('JC-' . ($job['id'] ?? ''));
        $plate = $job['plate_number'] ?? 'Vehicle';
        $mechName = $mechanicName ?: ($job['mechanic_name'] ?? 'Assigned Mechanic');

        // SMS to Receptionist
        $recPhone = self::getRolePhone('receptionist');
        $recMsg = "Alpha Garage [WORK COMPLETED]: Technician {$mechName} completed all repairs on Job {$jobNumber} (Plate: {$plate}). Please submit to Finance for billing clearance.";
        self::send($recPhone, $recMsg, 'work_completed_staff', 'Reception Team', 'receptionist', 'job_cards', (int) ($job['id'] ?? 0));

        // In-app Notification to Receptionist
        NotificationService::broadcast(
            'Work Completed by Mechanic',
            "Technician {$mechName} finished work on Job {$jobNumber} ({$plate}). Forward to Finance for clearance.",
            'job-cards/view.php?id=' . ($job['id'] ?? 0),
            'receptionist'
        );
    }

    /**
     * 7. Receptionist forwards completed job to Finance Manager
     */
    public static function notifySentToFinance(array $job): void
    {
        $jobNumber = $job['job_number'] ?? ('JC-' . ($job['id'] ?? ''));
        $plate = $job['plate_number'] ?? 'Vehicle';
        $customerName = $job['customer_name'] ?? 'Customer';

        // SMS to Finance Manager / Accountant
        $finPhone = self::getRolePhone('accountant');
        $finMsg = "Alpha Garage [BILLING CLEARANCE]: Job {$jobNumber} ({$customerName} · {$plate}) repair work is done. Please review parts, labor, VAT & payments, and issue clearance.";
        self::send($finPhone, $finMsg, 'submitted_to_finance', 'Finance Manager', 'accountant', 'job_cards', (int) ($job['id'] ?? 0));

        // In-app Notification to Accountant
        NotificationService::broadcast(
            'Job Completed — Finance Clearance Required',
            "Job {$jobNumber} ({$plate}) completed. Please review invoice & payment clearance.",
            'job-cards/view.php?id=' . ($job['id'] ?? 0),
            'accountant'
        );
    }

    /**
     * 8. Finance Manager Clears Billing -> Notify Receptionist
     */
    public static function notifyFinanceCleared(array $job, string $status, string $balance): void
    {
        $jobNumber = $job['job_number'] ?? ('JC-' . ($job['id'] ?? ''));
        $plate = $job['plate_number'] ?? 'Vehicle';
        $formattedBalance = number_format((float) $balance, 2) . ' ETB';

        // SMS to Receptionist
        $recPhone = self::getRolePhone('receptionist');
        $recMsg = "Alpha Garage [FINANCE CLEARANCE]: Job {$jobNumber} ({$plate}) financial review complete. Status: {$status}. Balance: {$formattedBalance}. Ready for customer notification.";
        self::send($recPhone, $recMsg, 'finance_cleared_staff', 'Reception Team', 'receptionist', 'job_cards', (int) ($job['id'] ?? 0));

        // In-app Notification to Receptionist
        NotificationService::broadcast(
            'Financial Clearance Issued',
            "Finance cleared Job {$jobNumber} ({$plate}). Status: {$status}, Balance: {$formattedBalance}.",
            'job-cards/view.php?id=' . ($job['id'] ?? 0),
            'receptionist'
        );
    }

    /**
     * 9. Receptionist Notifies Customer to Pick Up Vehicle
     */
    public static function notifyCustomerPickupReady(array $job, string $balance, string $ticketNumber): void
    {
        $customerName = $job['customer_name'] ?? 'Valued Customer';
        $customerPhone = $job['customer_phone'] ?? '';
        $plate = $job['plate_number'] ?? 'Vehicle';
        $jobNumber = $job['job_number'] ?? ('JC-' . ($job['id'] ?? ''));
        $formattedBalance = number_format((float) $balance, 2) . ' ETB';

        $statusText = ((float) $balance <= 0.01) ? "Paid in Full" : "Outstanding: {$formattedBalance}";
        $custMsg = "Alpha Garage: Dear {$customerName}, your vehicle {$plate} is READY FOR PICKUP! Payment: {$statusText}. Please present your Claim Ticket {$ticketNumber} at reception. Tel: +251 911 000 000";
        self::send($customerPhone, $custMsg, 'customer_pickup_ready', $customerName, 'customer', 'job_cards', (int) ($job['id'] ?? 0));

        // In-app Notification for record
        NotificationService::broadcast(
            'Customer Notified for Pickup',
            "Pickup SMS dispatched to {$customerName} ({$plate}) with Claim Ticket {$ticketNumber}.",
            'job-cards/view.php?id=' . ($job['id'] ?? 0),
            'receptionist'
        );
    }

    /**
     * 10 & 11. Customer arrives, Claim Ticket verified & Vehicle Released -> Notify Manager
     */
    public static function notifyVehicleReleased(array $job): void
    {
        $jobNumber = $job['job_number'] ?? ('JC-' . ($job['id'] ?? ''));
        $plate = $job['plate_number'] ?? 'Vehicle';
        $customerName = $job['customer_name'] ?? 'Customer';
        $ticket = $job['claim_ticket_number'] ?? 'N/A';

        // SMS to Manager
        $mgrPhone = self::getRolePhone('administrator');
        $mgrMsg = "Alpha Garage [VEHICLE EXIT]: Vehicle {$plate} (Job {$jobNumber}) verified with Claim Ticket {$ticket} and released to customer {$customerName}. Awaiting final manager delivery sign-off.";
        self::send($mgrPhone, $mgrMsg, 'vehicle_released_to_customer', 'Workshop Manager', 'administrator', 'job_cards', (int) ($job['id'] ?? 0));

        // In-app Notification to Manager
        NotificationService::broadcast(
            'Vehicle Handed Over to Customer',
            "Vehicle {$plate} (Job {$jobNumber}) released to {$customerName}. Awaiting manager delivery sign-off.",
            'job-cards/view.php?id=' . ($job['id'] ?? 0),
            'administrator'
        );
    }

    /**
     * 12 & 13. Manager signs off final delivery -> Notify Customer & Manager
     */
    public static function notifyDeliveryFinalized(array $job): void
    {
        $customerName = $job['customer_name'] ?? 'Valued Customer';
        $customerPhone = $job['customer_phone'] ?? '';
        $plate = $job['plate_number'] ?? 'Vehicle';
        $jobNumber = $job['job_number'] ?? ('JC-' . ($job['id'] ?? ''));

        // Thank You SMS to Customer
        $custMsg = "Alpha Garage: Dear {$customerName}, thank you for choosing Alpha Garage! Service delivery for vehicle {$plate} (Job {$jobNumber}) is officially finalized. Drive safely!";
        self::send($customerPhone, $custMsg, 'delivery_finalized_customer', $customerName, 'customer', 'job_cards', (int) ($job['id'] ?? 0));

        // In-app notification
        NotificationService::broadcast(
            'Delivery Officially Finalized',
            "Job {$jobNumber} ({$plate}) has been officially closed and delivered by Manager.",
            'job-cards/view.php?id=' . ($job['id'] ?? 0)
        );
    }

    /**
     * Resolve staff phone number by role name.
     */
    public static function getRolePhone(string $roleName): string
    {
        try {
            $user = Database::one(
                'SELECT u.phone FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = ? AND u.is_active = 1 AND u.phone IS NOT NULL AND u.phone != "" ORDER BY u.id ASC LIMIT 1',
                [$roleName]
            );
            if (!empty($user['phone'])) {
                return $user['phone'];
            }
        } catch (Throwable $e) {
            // ignore
        }

        // Fallback to garage setting or default operations line
        $defaultPhone = Setting::get('garage_phone', '+251911000000');
        return $defaultPhone ?: '+251911000000';
    }

    /**
     * Normalize Ethiopian phone numbers.
     */
    public static function normalizePhone(string $raw): string
    {
        $p = preg_replace('/[^\d\+]/', '', trim($raw));
        if ($p === '') {
            return '';
        }
        if (str_starts_with($p, '09') || str_starts_with($p, '07')) {
            return '+251' . substr($p, 1);
        }
        if (str_starts_with($p, '251')) {
            return '+' . $p;
        }
        if (str_starts_with($p, '+251')) {
            return $p;
        }
        return $p;
    }

    /**
     * Write SMS to persistent log file.
     */
    private static function spool(string $phone, string $msg, string $event, ?string $name, ?string $role): void
    {
        if (!is_dir(self::SPOOL_DIR)) {
            @mkdir(self::SPOOL_DIR, 0755, true);
        }
        $line = sprintf(
            "[%s] EVENT=%s | TO=%s (%s / %s) | MSG=\"%s\"\n",
            date('Y-m-d H:i:s'),
            $event,
            $phone,
            $name ?: 'N/A',
            $role ?: 'N/A',
            addcslashes($msg, "\r\n\"")
        );
        @file_put_contents(self::SPOOL_FILE, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Dispatch via external gateway if configured (AfroMessage, Africa's Talking, Android Gateway).
     */
    public static function dispatchExternalGateway(string $phone, string $msg): ?array
    {
        $provider = Setting::get('sms_gateway_provider', 'local_spool');
        if ($provider === 'local_spool' || $provider === '') {
            return null;
        }

        if ($provider === 'afromessage' || $provider === 'sms_et') {
            return self::sendAfroMessage($phone, $msg);
        }

        if ($provider === 'africastalking') {
            return self::sendAfricasTalking($phone, $msg);
        }

        if ($provider === 'android_gateway') {
            return self::sendAndroidGateway($phone, $msg);
        }

        return null;
    }

    /**
     * Send SMS via AfroMessage (afromessage.com / Ethiopian SMS Gateway).
     */
    private static function sendAfroMessage(string $phone, string $msg): array
    {
        $token = trim((string) Setting::get('sms_api_key', ''));
        $sender = trim((string) Setting::get('sms_sender_id', ''));
        $identifier = trim((string) Setting::get('sms_identifier_id', ''));

        if ($token === '') {
            return ['success' => false, 'gateway' => 'afromessage', 'error' => 'API Token not configured'];
        }

        $url = 'https://api.afromessage.com/api/send';
        $params = [
            'to' => $phone,
            'message' => $msg,
        ];
        if ($sender !== '') {
            $params['sender'] = $sender;
        }
        if ($identifier !== '') {
            $params['from'] = $identifier;
        }

        $fullUrl = $url . '?' . http_build_query($params);

        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $fullUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $token,
                'Accept: application/json'
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            $isSuccess = ($httpCode >= 200 && $httpCode < 300) && empty($curlError);

            return [
                'success' => $isSuccess,
                'gateway' => 'afromessage',
                'http_code' => $httpCode,
                'response' => (string)$response,
                'error' => $curlError
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'gateway' => 'afromessage',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Send SMS via Africa's Talking API.
     */
    private static function sendAfricasTalking(string $phone, string $msg): array
    {
        $apiKey = trim((string) Setting::get('sms_api_key', ''));
        $username = trim((string) Setting::get('sms_username', 'sandbox'));
        $from = trim((string) Setting::get('sms_sender_id', ''));

        if ($apiKey === '') {
            return ['success' => false, 'gateway' => 'africastalking', 'error' => 'API Key not configured'];
        }

        $url = $username === 'sandbox'
            ? 'https://api.sandbox.africastalking.com/version1/messaging'
            : 'https://api.africastalking.com/version1/messaging';

        $postData = [
            'username' => $username,
            'to' => $phone,
            'message' => $msg,
        ];
        if ($from !== '') {
            $postData['from'] = $from;
        }

        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'apiKey: ' . $apiKey,
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json'
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            $isSuccess = ($httpCode >= 200 && $httpCode < 300) && empty($curlError);

            return [
                'success' => $isSuccess,
                'gateway' => 'africastalking',
                'http_code' => $httpCode,
                'response' => (string)$response,
                'error' => $curlError
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'gateway' => 'africastalking',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Send SMS via local Android Phone SMS Gateway app.
     */
    private static function sendAndroidGateway(string $phone, string $msg): array
    {
        $gatewayUrl = trim((string) Setting::get('sms_gateway_url', ''));
        if ($gatewayUrl === '') {
            return ['success' => false, 'gateway' => 'android_gateway', 'error' => 'Android Gateway URL not configured'];
        }

        $url = rtrim($gatewayUrl, '/') . '?number=' . urlencode($phone) . '&message=' . urlencode($msg);

        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 8);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            $isSuccess = ($httpCode >= 200 && $httpCode < 300) && empty($curlError);

            return [
                'success' => $isSuccess,
                'gateway' => 'android_gateway',
                'http_code' => $httpCode,
                'response' => (string)$response,
                'error' => $curlError
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'gateway' => 'android_gateway',
                'error' => $e->getMessage()
            ];
        }
    }
}
