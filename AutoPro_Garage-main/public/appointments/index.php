<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';
$user = require_login();
require_permission('appointments.view');

$filters = [
    'status' => get('status'),
    'from' => get('from'),
    'to' => get('to'),
    'q' => get('q'),
];

$appointments = AppointmentService::all($filters);
$canManage = can('appointments.manage');

ui_header('Appointments & Scheduling', 'appointments');
page_header(
    'Service Appointments',
    'Customer service bookings, scheduled workshop visits, and work order conversion',
    $canManage ? '<a class="btn btn-primary" href="' . e(url('appointments/form.php')) . '">+ Book appointment</a>' : ''
);
?>

<div class="card card-flat">
  <form method="get" class="filters">
    <div class="field grow">
      <label for="f_q">Search</label>
      <input type="search" id="f_q" name="q" class="input" value="<?= e($filters['q']) ?>" placeholder="Customer, phone, plate or appointment #…">
    </div>
    <div class="field">
      <label for="f_status">Status</label>
      <select id="f_status" name="status" class="input" data-autosubmit>
        <option value="">All statuses</option>
        <?php foreach (AppointmentService::STATUSES as $k => $l): ?>
          <option value="<?= e($k) ?>"<?= $filters['status'] === $k ? ' selected' : '' ?>><?= e($l) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="f_from">From date</label>
      <input type="date" id="f_from" name="from" class="input" value="<?= e($filters['from']) ?>">
    </div>
    <div class="field">
      <label for="f_to">To date</label>
      <input type="date" id="f_to" name="to" class="input" value="<?= e($filters['to']) ?>">
    </div>
    <button class="btn btn-ghost" type="submit">Filter</button>
    <?php if (array_filter($filters)): ?><a class="btn btn-ghost" href="<?= e(url('appointments/')) ?>">Clear</a><?php endif; ?>
  </form>
</div>

<div class="table-wrap">
  <table class="table stack">
    <thead>
      <tr>
        <th>Booking #</th>
        <th>Date &amp; Time</th>
        <th>Customer</th>
        <th>Vehicle</th>
        <th>Requested Service</th>
        <th>Status</th>
        <th>Converted Job</th>
        <?php if ($canManage): ?><th>Actions</th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($appointments)): ?>
        <tr><td colspan="<?= $canManage ? 8 : 7 ?>" class="empty">No appointments matching the selected criteria.</td></tr>
      <?php else: foreach ($appointments as $a): ?>
        <tr>
          <td><code><?= e($a['appointment_number']) ?></code></td>
          <td>
            <strong><?= fmt_date($a['appointment_date']) ?></strong><br>
            <small class="muted"><?= e(date('H:i', strtotime($a['appointment_time']))) ?></small>
          </td>
          <td>
            <strong><a href="<?= e(url('customers/view.php?id=' . $a['customer_id'])) ?>"><?= e($a['customer_name']) ?></a></strong><br>
            <small class="muted"><?= e($a['customer_phone']) ?></small>
          </td>
          <td>
            <strong><a href="<?= e(url('vehicles/view.php?id=' . $a['vehicle_id'])) ?>"><?= e($a['plate_number']) ?></a></strong><br>
            <small class="muted"><?= e($a['make'] . ' ' . $a['model']) ?></small>
          </td>
          <td>
            <strong><?= e($a['service_name'] ?: 'General Inspection') ?></strong>
            <?php if (!empty($a['problem_description'])): ?><br><small class="muted"><?= e($a['problem_description']) ?></small><?php endif; ?>
          </td>
          <td><?= badge($a['status']) ?></td>
          <td>
            <?php if ($a['converted_job_id']): ?>
              <a class="btn btn-ghost btn-sm" href="<?= e(url('job-cards/view.php?id=' . $a['converted_job_id'])) ?>">Job <?= e($a['converted_job_number']) ?></a>
            <?php else: ?>
              <span class="muted small">Not converted</span>
            <?php endif; ?>
          </td>
          <?php if ($canManage): ?>
            <td>
              <?php if (!$a['converted_job_id'] && $a['status'] !== 'cancelled'): ?>
                <?= post_button(url('appointments/action.php'), ['id' => $a['id'], 'action' => 'convert'], '⚡ Convert to Job Card', 'btn btn-primary btn-sm', 'Check in vehicle and create Job Card now?') ?>
              <?php endif; ?>
              <?php if ($a['status'] === 'scheduled'): ?>
                <?= post_button(url('appointments/action.php'), ['id' => $a['id'], 'action' => 'confirm'], 'Confirm', 'btn btn-ghost btn-sm') ?>
              <?php endif; ?>
              <?php if ($a['status'] !== 'cancelled' && $a['status'] !== 'completed' && !$a['converted_job_id']): ?>
                <?= post_button(url('appointments/action.php'), ['id' => $a['id'], 'action' => 'cancel'], 'Cancel', 'btn btn-ghost btn-sm', 'Cancel this appointment?') ?>
              <?php endif; ?>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<?php ui_footer();

