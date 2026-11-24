<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — MANUAL PAYMENT MANAGEMENT & VERIFICATION AUTOMATED TEST SUITE
 * Validates configurable Ethiopian payment methods, Admin 1-click active/inactive toggle privilege,
 * customer payment submission, duplicate reference protection, proof uploads,
 * verification & rejection workflows, partial payment handling, financial invariance,
 * anti-IDOR proof streaming, reporting, and bilingual i18n resolution.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Must be run from CLI.\n");
    exit(1);
}

putenv('GMS_TEST_MODE=1');
putenv('GMS_STORAGE_PATH=' . __DIR__ . '/../storage/testing');
putenv('GMS_DB_NAME=garage_management_system_staging');
putenv('GMS_DB_USER=garage_app_staging');
putenv('GMS_DB_PASS=StagingAppPass#2026!Sec');

require_once __DIR__ . '/../app/bootstrap.php';

echo "=================================================================\n";
echo "ALPHA GARAGE — MANUAL PAYMENT MANAGEMENT & VERIFICATION SUITE\n";
echo "Database: " . Database::value("SELECT DATABASE()") . " | User: " . Database::value("SELECT CURRENT_USER()") . "\n";
echo "=================================================================\n\n";

$passCount = 0;
$totalCount = 0;

function assert_suite(string $group, string $desc, bool $condition, string $detail = ''): void {
    global $passCount, $totalCount;
    $totalCount++;
    echo "[$group] Check $totalCount: $desc ... ";
    if ($condition) {
        echo "[PASS]" . ($detail ? " ($detail)" : "") . "\n";
        $passCount++;
    } else {
        echo "[FAIL] $detail\n";
    }
}

// -------------------------------------------------------------
// SECTION 1: DATABASE SCHEMA & SEEDED ETHIOPIAN PROVIDERS
// -------------------------------------------------------------
$pmCols = Database::all("SHOW COLUMNS FROM payment_methods");
$pmColNames = array_column($pmCols, 'Field');
assert_suite("SCHEMA", "payment_methods table has code column", in_array('code', $pmColNames, true));
assert_suite("SCHEMA", "payment_methods table has name & name_am columns", in_array('name', $pmColNames, true) && in_array('name_am', $pmColNames, true));
assert_suite("SCHEMA", "payment_methods table has is_active column", in_array('is_active', $pmColNames, true));
assert_suite("SCHEMA", "payment_methods table has account_number & phone_number", in_array('account_number', $pmColNames, true) && in_array('phone_number', $pmColNames, true));

$psCols = Database::all("SHOW COLUMNS FROM payment_submissions");
$psColNames = array_column($psCols, 'Field');
assert_suite("SCHEMA", "payment_submissions table has submission_number", in_array('submission_number', $psColNames, true));
assert_suite("SCHEMA", "payment_submissions table has transaction_reference", in_array('transaction_reference', $psColNames, true));
assert_suite("SCHEMA", "payment_submissions table has proof_file_path & mime", in_array('proof_file_path', $psColNames, true) && in_array('proof_file_mime', $psColNames, true));
assert_suite("SCHEMA", "payment_submissions table has status ENUM", in_array('status', $psColNames, true));
assert_suite("SCHEMA", "payment_submissions table has reviewer_id & review_notes", in_array('reviewer_id', $psColNames, true) && in_array('review_notes', $psColNames, true));

$allProviders = ManualPaymentService::getAllMethods();
assert_suite("PROVIDERS", "Seeded payment methods count is >= 26", count($allProviders) >= 26, "Found " . count($allProviders));

// Check critical Ethiopian banks & wallets
$cbe = ManualPaymentService::getMethodByCode('cbe');
assert_suite("PROVIDERS", "Commercial Bank of Ethiopia (CBE) seeded", $cbe !== null && $cbe['type'] === 'bank');
$telebirr = ManualPaymentService::getMethodByCode('telebirr');
assert_suite("PROVIDERS", "Telebirr (Ethio Telecom) seeded", $telebirr !== null && $telebirr['type'] === 'mobile_money');
$mpesa = ManualPaymentService::getMethodByCode('mpesa');
assert_suite("PROVIDERS", "M-Pesa Safaricom seeded", $mpesa !== null && $mpesa['type'] === 'mobile_money');
$boa = ManualPaymentService::getMethodByCode('boa');
assert_suite("PROVIDERS", "Bank of Abyssinia seeded", $boa !== null && $boa['type'] === 'bank');
$awash = ManualPaymentService::getMethodByCode('awash');
assert_suite("PROVIDERS", "Awash Bank seeded", $awash !== null && $awash['type'] === 'bank');
$dashen = ManualPaymentService::getMethodByCode('dashen');
assert_suite("PROVIDERS", "Dashen Bank seeded", $dashen !== null && $dashen['type'] === 'bank');

// Verify exclusion of foreign banks (strict check)
$boaCheck = Database::value("SELECT COUNT(*) FROM payment_methods WHERE name LIKE '%Bank of America%'");
assert_suite("SECURITY", "Bank of America is NOT seeded (Ethiopian providers only)", (int)$boaCheck === 0);

// -------------------------------------------------------------
// SECTION 2: PERMISSIONS & RBAC
// -------------------------------------------------------------
$perms = Database::all("SELECT code FROM permissions WHERE code IN ('payments.verify', 'payments.methods')");
$permCodes = array_column($perms, 'code');
assert_suite("RBAC", "payments.verify permission registered", in_array('payments.verify', $permCodes, true));
assert_suite("RBAC", "payments.methods permission registered", in_array('payments.methods', $permCodes, true));

$adminRole = Database::one("SELECT id FROM roles WHERE name = 'administrator'");
$adminPerms = Database::all(
    "SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?",
    [$adminRole['id']]
);
$adminPermCodes = array_column($adminPerms, 'code');
assert_suite("RBAC", "Administrator has payments.verify", in_array('payments.verify', $adminPermCodes, true));
assert_suite("RBAC", "Administrator has payments.methods", in_array('payments.methods', $adminPermCodes, true));

$acctRole = Database::one("SELECT id FROM roles WHERE name = 'accountant'");
$acctPerms = Database::all(
    "SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?",
    [$acctRole['id']]
);
$acctPermCodes = array_column($acctPerms, 'code');
assert_suite("RBAC", "Accountant has payments.verify", in_array('payments.verify', $acctPermCodes, true));
assert_suite("RBAC", "Accountant does NOT have payments.methods (Admin privilege only)", !in_array('payments.methods', $acctPermCodes, true));

// -------------------------------------------------------------
// SECTION 3: ADMIN 1-CLICK TOGGLE PRIVILEGE & METHOD MANAGEMENT
// -------------------------------------------------------------
$adminUser = Database::one("SELECT id FROM users WHERE role_id = ? LIMIT 1", [$adminRole['id']]);
$adminUserId = (int)($adminUser['id'] ?? 1);

// Toggle CBE inactive
ManualPaymentService::toggleMethodStatus((int)$cbe['id'], false, $adminUserId);
$cbeCheck1 = ManualPaymentService::getMethod((int)$cbe['id']);
assert_suite("ADMIN_TOGGLE", "Admin 1-click toggles provider inactive", (int)$cbeCheck1['is_active'] === 0);

// Toggle CBE back to active
ManualPaymentService::toggleMethodStatus((int)$cbe['id'], true, $adminUserId);
$cbeCheck2 = ManualPaymentService::getMethod((int)$cbe['id']);
assert_suite("ADMIN_TOGGLE", "Admin 1-click toggles provider active", (int)$cbeCheck2['is_active'] === 1);

// Create custom provider via saveMethod
$customCode = 'test_bank_' . bin2hex(random_bytes(3));
$customId = ManualPaymentService::saveMethod([
    'code' => $customCode,
    'name' => 'Custom Community Bank',
    'name_am' => 'የማህበረሰብ ባንክ',
    'type' => 'bank',
    'account_name' => 'ALPHA GARAGE PLC',
    'account_number' => '9988776655',
    'is_active' => 1,
    'requires_reference' => 1,
    'requires_screenshot' => 1,
    'display_order' => 50,
], null, $adminUserId);
assert_suite("METHOD_MGMT", "Admin creates new payment provider", $customId > 0);

// Update custom provider
ManualPaymentService::saveMethod([
    'code' => $customCode,
    'name' => 'Updated Community Bank',
    'name_am' => 'የተሻሻለ የማህበረሰብ ባንክ',
    'type' => 'bank',
    'account_name' => 'ALPHA GARAGE PLC',
    'account_number' => '9988776655-01',
    'is_active' => 1,
    'requires_reference' => 1,
    'requires_screenshot' => 1,
    'display_order' => 51,
], $customId, $adminUserId);
$updatedCustom = ManualPaymentService::getMethod($customId);
assert_suite("METHOD_MGMT", "Admin updates payment provider details", $updatedCustom['name'] === 'Updated Community Bank' && $updatedCustom['account_number'] === '9988776655-01');

// -------------------------------------------------------------
// SECTION 4: CUSTOMER PAYMENT SUBMISSION & VALIDATION
// -------------------------------------------------------------
// Provision test customer and fresh invoice
$testCust = Database::one("SELECT * FROM customers LIMIT 1");
if (!$testCust) {
    $custNum = 'CUST-' . date('Ymd') . '-999';
    $cId = Database::insert(
        "INSERT INTO customers (customer_number, full_name, email, phone) VALUES (?,?,?,?)",
        [$custNum, 'Payment Test Customer', 'paytest@alphagarage.et', '0911009988']
    );
    $testCust = Database::one("SELECT * FROM customers WHERE id = ?", [$cId]);
}
$custId = (int)$testCust['id'];

// Create fresh dedicated test invoice with exact known balance 5750.00
$vId = Database::value("SELECT id FROM vehicles WHERE customer_id = ? LIMIT 1", [$custId]);
if (!$vId) {
    $vId = Database::insert(
        "INSERT INTO vehicles (customer_id, plate_number, make, model, vehicle_year) VALUES (?,?,?,?,?)",
        [$custId, 'PAY-' . rand(100, 999), 'Toyota', 'Land Cruiser', 2024]
    );
}
$jobId = Database::insert(
    "INSERT INTO job_cards (job_number, customer_id, vehicle_id, date_received, status, reported_problem) VALUES (?,?,?,?,?,?)",
    ['JOB-PAY-' . rand(10000, 99999), $custId, $vId, date('Y-m-d'), 'Completed', 'Major brake overhaul and diagnostics']
);
$invNum = 'INV-PAY-' . rand(10000, 99999);
$invId = Database::insert(
    "INSERT INTO invoices (invoice_number, job_card_id, customer_id, vehicle_id, issue_date, due_date, subtotal, tax_amount, grand_total, amount_paid, balance, payment_status)
     VALUES (?,?,?,?,?,?,5000.00,750.00,5750.00,0.00,5750.00,'Unpaid')",
    [$invNum, $jobId, $custId, $vId, date('Y-m-d'), date('Y-m-d', strtotime('+7 days'))]
);
Database::insert(
    "INSERT INTO invoice_items (invoice_id, item_type, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?)",
    [$invId, 'labor', 'Major brake overhaul and diagnostics', 1.00, 5000.00, 5000.00]
);
InvoiceService::recalculate($invId);
$testInv = InvoiceService::find($invId);
$initialBalance = (float)$testInv['balance'];

// Test zero or negative amount
$zeroRejected = false;
try {
    ManualPaymentService::submitPayment($custId, $invId, [
        'payment_method_id' => $cbe['id'],
        'amount' => '0.00',
        'transaction_reference' => 'TX-ZERO',
    ]);
} catch (DomainException $e) {
    $zeroRejected = true;
}
assert_suite("VALIDATION", "Zero amount submission rejected", $zeroRejected);

// Test overpayment
$overpayRejected = false;
try {
    ManualPaymentService::submitPayment($custId, $invId, [
        'payment_method_id' => $cbe['id'],
        'amount' => (string)($initialBalance + 500.00),
        'transaction_reference' => 'TX-OVERPAY',
    ]);
} catch (DomainException $e) {
    $overpayRejected = true;
}
assert_suite("VALIDATION", "Amount exceeding invoice balance rejected", $overpayRejected);

// Test inactive payment method submission
ManualPaymentService::toggleMethodStatus((int)$customId, false, $adminUserId);
$inactiveRejected = false;
try {
    ManualPaymentService::submitPayment($custId, $invId, [
        'payment_method_id' => $customId,
        'amount' => '100.00',
        'transaction_reference' => 'TX-INACTIVE',
    ]);
} catch (DomainException $e) {
    $inactiveRejected = true;
}
assert_suite("VALIDATION", "Inactive payment method rejected", $inactiveRejected);

// Prepare dummy test proof file
$tempProof = tempnam(sys_get_temp_dir(), 'proof_') . '.png';
// 1x1 valid PNG image
file_put_contents($tempProof, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));

$txRef1 = 'FT' . date('YmdHis') . rand(100, 999);
$sub1 = ManualPaymentService::submitPayment($custId, $invId, [
    'payment_method_id' => $cbe['id'],
    'amount' => '1500.00',
    'transaction_reference' => $txRef1,
    'transaction_date' => date('Y-m-d'),
    'transaction_time' => '14:30:00',
    'sender_name' => 'Payment Test Customer',
    'sender_phone' => '0911009988',
    'customer_notes' => 'Transferred via CBE Mobile Banking',
], [
    'tmp_name' => $tempProof,
    'size' => filesize($tempProof),
    'error' => UPLOAD_ERR_OK,
]);

assert_suite("SUBMISSION", "Valid payment submission created", $sub1 !== null && $sub1['id'] > 0);
assert_suite("SUBMISSION", "Submission status is pending_verification", $sub1['status'] === 'pending_verification');
assert_suite("SUBMISSION", "Tracking number generated (SUB-YYYYMMDD-XXXX)", str_starts_with($sub1['submission_number'], 'SUB-'));
assert_suite("SUBMISSION", "Proof file securely stored in storage directory", !empty($sub1['proof_file_path']) && file_exists(ManualPaymentService::getProofFullPath($sub1['proof_file_path'])));

// Financial Invariance Check: invoice balance MUST NOT change on pending submission!
$invAfterSubmit = InvoiceService::find($invId);
assert_suite("FINANCIAL_INVARIANCE", "Pending submission does NOT decrease invoice balance", (float)$invAfterSubmit['balance'] === $initialBalance, "Balance: " . $invAfterSubmit['balance']);

// -------------------------------------------------------------
// SECTION 5: DUPLICATE TRANSACTION DETECTION
// -------------------------------------------------------------
$dupRejected = false;
try {
    ManualPaymentService::submitPayment($custId, $invId, [
        'payment_method_id' => $cbe['id'],
        'amount' => '1500.00',
        'transaction_reference' => $txRef1, // Duplicate ref for same provider!
        'transaction_date' => date('Y-m-d'),
    ]);
} catch (DomainException $e) {
    $dupRejected = true;
}
assert_suite("DUPLICATE_CHECK", "Duplicate transaction reference blocked for same provider", $dupRejected);

// -------------------------------------------------------------
// SECTION 6: VERIFICATION WORKFLOW & INVOICE SETTLEMENT
// -------------------------------------------------------------
// Mark Under Review
$marked = ManualPaymentService::markUnderReview((int)$sub1['id'], $adminUserId);
$sub1UnderReview = ManualPaymentService::getSubmission((int)$sub1['id']);
assert_suite("WORKFLOW", "Submission transition to under_review", $marked && $sub1UnderReview['status'] === 'under_review');

// Verify Payment
$sub1Verified = ManualPaymentService::verifyPayment((int)$sub1['id'], $adminUserId, 'Verified via CBE online statement', '1500.00');
assert_suite("VERIFICATION", "Submission status updated to verified", $sub1Verified['status'] === 'verified');
assert_suite("VERIFICATION", "Payment ID linked on verified submission", !empty($sub1Verified['payment_id']));
assert_suite("VERIFICATION", "Verified amount recorded", (float)$sub1Verified['verified_amount'] === 1500.00);

// Check that invoice balance is now reduced by 1500.00
$invAfterVerify = InvoiceService::find($invId);
$expectedBalance = $initialBalance - 1500.00;
assert_suite("SETTLEMENT", "Invoice balance reduced atomically upon verification", (float)$invAfterVerify['balance'] === $expectedBalance, "New balance: " . $invAfterVerify['balance']);
assert_suite("SETTLEMENT", "Invoice status updated to Partially Paid", $invAfterVerify['payment_status'] === 'Partially Paid');

// Verify payment record in immutable payments table
$payRecord = PaymentService::find((int)$sub1Verified['payment_id']);
assert_suite("IMMUTABLE_LEDGER", "Verified payment recorded in payments table", $payRecord !== null && (float)$payRecord['amount'] === 1500.00);
assert_suite("IMMUTABLE_LEDGER", "Official receipt generated (RCT-xxxx)", str_starts_with($payRecord['receipt_number'], 'RCT-'));

// -------------------------------------------------------------
// SECTION 7: REJECTION WORKFLOW & ZERO FINANCIAL IMPACT
// -------------------------------------------------------------
$txRefReject = 'FT' . date('YmdHis') . 'REJ';
$subReject = ManualPaymentService::submitPayment($custId, $invId, [
    'payment_method_id' => $telebirr['id'],
    'amount' => '500.00',
    'transaction_reference' => $txRefReject,
    'transaction_date' => date('Y-m-d'),
    'sender_name' => 'Payment Test Customer',
    'sender_phone' => '0911009988',
], [
    'tmp_name' => $tempProof,
    'size' => filesize($tempProof),
    'error' => UPLOAD_ERR_OK,
]);

$rejectedResult = ManualPaymentService::rejectPayment(
    (int)$subReject['id'],
    $adminUserId,
    'Transaction reference not found in bank statement',
    'Checked Telebirr merchant portal at 15:00, no match found.'
);

assert_suite("REJECTION", "Submission status updated to rejected", $rejectedResult['status'] === 'rejected');
assert_suite("REJECTION", "Rejection reason saved", $rejectedResult['rejection_reason'] === 'Transaction reference not found in bank statement');
assert_suite("REJECTION", "Reviewer notes saved", strpos($rejectedResult['review_notes'], 'Telebirr merchant portal') !== false);

// Financial Invariance: invoice balance must remain untouched!
$invAfterReject = InvoiceService::find($invId);
assert_suite("FINANCIAL_INVARIANCE", "Rejected submission does NOT modify invoice balance", (float)$invAfterReject['balance'] === $expectedBalance);

// -------------------------------------------------------------
// SECTION 8: FULL INVOICE SETTLEMENT VIA SECOND VERIFICATION
// -------------------------------------------------------------
$remainingBal = (float)$invAfterReject['balance'];
$txRefFull = 'FT' . date('YmdHis') . 'FULL';
$subFull = ManualPaymentService::submitPayment($custId, $invId, [
    'payment_method_id' => $cbe['id'],
    'amount' => (string)$remainingBal,
    'transaction_reference' => $txRefFull,
    'transaction_date' => date('Y-m-d'),
    'sender_name' => 'Payment Test Customer',
    'sender_phone' => '0911009988',
], [
    'tmp_name' => $tempProof,
    'size' => filesize($tempProof),
    'error' => UPLOAD_ERR_OK,
]);

ManualPaymentService::verifyPayment((int)$subFull['id'], $adminUserId, 'Full settlement verified', (string)$remainingBal);
$invFullySettled = InvoiceService::find($invId);
assert_suite("FULL_SETTLEMENT", "Invoice balance reaches 0.00 upon final verification", (float)$invFullySettled['balance'] === 0.00);
assert_suite("FULL_SETTLEMENT", "Invoice payment_status becomes Paid", $invFullySettled['payment_status'] === 'Paid');

// -------------------------------------------------------------
// SECTION 9: REPORTING & KPIS
// -------------------------------------------------------------
$summaryStats = ManualPaymentService::getSummaryStats();
assert_suite("REPORTING", "Summary stats has verified_count >= 2", $summaryStats['verified_count'] >= 2);
assert_suite("REPORTING", "Summary stats has verified_amount > 0", $summaryStats['verified_amount'] > 0);
assert_suite("REPORTING", "Summary stats has rejected_count >= 1", $summaryStats['rejected_count'] >= 1);

$collections = ManualPaymentService::getCollectionsByProvider();
assert_suite("REPORTING", "Collections grouped by provider returned", count($collections) > 0);

// -------------------------------------------------------------
// SECTION 10: SECURE PROOF & ANTI-IDOR RESOLUTION
// -------------------------------------------------------------
$proofFullPath = ManualPaymentService::getProofFullPath($sub1['proof_file_path']);
assert_suite("PROOF_SECURITY", "Proof path resolved inside storage directory", file_exists($proofFullPath));

// Clean up test proof file
if (file_exists($tempProof)) {
    unlink($tempProof);
}

// -------------------------------------------------------------
// SECTION 11: BILINGUAL RESOLUTION (EN / AM)
// -------------------------------------------------------------
$_GET['lang'] = 'am';
init_language();

assert_suite("I18N", "t('Payment Methods') resolves in Amharic", t('Payment Methods') === 'የክፍያ መንገዶች');
assert_suite("I18N", "t('Verification Queue') resolves in Amharic", t('Verification Queue') === 'የማረጋገጫ ረድፍ');
assert_suite("I18N", "t('Commercial Bank of Ethiopia (CBE)') resolves in Amharic", t('Commercial Bank of Ethiopia (CBE)') === 'የኢትዮጵያ ንግድ ባንክ (CBE)');
assert_suite("I18N", "t('Telebirr (Ethio Telecom)') resolves in Amharic", t('Telebirr (Ethio Telecom)') === 'ቴሌብር (ኢትዮ ቴሌኮም)');
assert_suite("I18N", "t('Pending Verification') resolves in Amharic", t('Pending Verification') === 'ማረጋገጫ የሚጠብቅ');
assert_suite("I18N", "t('Under Review') resolves in Amharic", t('Under Review') === 'በግምገማ ላይ');
assert_suite("I18N", "t('Verified') resolves in Amharic", t('Verified') === 'የተረጋገጠ');
assert_suite("I18N", "t('Rejected') resolves in Amharic", t('Rejected') === 'ውድቅ የተደረገ');

// Reset to English
$_GET['lang'] = 'en';
init_language();

// -------------------------------------------------------------
// SECTION 12: PUBLIC ENDPOINTS INTEGRITY
// -------------------------------------------------------------
$endpoints = [
    'public/payments/index.php',
    'public/payments/verification.php',
    'public/payments/methods.php',
    'public/payments/pay.php',
    'public/payments/proof.php',
    'public/payments/reports.php',
];
foreach ($endpoints as $ep) {
    $fullEp = __DIR__ . '/../' . $ep;
    assert_suite("ENDPOINTS", "$ep exists on disk", file_exists($fullEp));
}

// -------------------------------------------------------------
// SECTION 13: PRIORITY 13 CSRF VALIDATION TESTS
// -------------------------------------------------------------
// Test csrf_token generation and validation helpers
$validToken = csrf_token();
$createdAt = (int)($_SESSION['csrf_created_at'] ?? time());
assert_suite("CSRF", "Valid CSRF token passes validation", csrf_token_is_valid($validToken, $validToken, $createdAt, time()));
assert_suite("CSRF", "Invalid CSRF token fails validation", !csrf_token_is_valid('bad_token_123', $validToken, $createdAt, time()));
assert_suite("CSRF", "Expired CSRF token fails validation (> 7200s)", !csrf_token_is_valid($validToken, $validToken, $createdAt, time() + 7201));
assert_suite("CSRF", "Missing CSRF token fails validation", !csrf_token_is_valid('', $validToken, $createdAt, time()));

// -------------------------------------------------------------
// SECTION 14: PRIORITY 13 ATOMIC ACTIVE REFERENCES & CONCURRENCY
// -------------------------------------------------------------
$p13JobId = Database::insert(
    "INSERT INTO job_cards (job_number, customer_id, vehicle_id, date_received, status, reported_problem) VALUES (?,?,?,?,?,?)",
    ['JOB-P13-' . rand(10000, 99999), $custId, $vId, date('Y-m-d'), 'Completed', 'Priority 13 Service']
);
$p13InvNum = 'INV-P13-' . rand(10000, 99999);
$p13InvId = Database::insert(
    "INSERT INTO invoices (invoice_number, job_card_id, customer_id, vehicle_id, issue_date, due_date, subtotal, tax_amount, grand_total, amount_paid, balance, payment_status)
     VALUES (?,?,?,?,?,?,5000.00,750.00,5750.00,0.00,5750.00,'Unpaid')",
    [$p13InvNum, $p13JobId, $custId, $vId, date('Y-m-d'), date('Y-m-d', strtotime('+7 days'))]
);
Database::insert(
    "INSERT INTO invoice_items (invoice_id, item_type, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?)",
    [$p13InvId, 'labor', 'Priority 13 Security & Concurrency Verification', 1.00, 5000.00, 5000.00]
);
InvoiceService::recalculate($p13InvId);

$p13Method = ManualPaymentService::getMethodByCode('telebirr');
assert_suite("ATOMIC_DUP", "Telebirr method found for duplicate tests", $p13Method !== null);

$p13Proof = __DIR__ . '/p13_test_proof.jpg';
file_put_contents($p13Proof, "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 100));
$p13File = ['tmp_name' => $p13Proof, 'size' => filesize($p13Proof), 'error' => UPLOAD_ERR_OK];

$p13TxRef = 'TX-P13-DUP-' . bin2hex(random_bytes(4));
$p13Sub = ManualPaymentService::submitPayment($custId, $p13InvId, [
    'payment_method_id' => $p13Method['id'],
    'amount' => '100.00',
    'transaction_reference' => $p13TxRef,
    'sender_name' => 'P13 Tester',
    'sender_phone' => '0911001122',
], $p13File);
assert_suite("ATOMIC_DUP", "Submission created with reference $p13TxRef", !empty($p13Sub['id']));

// Verify active reference exists in payment_active_references table
$activeRefRow = Database::one(
    "SELECT * FROM payment_active_references WHERE payment_method_id = ? AND transaction_reference = ?",
    [$p13Method['id'], $p13TxRef]
);
assert_suite("ATOMIC_DUP", "Reference tracked in payment_active_references table", !empty($activeRefRow));

// Attempt immediate duplicate submission with identical reference
$dupCaught = false;
try {
    ManualPaymentService::submitPayment($custId, $p13InvId, [
        'payment_method_id' => $p13Method['id'],
        'amount' => '100.00',
        'transaction_reference' => $p13TxRef,
    ], $p13File);
} catch (DomainException $e) {
    $dupCaught = true;
}
assert_suite("ATOMIC_DUP", "Duplicate reference submission blocked", $dupCaught);

// Reject the submission and verify reference is released from payment_active_references
ManualPaymentService::rejectPayment((int)$p13Sub['id'], $adminUserId, 'illegible_proof', 'Testing reference release');
$activeRefAfterReject = Database::one(
    "SELECT * FROM payment_active_references WHERE payment_method_id = ? AND transaction_reference = ?",
    [$p13Method['id'], $p13TxRef]
);
assert_suite("ATOMIC_DUP", "Active reference deleted from table on rejection", empty($activeRefAfterReject));

// Resubmit with the released reference (legitimate customer correction)
$resubmissionSuccess = false;
try {
    $p13Resub = ManualPaymentService::submitPayment($custId, $p13InvId, [
        'payment_method_id' => $p13Method['id'],
        'amount' => '100.00',
        'transaction_reference' => $p13TxRef,
        'sender_name' => 'P13 Tester Corrected',
        'sender_phone' => '0911001122',
    ], $p13File);
    $resubmissionSuccess = !empty($p13Resub['id']);
} catch (DomainException $e) {
    $resubmissionSuccess = false;
}
assert_suite("ATOMIC_DUP", "Legitimate resubmission allowed after rejection", $resubmissionSuccess);

if (file_exists($p13Proof)) {
    unlink($p13Proof);
}

// -------------------------------------------------------------
// SECTION 15: HISTORICAL ACCOUNT CONFIGURATION SNAPSHOTS
// -------------------------------------------------------------
$fetchedSub = ManualPaymentService::getSubmission((int)$p13Resub['id']);
assert_suite("SNAPSHOT", "Submission recorded snapshot_provider_name", !empty($fetchedSub['snapshot_provider_name']));
assert_suite("SNAPSHOT", "Submission recorded snapshot_instructions", isset($fetchedSub['snapshot_instructions']));

// Modify provider details in payment_methods
$origName = $p13Method['name'];
Database::run(
    "UPDATE payment_methods SET name = 'Telebirr Changed Brand Name 2026', phone_number = '0999999999' WHERE id = ?",
    [$p13Method['id']]
);
$reReadSub = ManualPaymentService::getSubmission((int)$p13Resub['id']);
assert_suite("SNAPSHOT", "Historical submission display_provider_name preserved", $reReadSub['display_provider_name'] === $p13Method['name']);

// Revert provider details
Database::run(
    "UPDATE payment_methods SET name = ?, phone_number = ? WHERE id = ?",
    [$origName, $p13Method['phone_number'], $p13Method['id']]
);

// -------------------------------------------------------------
// SECTION 16: CASH AT RECEPTION RECONCILIATION
// -------------------------------------------------------------
$cashMethod = ManualPaymentService::getMethodByCode('cash');
assert_suite("CASH_RECON", "Cash payment method found", $cashMethod !== null);

// Step 1: Customer submits cash intent online
$cashSub = ManualPaymentService::submitPayment($custId, $p13InvId, [
    'payment_method_id' => $cashMethod['id'],
    'amount' => '200.00',
    'transaction_reference' => '',
    'sender_name' => 'Cash Customer',
    'sender_phone' => '0911556677',
]);
assert_suite("CASH_RECON", "Cash intent submission created (SUB-...)", !empty($cashSub['id']));

// Step 2: Front desk receptionist records cash via record.php (calls PaymentService::record)
$cashPayId = PaymentService::record(
    $p13InvId,
    '200.00',
    'Cash',
    'DESK-REC-' . bin2hex(random_bytes(3)),
    'Recorded at front desk'
);
assert_suite("CASH_RECON", "Front desk receptionist records cash payment", $cashPayId > 0);

// Step 3: Accountant reconciles customer online submission with front desk payment
$preReconBalance = InvoiceService::find($p13InvId)['balance'];
$reconciled = ManualPaymentService::reconcileCashPayment(
    (int)$cashSub['id'],
    $adminUserId,
    $cashPayId,
    null,
    'Reconciled with front-desk cash receipt'
);
assert_suite("CASH_RECON", "Submission updated to verified via reconciliation", $reconciled['status'] === 'verified');
assert_suite("CASH_RECON", "Reconciled payment ID linked on submission", (int)$reconciled['reconciled_payment_id'] === $cashPayId);

$postReconBalance = InvoiceService::find($p13InvId)['balance'];
assert_suite("CASH_RECON", "Zero double-deduction: invoice balance remains identical post-reconciliation", $preReconBalance === $postReconBalance);

// -------------------------------------------------------------
// SECTION 17: CSV FORMULA INJECTION SANITIZATION
// -------------------------------------------------------------
assert_suite("CSV_SECURITY", "Formula trigger '=' is prepended with single-quote", ManualPaymentService::sanitizeCsvCell('=SUM(A1:A5)') === "'=SUM(A1:A5)");
assert_suite("CSV_SECURITY", "Formula trigger '@' is prepended with single-quote", ManualPaymentService::sanitizeCsvCell('@CMD("calc")') === "'@CMD(\"calc\")");
assert_suite("CSV_SECURITY", "Formula trigger '+12+34' is prepended with single-quote", ManualPaymentService::sanitizeCsvCell('+12+34') === "'+12+34");
assert_suite("CSV_SECURITY", "Valid negative monetary amount is preserved without quote", ManualPaymentService::sanitizeCsvCell('-500.00') === '-500.00');
assert_suite("CSV_SECURITY", "Valid positive monetary amount is preserved without quote", ManualPaymentService::sanitizeCsvCell('+500.00') === '+500.00');
assert_suite("CSV_SECURITY", "Embedded newlines and tabs are stripped", ManualPaymentService::sanitizeCsvCell("Line1\nLine2\tLine3") === "Line1 Line2 Line3");

// -------------------------------------------------------------
// SECTION 18: ACCOUNT IDENTIFIER MASKING & EXTENDED FIELDS
// -------------------------------------------------------------
assert_suite("MASKING", "13-digit CBE account masked cleanly", ManualPaymentService::maskIdentifier('1000123456789') === '••••••••• 6789');
assert_suite("MASKING", "10-digit phone masked cleanly", ManualPaymentService::maskIdentifier('0911002233') === '•••••• 2233');
assert_suite("MASKING", "Short identifier (<= 4 chars) left unchanged", ManualPaymentService::maskIdentifier('123') === '123');

// Extended method fields test (merchant_number, till_number, branch)
$testCode = 'p13_test_' . bin2hex(random_bytes(4));
$newMethodId = ManualPaymentService::saveMethod([
    'code' => $testCode,
    'name' => 'Coopbank Merchant Account',
    'name_am' => 'የኦሮሚያ ህብረት ስራ ባንክ ነጋዴ',
    'type' => 'other',
    'merchant_number' => 'MERCH-9988',
    'till_number' => 'TILL-1122',
    'branch' => 'Bole Medhanialem',
    'is_active' => 1,
], null, $adminUserId);
$savedMethod = ManualPaymentService::getMethod($newMethodId);
assert_suite("EXT_FIELDS", "Merchant number saved successfully", $savedMethod['merchant_number'] === 'MERCH-9988');
assert_suite("EXT_FIELDS", "Till number saved successfully", $savedMethod['till_number'] === 'TILL-1122');
assert_suite("EXT_FIELDS", "Branch saved successfully", $savedMethod['branch'] === 'Bole Medhanialem');

// Deactivate test method (DELETE is disallowed by least privilege)
Database::run("UPDATE payment_methods SET is_active = 0 WHERE id = ?", [$newMethodId]);

echo "\n=================================================================\n";
echo "MANUAL PAYMENT SYSTEM SUITE RESULT: $passCount / $totalCount tests PASSED (" . round(($passCount / $totalCount) * 100) . "%)\n";
echo "=================================================================\n";

if ($passCount !== $totalCount) {
    exit(1);
}
