<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';
$user = require_login();
require_permission('customers.view');

$id = get_int('id');
$c = Database::one('SELECT c.*, u.full_name AS created_by_name FROM customers c LEFT JOIN users u ON u.id = c.created_by WHERE c.id = ?', [$id]);
if (!$c) { abort(404, 'Customer not found.'); }

$vehicles = Database::all('SELECT * FROM vehicles WHERE customer_id = ? ORDER BY is_active DESC, plate_number', [$id]);
$canSeeJobs = can('jobs.view_all');
$jobs = $canSeeJobs ? Database::all('SELECT j.id, j.job_number, j.date_received, j.status, j.labor_cost, v.plate_number, m.full_name AS mechanic,
        (SELECT payment_status FROM invoices WHERE job_card_id = j.id) AS payment_status
    FROM job_cards j JOIN vehicles v ON v.id = j.vehicle_id LEFT JOIN mechanics m ON m.id = j.mechanic_id WHERE j.customer_id = ? ORDER BY j.id DESC', [$id]) : [];

$invoices = can('invoices.view') ? Database::all(
    'SELECT i.*, j.job_number, v.plate_number 
     FROM invoices i
     JOIN job_cards j ON j.id = i.job_card_id
     JOIN vehicles v ON v.id = i.vehicle_id
     WHERE i.customer_id = ? ORDER BY i.id DESC', [$id]
) : [];

$billing = can('invoices.view') ? Database::one(
    "SELECT COUNT(*) n, COALESCE(SUM(grand_total),0) total, COALESCE(SUM(amount_paid),0) paid, COALESCE(SUM(balance),0) due 
     FROM invoices WHERE customer_id = ?", [$id]
) : null;

$openJobs = (int) Database::value("SELECT COUNT(*) FROM job_cards WHERE customer_id = ? AND status IN ('Pending','Diagnosing','Waiting for Parts','In Progress','Completed')", [$id]);

$actions = '';
if (can('appointments.manage') && $c['is_active']) { $actions .= '<a class="btn btn-ghost" href="' . e(url('appointments/form.php?customer_id=' . $id)) . '">+ Appointment</a>'; }
if (can('customers.manage')) { $actions .= '<a class="btn btn-ghost" href="' . e(url('customers/form.php?id=' . $id)) . '">Edit Profile</a>'; }
if (can('vehicles.manage') && $c['is_active']) { $actions .= '<a class="btn btn-ghost" href="' . e(url('vehicles/form.php?customer_id=' . $id)) . '">+ Vehicle</a>'; }
if (can('jobs.create') && $c['is_active']) { $actions .= '<a class="btn btn-primary" href="' . e(url('job-cards/form.php?customer_id=' . $id)) . '">+ Job card</a>'; }
if (!empty($invoices)) { $actions .= '<button type="button" class="btn btn-ghost" data-print>🖨 Print Statement</button>'; }
if (can('customers.deactivate')) {
    $actions .= $c['is_active']
        ? post_button(url('customers/toggle.php'), ['id' => $id, 'action' => 'deactivate'], 'Deactivate', 'btn btn-danger', 'Deactivate this customer? Their history is preserved but no new vehicles or job cards can be created.')
        : post_button(url('customers/toggle.php'), ['id' => $id, 'action' => 'activate'], 'Reactivate', 'btn btn-ghost');
}

ui_header($c['full_name'], 'customers');
page_header($c['full_name'], 'Customer Account: CUS-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT) . ' · ' . ($c['is_active'] ? 'Active' : 'Inactive'), $actions);
?>

<div class="grid grid-2">
  <div class="card">
    <div class="card-head"><h3>Contact Information</h3></div>
    <dl class="kv">
      <dt>Phone Number</dt><dd><strong><?= e($c['phone']) ?></strong></dd>
      <dt>Email Address</dt><dd><?= e($c['email'] ?: '—') ?></dd>
      <dt>Billing Address</dt><dd><?= e($c['address'] ?: '—') ?></dd>
      <dt>Registered Date</dt><dd><?= e(fmt_datetime($c['registered_at'])) ?></dd>
      <dt>Account Manager</dt><dd><?= e($c['created_by_name'] ?: 'System Administrator') ?></dd>
    </dl>
  </div>

  <div class="card">
    <div class="card-head"><h3>Account Financial Balance</h3></div>
    <dl class="kv">
      <dt>Registered Fleet</dt><dd><?= count($vehicles) ?> vehicles</dd>
      <?php if ($canSeeJobs): ?>
        <dt>Work Orders</dt><dd><?= count($jobs) ?> total (<?= $openJobs ?> active)</dd>
      <?php endif; ?>
      <?php if ($billing): ?>
        <dt>Total Invoiced</dt><dd class="font-mono"><?= money($billing['total']) ?></dd>
        <dt>Total Received</dt><dd class="font-mono text-ok"><?= money($billing['paid']) ?></dd>
        <dt>Current Balance</dt><dd class="font-mono text-lg fw-800 <?= $billing['due'] > 0 ? 'text-danger' : 'text-ok' ?>"><?= money($billing['due']) ?></dd>
      <?php endif; ?>
    </dl>
  </div>
</div>

<?php if (can('vehicles.view')): ?>
<div class="card">
  <div class="card-head"><h3>Registered Vehicles (<?= count($vehicles) ?>)</h3></div>
  <div class="table-wrap">
    <table class="table stack">
      <thead>
        <tr>
          <th>Plate Number</th>
          <th>Make &amp; Model</th>
          <th>Year</th>
          <th>VIN</th>
          <th>Fuel</th>
          <th class="num">Odometer</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($vehicles as $v): ?>
          <tr>
            <td><a href="<?= e(url('vehicles/view.php?id=' . $v['id'])) ?>"><strong><?= e($v['plate_number']) ?></strong></a></td>
            <td><?= e($v['make'] . ' ' . $v['model']) ?></td>
            <td><?= e($v['year'] ?: '—') ?></td>
            <td><code><?= e($v['vin'] ?: '—') ?></code></td>
            <td><?= e($v['fuel_type']) ?></td>
            <td class="num"><?= e(number_format((int) $v['mileage'])) ?> km</td>
            <td><?= $v['is_active'] ? badge('Active') : badge('Inactive') ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$vehicles): ?>
          <tr><td colspan="7" class="empty">No vehicles registered for this client.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if (can('invoices.view') && $invoices): ?>
<div class="card">
  <div class="card-head"><h3>Billing &amp; Invoices Statement</h3></div>
  <div class="table-wrap">
    <table class="table stack">
      <thead>
        <tr>
          <th>Invoice #</th>
          <th>Work Order</th>
          <th>Vehicle</th>
          <th>Issue Date</th>
          <th class="num">Grand Total</th>
          <th class="num">Paid</th>
          <th class="num">Balance Due</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($invoices as $inv): ?>
          <tr>
            <td><a href="<?= e(url('invoices/view.php?id=' . $inv['id'])) ?>"><strong><?= e($inv['invoice_number']) ?></strong></a></td>
            <td><a href="<?= e(url('job-cards/view.php?id=' . $inv['job_card_id'])) ?>"><?= e($inv['job_number']) ?></a></td>
            <td><?= e($inv['plate_number']) ?></td>
            <td><?= fmt_date($inv['issue_date']) ?></td>
            <td class="num font-mono"><?= money($inv['grand_total']) ?></td>
            <td class="num font-mono"><?= money($inv['amount_paid']) ?></td>
            <td class="num font-mono"><strong><?= money($inv['balance']) ?></strong></td>
            <td><?= badge($inv['payment_status']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($canSeeJobs): ?>
<div class="card">
  <div class="card-head"><h3>Workshop Service History</h3></div>
  <div class="table-wrap">
    <table class="table stack">
      <thead>
        <tr>
          <th>Job Card #</th>
          <th>Check-in Date</th>
          <th>Vehicle</th>
          <th>Mechanic</th>
          <th>Status</th>
          <th>Invoice Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($jobs as $j): ?>
          <tr>
            <td><a href="<?= e(url('job-cards/view.php?id=' . $j['id'])) ?>"><strong><?= e($j['job_number']) ?></strong></a></td>
            <td><?= e(fmt_date($j['date_received'])) ?></td>
            <td><?= e($j['plate_number']) ?></td>
            <td><?= e($j['mechanic'] ?: 'Unassigned') ?></td>
            <td><?= badge($j['status']) ?></td>
            <td><?= $j['payment_status'] ? badge($j['payment_status']) : '<span class="muted">—</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$jobs): ?>
          <tr><td colspan="6" class="empty">No repair history recorded.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php ui_footer();
