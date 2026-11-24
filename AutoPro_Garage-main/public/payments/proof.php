<?php
declare(strict_types=1);

require __DIR__ . '/../../app/bootstrap.php';

require_login();

$u = current_user();
$subId = get_int('id');

if ($subId <= 0) {
    abort(404, 'Invalid payment submission ID.');
}

$sub = ManualPaymentService::getSubmission($subId);
if (!$sub) {
    abort(404, 'Payment submission not found.');
}

// Authorization check (Anti-IDOR)
$isStaff = (($u['role_name'] ?? '') !== 'customer') && can_any('payments.verify', 'payments.view');
if (!$isStaff) {
    $customer = CustomerIdentityService::resolveCustomer($u);
    $linkedCustIds = CustomerIdentityService::getLinkedCustomerIds((int) $u['id']);
    if (!in_array((int) $customer['id'], $linkedCustIds, true)) {
        $linkedCustIds[] = (int) $customer['id'];
    }
    if (!in_array((int)$sub['customer_id'], $linkedCustIds, true)) {
        AuditLogger::logSafe('idor_proof_view_blocked', 'payments', $subId, null, [
            'actor_user_id' => $u['id'],
            'actor_customer_id' => $customer['id'],
            'target_submission_customer' => $sub['customer_id'],
        ], 'denied');
        abort(403, 'You do not have permission to view this payment proof.');
    }
}

if (empty($sub['proof_file_path'])) {
    abort(404, 'No proof attachment exists for this payment submission.');
}

$storageDir = ManualPaymentService::getStorageDir();
$filePath = ManualPaymentService::getProofFullPath($sub['proof_file_path']);

// Path traversal defense
$realStorage = realpath($storageDir);
$realFile = realpath($filePath);

if ($realStorage === false || $realFile === false || !str_starts_with($realFile, $realStorage)) {
    abort(404, 'Proof file not found or path invalid.');
}

if (!is_file($realFile) || !is_readable($realFile)) {
    abort(404, 'Proof file is unreadable or missing from storage.');
}

$detectedMime = ManualPaymentService::detectMimeType($realFile);

if (!isset(ManualPaymentService::ALLOWED_MIME_TYPES[$detectedMime])) {
    abort(415, 'Unsupported or unsafe file media type.');
}

$ext = ManualPaymentService::ALLOWED_MIME_TYPES[$detectedMime];
$safeFilename = preg_replace('/[^A-Za-z0-9_-]/', '_', $sub['submission_number']) . '.' . $ext;
$fileSize = filesize($realFile);

// Clear output buffers
while (ob_get_level() > 0) {
    ob_end_clean();
}

// Security & Caching Headers
header('Content-Type: ' . $detectedMime);
header('Content-Length: ' . $fileSize);
header('Content-Disposition: inline; filename="' . $safeFilename . '"');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
header('Cache-Control: private, max-age=1800');

readfile($realFile);
exit;
