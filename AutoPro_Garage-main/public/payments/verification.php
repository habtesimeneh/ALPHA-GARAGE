<?php
declare(strict_types=1);

require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';

require_login();
require_permission('payments.verify');

$u = current_user();
$errors = [];

// Handle POST actions with server-side CSRF validation
if (is_post()) {
    verify_csrf();

    $action = post('action');
    $subId = post_int('submission_id');

    if ($action === 'verify' && $subId > 0) {
        $notes = post('review_notes');
        $amount = post('verified_amount');
        try {
            $updated = ManualPaymentService::verifyPayment($subId, (int)$u['id'], $notes, $amount);
            flash('success', t('Payment successfully verified! Receipt ', 'ክፍያው በተሳካ ሁኔታ ተረጋግጧል! ደረሰኝ ') . ($updated['receipt_number'] ?? '') . t(' generated and invoice balance updated.', ' ተዘጋጅቶ ሂሳቡ ተስተካክሏል።'));
            redirect('payments/verification.php');
        } catch (DomainException $e) {
            flash('danger', $e->getMessage());
            redirect('payments/verification.php?review=' . $subId);
        }
    }

    if ($action === 'reconcile_cash' && $subId > 0) {
        $existingPaymentId = post_int('existing_payment_id') ?: null;
        $receiptNumber = trim((string)post('receipt_number')) ?: null;
        $notes = post('review_notes');
        try {
            $updated = ManualPaymentService::reconcileCashPayment($subId, (int)$u['id'], $existingPaymentId, $receiptNumber, $notes);
            flash('success', t('Cash payment reconciled and verified successfully! Invoice balance updated.', 'የጥሬ ገንዘብ ክፍያው በተሳካ ሁኔታ ተረጋግጧል!'));
            redirect('payments/verification.php');
        } catch (DomainException $e) {
            flash('danger', $e->getMessage());
            redirect('payments/verification.php?review=' . $subId);
        }
    }

    if ($action === 'reject' && $subId > 0) {
        $reason = post('rejection_reason');
        $customReason = post('custom_rejection_reason');
        if ($reason === 'other' && $customReason !== '') {
            $reason = $customReason;
        } elseif (isset(ManualPaymentService::REJECTION_REASONS[$reason])) {
            $reason = ManualPaymentService::REJECTION_REASONS[$reason];
        }
        $notes = post('review_notes');
        try {
            ManualPaymentService::rejectPayment($subId, (int)$u['id'], (string)$reason, $notes);
            flash('warning', t('Payment submission has been rejected. Invoice balance remains untouched.', 'የክፍያ ማረጋገጫው ውድቅ ተደርጓል። ያልተከፈለው ሂሳብ አልተለወጠም።'));
            redirect('payments/verification.php');
        } catch (DomainException $e) {
            flash('danger', $e->getMessage());
            redirect('payments/verification.php?review=' . $subId);
        }
    }

    if ($action === 'under_review' && $subId > 0) {
        ManualPaymentService::markUnderReview($subId, (int)$u['id']);
        flash('info', t('Submission marked as Under Review.', 'ማረጋገጫው በግምገማ ላይ መሆኑ ተመዝግቧል።'));
        redirect('payments/verification.php?review=' . $subId);
    }
}

// Filters & Pagination
$statusFilter = get('status');
$methodFilter = get_int('method_id');
$searchFilter = get('search');
$fromDate = get('from');
$toDate = get('to');

$filters = [];
if ($statusFilter !== '' && $statusFilter !== 'all') {
    $filters['status'] = $statusFilter;
}
if ($methodFilter > 0) {
    $filters['method_id'] = $methodFilter;
}
if ($searchFilter !== '') {
    $filters['search'] = $searchFilter;
}
if ($fromDate !== '') {
    $filters['from'] = $fromDate;
}
if ($toDate !== '') {
    $filters['to'] = $toDate;
}

$page = max(1, get_int('page') ?: 1);
$perPage = (int)($GLOBALS['config']['per_page'] ?? 20);
$totalRows = ManualPaymentService::countSubmissions($filters);
$pg = paginate($totalRows, $perPage, $page);
$submissions = ManualPaymentService::findSubmissions($filters, $pg['per_page'], $pg['offset']);

$stats = ManualPaymentService::getSummaryStats();
$activeMethods = ManualPaymentService::getActiveMethods();
$pendingBadge = ManualPaymentService::getPendingCount();

// If reviewing a specific submission
$reviewId = get_int('review');
$reviewItem = null;
$existingCashPayments = [];
if ($reviewId > 0) {
    $reviewItem = ManualPaymentService::getSubmission($reviewId);
    if ($reviewItem && $reviewItem['method_type'] === 'cash') {
        $existingCashPayments = Database::all(
            "SELECT id, amount, method, reference, notes, receipt_number, paid_at FROM payments WHERE invoice_id = ? AND kind = 'payment' ORDER BY id DESC",
            [$reviewItem['invoice_id']]
        );
    }
}

ui_header('Payment Verification', 'payments');
page_header(
    t('Payment Verification Queue', 'የክፍያ ማረጋገጫ ረድፍ'),
    t('Review and verify manual customer bank transfers, Telebirr, and cash payments against ledger',
      'የደንበኞችን የባንክ ዝውውሮች፣ የቴሌብር እና የጥሬ ገንዘብ ክፍያዎች ከሂሳብ መዝገብ ጋር በማነጻጸር ያረጋግጡ')
);
?>

<!-- Payment Sub-Navigation -->
<div class="tabs mb2" style="display:flex;gap:8px;flex-wrap:wrap;border-bottom:1px solid var(--border-color,#334155);padding-bottom:12px;margin-bottom:24px;">
    <a href="<?= e(url('payments/')) ?>" class="btn btn-ghost btn-sm">
        <?= e(t('Payment History', 'የክፍያ ታሪክ')) ?>
    </a>
    <a href="<?= e(url('payments/verification.php')) ?>" class="btn btn-primary btn-sm" style="position:relative;">
        <?= e(t('Verification Queue', 'የማረጋገጫ ረድፍ')) ?>
        <?php if ($pendingBadge > 0): ?>
            <span style="background:#EF4444;color:#fff;font-size:0.75rem;font-weight:700;padding:2px 7px;border-radius:9999px;margin-left:6px;"><?= $pendingBadge ?></span>
        <?php endif; ?>
    </a>
    <?php if (can('payments.methods')): ?>
    <a href="<?= e(url('payments/methods.php')) ?>" class="btn btn-ghost btn-sm">
        <?= e(t('Payment Methods', 'የክፍያ መንገዶች')) ?>
    </a>
    <?php endif; ?>
    <?php if (can_any('payments.view', 'reports.financial')): ?>
    <a href="<?= e(url('payments/reports.php')) ?>" class="btn btn-ghost btn-sm">
        <?= e(t('Payment Reports', 'የክፍያ ሪፖርቶች')) ?>
    </a>
    <?php endif; ?>
    <?php if (can('payments.record')): ?>
    <a href="<?= e(url('payments/record.php')) ?>" class="btn btn-ghost btn-sm">
        <?= e(t('Record Manual Payment', 'ክፍያ ይመዝግቡ')) ?>
    </a>
    <?php endif; ?>
</div>

<!-- KPI Summary Cards -->
<div class="grid-4 mb3">
    <div class="card" style="border-left:4px solid #F59E0B;padding:16px;">
        <div class="muted small"><?= e(t('Pending Verification', 'ማረጋገጫ የሚጠብቁ')) ?></div>
        <div style="font-size:1.75rem;font-weight:700;color:#F59E0B;margin-top:4px;"><?= $stats['pending_count'] ?></div>
        <div class="muted small"><?= e(money($stats['pending_amount'])) ?> <?= e(t('awaiting review', 'በግምገማ ላይ ያለ')) ?></div>
    </div>
    <div class="card" style="border-left:4px solid #3B82F6;padding:16px;">
        <div class="muted small"><?= e(t('Under Review', 'በግምገማ ላይ')) ?></div>
        <div style="font-size:1.75rem;font-weight:700;color:#3B82F6;margin-top:4px;"><?= $stats['under_review_count'] ?></div>
        <div class="muted small"><?= e(t('Currently locked by staff', 'በሰራተኛ የተያዙ')) ?></div>
    </div>
    <div class="card" style="border-left:4px solid #10B981;padding:16px;">
        <div class="muted small"><?= e(t('Verified & Settled', 'የተረጋገጡ እና የተመዘገቡ')) ?></div>
        <div style="font-size:1.75rem;font-weight:700;color:#10B981;margin-top:4px;"><?= $stats['verified_count'] ?></div>
        <div class="muted small"><?= e(money($stats['verified_amount'])) ?> <?= e(t('credited', 'ገቢ የተደረገ')) ?></div>
    </div>
    <div class="card" style="border-left:4px solid #EF4444;padding:16px;">
        <div class="muted small"><?= e(t('Rejected Submissions', 'ውድቅ የተደረጉ')) ?></div>
        <div style="font-size:1.75rem;font-weight:700;color:#EF4444;margin-top:4px;"><?= $stats['rejected_count'] ?></div>
        <div class="muted small"><?= e(t('Non-settling transfers', 'ያልተመዘገቡ')) ?></div>
    </div>
</div>

<!-- Modal: Review Specific Submission -->
<?php if ($reviewItem): ?>
<div class="card mb3" style="border:2px solid var(--gold,#D4AF37);background:var(--card-bg,#1E293B);padding:24px;">
    <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border-color,#334155);padding-bottom:14px;margin-bottom:20px;">
        <div>
            <h3 style="margin:0;color:var(--gold,#D4AF37);">
                <?= e(t('Review Payment Submission: ', 'የክፍያ ማረጋገጫ ግምገማ: ')) ?>
                <span class="mono"><?= e($reviewItem['submission_number']) ?></span>
            </h3>
            <span class="muted small">
                <?= e(t('Invoice: ', 'ደረሰኝ: ')) ?><strong><?= e($reviewItem['invoice_number']) ?></strong> ·
                <?= e(t('Submitted: ', 'የቀረበበት ቀን: ') . fmt_datetime($reviewItem['created_at'])) ?>
            </span>
        </div>
        <div style="display:flex;gap:8px;align-items:center;">
            <?php
            $statusBadges = [
                'pending_verification' => '<span class="badge badge-pending">' . e(t('Pending Verification', 'ማረጋገጫ የሚጠብቅ')) . '</span>',
                'under_review' => '<span class="badge badge-progress">' . e(t('Under Review', 'በግምገማ ላይ')) . '</span>',
                'verified' => '<span class="badge badge-active">' . e(t('Verified', 'የተረጋገጠ')) . '</span>',
                'rejected' => '<span class="badge badge-danger">' . e(t('Rejected', 'ውድቅ የተደረገ')) . '</span>',
            ];
            echo $statusBadges[$reviewItem['status']] ?? '<span class="badge">' . e($reviewItem['status']) . '</span>';
            ?>
            <a href="<?= e(url('payments/verification.php')) ?>" class="btn btn-ghost btn-sm">✕ <?= e(t('Close', 'ዝጋ')) ?></a>
        </div>
    </div>

    <div class="grid-2" style="gap:24px;">
        <!-- Left Column: Transaction & Customer Details -->
        <div>
            <h4 style="margin-top:0;color:var(--text-light,#F8FAFC);"><?= e(t('Transaction Details', 'የግብይት ዝርዝር')) ?></h4>
            <table class="table" style="background:transparent;">
                <tr>
                    <td class="muted" style="width:160px;"><?= e(t('Payment Provider', 'የክፍያ አቅራቢ')) ?>:</td>
                    <td>
                        <strong><?= e(current_lang() === 'am' ? ($reviewItem['display_provider_name_am'] ?? $reviewItem['display_provider_name']) : $reviewItem['display_provider_name']) ?></strong>
                        <span class="mono small muted">(<?= e($reviewItem['method_code']) ?>)</span>
                    </td>
                </tr>
                <tr>
                    <td class="muted"><?= e(t('Account / Identifier', 'የሂሳብ / መለያ ቁጥር')) ?>:</td>
                    <td>
                        <strong class="mono"><?= e($reviewItem['display_account_number'] ?: ($reviewItem['display_phone_number'] ?: '—')) ?></strong>
                        <?php if ($reviewItem['display_account_name']): ?>
                            <div class="muted small"><?= e($reviewItem['display_account_name']) ?></div>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td class="muted"><?= e(t('Submitted Amount', 'የቀረበው የገንዘብ መጠን')) ?>:</td>
                    <td><strong style="font-size:1.25rem;color:var(--gold,#D4AF37);"><?= e(money($reviewItem['amount'])) ?></strong></td>
                </tr>
                <tr>
                    <td class="muted"><?= e(t('Transaction Reference', 'የግብይት ማረጋገጫ ቁጥር')) ?>:</td>
                    <td><strong class="mono" style="background:rgba(212,175,55,0.1);padding:3px 8px;border-radius:4px;border:1px solid rgba(212,175,55,0.3);"><?= e($reviewItem['transaction_reference'] ?: '—') ?></strong></td>
                </tr>
                <tr>
                    <td class="muted"><?= e(t('Transaction Timestamp', 'የግብይት ቀንና ሰዓት')) ?>:</td>
                    <td><?= e(fmt_date($reviewItem['transaction_date'])) ?> <?= e($reviewItem['transaction_time'] ?: '') ?></td>
                </tr>
                <tr>
                    <td class="muted"><?= e(t('Sender / Payer Name', 'የከፋይ ስም')) ?>:</td>
                    <td><?= e($reviewItem['sender_name']) ?></td>
                </tr>
                <tr>
                    <td class="muted"><?= e(t('Sender Phone', 'የከፋይ ስልክ')) ?>:</td>
                    <td><span class="mono"><?= e($reviewItem['sender_phone'] ?: '—') ?></span></td>
                </tr>
                <tr>
                    <td class="muted"><?= e(t('Customer Account', 'የደንበኛ መለያ')) ?>:</td>
                    <td><?= e($reviewItem['customer_name']) ?> (<span class="mono"><?= e($reviewItem['customer_phone']) ?></span>)</td>
                </tr>
                <tr>
                    <td class="muted"><?= e(t('Invoice Balance Due', 'ያልተከፈለ ቀሪ ሂሳብ')) ?>:</td>
                    <td><strong><?= e(money($reviewItem['invoice_balance'])) ?></strong> (<?= e(t('Total: ', 'ጠቅላላ: ') . money($reviewItem['invoice_total'])) ?>)</td>
                </tr>
                <?php if (!empty($reviewItem['customer_notes'])): ?>
                <tr>
                    <td class="muted"><?= e(t('Customer Notes', 'የደንበኛ ማስታወሻ')) ?>:</td>
                    <td><em><?= e($reviewItem['customer_notes']) ?></em></td>
                </tr>
                <?php endif; ?>
            </table>
        </div>

        <!-- Right Column: Proof Attachment & Review Controls -->
        <div>
            <h4 style="margin-top:0;color:var(--text-light,#F8FAFC);"><?= e(t('Payment Proof Screenshot / Slip', 'የክፍያ ማረጋገጫ ስክሪንሾት / ደረሰኝ')) ?></h4>
            <div style="border:1px solid var(--border-color,#334155);border-radius:8px;padding:12px;background:rgba(0,0,0,0.15);min-height:220px;display:flex;flex-direction:column;align-items:center;justify-content:center;">
                <?php if (!empty($reviewItem['proof_file_path'])): ?>
                    <?php
                    $isPdf = $reviewItem['proof_file_mime'] === 'application/pdf';
                    $proofUrl = url('payments/proof.php?id=' . $reviewItem['id']);
                    ?>
                    <?php if ($isPdf): ?>
                        <div style="text-align:center;padding:24px;">
                            <div style="font-size:3rem;margin-bottom:8px;">📄</div>
                            <div style="font-weight:600;margin-bottom:12px;"><?= e(t('PDF Document Receipt Attached', 'የፒዲኤፍ ሰነድ ደረሰኝ ተያይዟል')) ?></div>
                            <a href="<?= e($proofUrl) ?>" target="_blank" class="btn btn-outline btn-sm">
                                <?= e(t('Open PDF Proof in New Tab', 'ፒዲኤፍ ደረሰኝ በሌላ መስኮት ክፈት')) ?>
                            </a>
                        </div>
                    <?php else: ?>
                        <div style="text-align:center;">
                            <a href="<?= e($proofUrl) ?>" target="_blank" title="<?= e(t('Click to open full high-resolution proof', 'ትልቁን ምስል ለመክፈት ጠቅ ያድርጉ')) ?>">
                                <img src="<?= e($proofUrl) ?>" alt="Payment Proof"
                                     style="max-width:100%;max-height:260px;object-fit:contain;border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,0.3);border:1px solid rgba(255,255,255,0.1);">
                            </a>
                            <div class="mt1">
                                <a href="<?= e($proofUrl) ?>" target="_blank" class="btn btn-ghost btn-sm">
                                    🔍 <?= e(t('View Full Resolution', 'ሙሉውን ምስል ክፈት')) ?>
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="muted small" style="text-align:center;padding:20px;">
                        <?= e(t('No proof image uploaded (Cash / Over-the-Counter or direct deposit).', 'ምንም ስክሪንሾት አልተያያዘም (ጥሬ ገንዘብ ወይም ቀጥታ ተቀማጭ)።')) ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Reviewer Decision Box -->
            <?php if (in_array($reviewItem['status'], ['pending_verification', 'under_review'], true)): ?>
                <div class="card mt2" style="background:rgba(255,255,255,0.02);border:1px solid var(--border-color,#334155);padding:16px;">
                    <h5 style="margin-top:0;margin-bottom:12px;"><?= e(t('Verification Action', 'የማረጋገጫ እርምጃ')) ?></h5>

                    <?php if ($reviewItem['method_type'] === 'cash'): ?>
                    <!-- Special Cash Reconciliation Section -->
                    <div class="alert alert-info mb2" style="font-size:0.88rem;padding:10px 14px;">
                        <strong><?= e(t('Cash Reception Reconciliation', 'የጥሬ ገንዘብ ማስታረቂያ')) ?>:</strong>
                        <div><?= e(t('This is a cash payment intent. You can either reconcile with cash already recorded at the reception desk, or confirm physical intake now.',
                                   'ይህ የጥሬ ገንዘብ ክፍያ ጥያቄ ነው። በዴስክ ቀደም ሲል ከተመዘገበ ክፍያ ጋር ማዛመድ ወይም አሁን ጥሬ ገንዘቡን መቀበል ይችላሉ።')) ?></div>
                    </div>

                    <?php if (!empty($existingCashPayments)): ?>
                    <!-- Reconcile with Existing Payment -->
                    <form method="post" action="<?= e(url('payments/verification.php')) ?>" class="mb2" data-validate novalidate>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="reconcile_cash">
                        <input type="hidden" name="submission_id" value="<?= (int)$reviewItem['id'] ?>">
                        <div class="field">
                            <label for="existing_payment_id"><?= e(t('Link to Already-Recorded Cash Receipt:', 'ቀደም ሲል ከተመዘገበ ደረሰኝ ጋር አዛምድ:')) ?></label>
                            <select class="input" id="existing_payment_id" name="existing_payment_id" required>
                                <option value=""><?= e(t('— Select Existing Cash Receipt —', '— የተመዘገበ ደረሰኝ ይምረጡ —')) ?></option>
                                <?php foreach ($existingCashPayments as $ep): ?>
                                    <option value="<?= (int)$ep['id'] ?>">
                                        <?= e($ep['receipt_number']) ?> · <?= e(money($ep['amount'])) ?> · <?= e(fmt_date($ep['paid_at'])) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button class="btn btn-primary btn-sm" type="submit" style="width:100%;background:#3B82F6;border-color:#3B82F6;">
                            🔗 <?= e(t('Reconcile with Recorded Cash Receipt', 'በተመዘገበ የጥሬ ገንዘብ ደረሰኝ አዛምድ')) ?>
                        </button>
                    </form>
                    <hr style="border:0;border-top:1px dashed var(--border-color,#334155);margin:14px 0;">
                    <?php endif; ?>

                    <!-- Direct Cash Receipt Intake -->
                    <form method="post" action="<?= e(url('payments/verification.php')) ?>" class="mb2" data-validate novalidate>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="reconcile_cash">
                        <input type="hidden" name="submission_id" value="<?= (int)$reviewItem['id'] ?>">
                        <div class="field">
                            <label for="receipt_number"><?= e(t('Physical Receipt Number (Optional):', 'የደረሰኝ ቁጥር (አማራጭ):')) ?></label>
                            <input class="input" type="text" id="receipt_number" name="receipt_number"
                                   placeholder="e.g. REC-MAN-0012">
                        </div>
                        <div class="field">
                            <label for="cash_notes"><?= e(t('Desk Remarks:', 'የዴስክ ማስታወሻ:')) ?></label>
                            <input class="input" type="text" id="cash_notes" name="review_notes"
                                   placeholder="<?= e(t('e.g. Received in cash at garage reception desk', 'ምሳሌ: በጋራዥ የፊት ዴስክ በጥሬ ገንዘብ ተቀብያለሁ')) ?>">
                        </div>
                        <button class="btn btn-primary btn-sm" type="submit" style="width:100%;background:#10B981;border-color:#10B981;">
                            💵 <?= e(t('Confirm Physical Cash Received at Desk', 'በዴስክ የተቀበሉትን ጥሬ ገንዘብ ያረጋግጡ')) ?>
                        </button>
                    </form>

                    <?php else: ?>
                    <!-- Standard Bank / Mobile Wallet Approval Form -->
                    <form method="post" action="<?= e(url('payments/verification.php')) ?>" class="mb2" data-validate novalidate>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="verify">
                        <input type="hidden" name="submission_id" value="<?= (int)$reviewItem['id'] ?>">
                        <div class="field">
                            <label for="verified_amount"><?= e(t('Verified Amount to Credit (ETB):', 'የሚረጋገጥ ትክክለኛ የገንዘብ መጠን (ብር):')) ?></label>
                            <input class="input" type="number" step="0.01" min="0.01" id="verified_amount" name="verified_amount"
                                   value="<?= e($reviewItem['amount']) ?>" required>
                        </div>
                        <div class="field">
                            <label for="verify_notes"><?= e(t('Auditor / Verification Remarks (Optional):', 'የአረጋጋጭ ማስታወሻ (አማራጭ):')) ?></label>
                            <input class="input" type="text" id="verify_notes" name="review_notes"
                                   placeholder="<?= e(t('e.g. Bank statement ref confirmed on CBE Portal', 'ምሳሌ: በሲቢኢ ፖርታል ላይ ተረጋግጧል')) ?>">
                        </div>
                        <button class="btn btn-primary btn-sm" type="submit" style="width:100%;background:#10B981;border-color:#10B981;">
                            ✓ <?= e(t('Verify & Settle Payment', 'ክፍያውን አረጋግጥ እና መዝግብ')) ?>
                        </button>
                    </form>
                    <?php endif; ?>

                    <hr style="border:0;border-top:1px dashed var(--border-color,#334155);margin:14px 0;">

                    <!-- Rejection Form -->
                    <form method="post" action="<?= e(url('payments/verification.php')) ?>" data-validate novalidate>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="reject">
                        <input type="hidden" name="submission_id" value="<?= (int)$reviewItem['id'] ?>">
                        <div class="field">
                            <label for="rejection_reason"><?= e(t('Rejection Reason:', 'ውድቅ የተደረገበት ምክንያት:')) ?> *</label>
                            <select class="input" id="rejection_reason" name="rejection_reason" required>
                                <option value=""><?= e(t('— Select Reason —', '— ምክንያት ይምረጡ —')) ?></option>
                                <?php foreach (ManualPaymentService::REJECTION_REASONS as $code => $label): ?>
                                    <option value="<?= e($code) ?>"><?= e(t($label, $label)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field" id="custom_reason_wrapper" style="display:none;">
                            <label for="custom_rejection_reason"><?= e(t('Specify Other Reason:', 'ሌላ የተለየ ምክንያት ያብራሩ:')) ?></label>
                            <input class="input" type="text" id="custom_rejection_reason" name="custom_rejection_reason">
                        </div>
                        <div class="field">
                            <label for="reject_notes"><?= e(t('Notes to Customer (shown on their dashboard):', 'ለደንበኛው የሚታይ ማብራሪያ:')) ?></label>
                            <input class="input" type="text" id="reject_notes" name="review_notes"
                                   placeholder="<?= e(t('e.g. Reference number not found in our daily CBE statement', 'ምሳሌ: የግብይት ቁጥሩ በባንክ ሂሳባችን ውስጥ አልተገኘም')) ?>">
                        </div>
                        <button class="btn btn-outline btn-sm" type="submit" style="width:100%;color:#EF4444;border-color:rgba(239,68,68,0.5);">
                            ✕ <?= e(t('Reject Submission', 'ውድቅ አድርግ')) ?>
                        </button>
                    </form>
                </div>
            <?php else: ?>
                <div class="card mt2" style="background:rgba(255,255,255,0.02);padding:14px;">
                    <div class="small">
                        <strong><?= e(t('Audit Record:', 'የኦዲት መዝገብ:')) ?></strong><br>
                        <?= e(t('Reviewed by: ', 'ያረጋገጠው: ')) ?><strong><?= e($reviewItem['reviewer_name'] ?: 'Staff') ?></strong><br>
                        <?= e(t('Timestamp: ', 'ቀንና ሰዓት: ')) ?><?= e(fmt_datetime($reviewItem['reviewed_at'])) ?><br>
                        <?php if ($reviewItem['status'] === 'verified'): ?>
                            <?= e(t('Receipt Created: ', 'የተዘጋጀ ደረሰኝ: ')) ?><strong><?= e($reviewItem['receipt_number']) ?></strong><br>
                            <?= e(t('Verified Amount: ', 'የተረጋገጠ መጠን: ')) ?><strong><?= e(money($reviewItem['verified_amount'])) ?></strong><br>
                        <?php elseif ($reviewItem['status'] === 'rejected'): ?>
                            <span style="color:#EF4444;"><?= e(t('Rejection Reason: ', 'ውድቅ የተደረገበት ምክንያት: ')) ?><strong><?= e($reviewItem['rejection_reason']) ?></strong></span><br>
                        <?php endif; ?>
                        <?php if (!empty($reviewItem['review_notes'])): ?>
                            <div class="muted mt1"><?= e(t('Notes: ', 'ማስታወሻ: ')) ?><?= e($reviewItem['review_notes']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Submissions Queue Table & Filters -->
<div class="card">
    <form method="get" action="<?= e(url('payments/verification.php')) ?>" class="mb3" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
        <div style="flex:1;min-width:180px;">
            <label for="status" class="small muted"><?= e(t('Filter Status:', 'የሁኔታ ማጣሪያ:')) ?></label>
            <select class="input" id="status" name="status">
                <option value="all" <?= $statusFilter === 'all' || $statusFilter === '' ? 'selected' : '' ?>><?= e(t('All Statuses', 'ሁሉም ሁኔታዎች')) ?></option>
                <option value="pending_verification" <?= $statusFilter === 'pending_verification' ? 'selected' : '' ?>><?= e(t('Pending Verification', 'ማረጋገጫ የሚጠብቅ')) ?></option>
                <option value="under_review" <?= $statusFilter === 'under_review' ? 'selected' : '' ?>><?= e(t('Under Review', 'በግምገማ ላይ')) ?></option>
                <option value="verified" <?= $statusFilter === 'verified' ? 'selected' : '' ?>><?= e(t('Verified & Settled', 'የተረጋገጡ')) ?></option>
                <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>><?= e(t('Rejected', 'ውድቅ የተደረጉ')) ?></option>
            </select>
        </div>

        <div style="flex:1;min-width:180px;">
            <label for="method_id" class="small muted"><?= e(t('Payment Provider:', 'የክፍያ አቅራቢ:')) ?></label>
            <select class="input" id="method_id" name="method_id">
                <option value="0"><?= e(t('All Providers', 'ሁሉም አቅራቢዎች')) ?></option>
                <?php foreach ($activeMethods as $m): ?>
                    <option value="<?= (int)$m['id'] ?>" <?= $methodFilter === (int)$m['id'] ? 'selected' : '' ?>>
                        <?= e(current_lang() === 'am' && !empty($m['name_am']) ? $m['name_am'] : $m['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="flex:1;min-width:200px;">
            <label for="search" class="small muted"><?= e(t('Search Ref / Customer / Invoice:', 'መፈለጊያ ቁጥር / ደንበኛ / ደረሰኝ:')) ?></label>
            <input class="input" type="text" id="search" name="search" value="<?= e($searchFilter) ?>"
                   placeholder="<?= e(t('Search reference, customer, invoice...', 'ግብይት ቁጥር፣ ደንበኛ፣ ደረሰኝ...')) ?>">
        </div>

        <div>
            <button class="btn btn-primary" type="submit"><?= e(t('Filter', 'አጣራ')) ?></button>
            <a href="<?= e(url('payments/verification.php')) ?>" class="btn btn-ghost"><?= e(t('Reset', 'አጽዳ')) ?></a>
        </div>
    </form>

    <div class="table-wrap">
        <table class="table stack">
            <thead>
                <tr>
                    <th><?= e(t('Tracking #', 'መከታተያ #')) ?></th>
                    <th><?= e(t('Invoice', 'ደረሰኝ')) ?></th>
                    <th><?= e(t('Customer', 'ደንበኛ')) ?></th>
                    <th><?= e(t('Provider / Method', 'አቅራቢ / መንገድ')) ?></th>
                    <th><?= e(t('Transaction Reference', 'የግብይት ቁጥር')) ?></th>
                    <th><?= e(t('Amount', 'መጠን')) ?></th>
                    <th><?= e(t('Proof', 'ማረጋገጫ')) ?></th>
                    <th><?= e(t('Status', 'ሁኔታ')) ?></th>
                    <th style="text-align:right;"><?= e(t('Action', 'እርምጃ')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($submissions as $s): ?>
                <tr>
                    <td class="mono">
                        <a href="<?= e(url('payments/verification.php?review=' . $s['id'])) ?>" style="font-weight:600;color:var(--gold,#D4AF37);">
                            <?= e($s['submission_number']) ?>
                        </a>
                    </td>
                    <td>
                        <a href="<?= e(url('invoices/view.php?id=' . $s['invoice_id'])) ?>">
                            <?= e($s['invoice_number']) ?>
                        </a>
                    </td>
                    <td>
                        <div><?= e($s['customer_name']) ?></div>
                        <span class="mono small muted"><?= e($s['customer_phone']) ?></span>
                    </td>
                    <td>
                        <strong><?= e($s['method_name']) ?></strong>
                        <div class="muted small"><?= e(fmt_date($s['transaction_date'])) ?></div>
                    </td>
                    <td class="mono"><?= e($s['transaction_reference'] ?: '—') ?></td>
                    <td><strong><?= e(money($s['amount'])) ?></strong></td>
                    <td>
                        <?php if (!empty($s['proof_file_path'])): ?>
                            <a href="<?= e(url('payments/proof.php?id=' . $s['id'])) ?>" target="_blank" class="btn btn-ghost btn-sm" title="View Proof">
                                📎 <?= e(t('View', 'እይ')) ?>
                            </a>
                        <?php else: ?>
                            <span class="muted small"><?= e(t('None', 'የለም')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        $statusBadges = [
                            'pending_verification' => '<span class="badge badge-pending">' . e(t('Pending', 'ማረጋገጫ የሚጠብቅ')) . '</span>',
                            'under_review' => '<span class="badge badge-progress">' . e(t('Under Review', 'በግምገማ ላይ')) . '</span>',
                            'verified' => '<span class="badge badge-active">' . e(t('Verified', 'የተረጋገጠ')) . '</span>',
                            'rejected' => '<span class="badge badge-danger">' . e(t('Rejected', 'ውድቅ')) . '</span>',
                        ];
                        echo $statusBadges[$s['status']] ?? '<span class="badge">' . e($s['status']) . '</span>';
                        ?>
                    </td>
                    <td style="text-align:right;">
                        <a href="<?= e(url('payments/verification.php?review=' . $s['id'])) ?>" class="btn btn-outline btn-sm">
                            <?= e(t('Review', 'መርምር')) ?>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$submissions): ?>
                    <tr><td colspan="9" class="empty"><?= e(t('No payment submissions found matching criteria.', 'ምንም የክፍያ ማረጋገጫ አልተገኘም።')) ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination Controls -->
    <?php if ($pg['total_pages'] > 1): ?>
        <div style="display:flex;justify-content:center;gap:6px;margin-top:20px;">
            <?php for ($i = 1; $i <= $pg['total_pages']; $i++): ?>
                <a href="<?= e(url('payments/verification.php?' . http_build_query(array_merge($_GET, ['page' => $i])))) ?>"
                   class="btn <?= $i === $pg['page'] ? 'btn-primary' : 'btn-ghost' ?> btn-sm">
                    <?= $i ?>
                </a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var reasonSelect = document.getElementById('rejection_reason');
    var customWrapper = document.getElementById('custom_reason_wrapper');
    var customInput = document.getElementById('custom_rejection_reason');

    if (reasonSelect && customWrapper && customInput) {
        reasonSelect.addEventListener('change', function () {
            if (this.value === 'other') {
                customWrapper.style.display = 'block';
                customInput.required = true;
            } else {
                customWrapper.style.display = 'none';
                customInput.required = false;
            }
        });
    }
});
</script>

<?php ui_footer(); ?>
