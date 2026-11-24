<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';
$user = require_login();

$id = get_int('id');
$inv = InvoiceService::find($id);
if (!$inv) { abort(404, 'Invoice not found.'); }

// Authorization check: Staff require 'invoices.view'; Customers require verified ownership
if (($user['role_name'] ?? '') === 'customer') {
    $cust = CustomerIdentityService::resolveCustomer($user);
    $linkedCustIds = CustomerIdentityService::getLinkedCustomerIds((int) $user['id']);
    if (!in_array((int) $cust['id'], $linkedCustIds, true)) {
        $linkedCustIds[] = (int) $cust['id'];
    }
    if (!in_array((int) $inv['customer_id'], $linkedCustIds, true)) {
        AuditLogger::logSafe('idor_invoice_view_blocked', 'invoices', $id, null, [
            'actor_user_id' => $user['id'],
            'actor_customer_id' => $cust['id'],
            'target_invoice_customer' => $inv['customer_id'],
        ], 'denied');
        abort(403, 'You do not have authorization to view this invoice.');
    }
} else {
    require_permission('invoices.view');
}
$items = InvoiceService::items($id);
$pays = Database::all('SELECT p.*, u.full_name AS by_name FROM payments p LEFT JOIN users u ON u.id = p.recorded_by WHERE p.invoice_id = ? ORDER BY p.id', [$id]);
$submissions = Database::all('SELECT ps.*, pm.name AS method_name FROM payment_submissions ps JOIN payment_methods pm ON pm.id = ps.payment_method_id WHERE ps.invoice_id = ? ORDER BY ps.id DESC', [$id]);
$net = InvoiceService::netPaid($id);
$stampClass = ['Paid' => 'paid', 'Partially Paid' => 'partial', 'Unpaid' => 'unpaid', 'Refunded' => 'unpaid'][$inv['payment_status']];
$acts = '<button class="btn btn-ghost" type="button" data-print>Print / PDF</button>';
if ($inv['payment_status'] !== 'Refunded' && (float) $inv['balance'] > 0) {
    $acts .= ' <a class="btn btn-outline" style="border-color:var(--gold,#D4AF37);color:var(--gold,#D4AF37);" href="' . e(url('payments/pay.php?invoice_id=' . $id)) . '">Pay via Bank/Telebirr</a>';
}
if (can('payments.record') && $inv['payment_status'] !== 'Refunded' && (float) $inv['balance'] > 0) { $acts .= ' <a class="btn btn-primary" href="' . e(url('payments/record.php?invoice_id=' . $id)) . '">Record payment</a>'; }
ui_header($inv['invoice_number'], 'invoices');
page_header($inv['invoice_number'], 'Job card ' . $inv['job_number'], $acts);
?>
<div class="doc">
  <div class="doc-head"><div><h1>INVOICE</h1><strong><?= e(Setting::get('garage_name')) ?></strong><div class="muted small"><?= e(Setting::get('garage_address')) ?><br><?= e(Setting::get('garage_phone')) ?> · <?= e(Setting::get('garage_email')) ?></div></div>
    <div class="doc-meta"><strong><?= e($inv['invoice_number']) ?></strong><div class="small">Issued: <?= e(fmt_date($inv['issue_date'])) ?><br>Due: <?= e(fmt_date($inv['due_date'])) ?><br>Job card: <?= e($inv['job_number']) ?></div><div class="mt1"><span class="stamp <?= e($stampClass) ?>"><?= e($inv['payment_status']) ?></span></div></div></div>
  <div class="cols"><div><h4>Bill to</h4><strong><?= e($inv['customer_name']) ?></strong><br><?= e($inv['customer_phone']) ?><br><?= e($inv['customer_email'] ?: '') ?><br><?= e($inv['customer_address'] ?: '') ?></div>
    <div><h4>Vehicle</h4><strong><?= e($inv['plate_number']) ?></strong><br><?= e($inv['make'] . ' ' . $inv['model'] . ($inv['vehicle_year'] ? ' (' . $inv['vehicle_year'] . ')' : '')) ?><br>VIN: <?= e($inv['vin'] ?: '—') ?></div></div>
  <div class="table-wrap"><table class="table"><thead><tr><th>Description</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Amount</th></tr></thead><tbody>
  <?php foreach ($items as $it): ?><tr><td><?= e($it['description']) ?> <span class="muted small"><?= $it['item_type'] === 'labor' ? '(labor)' : '(part)' ?></span></td><td class="num"><?= e(rtrim(rtrim(number_format((float) $it['quantity'], 2, '.', ''), '0'), '.')) ?></td><td class="num"><?= e(money($it['unit_price'], false)) ?></td><td class="num"><?= e(money($it['line_total'], false)) ?></td></tr><?php endforeach; ?>
  <?php if (!$items): ?><tr><td colspan="4" class="empty">No billable items.</td></tr><?php endif; ?></tbody></table></div>
  <table class="totals mt1"><tr><td>Labor</td><td class="num"><?= e(money($inv['labor_total'])) ?></td></tr><tr><td>Spare parts</td><td class="num"><?= e(money($inv['parts_total'])) ?></td></tr><tr><td>Subtotal</td><td class="num"><?= e(money($inv['subtotal'])) ?></td></tr>
    <tr><td>Discount</td><td class="num">− <?= e(money($inv['discount'])) ?></td></tr><tr><td>Tax (<?= e(rtrim(rtrim($inv['tax_rate'], '0'), '.')) ?>%)</td><td class="num"><?= e(money($inv['tax_amount'])) ?></td></tr>
    <tr class="grand"><td>Grand total</td><td class="num"><?= e(money($inv['grand_total'])) ?></td></tr><tr><td>Amount paid</td><td class="num"><?= e(money($inv['amount_paid'])) ?></td></tr><tr><td><strong>Outstanding balance</strong></td><td class="num"><strong><?= e(money($inv['balance'])) ?></strong></td></tr></table>
  <?php if ($inv['notes']): ?><p class="small"><strong>Notes:</strong> <?= e($inv['notes']) ?></p><?php endif; ?>
  <p class="muted small"><?= e(Setting::get('invoice_footer')) ?></p>
</div>

<?php if (can('payments.view')): ?>
<div class="card"><h3>Payment history</h3><div class="table-wrap"><table class="table stack"><thead><tr><th>Receipt</th><th>Date</th><th>Type</th><th>Method</th><th>Reference</th><th class="num">Amount</th><th>By</th><th></th></tr></thead><tbody>
<?php foreach ($pays as $p): ?><tr><td class="mono"><?= e($p['receipt_number']) ?></td><td class="nowrap"><?= e(fmt_datetime($p['paid_at'])) ?></td><td><?= $p['kind'] === 'refund' ? badge('Refund', 'badge-refunded') : badge('Payment', 'badge-paid') ?></td><td><?= e($p['method']) ?></td><td><?= e($p['reference'] ?: ($p['kind'] === 'refund' ? $p['notes'] : '—')) ?></td><td class="num"><?= $p['kind'] === 'refund' ? '−' : '' ?><?= e(money($p['amount'], false)) ?></td><td><?= e($p['by_name'] ?: '—') ?></td><td><a class="btn btn-ghost btn-sm" href="<?= e(url('payments/receipt.php?id=' . $p['id'])) ?>">Receipt</a></td></tr><?php endforeach; ?>
<?php if (!$pays): ?><tr><td colspan="8" class="empty">No payments yet.</td></tr><?php endif; ?></tbody></table></div></div>
<?php endif; ?>

<?php if ($submissions): ?>
<div class="card">
  <h3><?= e(t('Manual Payment Submissions', 'የእጅ ክፍያ ማረጋገጫዎች')) ?></h3>
  <div class="table-wrap">
    <table class="table stack">
      <thead>
        <tr>
          <th><?= e(t('Submission #', 'ማረጋገጫ ቁጥር')) ?></th>
          <th><?= e(t('Date', 'ቀን')) ?></th>
          <th><?= e(t('Provider', 'አቅራቢ')) ?></th>
          <th><?= e(t('Reference', 'ሪፈረንስ')) ?></th>
          <th class="num"><?= e(t('Amount', 'መጠን')) ?></th>
          <th><?= e(t('Status', 'ሁኔታ')) ?></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($submissions as $s): ?>
          <tr>
            <td class="mono"><strong><?= e($s['submission_number']) ?></strong></td>
            <td class="nowrap"><?= e(fmt_date($s['transaction_date'])) ?></td>
            <td><?= e($s['method_name']) ?></td>
            <td class="mono small"><?= e($s['transaction_reference']) ?></td>
            <td class="num"><strong><?= e(money($s['amount'])) ?></strong></td>
            <td>
              <?php
              $statusBadges = [
                'pending_verification' => '<span class="badge badge-pending">' . e(t('Pending', 'በመጠባበቅ ላይ')) . '</span>',
                'under_review' => '<span class="badge" style="background:rgba(59,130,246,0.15);color:#3B82F6;border:1px solid rgba(59,130,246,0.3);">' . e(t('Under Review', 'በግምገማ ላይ')) . '</span>',
                'verified' => '<span class="badge badge-paid">' . e(t('Verified', 'ተረጋግጧል')) . '</span>',
                'rejected' => '<span class="badge badge-rejected">' . e(t('Rejected', 'ውድቅ ሆኗል')) . '</span>',
              ];
              echo $statusBadges[$s['status']] ?? '<span class="badge">' . e($s['status']) . '</span>';
              if ($s['status'] === 'rejected' && $s['rejection_reason']) {
                echo '<div class="muted small text-danger" style="margin-top:2px;">' . e($s['rejection_reason']) . '</div>';
              }
              ?>
            </td>
            <td>
              <?php if (can('payments.verify')): ?>
                <a class="btn btn-ghost btn-sm" href="<?= e(url('payments/verification.php?review=' . $s['id'])) ?>"><?= e(t('Review', 'ግምገማ')) ?></a>
              <?php elseif (!empty($s['proof_file_path'])): ?>
                <a class="btn btn-ghost btn-sm" href="<?= e(url('payments/proof.php?id=' . $s['id'])) ?>" target="_blank"><?= e(t('Proof', 'ማረጋገጫ')) ?></a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="grid grid-2 no-print">
<?php if (can('invoices.edit')): ?>
  <div class="card"><h3>Discount &amp; notes</h3><form method="post" action="<?= e(url('invoices/action.php')) ?>" data-validate novalidate><?= csrf_field() ?><input type="hidden" name="do" value="discount"><input type="hidden" name="id" value="<?= $id ?>">
    <?php form_input('discount', 'Discount amount (' . Setting::get('currency') . ')', $inv['discount'], [], ['type' => 'number', 'step' => '0.01', 'min' => 0]); form_textarea('notes', 'Notes', $inv['notes'] ?? '', [], ['maxlength' => 500]); ?><button class="btn btn-primary btn-sm" type="submit">Save</button></form>
    <?php if (!$pays): ?><hr><?= post_button(url('invoices/action.php'), ['do' => 'sync', 'id' => $id], 'Re-sync from job card', 'btn btn-ghost btn-sm', 'Rebuild invoice lines from the job card?') ?><?php endif; ?></div>
<?php endif; ?>
<?php if (can('payments.refund') && $net['net'] > 0): ?>
  <div class="card"><h3>Authorised refund</h3><p class="muted small">Net paid: <?= e(money(from_cents($net['net']))) ?>. Refunds are permanent, require a reason and are audited.</p>
    <form method="post" action="<?= e(url('payments/refund.php')) ?>" data-validate novalidate><?= csrf_field() ?><input type="hidden" name="invoice_id" value="<?= $id ?>"><input type="hidden" name="idempotency_key" value="<?= e(PaymentIdempotencyService::generateKey()) ?>">
    <?php form_input('amount', 'Refund amount', from_cents($net['net']), [], ['type' => 'number', 'step' => '0.01', 'min' => '0.01', 'required' => true]); $mo = []; foreach (Setting::paymentMethods() as $m) { $mo[$m] = $m; } form_select('method', 'Refunded via', $mo); form_input('reason', 'Reason', '', [], ['required' => true, 'maxlength' => 255]); ?>
    <button class="btn btn-danger btn-sm" type="submit" data-no-loader>Issue refund</button></form></div>
<?php endif; ?>
</div>
<?php ui_footer();
