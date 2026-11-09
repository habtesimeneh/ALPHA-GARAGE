<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';
require_login();
require_permission('inventory.issue');

$currency = Setting::get('currency', 'ETB');
if (is_post()) {
    $do = post('do');
    try {
        if ($do === 'reject') {
            require_permission('inventory.issue');
            InventoryService::rejectRequest(post_int('request_id'), post('note'));
            flash('success', 'Request rejected.');
        } elseif ($do === 'fulfil') {
            $r = Database::one('SELECT job_id, part_id, quantity, status FROM part_requests WHERE id = ? FOR UPDATE', [post_int('request_id')]);
            // issue() performs final validation/locking; read here is only for better UX messaging context
            if (!$r) { throw new DomainException('Request not found.'); }
            InventoryService::issue((int) $r['job_id'], (int) $r['part_id'], (int) $r['quantity'], post_int('request_id'));
            flash('success', 'Parts issued and stock updated.');
        } elseif ($do === 'issue_direct') {
            $e = validate($_POST, ['job_id' => 'required|int|min_val:1', 'note' => 'max:255']);
            if ($e) { throw new DomainException(reset($e)); }
            $rows = $_POST['parts'] ?? null;
            if (!is_array($rows) || count($rows) > 5) {
                throw new DomainException('Submit between 1 and 5 valid part lines.');
            }
            $items = array();
            foreach ($rows as $row) {
                if (!is_array($row)) { throw new DomainException('Invalid part issue line.'); }
                $partId = isset($row['part_id']) ? $row['part_id'] : '';
                $quantity = isset($row['quantity']) ? $row['quantity'] : '';
                if ((!is_string($partId) && !is_int($partId)) || (!is_string($quantity) && !is_int($quantity))) {
                    throw new DomainException('Part and quantity must be whole numbers.');
                }
                if (trim((string) $partId) === '' && trim((string) $quantity) === '') { continue; }
                $le = validate(
                    array('part_id' => (string) $partId, 'quantity' => (string) $quantity),
                    array('part_id' => 'required|int|min_val:1', 'quantity' => 'required|int|min_val:1|max_val:100000')
                );
                if ($le) { throw new DomainException(reset($le)); }
                $items[] = array('part_id' => (int) $partId, 'quantity' => (int) $quantity);
            }
            InventoryService::issueMany(post_int('job_id'), $items);
            flash('success', 'Parts issued to job card and stock updated.');
        } else {
            throw new DomainException('Unknown action.');
        }
    } catch (DomainException $e) { flash('error', $e->getMessage()); } catch (Throwable $e) { app_log('inventory/issue.php: ' . $e->getMessage()); flash('error', 'Could not complete the stock action. Please try again.'); }
    redirect('inventory/issue.php' . (post_int('job_id') ? '?job_id=' . post_int('job_id') : ''));
}

$pending = Database::all("SELECT pr.*, sp.name, sp.sku, sp.quantity AS in_stock, sp.unit, j.job_number, j.status AS job_status, u.full_name AS requester
    FROM part_requests pr
    JOIN spare_parts sp ON sp.id = pr.part_id
    JOIN job_cards j ON j.id = pr.job_id
    LEFT JOIN users u ON u.id = pr.requested_by
    WHERE pr.status = 'pending' ORDER BY pr.id DESC LIMIT 200");
$jobs = Database::all("SELECT j.id, j.job_number, j.status, j.date_received, c.full_name AS customer_name, v.plate_number, v.make, v.model
    FROM job_cards j
    JOIN customers c ON c.id = j.customer_id
    JOIN vehicles v ON v.id = j.vehicle_id
    WHERE j.status IN ('Pending','Diagnosing','Waiting for Parts','In Progress')
    ORDER BY j.id DESC LIMIT 800");
$parts = Database::all('SELECT id, sku, name, quantity, unit, unit_price, reorder_level, is_active FROM spare_parts WHERE is_active = 1 AND quantity > 0 ORDER BY name ASC LIMIT 4000');
$lowStock = InventoryService::lowStock(8);
$selectedJob = get_int('job_id');

ui_header('Issue parts', 'inventory');
page_header('Issue parts to job cards', 'Search/select an open job card and issue available parts with live stock checks and server-side inventory validation', '<a class="btn btn-ghost" href="' . e(url('inventory/')) . '">Inventory</a><a class="btn btn-ghost" href="' . e(url('inventory/history.php')) . '">Stock history</a>');
?>
<?php if ($lowStock): ?>
<div class="alert alert-warn issue-stock-alert" role="status">
  <strong>Stock alert:</strong>
  <?php $bits = array(); foreach ($lowStock as $ls) { $bits[] = e($ls['name']) . ' (' . (int) $ls['quantity'] . ' ' . e($ls['unit']) . ' left)'; } echo implode(' · ', $bits); ?>
  . <a href="<?= e(url('inventory/?stock=low')) ?>">View low stock</a>
</div>
<?php endif; ?>

<section class="card form-section issue-direct-card" aria-labelledby="issue-direct-title">
  <h3 id="issue-direct-title" class="form-section-title">Issue parts directly</h3>
  <form method="post" data-issue-parts novalidate autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="issue_direct">
    <input type="hidden" id="f_issue_job_id" name="job_id" value="<?= $selectedJob ? (int) $selectedJob : '' ?>">

    <div class="form-grid">
      <div class="field full" data-combobox-root="issue-job">
        <label for="cb_issue_job">Select job card <span class="req">*</span></label>
        <div class="combobox" data-combobox="issue-job">
          <input class="input" id="cb_issue_job" type="text" role="combobox" aria-expanded="false" aria-controls="cb_issue_job_list" aria-autocomplete="list" autocomplete="off" placeholder="Search by job number, customer, plate, make/model" value="">
          <button type="button" class="combobox-toggle" aria-label="Toggle job card list" tabindex="-1">▾</button>
          <div class="combobox-panel" id="cb_issue_job_list" role="listbox" hidden>
            <div class="combobox-status" data-combobox-status>Loading open job cards…</div>
            <ul class="combobox-list" data-combobox-list></ul>
          </div>
        </div>
        <small class="help">Only open job cards can receive issued parts.</small>
        <div class="field-error" id="err_issue_job_id"></div>
      </div>
    </div>

    <div class="issue-lines-head">
      <h4>Parts to issue</h4>
      <p class="muted small">Choose up to 5 different active parts with available stock. Quantities and prices are rechecked on the server.</p>
    </div>
    <div class="form-grid issue-grid">
    <?php for ($line = 0; $line < 5; $line++): ?>
      <div class="issue-part-line" data-issue-line>
        <p class="issue-line-title">Part <?= $line + 1 ?><?= $line === 0 ? ' <span class="req">*</span>' : ' <span class="muted">(optional)</span>' ?></p>
        <div class="field" data-combobox-root="issue-part-<?= $line ?>">
          <label for="cb_issue_part_<?= $line ?>">Select part</label>
          <div class="combobox" data-combobox="issue-part" data-line="<?= $line ?>">
            <input class="input" id="cb_issue_part_<?= $line ?>" type="text" role="combobox" aria-expanded="false" aria-controls="cb_issue_part_list_<?= $line ?>" aria-autocomplete="list" autocomplete="off" placeholder="Search and select part by name or SKU">
            <button type="button" class="combobox-toggle" aria-label="Toggle part list" tabindex="-1">▾</button>
            <div class="combobox-panel" id="cb_issue_part_list_<?= $line ?>" role="listbox" hidden>
              <div class="combobox-status" data-combobox-status>Type to search parts…</div>
              <ul class="combobox-list" data-combobox-list></ul>
            </div>
          </div>
          <select class="input issue-part-hidden-select" id="issue-part-<?= $line ?>" name="parts[<?= $line ?>][part_id]" data-issue-part tabindex="-1" aria-hidden="true">
            <option value="">— Select part —</option>
            <?php foreach ($parts as $p): $stockLabel = (int) $p['quantity'] . ' ' . $p['unit'] . ' available'; ?>
              <option value="<?= (int) $p['id'] ?>" data-stock="<?= (int) $p['quantity'] ?>" data-unit-price="<?= e($p['unit_price']) ?>" data-reorder="<?= (int) $p['reorder_level'] ?>" data-unit="<?= e($p['unit']) ?>" data-search="<?= e(mb_strtolower($p['name'] . ' ' . $p['sku'])) ?>" data-label="<?= e($p['name'] . ' (' . $p['sku'] . ') — ' . $stockLabel) ?>"><?= e($p['name'] . ' (' . $p['sku'] . ') — ' . $stockLabel) ?></option>
            <?php endforeach; ?>
          </select>
          <small class="help">Select an available part from the dropdown list. Stock and totals update automatically.</small>
        </div>
        <div class="field">
          <label for="issue-qty-<?= $line ?>">Quantity</label>
          <input class="input" id="issue-qty-<?= $line ?>" type="number" name="parts[<?= $line ?>][quantity]" min="1" max="100000" step="1" inputmode="numeric" data-issue-quantity placeholder="0">
        </div>
        <div class="issue-line-meta" aria-live="polite">
          <span>Available: <strong data-issue-available>—</strong></span>
          <span>Unit: <strong data-issue-unit>—</strong></span>
          <span>Line total: <strong><?= e($currency) ?> <span data-issue-total>0.00</span></strong></span>
          <span class="stock-warn" data-issue-warn hidden></span>
        </div>
      </div>
    <?php endfor; ?>
    </div>

    <div class="cost-summary issue-cost-summary">
      <div class="cost-row"><span>Estimated parts total</span><strong><?= e($currency) ?> <span data-issue-grand-total>0.00</span></strong></div>
    </div>
    <p class="muted small issue-estimate-note">Estimates use current catalog prices shown here. Final stock availability, job status, and prices are validated in a database transaction when you submit.</p>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit">Issue selected parts</button>
      <a class="btn btn-ghost" href="<?= e(url('inventory/')) ?>">Cancel</a>
    </div>
  </form>
</section>

<div class="card issue-pending-card">
  <div class="card-head"><h3>Pending mechanic requests</h3><span class="muted small"><?= count($pending) ?> pending</span></div>
  <div class="table-wrap"><table class="table stack"><thead><tr><th>Job</th><th>Part</th><th class="num">Qty</th><th class="num">In stock</th><th>Requested by</th><th></th></tr></thead><tbody>
  <?php foreach ($pending as $r): $short = ((int) $r['in_stock'] < (int) $r['quantity']); ?>
    <tr>
      <td><a href="<?= e(url('job-cards/view.php?id=' . $r['job_id'])) ?>"><?= e($r['job_number']) ?></a><div class="muted small"><?= e($r['job_status']) ?></div></td>
      <td><strong><?= e($r['name']) ?></strong> <span class="muted small"><?= e($r['sku']) ?></span><?= $r['note'] ? '<div class="muted small">' . e($r['note']) . '</div>' : '' ?></td>
      <td class="num"><?= (int) $r['quantity'] ?> <?= e($r['unit']) ?></td>
      <td class="num"><?= (int) $r['in_stock'] ?> <?= e($r['unit']) ?> <?= $short ? badge('Short', 'badge-out') : badge('OK', 'badge-success') ?></td>
      <td class="small"><?= e($r['requester'] ?: '—') ?><br><?= e(fmt_datetime($r['created_at'])) ?></td>
      <td class="nowrap">
        <?= post_button(url('inventory/issue.php'), array('do' => 'fulfil', 'request_id' => $r['id'], 'job_id' => $selectedJob), 'Issue', 'btn btn-primary btn-sm', $short ? 'Stock looks short. The server will still verify availability. Continue?' : '') ?>
        <?= post_button(url('inventory/issue.php'), array('do' => 'reject', 'request_id' => $r['id'], 'job_id' => $selectedJob), 'Reject', 'btn btn-ghost btn-sm', 'Reject this request?') ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$pending): ?><tr><td colspan="6" class="empty">No pending requests.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>

<script type="application/json" id="issue-form-data"><?= json_encode(array(
    'jobs' => array_map(function ($j) {
        $label = $j['job_number'] . ' — ' . $j['customer_name'] . ' — ' . $j['plate_number'] . ' (' . trim($j['make'] . ' ' . $j['model']) . ') [' . $j['status'] . ']';
        return array('id' => (int) $j['id'], 'label' => $label, 'status' => $j['status']);
    }, $jobs),
    'selectedJobId' => $selectedJob ? (string) $selectedJob : '',
    'currency' => $currency,
), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= e(asset('js/inventory-issue.js')) ?>"></script>
<?php ui_footer();
