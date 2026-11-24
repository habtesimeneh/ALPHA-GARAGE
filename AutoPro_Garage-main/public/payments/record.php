<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';
require_login();
require_permission('payments.record');

$invoiceId = get_int('invoice_id') ?: post_int('invoice_id');
$inv = $invoiceId ? InvoiceService::find($invoiceId) : null;
$errors = [];
$idempotencyKey = post('idempotency_key') ?: PaymentIdempotencyService::generateKey();

if (is_post()) {
    verify_csrf();
    if (!$inv) { abort(404, 'Invoice not found.'); }
    $errors = validate($_POST, ['amount' => 'required|numeric', 'method' => 'required|max:40', 'reference' => 'max:80', 'notes' => 'max:255']);
    if (!PaymentIdempotencyService::isValidFormat(post('idempotency_key'))) {
        $errors['idempotency_key'] = 'A valid idempotency key is required for payment processing.';
    }
    if (!$errors) {
        try {
            $u = current_user();
            $pid = PaymentService::record($invoiceId, post('amount'), post('method'), post('reference'), post('notes'), post('idempotency_key'));

            // If there was an active pending cash submission for this invoice, reconcile it automatically
            $pendingCashSub = Database::one(
                "SELECT ps.* FROM payment_submissions ps
                 JOIN payment_methods pm ON pm.id = ps.payment_method_id
                 WHERE ps.invoice_id = ? AND pm.type = 'cash' AND ps.status IN ('pending_verification', 'under_review') LIMIT 1",
                [$invoiceId]
            );
            if ($pendingCashSub) {
                ManualPaymentService::reconcileCashPayment((int)$pendingCashSub['id'], (int)($u['id'] ?? 1), $pid, post('reference'), post('notes'));
            }

            flash('success', 'Payment recorded.');
            redirect('payments/receipt.php?id=' . $pid);
        }
        catch (DomainException $e) { $errors['amount'] = $e->getMessage(); }
    }
}
$open = Database::all("SELECT i.id, i.invoice_number, i.balance, c.full_name FROM invoices i JOIN customers c ON c.id = i.customer_id WHERE i.balance > 0 AND i.payment_status IN ('Unpaid','Partially Paid') ORDER BY i.id DESC LIMIT 500");
$io = ['' => '— Select invoice —']; foreach ($open as $o) { $io[$o['id']] = $o['invoice_number'] . ' — ' . $o['full_name'] . ' (due ' . money($o['balance']) . ')'; }
$mo = []; foreach (Setting::paymentMethods() as $m) { $mo[$m] = $m; }
ui_header('Record payment', 'payments');
page_header('Record payment', 'Multiple payments per invoice are supported; overpayment is blocked');
$pendingBadge = ManualPaymentService::getPendingCount();
?>
<div class="tabs mb2" style="display:flex;gap:8px;flex-wrap:wrap;border-bottom:1px solid var(--border-color,#334155);padding-bottom:12px;margin-bottom:24px;">
    <a href="<?= e(url('payments/')) ?>" class="btn btn-ghost btn-sm">
        <?= e(t('Payment History', 'የክፍያ ታሪክ')) ?>
    </a>
    <?php if (can('payments.verify')): ?>
    <a href="<?= e(url('payments/verification.php')) ?>" class="btn btn-ghost btn-sm" style="position:relative;">
        <?= e(t('Verification Queue', 'የማረጋገጫ ረድፍ')) ?>
        <?php if ($pendingBadge > 0): ?>
            <span style="background:#EF4444;color:#fff;font-size:0.75rem;font-weight:700;padding:2px 7px;border-radius:9999px;margin-left:6px;"><?= $pendingBadge ?></span>
        <?php endif; ?>
    </a>
    <?php endif; ?>
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
    <a href="<?= e(url('payments/record.php')) ?>" class="btn btn-primary btn-sm">
        <?= e(t('Record Manual Payment', 'ክፍያ ይመዝግቡ')) ?>
    </a>
</div>
<div class="card w-sm"><?php if ($inv): ?><div class="alert alert-info"><strong><?= e($inv['invoice_number']) ?></strong> · <?= e($inv['customer_name']) ?><br>Total <?= e(money($inv['grand_total'])) ?> · Paid <?= e(money($inv['amount_paid'])) ?> · <strong>Balance <?= e(money($inv['balance'])) ?></strong></div><?php endif; ?>
<?php if (!empty($errors['idempotency_key'])): ?><div class="alert alert-danger"><?= e($errors['idempotency_key']) ?></div><?php endif; ?>
<form method="post" data-validate novalidate><?= csrf_field() ?>
<input type="hidden" name="idempotency_key" value="<?= e($idempotencyKey) ?>">
<?php if ($inv): ?><input type="hidden" name="invoice_id" value="<?= (int) $invoiceId ?>"><?php else: form_select('invoice_id', 'Invoice', $io, '', $errors, ['required' => true]); endif;
form_input('amount', 'Amount (' . Setting::get('currency') . ')', post('amount', $inv['balance'] ?? ''), $errors, ['type' => 'number', 'step' => '0.01', 'min' => '0.01', 'required' => true]);
form_select('method', 'Payment method', $mo, post('method'), $errors, ['required' => true]); form_input('reference', 'Reference (bank slip / transaction no.)', post('reference'), $errors, ['maxlength' => 80]); form_input('notes', 'Notes', post('notes'), $errors, ['maxlength' => 255]); ?>
<button class="btn btn-primary" type="submit">Record payment</button> <a class="btn btn-ghost" href="<?= e(url('payments/')) ?>">Cancel</a></form></div>
<?php ui_footer();
