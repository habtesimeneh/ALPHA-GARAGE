<?php
declare(strict_types=1);

require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';

require_login();

$u = current_user();
$isStaff = ($u['role_name'] ?? '') !== 'customer' && can_any('payments.view', 'payments.record', 'invoices.view');
$invoiceId = get_int('invoice_id') ?: post_int('invoice_id');
$errors = [];
$successSubmission = null;

if (get('success') === '1' && get_int('sub_id') > 0) {
    $successSubmission = ManualPaymentService::getSubmission(get_int('sub_id'));
}

$inv = $invoiceId ? InvoiceService::find($invoiceId) : null;

// Validate customer authorization (anti-IDOR)
if ($inv && !$isStaff) {
    $customer = CustomerIdentityService::resolveCustomer($u);
    $linkedCustIds = CustomerIdentityService::getLinkedCustomerIds((int) $u['id']);
    if (!in_array((int) $customer['id'], $linkedCustIds, true)) {
        $linkedCustIds[] = (int) $customer['id'];
    }
    if (!in_array((int)$inv['customer_id'], $linkedCustIds, true)) {
        AuditLogger::logSafe('idor_payment_pay_blocked', 'payments', (int) $inv['id'], null, [
            'actor_user_id' => $u['id'],
            'actor_customer_id' => $customer['id'],
            'target_invoice_customer' => $inv['customer_id'],
        ], 'denied');
        abort(403, 'You are not authorized to access this invoice payment portal.');
    }
}

// Handle Form Submission with server-side CSRF validation
if (is_post() && !$successSubmission) {
    verify_csrf();

    if (!$inv) {
        abort(404, 'Invoice not found.');
    }

    try {
        $customerId = (int)($inv['customer_id'] ?? ($u['customer_id'] ?? 0));
        $file = $_FILES['proof'] ?? null;
        $submission = ManualPaymentService::submitPayment($customerId, (int)$inv['id'], $_POST, $file);

        flash('success', t('Payment submitted successfully! Your submission tracking number is ', 'የክፍያ ማረጋገጫዎ በተሳካ ሁኔታ ቀርቧል! የመከታተያ ቁጥርዎ ') . $submission['submission_number']);
        redirect('payments/pay.php?success=1&sub_id=' . $submission['id'] . '&invoice_id=' . $inv['id']);
    } catch (DomainException $e) {
        $errors['general'] = $e->getMessage();
    } catch (Throwable $e) {
        $errors['general'] = 'An unexpected error occurred while processing your submission. Please try again.';
        app_log('Payment submission error: ' . $e->getMessage());
    }
}

$activeMethods = ManualPaymentService::getActiveMethods();

ui_header(t('Submit Invoice Payment', 'የደረሰኝ ክፍያ ማረጋገጫ ማስገቢያ'), 'invoices');
page_header(
    t('Manual Payment & Proof Submission', 'የእጅ ክፍያ እና የደረሰኝ ማረጋገጫ ማቅረቢያ'),
    t('Pay via Ethiopian banks, Telebirr, or M-Pesa and submit your transaction proof for verification',
      'በኢትዮጵያ ባንኮች፣ በቴሌብር ወይም በኤም-ፔሳ ከፍለው የግብይት ማረጋገጫዎን እዚህ ያስገቡ')
);
?>

<?php if ($successSubmission): ?>
<!-- Submission Success Card -->
<div class="card mb3" style="max-width:720px;margin:0 auto;text-align:center;padding:36px;border:2px solid #10B981;background:var(--card-bg,#1E293B);">
    <div style="font-size:3.5rem;color:#10B981;margin-bottom:12px;">✓</div>
    <h2 style="margin:0 0 8px;color:#10B981;"><?= e(t('Payment Proof Submitted Successfully!', 'የክፍያ ማረጋገጫ በተሳካ ሁኔታ ገብቷል!')) ?></h2>
    <p class="muted" style="margin-bottom:20px;">
        <?= e(t('Our accounting team will manually verify the transaction against bank records. Once verified, your invoice balance will be updated immediately.',
               'የሂሳብ ክፍላችን የባንክ መዝገብ በማየት ክፍያውን ያረጋግጣል። እንደተረጋገጠ የደረሰኝዎ ሂሳብ ወዲያውኑ ይስተካከላል።')) ?>
    </p>

    <div style="background:rgba(0,0,0,0.2);padding:18px;border-radius:8px;text-align:left;margin-bottom:24px;border:1px solid var(--border-color,#334155);">
        <div style="display:flex;justify-content:space-between;margin-bottom:8px;">
            <span class="muted"><?= e(t('Tracking Number:', 'የመከታተያ ቁጥር:')) ?></span>
            <strong class="mono" style="color:var(--gold,#D4AF37);"><?= e($successSubmission['submission_number']) ?></strong>
        </div>
        <div style="display:flex;justify-content:space-between;margin-bottom:8px;">
            <span class="muted"><?= e(t('Invoice Number:', 'የደረሰኝ ቁጥር:')) ?></span>
            <strong><?= e($successSubmission['invoice_number']) ?></strong>
        </div>
        <div style="display:flex;justify-content:space-between;margin-bottom:8px;">
            <span class="muted"><?= e(t('Payment Provider:', 'የክፍያ አቅራቢ:')) ?></span>
            <strong><?= e(current_lang() === 'am' ? ($successSubmission['display_provider_name_am'] ?? $successSubmission['display_provider_name']) : $successSubmission['display_provider_name']) ?></strong>
        </div>
        <div style="display:flex;justify-content:space-between;margin-bottom:8px;">
            <span class="muted"><?= e(t('Transaction Reference:', 'የግብይት ቁጥር:')) ?></span>
            <span class="mono"><?= e($successSubmission['transaction_reference'] ?: '—') ?></span>
        </div>
        <div style="display:flex;justify-content:space-between;margin-bottom:8px;">
            <span class="muted"><?= e(t('Amount Submitted:', 'የተከፈለው መጠን:')) ?></span>
            <strong style="font-size:1.15rem;color:#10B981;"><?= e(money($successSubmission['amount'])) ?></strong>
        </div>
        <div style="display:flex;justify-content:space-between;">
            <span class="muted"><?= e(t('Current Status:', 'ወቅታዊ ሁኔታ:')) ?></span>
            <span class="badge badge-pending"><?= e(t('Pending Verification', 'ማረጋገጫ የሚጠብቅ')) ?></span>
        </div>
    </div>

    <div style="display:flex;gap:12px;justify-content:center;">
        <a href="<?= e(url('customer.php#invoices')) ?>" class="btn btn-primary">
            <?= e(t('Return to My Dashboard', 'ወደ ዳሽቦርድ ተመለስ')) ?>
        </a>
        <a href="<?= e(url('invoices/view.php?id=' . $successSubmission['invoice_id'])) ?>" class="btn btn-ghost">
            <?= e(t('View Invoice', 'ደረሰኝ ተመልከት')) ?>
        </a>
    </div>
</div>

<?php elseif (!$inv): ?>
<!-- Invoice Selector if none passed -->
<div class="card" style="max-width:600px;margin:0 auto;text-align:center;padding:32px;">
    <h3><?= e(t('Select Invoice to Pay', 'የሚከፍሉትን ደረሰኝ ይምረጡ')) ?></h3>
    <p class="muted"><?= e(t('Please select an invoice with an outstanding balance from your customer dashboard.', 'እባክዎ ያልተከፈለ ቀሪ ሂሳብ ያለበትን ደረሰኝ ከዳሽቦርድዎ ይምረጡ።')) ?></p>
    <a href="<?= e(url('customer.php#invoices')) ?>" class="btn btn-primary mt2"><?= e(t('View My Invoices', 'ደረሰኞቼን ተመልከት')) ?></a>
</div>

<?php else: ?>

<!-- Main Payment Submission Workflow -->
<div style="max-width:850px;margin:0 auto;">
    <?php if (!empty($errors['general'])): ?>
        <div class="alert alert-danger mb3"><?= e($errors['general']) ?></div>
    <?php endif; ?>

    <!-- Prominent Verification Warning Banner -->
    <div class="alert alert-warning mb3" style="border:1px solid #F59E0B;background:rgba(245,158,11,0.08);display:flex;align-items:center;gap:12px;border-radius:8px;padding:14px 18px;">
        <span style="font-size:1.8rem;color:#F59E0B;line-height:1;">⚠️</span>
        <div>
            <strong style="color:#F59E0B;"><?= e(t('Manual Payment Verification Notice', 'የእጅ ክፍያ ማረጋገጫ ማሳሰቢያ')) ?></strong>
            <div style="font-size:0.92rem;margin-top:2px;">
                <?= e(t('Submitting payment evidence does not automatically confirm payment. Your payment will be manually verified by ALPHA GARAGE staff.',
                       'የክፍያ ማረጋገጫ ማስገባት ክፍያውን በቀጥታ አያረጋግጥም። ክፍያዎ በ ALPHA GARAGE ሰራተኞች በእጅ ይረጋገጣል።')) ?>
            </div>
        </div>
    </div>

    <!-- Invoice Summary Banner -->
    <div class="card mb3" style="background:linear-gradient(135deg,rgba(212,175,55,0.08),rgba(13,40,30,0.4));border:1px solid var(--gold,#D4AF37);">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px;">
            <div>
                <span class="muted small"><?= e(t('ALPHA GARAGE PLC — Service Invoice', 'አልፋ ጋራዥ ኃ/የተ/የግ/ማ — የአገልግሎት ደረሰኝ')) ?></span>
                <h3 style="margin:4px 0 0;color:var(--gold,#D4AF37);"><?= e($inv['invoice_number']) ?></h3>
                <div class="muted small mt1">
                    <?= e(t('Customer: ', 'ደንበኛ: ')) ?><strong><?= e($inv['customer_name'] ?? ($u['full_name'] ?? '')) ?></strong> ·
                    <?= e(t('Vehicle: ', 'ተሽከርካሪ: ')) ?><strong><?= e($inv['plate_number'] ?? '—') ?></strong> ·
                    <?= e(t('Due Date: ', 'የመክፈያ ቀን: ') . fmt_date($inv['due_date'])) ?>
                </div>
            </div>
            <div style="text-align:right;">
                <div class="muted small"><?= e(t('Outstanding Balance Due', 'ያልተከፈለ ቀሪ ሂሳብ')) ?></div>
                <div style="font-size:1.85rem;font-weight:700;color:var(--gold,#D4AF37);"><?= e(money($inv['balance'])) ?></div>
                <div class="muted small"><?= e(t('Total: ', 'ጠቅላላ: ') . money($inv['grand_total'])) ?> · <?= e(t('Paid: ', 'የተከፈለ: ') . money($inv['amount_paid'])) ?></div>
            </div>
        </div>
    </div>

    <!-- Payment Submission Form -->
    <div class="card">
        <form method="post" action="<?= e(url('payments/pay.php')) ?>" enctype="multipart/form-data" data-validate novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="invoice_id" value="<?= (int)$inv['id'] ?>">

            <!-- Step 1: Select Payment Method -->
            <div class="mb3">
                <h4 style="margin-top:0;margin-bottom:8px;color:var(--gold,#D4AF37);">
                    1. <?= e(t('Choose Payment Method & View Account Details', 'የክፍያ መንገድ ይምረጡ እና የሂሳብ ዝርዝር ይመልከቱ')) ?>
                </h4>
                <div class="field">
                    <label for="payment_method_id"><?= e(t('Select Bank, Mobile Wallet, or Cash:', 'ባንክ፣ ሞባይል ዋሌት ወይም ጥሬ ገንዘብ ይምረጡ:')) ?> *</label>
                    <select class="input" id="payment_method_id" name="payment_method_id" required>
                        <option value=""><?= e(t('— Select Payment Provider —', '— የክፍያ አቅራቢ ይምረጡ —')) ?></option>
                        <?php foreach ($activeMethods as $m): ?>
                            <option value="<?= (int)$m['id'] ?>"
                                    data-type="<?= e($m['type']) ?>"
                                    data-account-name="<?= e($m['account_name'] ?? '') ?>"
                                    data-account-number="<?= e($m['account_number'] ?? '') ?>"
                                    data-phone="<?= e($m['phone_number'] ?? '') ?>"
                                    data-merchant="<?= e($m['merchant_number'] ?? '') ?>"
                                    data-till="<?= e($m['till_number'] ?? '') ?>"
                                    data-branch="<?= e($m['branch'] ?? '') ?>"
                                    data-instructions="<?= e($m['instructions'] ?? '') ?>"
                                    data-instructions-am="<?= e($m['instructions_am'] ?? '') ?>"
                                    data-requires-ref="<?= (int)$m['requires_reference'] ?>"
                                    data-requires-proof="<?= (int)$m['requires_screenshot'] ?>"
                                    <?= post_int('payment_method_id') === (int)$m['id'] ? 'selected' : '' ?>>
                                <?= e(current_lang() === 'am' && !empty($m['name_am']) ? $m['name_am'] : $m['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Dynamic Account Information Box -->
                <div id="method_details_box" style="display:none;margin-top:14px;padding:18px;border-radius:8px;background:rgba(212,175,55,0.06);border:1px solid rgba(212,175,55,0.3);">
                    <div style="font-weight:700;color:var(--gold,#D4AF37);margin-bottom:8px;display:flex;align-items:center;gap:8px;">
                        <span>🏦</span> <span id="method_box_title"><?= e(t('Garage Receiving Account Information', 'የጋራዥ መቀበያ ሂሳብ መረጃ')) ?></span>
                    </div>
                    <div id="method_box_account" style="font-size:1.15rem;margin-bottom:6px;"></div>
                    <div id="method_box_name" class="muted small" style="margin-bottom:10px;"></div>
                    <div id="method_box_instructions" style="font-size:0.92rem;line-height:1.55;background:rgba(0,0,0,0.18);padding:12px;border-radius:6px;border-left:3px solid var(--gold,#D4AF37);"></div>
                </div>
            </div>

            <hr style="border:0;border-top:1px solid var(--border-color,#334155);margin:24px 0;">

            <!-- Step 2: Payment Transaction Information -->
            <div class="mb3">
                <h4 style="margin-top:0;margin-bottom:16px;color:var(--gold,#D4AF37);">
                    2. <?= e(t('Enter Transaction Information', 'የግብይት መረጃዎን ያስገቡ')) ?>
                </h4>

                <div class="grid-2">
                    <div class="field">
                        <label for="amount"><?= e(t('Amount Paid (ETB):', 'የተከፈለው መጠን (ብር):')) ?> *</label>
                        <input class="input" type="number" step="0.01" min="0.01" max="<?= e($inv['balance']) ?>"
                               id="amount" name="amount" required
                               value="<?= e(post('amount', $inv['balance'])) ?>">
                        <div style="display:flex;gap:8px;margin-top:6px;flex-wrap:wrap;">
                            <button type="button" class="btn btn-ghost btn-sm" id="btn_pay_full" style="font-size:0.8rem;padding:2px 8px;">
                                <?= e(t('Pay Full Balance', 'ሙሉውን ቀሪ ሂሳብ ክፈል')) ?> (<?= e(money($inv['balance'])) ?>)
                            </button>
                            <?php $halfBalance = round((float)$inv['balance'] / 2, 2); ?>
                            <?php if ($halfBalance > 0 && $halfBalance < (float)$inv['balance']): ?>
                            <button type="button" class="btn btn-ghost btn-sm" id="btn_pay_half" style="font-size:0.8rem;padding:2px 8px;">
                                <?= e(t('Pay 50%', '50% ክፈል')) ?> (<?= e(money($halfBalance)) ?>)
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="field">
                        <label for="transaction_reference">
                            <span id="label_tx_ref"><?= e(t('Transaction Reference / ID:', 'የግብይት ቁጥር / ሪፈረንስ:')) ?></span>
                            <span id="ref_required_asterisk">*</span>
                        </label>
                        <input class="input" type="text" id="transaction_reference" name="transaction_reference" required
                               value="<?= e(post('transaction_reference')) ?>"
                               placeholder="<?= e(t('e.g. CBE Mobile FT26..., Telebirr 8AG..., etc.', 'ምሳሌ: የቴሌብር ወይም የሲቢኢ ግብይት ቁጥር')) ?>">
                        <span class="muted small"><?= e(t('Found in your bank confirmation SMS or transaction receipt.', 'በባንክዎ የማረጋገጫ መልእክት ወይም ደረሰኝ ላይ የሚገኝ።')) ?></span>
                    </div>
                </div>

                <div class="grid-2">
                    <div class="field">
                        <label for="transaction_date"><?= e(t('Transaction Date:', 'የግብይት ቀን:')) ?> *</label>
                        <input class="input" type="date" id="transaction_date" name="transaction_date" required
                               value="<?= e(post('transaction_date', date('Y-m-d'))) ?>">
                    </div>

                    <div class="field">
                        <label for="transaction_time"><?= e(t('Transaction Time (Optional):', 'የግብይት ሰዓት (አማራጭ):')) ?></label>
                        <input class="input" type="time" id="transaction_time" name="transaction_time"
                               value="<?= e(post('transaction_time', date('H:i'))) ?>">
                    </div>
                </div>

                <div class="grid-2">
                    <div class="field">
                        <label for="sender_name"><?= e(t('Sender / Payer Full Name:', 'የከፋይ ሙሉ ስም:')) ?> *</label>
                        <input class="input" type="text" id="sender_name" name="sender_name" required
                               value="<?= e(post('sender_name', $u['full_name'] ?? ($inv['customer_name'] ?? ''))) ?>"
                               placeholder="<?= e(t('Name as shown on bank / mobile account', 'በባንክ ወይም ሞባይል ሂሳብ ላይ የሚታየው ስም')) ?>">
                    </div>

                    <div class="field">
                        <label for="sender_phone"><?= e(t('Sender Phone Number:', 'የከፋይ ስልክ ቁጥር:')) ?> *</label>
                        <input class="input" type="text" id="sender_phone" name="sender_phone" required
                               value="<?= e(post('sender_phone', $u['phone'] ?? ($inv['customer_phone'] ?? ''))) ?>"
                               placeholder="e.g. 0911223344">
                    </div>
                </div>
            </div>

            <hr style="border:0;border-top:1px solid var(--border-color,#334155);margin:24px 0;">

            <!-- Step 3: Payment Proof Screenshot / Receipt -->
            <div class="mb3">
                <h4 style="margin-top:0;margin-bottom:16px;color:var(--gold,#D4AF37);">
                    3. <?= e(t('Upload Payment Proof Screenshot or Receipt', 'የደረሰኝ ስክሪንሾት ወይም ፒዲኤፍ ደረሰኝ ያያይዙ')) ?>
                </h4>

                <div class="field" id="proof_field_wrapper">
                    <label for="proof">
                        <span id="label_proof"><?= e(t('Proof File (JPG, PNG, WEBP, or PDF - Max 5MB):', 'የማረጋገጫ ፋይል (JPG, PNG, WEBP, ወይም PDF - ቢበዛ 5MB):')) ?></span>
                        <span id="proof_required_asterisk">*</span>
                    </label>

                    <!-- Drag & Drop Container -->
                    <div id="drop_zone" style="border:2px dashed var(--gold,#D4AF37);border-radius:8px;padding:24px;text-align:center;background:rgba(0,0,0,0.1);cursor:pointer;transition:background 0.2s;">
                        <div style="font-size:2.4rem;margin-bottom:6px;">📎</div>
                        <div style="font-weight:600;margin-bottom:4px;">
                            <?= e(t('Drag & drop payment receipt screenshot or PDF here, or click to browse', 'የክፍያ ማረጋገጫ ስክሪንሾት ወይም ፒዲኤፍ እዚህ ይጎትቱ ወይም ፋይል ለመምረጥ ይጫኑ')) ?>
                        </div>
                        <div class="muted small"><?= e(t('Supports: JPG, PNG, WEBP, PDF (Maximum file size: 5 MB)', 'የሚደገፉ: JPG, PNG, WEBP, PDF (ከፍተኛው መጠን: 5 MB)')) ?></div>
                        <input type="file" id="proof" name="proof" accept="image/jpeg,image/png,image/webp,application/pdf" style="display:none;">
                    </div>

                    <!-- Selected File Info & Live Preview -->
                    <div id="file_selected_info" style="display:none;margin-top:12px;padding:12px;background:rgba(255,255,255,0.03);border:1px solid var(--border-color,#334155);border-radius:6px;align-items:center;justify-content:space-between;">
                        <div style="display:flex;align-items:center;gap:12px;">
                            <span id="file_icon" style="font-size:1.8rem;">📄</span>
                            <div>
                                <div id="selected_file_name" style="font-weight:600;"></div>
                                <div id="selected_file_size" class="muted small"></div>
                            </div>
                        </div>
                        <button type="button" id="btn_remove_file" class="btn btn-outline btn-sm" style="color:#EF4444;border-color:rgba(239,68,68,0.4);">
                            ✕ <?= e(t('Remove', 'አስወግድ')) ?>
                        </button>
                    </div>

                    <!-- Image Preview Box -->
                    <div id="image_preview_box" style="display:none;margin-top:12px;text-align:center;">
                        <img id="image_preview" src="" alt="Preview"
                             style="max-width:280px;max-height:220px;border-radius:6px;border:1px solid var(--gold,#D4AF37);box-shadow:0 4px 10px rgba(0,0,0,0.3);">
                        <div class="muted small mt1"><?= e(t('Attached payment proof preview', 'የተያያዘው የክፍያ ማረጋገጫ ቅድመ-እይታ')) ?></div>
                    </div>
                </div>
            </div>

            <div class="field mb3">
                <label for="customer_notes"><?= e(t('Additional Customer Notes (Optional):', 'ተጨማሪ ማስታወሻ (አማራጭ):')) ?></label>
                <textarea class="input" id="customer_notes" name="customer_notes" rows="2"
                          placeholder="<?= e(t('Any remarks or special instructions regarding this payment...', 'ከክፍያው ጋር የተያያዘ ማንኛውም ተጨማሪ ማብራሪያ...')) ?>"><?= e(post('customer_notes')) ?></textarea>
            </div>

            <div style="display:flex;gap:12px;align-items:center;margin-top:28px;">
                <button class="btn btn-primary" type="submit" style="padding:12px 28px;font-size:1.05rem;background:#10B981;border-color:#10B981;">
                    ✓ <?= e(t('Submit Payment for Verification', 'ክፍያውን ለማረጋገጫ ላክ')) ?>
                </button>
                <a href="<?= e(url('customer.php#invoices')) ?>" class="btn btn-ghost">
                    <?= e(t('Cancel & Return', 'ሰርዝ እና ተመለስ')) ?>
                </a>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var selectMethod = document.getElementById('payment_method_id');
    var detailsBox = document.getElementById('method_details_box');
    var boxAccount = document.getElementById('method_box_account');
    var boxName = document.getElementById('method_box_name');
    var boxInstructions = document.getElementById('method_box_instructions');
    var proofInput = document.getElementById('proof');
    var proofRequired = document.getElementById('proof_required_asterisk');
    var refRequired = document.getElementById('ref_required_asterisk');
    var txRefInput = document.getElementById('transaction_reference');
    var previewBox = document.getElementById('image_preview_box');
    var previewImg = document.getElementById('image_preview');
    var dropZone = document.getElementById('drop_zone');
    var fileSelectedInfo = document.getElementById('file_selected_info');
    var selectedFileName = document.getElementById('selected_file_name');
    var selectedFileSize = document.getElementById('selected_file_size');
    var btnRemoveFile = document.getElementById('btn_remove_file');
    var isAmharic = '<?= current_lang() ?>' === 'am';

    // Quick balance buttons
    var amountInput = document.getElementById('amount');
    var btnPayFull = document.getElementById('btn_pay_full');
    var btnPayHalf = document.getElementById('btn_pay_half');

    if (btnPayFull && amountInput) {
        btnPayFull.addEventListener('click', function () {
            amountInput.value = '<?= (float)$inv['balance'] ?>';
        });
    }
    if (btnPayHalf && amountInput) {
        btnPayHalf.addEventListener('click', function () {
            amountInput.value = '<?= round((float)$inv['balance'] / 2, 2) ?>';
        });
    }

    function updateMethodDetails() {
        if (!selectMethod || !selectMethod.value) {
            if (detailsBox) detailsBox.style.display = 'none';
            return;
        }
        var opt = selectMethod.selectedOptions[0];
        if (!opt) return;

        var type = opt.getAttribute('data-type');
        var accNum = opt.getAttribute('data-account-number') || '';
        var phone = opt.getAttribute('data-phone') || '';
        var merchant = opt.getAttribute('data-merchant') || '';
        var till = opt.getAttribute('data-till') || '';
        var branch = opt.getAttribute('data-branch') || '';
        var accName = opt.getAttribute('data-account-name') || '';
        var inst = isAmharic ? (opt.getAttribute('data-instructions-am') || opt.getAttribute('data-instructions')) : opt.getAttribute('data-instructions');
        var reqRef = opt.getAttribute('data-requires-ref') === '1';
        var reqProof = opt.getAttribute('data-requires-proof') === '1';

        var accountText = '';
        if (type === 'bank' && accNum) {
            accountText = '<?= e(t('Bank Account No:', 'የባንክ ሂሳብ ቁጥር:')) ?> <strong class="mono" style="font-size:1.25rem;letter-spacing:1px;color:var(--gold,#D4AF37);">' + accNum + '</strong>';
            if (branch) {
                accountText += ' <span class="muted small">(<?= e(t('Branch: ', 'ቅርንጫፍ: ')) ?>' + branch + ')</span>';
            }
        } else if (merchant) {
            accountText = '<?= e(t('Merchant Number:', 'የነጋዴ ቁጥር:')) ?> <strong class="mono" style="font-size:1.25rem;color:var(--gold,#D4AF37);">' + merchant + '</strong>';
        } else if (till) {
            accountText = '<?= e(t('Till Number:', 'የቲል ቁጥር:')) ?> <strong class="mono" style="font-size:1.25rem;color:var(--gold,#D4AF37);">' + till + '</strong>';
        } else if (phone) {
            accountText = '<?= e(t('Mobile / Wallet Number:', 'የሞባይል / ዋሌት ቁጥር:')) ?> <strong class="mono" style="font-size:1.25rem;color:var(--gold,#D4AF37);">' + phone + '</strong>';
        } else {
            accountText = '<?= e(t('Direct Reception Desk Cash Settlement', 'በጋራዥ የፊት ዴስክ በጥሬ ገንዘብ ይከፈላል')) ?>';
        }

        boxAccount.innerHTML = accountText;
        boxName.textContent = accName ? ('<?= e(t('Account Holder: ', 'የሂሳብ ባለቤት ስም: ')) ?>' + accName) : '';
        boxInstructions.textContent = inst || '<?= e(t('Please complete the transfer and provide the reference code.', 'እባክዎ ክፍያውን ፈጽመው የግብይት ቁጥሩን ያስገቡ።')) ?>';
        detailsBox.style.display = 'block';

        if (proofRequired) {
            proofRequired.style.display = reqProof ? 'inline' : 'none';
        }
        if (proofInput) {
            proofInput.required = reqProof;
        }
        if (refRequired) {
            refRequired.style.display = reqRef ? 'inline' : 'none';
        }
        if (txRefInput) {
            txRefInput.required = reqRef;
        }
    }

    if (selectMethod) {
        selectMethod.addEventListener('change', updateMethodDetails);
        updateMethodDetails();
    }

    // Drag and drop dropzone behavior
    if (dropZone && proofInput) {
        dropZone.addEventListener('click', function () {
            proofInput.click();
        });

        ['dragenter', 'dragover'].forEach(function (eventName) {
            dropZone.addEventListener(eventName, function (e) {
                e.preventDefault();
                e.stopPropagation();
                dropZone.style.background = 'rgba(212,175,55,0.15)';
            }, false);
        });

        ['dragleave', 'drop'].forEach(function (eventName) {
            dropZone.addEventListener(eventName, function (e) {
                e.preventDefault();
                e.stopPropagation();
                dropZone.style.background = 'rgba(0,0,0,0.1)';
            }, false);
        });

        dropZone.addEventListener('drop', function (e) {
            var dt = e.dataTransfer;
            var files = dt.files;
            if (files && files.length > 0) {
                proofInput.files = files;
                handleFiles(files[0]);
            }
        });

        proofInput.addEventListener('change', function () {
            if (this.files && this.files.length > 0) {
                handleFiles(this.files[0]);
            }
        });
    }

    function handleFiles(file) {
        if (!file) return;

        if (selectedFileName) selectedFileName.textContent = file.name;
        if (selectedFileSize) selectedFileSize.textContent = (file.size / 1024 / 1024).toFixed(2) + ' MB';
        if (fileSelectedInfo) fileSelectedInfo.style.display = 'flex';

        if (file.type.startsWith('image/')) {
            var reader = new FileReader();
            reader.onload = function (e) {
                previewImg.src = e.target.result;
                previewBox.style.display = 'block';
            };
            reader.readAsDataURL(file);
        } else {
            previewBox.style.display = 'none';
        }
    }

    if (btnRemoveFile && proofInput) {
        btnRemoveFile.addEventListener('click', function () {
            proofInput.value = '';
            fileSelectedInfo.style.display = 'none';
            previewBox.style.display = 'none';
        });
    }
});
</script>

<?php endif; ?>

<?php ui_footer(); ?>
