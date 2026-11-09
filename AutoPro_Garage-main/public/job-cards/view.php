<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';
$user = require_login();
require_permission('jobs.view_all', 'jobs.view_assigned');

$id = get_int('id');
$j = JobService::find($id);
if (!$j) { abort(404, 'Job card not found.'); }
if (!JobService::canAccess($j, $user)) { deny('jobs.view_all (object access)', 'job_cards', $id); }

$updates = Database::all('SELECT ju.*, u.full_name AS author FROM job_updates ju LEFT JOIN users u ON u.id = ju.user_id WHERE ju.job_id = ? ORDER BY ju.id DESC', [$id]);
$parts = Database::all('SELECT jp.*, sp.name, sp.sku, u.full_name AS issued_by_name FROM job_parts jp JOIN spare_parts sp ON sp.id = jp.part_id LEFT JOIN users u ON u.id = jp.issued_by WHERE jp.job_id = ? ORDER BY jp.id', [$id]);
$requests = Database::all('SELECT pr.*, sp.name, sp.sku, sp.quantity AS in_stock, u.full_name AS requester FROM part_requests pr JOIN spare_parts sp ON sp.id = pr.part_id LEFT JOIN users u ON u.id = pr.requested_by WHERE pr.job_id = ? ORDER BY pr.id DESC', [$id]);
$jobServices = CatalogService::forJob($id);
$catalogServices = CatalogService::all(true);

$isOpen = in_array($j['status'], JobService::OPEN, true);
$canProgress = can('jobs.progress') && JobService::canAccess($j, $user);
$targets = array_values(array_filter(JobService::TRANSITIONS[$j['status']] ?? [], function ($t) use ($j, $user) {
    return JobService::canChangeStatus($j, $user, $t);
}));
$partsTotal = 0; foreach ($parts as $p) { if (!$p['returned']) { $partsTotal += to_cents($p['unit_price']) * (int) $p['quantity']; } }
$stockParts = ($canProgress && can('jobs.request_parts') && $isOpen) ? Database::all('SELECT id, sku, name, quantity, unit FROM spare_parts WHERE is_active = 1 ORDER BY name LIMIT 2000') : [];
$mechanics = can('jobs.assign') && $isOpen ? Database::all('SELECT id, full_name, availability FROM mechanics WHERE is_active = 1 ORDER BY full_name') : [];
$act = url('job-cards/action.php');

$actions = '<button class="btn btn-ghost no-print" type="button" data-print>🖨 Print Job Card</button>';
if (can('jobs.edit') && ($isOpen || $j['status'] === 'Completed')) { $actions .= '<a class="btn btn-ghost" href="' . e(url('job-cards/form.php?id=' . $id)) . '">Edit Details</a>'; }
if ($j['invoice_id'] && can('invoices.view')) { $actions .= '<a class="btn btn-ghost" href="' . e(url('invoices/view.php?id=' . $j['invoice_id'])) . '">View Invoice</a>'; }
elseif (!$j['invoice_id'] && in_array($j['status'], ['Completed', 'Delivered'], true) && can('invoices.create')) { $actions .= post_button(url('invoices/create.php'), ['job_id' => $id], 'Generate Invoice', 'btn btn-primary'); }

ui_header($j['job_number'], 'jobs');
page_header($j['job_number'], $j['customer_name'] . ' · ' . $j['plate_number'], $actions);
?>

<div class="card">
  <div class="card-head">
    <div class="job-status-row">
      <span class="muted">Workflow Status:</span> <?= badge($j['status']) ?>
      <?php foreach ($targets as $t): ?>
        <?= post_button($act, ['do' => 'status', 'id' => $id, 'status' => $t], '→ ' . $t, $t === 'Cancelled' ? 'btn btn-danger btn-sm' : 'btn btn-primary btn-sm', in_array($t, ['Cancelled', 'Delivered'], true) ? "Change status to \"$t\"?" . ($t === 'Cancelled' ? ' Issued parts will be returned to stock.' : '') : '') ?>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="grid grid-2">
  <div class="card">
    <div class="card-head"><h3>Job Information</h3></div>
    <dl class="kv">
      <dt>Work Order #</dt><dd><code><?= e($j['job_number']) ?></code></dd>
      <dt>Date Received</dt><dd><?= e(fmt_date($j['date_received'])) ?></dd>
      <dt>Estimated Delivery</dt><dd><?= e(fmt_date($j['estimated_completion'])) ?></dd>
      <dt>Actual Delivery</dt><dd><?= e(fmt_date($j['actual_completion'])) ?></dd>
      <dt>Assigned Mechanic</dt><dd><strong><?= e($j['mechanic_name'] ?: 'Unassigned') ?></strong></dd>
      <dt>Odometer In</dt><dd><?= $j['mileage_in'] !== null ? e(number_format((int) $j['mileage_in'])) . ' km' : '—' ?></dd>
      <?php if (isset($j['priority']) && $j['priority'] !== ''): ?><dt>Priority</dt><dd><?= badge($j['priority']) ?></dd><?php endif; ?>
      <dt>Service Writer</dt><dd><?= e($j['created_by_name'] ?: '—') ?></dd>
      <dt>Labor Total</dt><dd class="font-mono"><strong><?= e(money($j['labor_cost'])) ?></strong></dd>
    </dl>
  </div>

  <div class="card">
    <div class="card-head"><h3>Customer &amp; Vehicle</h3></div>
    <dl class="kv">
      <dt>Customer</dt><dd><?= can('customers.view') ? '<a href="' . e(url('customers/view.php?id=' . $j['customer_id'])) . '"><strong>' . e($j['customer_name']) . '</strong></a>' : e($j['customer_name']) ?></dd>
      <?php if (can('customers.view')): ?><dt>Contact Phone</dt><dd><?= e($j['customer_phone']) ?></dd><?php endif; ?>
      <dt>Vehicle</dt><dd><strong><?= e($j['make'] . ' ' . $j['model'] . ($j['vehicle_year'] ? ' (' . $j['vehicle_year'] . ')' : '')) ?></strong></dd>
      <dt>Plate Number</dt><dd><?= can('vehicles.view') ? '<a href="' . e(url('vehicles/view.php?id=' . $j['vehicle_id'])) . '"><code>' . e($j['plate_number']) . '</code></a>' : e($j['plate_number']) ?></dd>
      <dt>VIN Number</dt><dd class="font-mono"><?= e($j['vin'] ?: '—') ?></dd>
      <dt>Color / Fuel</dt><dd><?= e(($j['color'] ?: '—') . ' · ' . $j['fuel_type']) ?></dd>
    </dl>
  </div>
</div>

<!-- Services Checklist -->
<div class="card">
  <div class="card-head">
    <h3>Service Checklist &amp; Tasks (<?= count($jobServices) ?>)</h3>
  </div>
  <div class="table-wrap">
    <table class="table stack">
      <thead>
        <tr>
          <th>Service Name</th>
          <th>Category</th>
          <th>Technician</th>
          <th class="num">Standard Price</th>
          <th>Status</th>
          <?php if ($canProgress && $isOpen): ?><th>Update Status</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($jobServices)): ?>
          <tr><td colspan="<?= ($canProgress && $isOpen) ? 6 : 5 ?>" class="empty">No catalog services attached to this work order yet.</td></tr>
        <?php else: foreach ($jobServices as $js): ?>
          <tr>
            <td>
              <strong><?= e($js['service_name']) ?></strong>
              <?php if (!empty($js['notes'])): ?><br><small class="muted"><?= e($js['notes']) ?></small><?php endif; ?>
            </td>
            <td><span class="badge"><?= e($js['category'] ?: 'General Repair') ?></span></td>
            <td><?= e($js['mechanic_name'] ?: 'Job Technician') ?></td>
            <td class="num font-mono"><?= money($js['price']) ?></td>
            <td><?= badge($js['status']) ?></td>
            <?php if ($canProgress && $isOpen): ?>
              <td>
                <form method="post" action="<?= e($act) ?>" class="inline-form">
                  <?= csrf_field() ?>
                  <input type="hidden" name="do" value="service_status">
                  <input type="hidden" name="id" value="<?= $id ?>">
                  <input type="hidden" name="job_service_id" value="<?= $js['id'] ?>">
                  <select name="service_status" class="input input-compact" data-autosubmit>
                    <?php foreach (['Pending', 'In Progress', 'Completed', 'Cancelled'] as $stOpt): ?>
                      <option value="<?= e($stOpt) ?>"<?= $js['status'] === $stOpt ? ' selected' : '' ?>><?= e($stOpt) ?></option>
                    <?php endforeach; ?>
                  </select>
                </form>
              </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($canProgress && $isOpen && $catalogServices): ?>
    <div class="subsection">
      <h4>+ Attach Service from Catalog</h4>
      <form method="post" action="<?= e($act) ?>" data-validate autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="attach_service">
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="form-grid">
          <div>
            <?php 
              $sOpts = ['' => '— Choose service —'];
              foreach ($catalogServices as $cs) {
                  $sOpts[$cs['id']] = $cs['name'] . ' (' . money($cs['standard_price']) . ')';
              }
              form_select('service_id', 'Service Catalog Item', $sOpts, '', [], ['required' => true]);
            ?>
          </div>
          <div>
            <?php 
              $mOpts = ['' => '— Default job mechanic —'];
              foreach ($mechanics as $m) {
                  $mOpts[$m['id']] = $m['full_name'];
              }
              form_select('service_mechanic_id', 'Assign Technician (optional)', $mOpts, '');
            ?>
          </div>
          <div class="full">
            <?php form_input('service_notes', 'Task Notes (optional)', '', [], ['placeholder' => 'Specific inspection points or notes for this service...']); ?>
          </div>
        </div>
        <button class="btn btn-primary btn-sm" type="submit">Attach Service</button>
      </form>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-head"><h3>Inspection, Problem &amp; Diagnosis</h3></div>
  <dl class="kv">
    <dt>Customer Problem</dt><dd class="pre-wrap"><?= e($j['reported_problem']) ?></dd>
    <dt>Check-in Condition</dt><dd class="pre-wrap"><?= e($j['initial_condition'] ?: '—') ?></dd>
    <dt>Technical Diagnosis</dt><dd class="pre-wrap"><?= e($j['diagnosis'] ?: '—') ?></dd>
    <dt>Required Repairs</dt><dd class="pre-wrap"><?= e($j['required_repairs'] ?: '—') ?></dd>
  </dl>
  <?php if ($canProgress && $isOpen): ?>
    <hr class="section-divider">
    <h4>Update Technical Diagnosis</h4>
    <form method="post" action="<?= e($act) ?>" data-validate autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="diagnosis">
      <input type="hidden" name="id" value="<?= $id ?>">
      <div class="form-grid">
        <?php 
          form_textarea('diagnosis', 'Technician Diagnosis', $j['diagnosis'] ?? '', [], ['maxlength' => 2000]);
          form_textarea('required_repairs', 'Required Repairs & Action Plan', $j['required_repairs'] ?? '', [], ['maxlength' => 2000]);
        ?>
      </div>
      <button class="btn btn-primary btn-sm" type="submit">Save Diagnosis</button>
    </form>
  <?php endif; ?>
</div>

<?php if ($canProgress && $isOpen): ?>
<div class="grid grid-2">
  <div class="card">
    <div class="card-head"><h3>Add Repair Note</h3></div>
    <form method="post" action="<?= e($act) ?>" data-validate autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="note">
      <input type="hidden" name="id" value="<?= $id ?>">
      <?php form_textarea('note', 'Progress Note', '', [], ['required' => true, 'maxlength' => 2000, 'placeholder' => 'Document findings, repair stages, or customer updates...']); ?>
      <button class="btn btn-primary btn-sm" type="submit">Add Note</button>
    </form>
  </div>

  <div class="card">
    <div class="card-head"><h3>Record Labor Hours</h3></div>
    <p class="muted small">Billed at <?= e(money(Setting::get('labor_hourly_rate'))) ?> per hour and added to the work order labor charge.</p>
    <form method="post" action="<?= e($act) ?>" data-validate autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="labor">
      <input type="hidden" name="id" value="<?= $id ?>">
      <?php 
        form_input('hours', 'Hours Worked', '', [], ['type' => 'number', 'step' => '0.25', 'min' => '0.25', 'max' => '24', 'required' => true, 'placeholder' => 'e.g. 1.5']);
        form_input('note', 'Description of Work Done', '', [], ['maxlength' => 255, 'placeholder' => 'e.g. Replaced front calipers and bled brakes']);
      ?>
      <button class="btn btn-primary btn-sm" type="submit">Record Labor</button>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="grid grid-2-1">
  <div class="card">
    <div class="card-head">
      <h3>Spare Parts Issued</h3>
      <?php if (can('inventory.issue') && $isOpen): ?>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('inventory/issue.php?job_id=' . $id)) ?>">+ Issue parts</a>
      <?php endif; ?>
    </div>
    <div class="table-wrap">
      <table class="table stack">
        <thead>
          <tr>
            <th>Part</th>
            <th class="num">Qty</th>
            <th class="num">Unit Price</th>
            <th class="num">Line Total</th>
            <th>Issued Time</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($parts as $p): ?>
            <tr>
              <td>
                <strong><?= e($p['name']) ?></strong> <span class="muted small"><code><?= e($p['sku']) ?></code></span>
                <?= $p['returned'] ? badge('Returned', 'badge-cancelled') : '' ?>
              </td>
              <td class="num"><?= (int) $p['quantity'] ?></td>
              <td class="num font-mono"><?= money($p['unit_price'], false) ?></td>
              <td class="num font-mono"><strong><?= money(from_cents(to_cents($p['unit_price']) * (int) $p['quantity']), false) ?></strong></td>
              <td class="small"><?= fmt_datetime($p['issued_at']) ?><br><span class="muted"><?= e($p['issued_by_name'] ?: '') ?></span></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$parts): ?>
            <tr><td colspan="5" class="empty">No spare parts issued to this job yet.</td></tr>
          <?php endif; ?>
        </tbody>
        <?php if ($parts): ?>
          <tfoot>
            <tr>
              <td colspan="3"><strong>Total Parts Charge</strong></td>
              <td class="num font-mono"><strong><?= money(from_cents($partsTotal)) ?></strong></td>
              <td></td>
            </tr>
          </tfoot>
        <?php endif; ?>
      </table>
    </div>

    <h4 class="subsection-tight">Technician Part Requests</h4>
    <?php if ($requests): ?>
      <div class="table-wrap">
        <table class="table stack">
          <thead>
            <tr><th>Part</th><th class="num">Qty</th><th>Requested By</th><th>Status</th></tr>
          </thead>
          <tbody>
            <?php foreach ($requests as $r): ?>
              <tr>
                <td><strong><?= e($r['name']) ?></strong> <span class="muted small"><code><?= e($r['sku']) ?></code></span><?php if ($r['note']): ?><br><small class="muted"><?= e($r['note']) ?></small><?php endif; ?></td>
                <td class="num"><?= (int) $r['quantity'] ?></td>
                <td class="small"><?= e($r['requester'] ?: '—') ?><br><small class="muted"><?= fmt_datetime($r['created_at']) ?></small></td>
                <td><?= badge(ucfirst($r['status'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <p class="muted small">No pending or historical part requests.</p>
    <?php endif; ?>

    <?php if ($stockParts): ?>
      <div class="subsection">
        <h4>Request Parts from Stock</h4>
        <form method="post" action="<?= e($act) ?>" data-validate autocomplete="off">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="request_part">
          <input type="hidden" name="id" value="<?= $id ?>">
          <div class="form-grid">
            <?php 
              $po = ['' => '— Select part from warehouse —'];
              foreach ($stockParts as $sp) {
                  $po[$sp['id']] = $sp['name'] . ' (' . $sp['sku'] . ') — ' . $sp['quantity'] . ' ' . $sp['unit'] . ' available';
              }
              form_select('part_id', 'Select Part', $po, '', [], ['required' => true]);
              form_input('quantity', 'Quantity', '1', [], ['type' => 'number', 'min' => 1, 'max' => 1000, 'required' => true]);
            ?>
            <div class="full">
              <?php form_input('note', 'Note for Inventory Manager', '', [], ['maxlength' => 255, 'placeholder' => 'e.g. Needed for front axle overhaul']); ?>
            </div>
          </div>
          <button class="btn btn-primary btn-sm" type="submit">Submit Part Request</button>
        </form>
      </div>
    <?php endif; ?>
  </div>

  <div>
    <?php if ($mechanics): ?>
      <div class="card">
        <div class="card-head"><h3>Assign Mechanic</h3></div>
        <form method="post" action="<?= e($act) ?>" data-validate autocomplete="off">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="assign">
          <input type="hidden" name="id" value="<?= $id ?>">
          <?php 
            $mo = ['' => '— Unassigned —'];
            foreach ($mechanics as $m) {
                $mo[$m['id']] = $m['full_name'] . ' (' . str_replace('_', ' ', $m['availability']) . ')';
            }
            form_select('mechanic_id', 'Technician', $mo, $j['mechanic_id'] ?? '');
          ?>
          <button class="btn btn-primary btn-sm" type="submit">Save Assignment</button>
        </form>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-head"><h3>Progress &amp; Activity Timeline</h3></div>
      <ul class="feed">
        <?php foreach ($updates as $u): ?>
          <li>
            <span class="dot"></span>
            <div>
              <strong><?= $u['update_type'] === 'status' && $u['new_status'] ? e(($u['old_status'] ? $u['old_status'] . ' → ' : '') . $u['new_status']) : e(ucfirst($u['update_type'])) ?></strong>
              <?php if ($u['hours']): ?>
                <span class="badge"><?= e($u['hours']) ?> hrs · <?= money($u['amount']) ?></span>
              <?php endif; ?>
              <?php if ($u['note']): ?>
                <div class="pre-wrap job-update-note"><?= e($u['note']) ?></div>
              <?php endif; ?>
              <div class="muted small"><?= e($u['author'] ?: 'System') ?> · <?= fmt_datetime($u['created_at']) ?></div>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</div>

<?php ui_footer();
