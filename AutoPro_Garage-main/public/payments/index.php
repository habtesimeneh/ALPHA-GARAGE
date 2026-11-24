<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';
require_login();
require_permission('payments.view');

$q = get('q'); $method = get('method'); $from = get('from'); $to = get('to');
$where = ['1=1']; $params = [];
if ($q !== '') { $where[] = '(p.receipt_number LIKE ? OR i.invoice_number LIKE ? OR c.full_name LIKE ? OR p.reference LIKE ?)'; $l = like_escape($q); array_push($params, $l, $l, $l, $l); }
if ($method !== '') { $where[] = 'p.method = ?'; $params[] = $method; }
if ($from !== '' && !validate(['d' => $from], ['d' => 'date'])) { $where[] = 'p.paid_at >= ?'; $params[] = $from . ' 00:00:00'; }
if ($to !== '' && !validate(['d' => $to], ['d' => 'date'])) { $where[] = 'p.paid_at <= ?'; $params[] = $to . ' 23:59:59'; }
$w = implode(' AND ', $where);
$base = 'FROM payments p JOIN invoices i ON i.id = p.invoice_id JOIN customers c ON c.id = i.customer_id LEFT JOIN users u ON u.id = p.recorded_by';
$pg = paginate((int) Database::value("SELECT COUNT(*) $base WHERE $w", $params), $GLOBALS['config']['per_page'], get_int('page') ?: 1);
$rows = Database::all("SELECT p.*, i.invoice_number, c.full_name AS customer, u.full_name AS by_name $base WHERE $w ORDER BY p.id DESC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}", $params);
$net = Database::value("SELECT COALESCE(SUM(CASE WHEN p.kind='payment' THEN p.amount ELSE -p.amount END),0) $base WHERE $w", $params);
ui_header('Payments', 'payments');
page_header('Payments', 'Payment and refund history (records are immutable)');
$pendingBadge = ManualPaymentService::getPendingCount();
?>
<div class="tabs mb2" style="display:flex;gap:8px;flex-wrap:wrap;border-bottom:1px solid var(--border-color,#334155);padding-bottom:12px;margin-bottom:24px;">
    <a href="<?= e(url('payments/')) ?>" class="btn btn-primary btn-sm">
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
    <?php if (can('payments.record')): ?>
    <a href="<?= e(url('payments/record.php')) ?>" class="btn btn-ghost btn-sm">
        <?= e(t('Record Manual Payment', 'ክፍያ ይመዝግቡ')) ?>
    </a>
    <?php endif; ?>
</div>
<div class="stats"><div class="stat green"><div class="label">Net received (filtered)</div><div class="value"><?= e(money($net)) ?></div></div></div>
<form class="filters" method="get">
<div class="field grow"><label for="q">Search</label><input class="input" id="q" name="q" value="<?= e($q) ?>" placeholder="Receipt, invoice, customer, reference"></div>
<div class="field"><label for="method">Method</label><select class="input" id="method" name="method" data-autosubmit><option value="">All</option><?php foreach (Setting::paymentMethods() as $m): ?><option<?= $method === $m ? ' selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?></select></div>
<div class="field"><label for="from">From</label><input class="input" type="date" id="from" name="from" value="<?= e($from) ?>"></div><div class="field"><label for="to">To</label><input class="input" type="date" id="to" name="to" value="<?= e($to) ?>"></div>
<button class="btn btn-primary" type="submit">Filter</button></form>
<div class="table-wrap"><table class="table stack"><thead><tr><th>Receipt</th><th>Date</th><th>Invoice</th><th>Customer</th><th>Method</th><th class="num">Amount</th><th>By</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $p): ?><tr><td class="mono"><?= e($p['receipt_number']) ?></td><td class="nowrap"><?= e(fmt_datetime($p['paid_at'])) ?></td><td><?= can('invoices.view') ? '<a href="' . e(url('invoices/view.php?id=' . $p['invoice_id'])) . '">' . e($p['invoice_number']) . '</a>' : e($p['invoice_number']) ?></td><td><?= e($p['customer']) ?></td><td><?= e($p['method']) ?> <?= $p['kind'] === 'refund' ? badge('Refund', 'badge-refunded') : '' ?></td><td class="num"><?= $p['kind'] === 'refund' ? '−' : '' ?><?= e(money($p['amount'], false)) ?></td><td><?= e($p['by_name'] ?: '—') ?></td><td><a class="btn btn-ghost btn-sm" href="<?= e(url('payments/receipt.php?id=' . $p['id'])) ?>">Receipt</a></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="8" class="empty">No payments found.</td></tr><?php endif; ?></tbody></table></div>
<?php pagination_links($pg, ['q' => $q, 'method' => $method, 'from' => $from, 'to' => $to]); ui_footer();
