<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';
require_login();
require_permission('payments.view');
$p = PaymentService::find(get_int('id'));
if (!$p) { abort(404, 'Receipt not found.'); }
$refund = $p['kind'] === 'refund';
ui_header('Receipt ' . $p['receipt_number'], 'payments');
page_header($refund ? 'Refund voucher' : 'Payment receipt', $p['receipt_number'], '<button class="btn btn-ghost" type="button" data-print>Print</button><a class="btn btn-ghost" href="' . e(url('invoices/view.php?id=' . $p['invoice_id'])) . '">Invoice</a>');
?>
<div class="doc w-md">
  <div class="doc-head"><div><h1><?= $refund ? 'REFUND VOUCHER' : 'PAYMENT RECEIPT' ?></h1><strong><?= e(Setting::get('garage_name')) ?></strong><div class="muted small"><?= e(Setting::get('garage_address')) ?><br><?= e(Setting::get('garage_phone')) ?></div></div>
  <div class="doc-meta"><strong><?= e($p['receipt_number']) ?></strong><div class="small"><?= e(fmt_datetime($p['paid_at'])) ?></div></div></div>
  <dl class="kv"><dt><?= $refund ? 'Refunded to' : 'Received from' ?></dt><dd><?= e($p['customer_name']) ?></dd><dt>Invoice</dt><dd><?= e($p['invoice_number']) ?> (job <?= e($p['job_number']) ?>)</dd><dt>Method</dt><dd><?= e($p['method']) ?></dd>
  <?php if ($p['reference']): ?><dt>Reference</dt><dd><?= e($p['reference']) ?></dd><?php endif; ?><?php if ($p['notes']): ?><dt><?= $refund ? 'Reason' : 'Notes' ?></dt><dd><?= e($p['notes']) ?></dd><?php endif; ?>
  <dt>Amount</dt><dd><strong class="big"><?= $refund ? '−' : '' ?><?= e(money($p['amount'])) ?></strong></dd><dt>Invoice total</dt><dd><?= e(money($p['grand_total'])) ?></dd><dt>Paid to date</dt><dd><?= e(money($p['invoice_paid'])) ?></dd><dt>Balance</dt><dd><?= e(money($p['invoice_balance'])) ?> · <?= badge($p['payment_status']) ?></dd><dt>Received by</dt><dd><?= e($p['recorded_by_name'] ?: '—') ?></dd></dl>
</div>
<?php ui_footer();
