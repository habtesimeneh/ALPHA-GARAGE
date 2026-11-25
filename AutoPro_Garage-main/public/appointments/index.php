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
    $canManage ? '<a class="btn btn-primary" href="' . e(url('appointments/form.php')) . '">' . e(t('+ Book appointment', '+ Book appointment')) . '</a>' : ''
);
?>

<div class="card card-flat">
  <form method="get" class="filters">
    <div class="field grow">
      <label for="f_q"><?= e(t('Search', 'Search')) ?></label>
      <input type="search" id="f_q" name="q" class="input" value="<?= e($filters['q']) ?>" placeholder="<?= e(t('Customer, phone, plate or appointment #…', 'Customer, phone, plate or appointment #…')) ?>">
    </div>
    <div class="field">
      <label for="f_status"><?= e(t('Status', 'Status')) ?></label>
      <select id="f_status" name="status" class="input" data-autosubmit>
        <option value=""><?= e(t('All statuses', 'All statuses')) ?></option>
        <?php foreach (AppointmentService::STATUSES as $k => $l): ?>
          <option value="<?= e($k) ?>"<?= $filters['status'] === $k ? ' selected' : '' ?>><?= e(t($l, $l)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="f_from"><?= e(t('From date', 'From date')) ?></label>
      <input type="date" id="f_from" name="from" class="input" value="<?= e($filters['from']) ?>">
    </div>
    <div class="field">
      <label for="f_to"><?= e(t('To date', 'To date')) ?></label>
      <input type="date" id="f_to" name="to" class="input" value="<?= e($filters['to']) ?>">
    </div>
    <button class="btn btn-ghost" type="submit"><?= e(t('Filter', 'Filter')) ?></button>
    <?php if (array_filter($filters)): ?><a class="btn btn-ghost" href="<?= e(url('appointments/')) ?>"><?= e(t('Clear', 'Clear')) ?></a><?php endif; ?>
  </form>
</div>

<div class="table-wrap">
  <table class="table stack">
    <thead>
      <tr>
        <th><?= e(t('Booking #', 'Booking #')) ?></th>
        <th><?= e(t('Date & Time', 'Date & Time')) ?></th>
        <th><?= e(t('Customer', 'Customer')) ?></th>
        <th><?= e(t('Vehicle', 'Vehicle')) ?></th>
        <th><?= e(t('Requested Service', 'Requested Service')) ?></th>
        <th><?= e(t('Status', 'Status')) ?></th>
        <th><?= e(t('Workflow Stage', 'Workflow Stage')) ?></th>
        <th><?= e(t('Converted Job', 'Converted Job')) ?></th>
        <?php if ($canManage): ?><th><?= e(t('Actions', 'Actions')) ?></th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($appointments)): ?>
        <tr><td colspan="<?= $canManage ? 9 : 8 ?>" class="empty"><?= e(t('No appointments matching the selected criteria.', 'No appointments matching the selected criteria.')) ?></td></tr>
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
            <strong><?= e(t($a['service_name'] ?: 'General Inspection', $a['service_name'] ?: 'General Inspection')) ?></strong>
            <?php if (!empty($a['problem_description'])): ?><br><small class="muted"><?= e($a['problem_description']) ?></small><?php endif; ?>
          </td>
          <td><?= badge($a['status']) ?></td>
          <td>
            <?php 
              $stage = $a['workflow_stage'] ?? 'pending_reception';
              if ($stage === 'pending_manager') {
                  echo '<span class="badge" style="background:#FEF3C7;color:#92400E;border:1px solid #F59E0B;">' . e(t('⏳ Sent to Manager', '⏳ Sent to Manager')) . '</span>';
              } elseif ($stage === 'manager_approved') {
                  echo '<span class="badge" style="background:#D1FAE5;color:#065F46;border:1px solid #10B981;">' . e(t('✓ Manager Approved', '✓ Manager Approved')) . '</span>';
              } elseif ($stage === 'converted_to_job') {
                  echo '<span class="badge" style="background:#E0E7FF;color:#3730A3;">' . e(t('⚡ In Workshop', '⚡ In Workshop')) . '</span>';
              } else {
                  echo '<span class="badge" style="background:#F1F5F9;color:#475569;">' . e(t('1. At Reception', '1. At Reception')) . '</span>';
              }
            ?>
          </td>
          <td>
            <?php if ($a['converted_job_id']): ?>
              <a class="btn btn-ghost btn-sm" href="<?= e(url('job-cards/view.php?id=' . $a['converted_job_id'])) ?>"><?= e(t('Job card', 'Job')) ?> <?= e($a['converted_job_number']) ?></a>
            <?php else: ?>
              <span class="muted small"><?= e(t('Not converted', 'Not converted')) ?></span>
            <?php endif; ?>
          </td>
          <?php if ($canManage): ?>
            <td>
              <?php $stage = $a['workflow_stage'] ?? 'pending_reception'; ?>
              <?php if (!$a['converted_job_id'] && $a['status'] !== 'cancelled'): ?>
                <?php if ($stage === 'pending_reception'): ?>
                  <?= post_button(url('appointments/action.php'), ['id' => $a['id'], 'action' => 'send_to_manager'], t('📤 Send to Manager', '📤 Send to Manager'), 'btn btn-warning btn-sm', t('Forward booking to Manager for approval?', 'Forward booking to Manager for approval?')) ?>
                <?php elseif ($stage === 'pending_manager' && (can('appointments.manager_approve') || current_user()['role_name'] === 'administrator')): ?>
                  <?= post_button(url('appointments/action.php'), ['id' => $a['id'], 'action' => 'manager_approve'], t('✓ Approve Request', '✓ Approve Request'), 'btn btn-success btn-sm', t('Approve this service booking?', 'Approve this service booking?')) ?>
                <?php elseif ($stage === 'manager_approved' || $a['status'] === 'confirmed'): ?>
                  <?= post_button(url('appointments/action.php'), ['id' => $a['id'], 'action' => 'convert'], t('⚡ Check-in & Job Card', '⚡ Check-in & Job Card'), 'btn btn-primary btn-sm', t('Check in vehicle, generate Claim Ticket, and create Job Card now?', 'Check in vehicle, generate Claim Ticket, and create Job Card now?')) ?>
                <?php endif; ?>
              <?php endif; ?>
              <?php if ($a['status'] === 'scheduled' && $stage !== 'pending_manager' && $stage !== 'manager_approved'): ?>
                <?= post_button(url('appointments/action.php'), ['id' => $a['id'], 'action' => 'confirm'], t('Confirm', 'Confirm'), 'btn btn-ghost btn-sm') ?>
              <?php endif; ?>
              <?php if ($a['status'] !== 'cancelled' && $a['status'] !== 'completed' && !$a['converted_job_id']): ?>
                <?= post_button(url('appointments/action.php'), ['id' => $a['id'], 'action' => 'cancel'], t('Cancel', 'Cancel'), 'btn btn-ghost btn-sm', t('Cancel this appointment?', 'Cancel this appointment?')) ?>
              <?php endif; ?>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<?php ui_footer();

