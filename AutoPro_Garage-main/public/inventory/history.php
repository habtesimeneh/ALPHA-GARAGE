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
ui_header(t('Stock history', 'Stock history'), 'inventory');
page_header(t('Stock movement history', 'Stock movement history'), $part ? $part['name'] : t('All parts', 'All parts'));
$typeLabels = ['purchase' => t('Purchase', 'Purchase'), 'issue' => t('Issue', 'Issue'), 'return' => t('Return', 'Return'), 'adjustment_in' => t('Adjustment In', 'Adjustment In'), 'adjustment_out' => t('Adjustment Out', 'Adjustment Out')];
?>
<form class="filters" method="get"><?php if ($pid): ?><input type="hidden" name="part_id" value="<?= $pid ?>"><?php endif; ?>
<div class="field"><label for="type"><?= e(t('Type', 'Type')) ?></label><select class="input" id="type" name="type" data-autosubmit><option value=""><?= e(t('All', 'All')) ?></option><?php foreach ($typeLabels as $k => $l): ?><option value="<?= e($k) ?>"<?= $type === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
<div class="field"><label for="from"><?= e(t('From', 'From')) ?></label><input class="input" type="date" id="from" name="from" value="<?= e($from) ?>"></div><div class="field"><label for="to"><?= e(t('To', 'To')) ?></label><input class="input" type="date" id="to" name="to" value="<?= e($to) ?>"></div>
<button class="btn btn-primary" type="submit"><?= e(t('Filter', 'Filter')) ?></button></form>
<div class="table-wrap"><table class="table stack"><thead><tr><th><?= e(t('Date', 'Date')) ?></th><th><?= e(t('Part', 'Part')) ?></th><th><?= e(t('Type', 'Type')) ?></th><th class="num"><?= e(t('Qty', 'Qty')) ?></th><th class="num"><?= e(t('Balance', 'Balance')) ?></th><th><?= e(t('Reference', 'Reference')) ?></th><th><?= e(t('By', 'By')) ?></th><th><?= e(t('Notes', 'Notes')) ?></th></tr></thead><tbody>
<?php foreach ($rows as $t): $in = in_array($t['txn_type'], ['purchase', 'return', 'adjustment_in'], true); ?>
<tr><td class="nowrap"><?= e(fmt_datetime($t['created_at'])) ?></td><td><?= e($t['name']) ?> <span class="muted small"><?= e($t['sku']) ?></span></td><td><?= badge($typeLabels[$t['txn_type']] ?? ucwords(str_replace('_', ' ', $t['txn_type'])), $in ? 'badge-completed' : 'badge-in-progress') ?></td>
<td class="num"><?= $in ? '+' : '−' ?><?= (int) $t['quantity'] ?></td><td class="num"><?= (int) $t['balance_after'] ?></td><td><?= e($t['reference'] ?: '—') ?></td><td><?= e($t['user_name'] ?: '—') ?></td><td><?= e($t['notes'] ?: '') ?></td></tr>
<?php endforeach; ?><?php if (!$rows): ?><tr><td colspan="8" class="empty"><?= e(t('No stock movements.', 'No stock movements.')) ?></td></tr><?php endif; ?></tbody></table></div>
<?php pagination_links($pg, ['part_id' => $pid ?: '', 'type' => $type, 'from' => $from, 'to' => $to]); ui_footer();
