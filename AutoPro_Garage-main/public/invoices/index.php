<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';
require_login();
require_permission('invoices.view');

$q = get('q'); $status = get('status'); $from = get('from'); $to = get('to');
$where = ['1=1']; $params = [];
if ($q !== '') { $where[] = '(i.invoice_number LIKE ? OR j.job_number LIKE ? OR c.full_name LIKE ? OR v.plate_number LIKE ?)'; $l = like_escape($q); array_push($params, $l, $l, $l, $l); }
if ($status === 'outstanding') { $where[] = "i.payment_status IN ('Unpaid','Partially Paid')"; }
elseif (in_array($status, ['Unpaid', 'Partially Paid', 'Paid', 'Refunded'], true)) { $where[] = 'i.payment_status = ?'; $params[] = $status; }
if ($from !== '' && !validate(['d' => $from], ['d' => 'date'])) { $where[] = 'i.issue_date >= ?'; $params[] = $from; }
if ($to !== '' && !validate(['d' => $to], ['d' => 'date'])) { $where[] = 'i.issue_date <= ?'; $params[] = $to; }
$w = implode(' AND ', $where);
$base = 'FROM invoices i JOIN job_cards j ON j.id = i.job_card_id JOIN customers c ON c.id = i.customer_id JOIN vehicles v ON v.id = i.vehicle_id';
$pg = paginate((int) Database::value("SELECT COUNT(*) $base WHERE $w", $params), $GLOBALS['config']['per_page'], get_int('page') ?: 1);
$rows = Database::all("SELECT i.*, j.job_number, c.full_name AS customer, v.plate_number $base WHERE $w ORDER BY i.id DESC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}", $params);
$sum = Database::one("SELECT COALESCE(SUM(i.grand_total),0) g, COALESCE(SUM(i.amount_paid),0) p, COALESCE(SUM(i.balance),0) b $base WHERE $w", $params);
ui_header('Invoices', 'invoices');
page_header('Invoices', 'Generated from completed job cards');
?>
<div class="stats"><div class="stat navy"><div class="label"><?= e(t('Billed', 'Billed')) ?></div><div class="value"><?= e(money($sum['g'])) ?></div></div><div class="stat green"><div class="label"><?= e(t('Collected', 'Collected')) ?></div><div class="value"><?= e(money($sum['p'])) ?></div></div><div class="stat red"><div class="label"><?= e(t('Outstanding', 'Outstanding')) ?></div><div class="value"><?= e(money($sum['b'])) ?></div></div></div>
<form class="filters" method="get">
<div class="field grow"><label for="q"><?= e(t('Search', 'Search')) ?></label><input class="input" id="q" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('Invoice no., job, customer, plate', 'Invoice no., job, customer, plate')) ?>"></div>
<div class="field"><label for="status"><?= e(t('Status', 'Status')) ?></label><select class="input" id="status" name="status" data-autosubmit><option value=""><?= e(t('All', 'All')) ?></option><option value="outstanding"<?= $status === 'outstanding' ? ' selected' : '' ?>><?= e(t('Outstanding', 'Outstanding')) ?></option><?php foreach (['Unpaid', 'Partially Paid', 'Paid', 'Refunded'] as $s): ?><option<?= $status === $s ? ' selected' : '' ?> value="<?= e($s) ?>"><?= e(t($s, $s)) ?></option><?php endforeach; ?></select></div>
<div class="field"><label for="from"><?= e(t('From', 'From')) ?></label><input class="input" type="date" id="from" name="from" value="<?= e($from) ?>"></div><div class="field"><label for="to"><?= e(t('To', 'To')) ?></label><input class="input" type="date" id="to" name="to" value="<?= e($to) ?>"></div>
<button class="btn btn-primary" type="submit"><?= e(t('Filter', 'Filter')) ?></button><a class="btn btn-ghost" href="<?= e(url('invoices/')) ?>"><?= e(t('Reset', 'Reset')) ?></a></form>
<div class="table-wrap"><table class="table stack"><thead><tr><th><?= e(t('Invoice', 'Invoice')) ?></th><th><?= e(t('Date', 'Date')) ?></th><th><?= e(t('Customer', 'Customer')) ?></th><th><?= e(t('Job', 'Job')) ?></th><th class="num"><?= e(t('Total', 'Total')) ?></th><th class="num"><?= e(t('Paid', 'Paid')) ?></th><th class="num"><?= e(t('Balance', 'Balance')) ?></th><th><?= e(t('Status', 'Status')) ?></th></tr></thead><tbody>
<?php foreach ($rows as $i): ?><tr><td><a href="<?= e(url('invoices/view.php?id=' . $i['id'])) ?>"><strong><?= e($i['invoice_number']) ?></strong></a></td><td class="nowrap"><?= e(fmt_date($i['issue_date'])) ?></td><td><?= e($i['customer']) ?><div class="muted small"><?= e($i['plate_number']) ?></div></td><td><?= e($i['job_number']) ?></td>
<td class="num"><?= e(money($i['grand_total'], false)) ?></td><td class="num"><?= e(money($i['amount_paid'], false)) ?></td><td class="num"><?= e(money($i['balance'], false)) ?></td><td><?= badge($i['payment_status']) ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="8" class="empty"><?= e(t('No invoices found.', 'No invoices found.')) ?></td></tr><?php endif; ?></tbody></table></div>
<?php pagination_links($pg, ['q' => $q, 'status' => $status, 'from' => $from, 'to' => $to]); ui_footer();
