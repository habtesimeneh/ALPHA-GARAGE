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
    if (!$inv) { abort(404, 'Invoice not found.'); }
    $errors = validate($_POST, ['amount' => 'required|numeric', 'method' => 'required|max:40', 'reference' => 'max:80', 'notes' => 'max:255']);
    if (!PaymentIdempotencyService::isValidFormat(post('idempotency_key'))) {
        $errors['idempotency_key'] = 'A valid idempotency key is required for payment processing.';
    }
    if (!$errors) {
        try {
            $pid = PaymentService::record($invoiceId, post('amount'), post('method'), post('reference'), post('notes'), post('idempotency_key'));
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
?>
<div class="card w-sm"><?php if ($inv): ?><div class="alert alert-info"><strong><?= e($inv['invoice_number']) ?></strong> · <?= e($inv['customer_name']) ?><br>Total <?= e(money($inv['grand_total'])) ?> · Paid <?= e(money($inv['amount_paid'])) ?> · <strong>Balance <?= e(money($inv['balance'])) ?></strong></div><?php endif; ?>
<?php if (!empty($errors['idempotency_key'])): ?><div class="alert alert-danger"><?= e($errors['idempotency_key']) ?></div><?php endif; ?>
<form method="post" data-validate novalidate><?= csrf_field() ?>
<input type="hidden" name="idempotency_key" value="<?= e($idempotencyKey) ?>">
<?php if ($inv): ?><input type="hidden" name="invoice_id" value="<?= (int) $invoiceId ?>"><?php else: form_select('invoice_id', 'Invoice', $io, '', $errors, ['required' => true]); endif;
form_input('amount', 'Amount (' . Setting::get('currency') . ')', post('amount', $inv['balance'] ?? ''), $errors, ['type' => 'number', 'step' => '0.01', 'min' => '0.01', 'required' => true]);
form_select('method', 'Payment method', $mo, post('method'), $errors, ['required' => true]); form_input('reference', 'Reference (bank slip / transaction no.)', post('reference'), $errors, ['maxlength' => 80]); form_input('notes', 'Notes', post('notes'), $errors, ['maxlength' => 255]); ?>
<button class="btn btn-primary" type="submit">Record payment</button> <a class="btn btn-ghost" href="<?= e(url('payments/')) ?>">Cancel</a></form></div>
<?php ui_footer();
