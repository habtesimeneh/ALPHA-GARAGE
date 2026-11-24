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
if (can('inventory.issue')) { $acts .= '<a class="btn btn-ghost" href="' . e(url('inventory/issue.php')) . '">Issue parts</a>'; }
$acts .= '<a class="btn btn-ghost" href="' . e(url('inventory/history.php')) . '">Stock history</a>';
if (can('inventory.stock')) { $acts .= '<a class="btn btn-ghost" href="' . e(url('inventory/stock.php')) . '">Stock in / adjust</a>'; }
if (can('inventory.manage')) { $acts .= '<a class="btn btn-primary" href="' . e(url('inventory/form.php')) . '">+ Add part</a>'; }
ui_header('Inventory', 'inventory');
page_header('Spare parts & inventory', 'Stock levels, pricing and reorder alerts', $acts);
if ($low): ?><div class="alert alert-warn"><strong>Low-stock alert:</strong> <?= e(implode(', ', array_map(fn($p) => $p['name'] . ' (' . $p['quantity'] . ')', $low))) ?><?= count(InventoryService::lowStock(6)) > 5 ? ' and more…' : '' ?> — <a href="<?= e(url('inventory/?stock=low')) ?>">review</a></div><?php endif; ?>
<form class="filters" method="get">
  <div class="field grow"><label for="q">Search</label><input class="input" id="q" name="q" value="<?= e($q) ?>" placeholder="SKU, name or supplier"></div>
  <div class="field"><label for="category">Category</label><select class="input" id="category" name="category" data-autosubmit><option value="">All</option><?php foreach ($cats as $c): ?><option<?= $cat === $c ? ' selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label for="stock">Stock</label><select class="input" id="stock" name="stock" data-autosubmit><?php foreach (['' => 'All', 'low' => 'Low stock', 'out' => 'Out of stock'] as $k => $l): ?><option value="<?= e($k) ?>"<?= $stock === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label for="status">Status</label><select class="input" id="status" name="status" data-autosubmit><?php foreach (['active' => 'Active', 'inactive' => 'Inactive', 'all' => 'All'] as $k => $l): ?><option value="<?= e($k) ?>"<?= $status === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
  <button class="btn btn-primary" type="submit">Search</button></form>
<div class="table-wrap"><table class="table stack"><thead><tr><th>SKU</th><th>Part</th><th>Category</th><th class="num">In stock</th><th class="num">Reorder at</th><th class="num">Cost</th><th class="num">Price</th><th>Supplier</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $p): $lowq = $p['quantity'] <= $p['reorder_level']; ?>
<tr><td class="mono"><?= e($p['sku']) ?></td><td><strong><?= e($p['name']) ?></strong> <?= !$p['is_active'] ? badge('Inactive') : '' ?></td><td><?= e($p['category'] ?: '—') ?></td>
<td class="num"><?= (int) $p['quantity'] ?> <?= e($p['unit']) ?> <?= $p['quantity'] == 0 ? badge('Out', 'badge-out') : ($lowq ? badge('Low', 'badge-low') : '') ?></td><td class="num"><?= (int) $p['reorder_level'] ?></td>
<td class="num"><?= e(money($p['unit_cost'], false)) ?></td><td class="num"><?= e(money($p['unit_price'], false)) ?></td><td><?= e($p['supplier'] ?: '—') ?></td>
<td class="nowrap"><a class="btn btn-ghost btn-sm" href="<?= e(url('inventory/history.php?part_id=' . $p['id'])) ?>">History</a><?php if (can('inventory.manage')): ?> <a class="btn btn-ghost btn-sm" href="<?= e(url('inventory/form.php?id=' . $p['id'])) ?>">Edit</a><?php endif; ?></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="9" class="empty">No spare parts match.</td></tr><?php endif; ?></tbody></table></div>
<?php pagination_links($pg, ['q' => $q, 'stock' => $stock, 'category' => $cat, 'status' => $status]); ui_footer();
