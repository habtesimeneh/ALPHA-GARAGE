<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';
require_login();
require_permission('inventory.view');

$pid = get_int('part_id'); $type = get('type'); $from = get('from'); $to = get('to');
$where = ['1=1']; $params = [];
if ($pid) { $where[] = 't.part_id = ?'; $params[] = $pid; }
if (in_array($type, ['purchase', 'issue', 'return', 'adjustment_in', 'adjustment_out'], true)) { $where[] = 't.txn_type = ?'; $params[] = $type; }
if ($from !== '' && !validate(['d' => $from], ['d' => 'date'])) { $where[] = 'DATE(t.created_at) >= ?'; $params[] = $from; }
if ($to !== '' && !validate(['d' => $to], ['d' => 'date'])) { $where[] = 'DATE(t.created_at) <= ?'; $params[] = $to; }
$w = implode(' AND ', $where);
$pg = paginate((int) Database::value("SELECT COUNT(*) FROM inventory_transactions t WHERE $w", $params), 25, get_int('page') ?: 1);
$rows = Database::all("SELECT t.*, p.name, p.sku, u.full_name AS user_name FROM inventory_transactions t JOIN spare_parts p ON p.id = t.part_id LEFT JOIN users u ON u.id = t.created_by
    WHERE $w ORDER BY t.id DESC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}", $params);
$part = $pid ? Database::one('SELECT name FROM spare_parts WHERE id = ?', [$pid]) : null;
ui_header('Stock history', 'inventory');
page_header('Stock movement history', $part ? $part['name'] : 'All parts');
?>
<form class="filters" method="get"><?php if ($pid): ?><input type="hidden" name="part_id" value="<?= $pid ?>"><?php endif; ?>
<div class="field"><label for="type">Type</label><select class="input" id="type" name="type" data-autosubmit><option value="">All</option><?php foreach (['purchase', 'issue', 'return', 'adjustment_in', 'adjustment_out'] as $t): ?><option value="<?= e($t) ?>"<?= $type === $t ? ' selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $t))) ?></option><?php endforeach; ?></select></div>
<div class="field"><label for="from">From</label><input class="input" type="date" id="from" name="from" value="<?= e($from) ?>"></div><div class="field"><label for="to">To</label><input class="input" type="date" id="to" name="to" value="<?= e($to) ?>"></div>
<button class="btn btn-primary" type="submit">Filter</button></form>
<div class="table-wrap"><table class="table stack"><thead><tr><th>Date</th><th>Part</th><th>Type</th><th class="num">Qty</th><th class="num">Balance</th><th>Reference</th><th>By</th><th>Notes</th></tr></thead><tbody>
<?php foreach ($rows as $t): $in = in_array($t['txn_type'], ['purchase', 'return', 'adjustment_in'], true); ?>
<tr><td class="nowrap"><?= e(fmt_datetime($t['created_at'])) ?></td><td><?= e($t['name']) ?> <span class="muted small"><?= e($t['sku']) ?></span></td><td><?= badge(ucwords(str_replace('_', ' ', $t['txn_type'])), $in ? 'badge-completed' : 'badge-in-progress') ?></td>
<td class="num"><?= $in ? '+' : '−' ?><?= (int) $t['quantity'] ?></td><td class="num"><?= (int) $t['balance_after'] ?></td><td><?= e($t['reference'] ?: '—') ?></td><td><?= e($t['user_name'] ?: '—') ?></td><td><?= e($t['notes'] ?: '') ?></td></tr>
<?php endforeach; ?><?php if (!$rows): ?><tr><td colspan="8" class="empty">No stock movements.</td></tr><?php endif; ?></tbody></table></div>
<?php pagination_links($pg, ['part_id' => $pid ?: '', 'type' => $type, 'from' => $from, 'to' => $to]); ui_footer();
