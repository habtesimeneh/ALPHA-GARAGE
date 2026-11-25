<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';
require_login();
require_permission('inventory.view');

$q = get('q'); $stock = get('stock'); $cat = get('category'); $status = get('status', 'active');
$where = ['1=1']; $params = [];
if ($q !== '') { $where[] = '(sku LIKE ? OR name LIKE ? OR supplier LIKE ?)'; $l = like_escape($q); array_push($params, $l, $l, $l); }
if ($cat !== '') { $where[] = 'category = ?'; $params[] = $cat; }
if ($stock === 'low') { $where[] = 'quantity <= reorder_level AND quantity > 0'; } elseif ($stock === 'out') { $where[] = 'quantity = 0'; }
if ($status === 'active') { $where[] = 'is_active = 1'; } elseif ($status === 'inactive') { $where[] = 'is_active = 0'; }
$w = implode(' AND ', $where);
$pg = paginate((int) Database::value("SELECT COUNT(*) FROM spare_parts WHERE $w", $params), $GLOBALS['config']['per_page'], get_int('page') ?: 1);
$rows = Database::all("SELECT * FROM spare_parts WHERE $w ORDER BY name LIMIT {$pg['per_page']} OFFSET {$pg['offset']}", $params);
$cats = array_column(Database::all('SELECT DISTINCT category FROM spare_parts WHERE category IS NOT NULL ORDER BY category'), 'category');
$low = can_any('inventory.stock', 'inventory.manage') ? InventoryService::lowStock(5) : [];
$acts = '';
if (can('inventory.issue')) { $acts .= '<a class="btn btn-ghost" href="' . e(url('inventory/issue.php')) . '">' . e(t('Issue parts', 'Issue parts')) . '</a>'; }
$acts .= '<a class="btn btn-ghost" href="' . e(url('inventory/history.php')) . '">' . e(t('Stock history', 'Stock history')) . '</a>';
if (can('inventory.stock')) { $acts .= '<a class="btn btn-ghost" href="' . e(url('inventory/stock.php')) . '">' . e(t('Stock in / adjust', 'Stock in / adjust')) . '</a>'; }
if (can('inventory.manage')) { $acts .= '<a class="btn btn-primary" href="' . e(url('inventory/form.php')) . '">' . e(t('+ Add part', '+ Add part')) . '</a>'; }
ui_header(t('Inventory', 'Inventory'), 'inventory');
page_header(t('Spare parts & inventory', 'Spare parts & inventory'), t('Stock levels, pricing and reorder alerts', 'Stock levels, pricing and reorder alerts'), $acts);
if ($low): ?><div class="alert alert-warn"><strong><?= e(t('Low-stock alert:', 'Low-stock alert:')) ?></strong> <?= e(implode(', ', array_map(fn($p) => $p['name'] . ' (' . $p['quantity'] . ')', $low))) ?><?= count(InventoryService::lowStock(6)) > 5 ? e(t(' and more…', ' and more…')) : '' ?> — <a href="<?= e(url('inventory/?stock=low')) ?>"><?= e(t('review', 'review')) ?></a></div><?php endif; ?>
<form class="filters" method="get">
  <div class="field grow"><label for="q"><?= e(t('Search', 'Search')) ?></label><input class="input" id="q" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('SKU, name or supplier', 'SKU, name or supplier')) ?>"></div>
  <div class="field"><label for="category"><?= e(t('Category', 'Category')) ?></label><select class="input" id="category" name="category" data-autosubmit><option value=""><?= e(t('All', 'All')) ?></option><?php foreach ($cats as $c): ?><option value="<?= e($c) ?>"<?= $cat === $c ? ' selected' : '' ?>><?= e(t($c, $c)) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label for="stock"><?= e(t('Stock', 'Stock')) ?></label><select class="input" id="stock" name="stock" data-autosubmit><?php foreach (['' => t('All', 'All'), 'low' => t('Low stock', 'Low stock'), 'out' => t('Out of stock', 'Out of stock')] as $k => $l): ?><option value="<?= e($k) ?>"<?= $stock === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label for="status"><?= e(t('Status', 'Status')) ?></label><select class="input" id="status" name="status" data-autosubmit><?php foreach (['active' => t('Active', 'Active'), 'inactive' => t('Inactive', 'Inactive'), 'all' => t('All', 'All')] as $k => $l): ?><option value="<?= e($k) ?>"<?= $status === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
  <button class="btn btn-primary" type="submit"><?= e(t('Search', 'Search')) ?></button></form>
<div class="table-wrap"><table class="table stack"><thead><tr><th><?= e(t('SKU', 'SKU')) ?></th><th><?= e(t('Part', 'Part')) ?></th><th><?= e(t('Category', 'Category')) ?></th><th class="num"><?= e(t('In stock', 'In stock')) ?></th><th class="num"><?= e(t('Reorder at', 'Reorder at')) ?></th><th class="num"><?= e(t('Cost', 'Cost')) ?></th><th class="num"><?= e(t('Price', 'Price')) ?></th><th><?= e(t('Supplier', 'Supplier')) ?></th><th></th></tr></thead><tbody>
<?php foreach ($rows as $p): $lowq = $p['quantity'] <= $p['reorder_level']; ?>
<tr><td class="mono"><?= e($p['sku']) ?></td><td><strong><?= e($p['name']) ?></strong> <?= !$p['is_active'] ? badge(t('Inactive', 'Inactive')) : '' ?></td><td><?= e($p['category'] ? t($p['category'], $p['category']) : '—') ?></td>
<td class="num"><?= (int) $p['quantity'] ?> <?= e($p['unit']) ?> <?= $p['quantity'] == 0 ? badge(t('Out', 'Out'), 'badge-out') : ($lowq ? badge(t('Low', 'Low'), 'badge-low') : '') ?></td><td class="num"><?= (int) $p['reorder_level'] ?></td>
<td class="num"><?= e(money($p['unit_cost'], false)) ?></td><td class="num"><?= e(money($p['unit_price'], false)) ?></td><td><?= e($p['supplier'] ?: '—') ?></td>
<td class="nowrap"><a class="btn btn-ghost btn-sm" href="<?= e(url('inventory/history.php?part_id=' . $p['id'])) ?>"><?= e(t('History', 'History')) ?></a><?php if (can('inventory.manage')): ?> <a class="btn btn-ghost btn-sm" href="<?= e(url('inventory/form.php?id=' . $p['id'])) ?>"><?= e(t('Edit', 'Edit')) ?></a> <?= post_button(url('inventory/delete.php'), ['id' => $p['id']], t('Remove', 'Remove'), 'btn btn-ghost btn-sm text-danger', t('Remove this part from catalog?', 'Remove this part from catalog?')) ?><?php endif; ?></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="9" class="empty"><?= e(t('No spare parts match.', 'No spare parts match.')) ?></td></tr><?php endif; ?></tbody></table></div>
<?php pagination_links($pg, ['q' => $q, 'stock' => $stock, 'category' => $cat, 'status' => $status]); ui_footer();
