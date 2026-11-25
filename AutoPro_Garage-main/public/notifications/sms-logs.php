<?php
declare(strict_types=1);

require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';

$user = require_login();
require_permission('sms.view_logs', 'notifications.view');

// Handle POST actions (Save Gateway & Send Test SMS)
if (is_post()) {
    verify_csrf();
    $action = post('action');

    if ($action === 'save_gateway' && can('settings.manage')) {
        Setting::set('sms_gateway_provider', post('sms_gateway_provider', 'local_spool'), (int)$user['id']);
        Setting::set('sms_api_key', post('sms_api_key', ''), (int)$user['id']);
        Setting::set('sms_sender_id', post('sms_sender_id', ''), (int)$user['id']);
        Setting::set('sms_identifier_id', post('sms_identifier_id', ''), (int)$user['id']);
        Setting::set('sms_gateway_url', post('sms_gateway_url', ''), (int)$user['id']);
        Setting::set('sms_username', post('sms_username', 'sandbox'), (int)$user['id']);
        flash('success', t('SMS Gateway settings updated successfully.', 'የኤስኤምኤስ ጌትዌይ ቅንብሮች በተሳካ ሁኔታ ተዘምነዋል።'));
        redirect('notifications/sms-logs.php');
    }

    if ($action === 'send_test') {
        $testPhone = post('test_phone');
        $testMsg = post('test_message', 'Alpha Garage: This is a live test SMS notification from your workshop management system.');
        if ($testPhone === '') {
            flash('danger', t('Please enter a valid phone number (e.g. +251911002233).', 'እባክዎ ትክክለኛ ስልክ ቁጥር ያስገቡ።'));
        } else {
            SmsService::send($testPhone, $testMsg, 'manual_test', 'Test Recipient', 'administrator');
            $provider = Setting::get('sms_gateway_provider', 'local_spool');
            flash('success', t("Test SMS dispatched via [$provider] to $testPhone! Check ledger below.", "የሙከራ ኤስኤምኤስ በ [$provider] ወደ $testPhone ተልኳል! ከታች ያለውን መዝገብ ይመልከቱ።"));
        }
        redirect('notifications/sms-logs.php');
    }
}

$phoneFilter = trim((string) get('phone', ''));
$roleFilter = trim((string) get('role', ''));
$eventFilter = trim((string) get('event', ''));

$where = ['1=1'];
$params = [];

if ($phoneFilter !== '') {
    $where[] = 'recipient_phone LIKE ?';
    $params[] = '%' . $phoneFilter . '%';
}
if ($roleFilter !== '') {
    $where[] = 'recipient_role = ?';
    $params[] = $roleFilter;
}
if ($eventFilter !== '') {
    $where[] = 'event_type = ?';
    $params[] = $eventFilter;
}

$sql = 'SELECT * FROM sms_logs WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 100';
$logs = Database::all($sql, $params);

$totalSent = (int) Database::value('SELECT COUNT(*) FROM sms_logs WHERE status = "sent"');
$totalEvents = (int) Database::value('SELECT COUNT(DISTINCT event_type) FROM sms_logs');

ui_header('SMS Notification Audit Logs', 'notifications');
page_header(
    'SMS Notification Dispatch Audit',
    'Real-time mobile phone SMS dispatch ledger tracking all customer and staff operational workflow alerts'
);
?>

<div class="grid grid-2" style="margin-bottom: 1.25rem;">
  <div class="card card-flat">
    <div style="font-size: 0.85rem; text-transform: uppercase; color: #64748B; font-weight: 600;"><?= e(t('Total SMS Dispatches', 'Total SMS Dispatches')) ?></div>
    <div style="font-size: 1.8rem; font-weight: 800; color: #0D281E; font-family: monospace;"><?= number_format($totalSent) ?></div>
  </div>
  <div class="card card-flat">
    <div style="font-size: 0.85rem; text-transform: uppercase; color: #64748B; font-weight: 600;"><?= e(t('Workflow Event Types', 'Workflow Event Types')) ?></div>
    <div style="font-size: 1.8rem; font-weight: 800; color: #C5A059; font-family: monospace;"><?= number_format($totalEvents) ?> <?= e(t('Events', 'Events')) ?></div>
  </div>
</div>

<?php
$currentProvider = Setting::get('sms_gateway_provider', 'local_spool');
$currentApiKey = Setting::get('sms_api_key', '');
$currentSender = Setting::get('sms_sender_id', '');
$currentAndroidUrl = Setting::get('sms_gateway_url', '');
?>
<!-- Gateway Configuration & Live Test Panel -->
<div class="card mb3" style="border: 1px solid rgba(212,175,55,0.3); background: var(--card-bg, #1E293B);">
  <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 16px;">
    <div>
      <h3 style="margin: 0; color: var(--gold, #D4AF37); display: flex; align-items: center; gap: 8px;">
        <span>📱</span> <?= e(t('SMS Gateway Setup & Live Testing', 'የኤስኤምኤስ ጌትዌይ ማዋቀሪያ እና የቀጥታ ሙከራ')) ?>
      </h3>
      <span class="muted small">
        <?= e(t('Choose your SMS delivery provider (AfroMessage Free Trial, Android Phone Gateway, or Local Test Spool).',
               'የኤስኤምኤስ መላኪያ ዘዴ ይምረጡ (የአፍሮሜሴጅ ነፃ ሙከራ፣ የአንድሮይድ ስልክ ጌትዌይ ወይም ነፃ የውስጥ ስፑል)።')) ?>
      </span>
    </div>
    <div>
      <span class="badge badge-active" style="font-size: 0.8rem;">
        <?= e(t('Current Gateway: ', 'የአሁኑ ጌትዌይ: ')) ?> <strong><?= e(strtoupper($currentProvider)) ?></strong>
      </span>
    </div>
  </div>

  <div class="grid-2" style="gap: 20px;">
    <!-- Live Test SMS Form -->
    <div style="background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.06); border-radius: 8px; padding: 16px;">
      <h4 style="margin: 0 0 10px 0; color: #fff;">
        <?= e(t('Send Live Test SMS', 'የቀጥታ የሙከራ ኤስኤምኤስ ይላኩ')) ?>
      </h4>
      <form method="post" action="<?= e(url('notifications/sms-logs.php')) ?>" data-validate novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="send_test">
        <div class="field" style="margin-bottom: 12px;">
          <label for="test_phone"><?= e(t('Recipient Mobile Phone Number', 'የተቀባይ ስልክ ቁጥር')) ?> *</label>
          <input class="input" type="text" id="test_phone" name="test_phone" required
                 placeholder="e.g. +251911223344 or 0911223344"
                 value="<?= e(Setting::get('garage_phone', '+251911000000')) ?>">
        </div>
        <div class="field" style="margin-bottom: 12px;">
          <label for="test_message"><?= e(t('Test SMS Message Content', 'የመልእክቱ ይዘት')) ?></label>
          <input class="input" type="text" id="test_message" name="test_message"
                 value="Alpha Garage: Dear Customer, your vehicle repair status is updated. Tel: +251 911 000 000">
        </div>
        <button class="btn btn-primary btn-sm" type="submit">
          🚀 <?= e(t('Send Live Test SMS Now', 'አሁኑኑ የሙከራ SMS ላክ')) ?>
        </button>
      </form>
    </div>

    <!-- Gateway Settings Form -->
    <?php if (can('settings.manage')): ?>
    <div style="background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.06); border-radius: 8px; padding: 16px;">
      <h4 style="margin: 0 0 10px 0; color: #fff;">
        <?= e(t('Configure SMS Gateway Provider', 'የኤስኤምኤስ አቅራቢ ያዋቅሩ')) ?>
      </h4>
      <form method="post" action="<?= e(url('notifications/sms-logs.php')) ?>" data-validate novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_gateway">
        <div class="field" style="margin-bottom: 12px;">
          <label for="sms_gateway_provider"><?= e(t('Gateway Provider', 'የጌትዌይ አቅራቢ')) ?></label>
          <select class="input" id="sms_gateway_provider" name="sms_gateway_provider">
            <option value="local_spool" <?= $currentProvider === 'local_spool' ? 'selected' : '' ?>>
              <?= e(t('Local Test Spool (Free - Logs to DB & File only)', 'የውስጥ የሙከራ ስፑል (ነፃ - ዳታቤዝ ላይ ብቻ)')) ?>
            </option>
            <option value="afromessage" <?= in_array($currentProvider, ['afromessage', 'sms_et'], true) ? 'selected' : '' ?>>
              <?= e(t('AfroMessage Ethiopia (afromessage.com - Free Trial Credits)', 'አፍሮሜሴጅ ኢትዮጵያ (ነፃ የሙከራ ሳንቲም ይሰጣል)')) ?>
            </option>
            <option value="android_gateway" <?= $currentProvider === 'android_gateway' ? 'selected' : '' ?>>
              <?= e(t('Android Mobile Phone Gateway (100% Free with Ethio Telecom SIM)', 'የአንድሮይድ ስልክ ጌትዌይ (በኢትዮ ቴሌኮም ሲም - 100% ነፃ)')) ?>
            </option>
            <option value="africastalking" <?= $currentProvider === 'africastalking' ? 'selected' : '' ?>>
              <?= e(t('Africa\'s Talking (Developer Sandbox)', 'አፍሪካስ ቶኪንግ (የሙከራ ሳንድቦክስ)')) ?>
            </option>
          </select>
        </div>
        <div class="field" style="margin-bottom: 12px;">
          <label for="sms_api_key"><?= e(t('API Token / Key (for AfroMessage / Africa\'s Talking)', 'የ API ቁልፍ / ቶከን')) ?></label>
          <input class="input" type="text" id="sms_api_key" name="sms_api_key"
                 value="<?= e($currentApiKey) ?>" placeholder="Paste your API token from afromessage.com...">
        </div>
        <div class="grid-2" style="gap: 10px; margin-bottom: 12px;">
          <div class="field">
            <label for="sms_sender_id"><?= e(t('Sender ID / Name', 'የላኪ ስም (Sender Name)')) ?></label>
            <input class="input" type="text" id="sms_sender_id" name="sms_sender_id"
                   value="<?= e($currentSender ?: 'AlphaGarage') ?>" placeholder="e.g. AlphaGarage">
          </div>
          <div class="field">
            <label for="sms_gateway_url"><?= e(t('Android Gateway URL', 'የአንድሮይድ ጌትዌይ IP')) ?></label>
            <input class="input" type="text" id="sms_gateway_url" name="sms_gateway_url"
                   value="<?= e($currentAndroidUrl) ?>" placeholder="e.g. http://192.168.1.50:8080/send">
          </div>
        </div>
        <button class="btn btn-ghost btn-sm" type="submit">
          💾 <?= e(t('Save Gateway Settings', 'ጌትዌይ ሴቭ አድርግ')) ?>
        </button>
      </form>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="card card-flat">
  <form method="get" class="filters">
    <div class="field grow">
      <label for="f_phone"><?= e(t('Phone Number', 'Phone Number')) ?></label>
      <input type="text" id="f_phone" name="phone" class="input" value="<?= e($phoneFilter) ?>" placeholder="<?= e(t('Filter by mobile phone...', 'Filter by mobile phone...')) ?>">
    </div>
    <div class="field">
      <label for="f_role"><?= e(t('Recipient Role', 'Recipient Role')) ?></label>
      <select id="f_role" name="role" class="input" data-autosubmit>
        <option value=""><?= e(t('All Roles', 'All Roles')) ?></option>
        <option value="customer"<?= $roleFilter === 'customer' ? ' selected' : '' ?>><?= e(t('Customer', 'Customer')) ?></option>
        <option value="receptionist"<?= $roleFilter === 'receptionist' ? ' selected' : '' ?>><?= e(t('Receptionist', 'Receptionist')) ?></option>
        <option value="administrator"<?= $roleFilter === 'administrator' ? ' selected' : '' ?>><?= e(t('Manager (Admin)', 'Manager (Admin)')) ?></option>
        <option value="mechanic"<?= $roleFilter === 'mechanic' ? ' selected' : '' ?>><?= e(t('Mechanic', 'Mechanic')) ?></option>
        <option value="accountant"<?= $roleFilter === 'accountant' ? ' selected' : '' ?>><?= e(t('Finance Manager', 'Finance Manager')) ?></option>
      </select>
    </div>
    <div class="field">
      <label for="f_event"><?= e(t('Event Type', 'Event Type')) ?></label>
      <select id="f_event" name="event" class="input" data-autosubmit>
        <option value=""><?= e(t('All Events', 'All Events')) ?></option>
        <option value="booking_received"<?= $eventFilter === 'booking_received' ? ' selected' : '' ?>><?= e(t('Booking Received', 'Booking Received')) ?></option>
        <option value="submitted_to_manager"<?= $eventFilter === 'submitted_to_manager' ? ' selected' : '' ?>><?= e(t('Submitted to Manager', 'Submitted to Manager')) ?></option>
        <option value="manager_approved_staff"<?= $eventFilter === 'manager_approved_staff' ? ' selected' : '' ?>><?= e(t('Manager Approved', 'Manager Approved')) ?></option>
        <option value="mechanic_assigned"<?= $eventFilter === 'mechanic_assigned' ? ' selected' : '' ?>><?= e(t('Mechanic Assigned', 'Mechanic Assigned')) ?></option>
        <option value="work_completed_staff"<?= $eventFilter === 'work_completed_staff' ? ' selected' : '' ?>><?= e(t('Work Completed', 'Work Completed')) ?></option>
        <option value="submitted_to_finance"<?= $eventFilter === 'submitted_to_finance' ? ' selected' : '' ?>><?= e(t('Submitted to Finance', 'Submitted to Finance')) ?></option>
        <option value="finance_cleared_staff"<?= $eventFilter === 'finance_cleared_staff' ? ' selected' : '' ?>><?= e(t('Finance Cleared', 'Finance Cleared')) ?></option>
        <option value="customer_pickup_ready"<?= $eventFilter === 'customer_pickup_ready' ? ' selected' : '' ?>><?= e(t('Customer Pickup Ready', 'Customer Pickup Ready')) ?></option>
        <option value="vehicle_released_to_customer"<?= $eventFilter === 'vehicle_released_to_customer' ? ' selected' : '' ?>><?= e(t('Vehicle Released', 'Vehicle Released')) ?></option>
        <option value="delivery_finalized_customer"<?= $eventFilter === 'delivery_finalized_customer' ? ' selected' : '' ?>><?= e(t('Delivery Finalized', 'Delivery Finalized')) ?></option>
      </select>
    </div>
    <button class="btn btn-ghost" type="submit"><?= e(t('Filter', 'Filter')) ?></button>
    <?php if ($phoneFilter || $roleFilter || $eventFilter): ?>
      <a class="btn btn-ghost" href="<?= e(url('notifications/sms-logs.php')) ?>"><?= e(t('Clear', 'Clear')) ?></a>
    <?php endif; ?>
  </form>
</div>

<?php if ($currentProvider === 'local_spool'): ?>
<div class="alert alert-info" style="margin-bottom:1.25rem;display:flex;align-items:flex-start;gap:12px;">
  <span style="font-size:1.4rem;line-height:1;">ℹ️</span>
  <div>
    <strong><?= e(t('Notice: Local Spool Mode Active (Simulation Mode)', 'ማስታወሻ: የውስጥ የሙከራ ሁነታ (Local Spool Mode) በርቷል')) ?></strong><br>
    <span class="small" style="line-height:1.4;display:inline-block;margin-top:2px;">
      <?= e(t('The system is successfully generating and auditing all workflow SMS messages. Because your computer does not have a physical SIM card, delivery to a real hand-held phone requires connecting Method 2 (Android Phone SMS Gateway) or AfroMessage API.',
             'ሲስተሙ የሁሉንም የስራ ፍሰቶች SMS መልእክቶች በትክክል እያዘጋጀ በዳታቤዝ እና በፋይል ላይ ይመዘግባል። ኮምፒውተርዎ የሲም ካርድ መክተቻ ስለሌለው፣ መልእክቱ በቀጥታ በእጅ ወደሚያዝ እውነተኛ ስልክ እንዲደርስ እርስዎ የመረጡትን Method 2 (አንድሮይድ ስልክ ጌትዌይ) ወይም የአፍሮሜሴጅ API ማገናኘት ያስፈልጋል።')) ?>
    </span>
  </div>
</div>
<?php endif; ?>

<div class="table-wrap">
  <table class="table stack">
    <thead>
      <tr>
        <th><?= e(t('Date & Time', 'Date & Time')) ?></th>
        <th><?= e(t('Recipient', 'Recipient')) ?></th>
        <th><?= e(t('Role', 'Role')) ?></th>
        <th><?= e(t('Workflow Event', 'Workflow Event')) ?></th>
        <th><?= e(t('Message Content', 'Message Content')) ?></th>
        <th><?= e(t('Entity', 'Entity')) ?></th>
        <th><?= e(t('Status', 'Status')) ?></th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($logs)): ?>
        <tr><td colspan="7" class="empty"><?= e(t('No SMS dispatches logged yet.', 'No SMS dispatches logged yet.')) ?></td></tr>
      <?php else: foreach ($logs as $l): ?>
        <tr>
          <td class="small" style="white-space: nowrap;">
            <strong><?= fmt_date($l['created_at']) ?></strong><br>
            <span class="muted"><?= e(date('H:i:s', strtotime($l['created_at']))) ?></span>
          </td>
          <td>
            <strong><?= e($l['recipient_phone']) ?></strong>
            <?php if (!empty($l['recipient_name'])): ?><br><small class="muted"><?= e($l['recipient_name']) ?></small><?php endif; ?>
          </td>
          <td>
            <span class="badge" style="text-transform: capitalize;"><?= e($l['recipient_role'] ?: 'Customer') ?></span>
          </td>
          <td>
            <span class="badge" style="background:#0D281E;color:#C5A059;font-size:0.75rem;">
              <?= e(str_replace('_', ' ', $l['event_type'])) ?>
            </span>
          </td>
          <td style="max-width: 380px;">
            <div style="font-size: 0.85rem; line-height: 1.4; color: #334155;">
              <?= e($l['message']) ?>
            </div>
          </td>
          <td class="small">
            <?php if ($l['related_entity_type'] === 'job_cards' && $l['related_entity_id']): ?>
              <a class="btn btn-ghost btn-sm" href="<?= e(url('job-cards/view.php?id=' . $l['related_entity_id'])) ?>">Job #<?= $l['related_entity_id'] ?></a>
            <?php elseif ($l['related_entity_type'] === 'appointments' && $l['related_entity_id']): ?>
              <a class="btn btn-ghost btn-sm" href="<?= e(url('appointments/')) ?>">Apt #<?= $l['related_entity_id'] ?></a>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if (($l['gateway'] ?? 'local_spool') === 'local_spool'): ?>
              <span class="badge" style="background:rgba(100,116,139,0.15);color:#94A3B8;border:1px solid rgba(100,116,139,0.3);" title="Local Spool: Logged in system. Real cellular gateway (Android Phone or AfroMessage) required to deliver to handset.">
                <?= e(t('Spool (Local Test)', 'የውስጥ ስፑል (ሙከራ)')) ?>
              </span>
            <?php elseif ($l['status'] === 'delivered'): ?>
              <span class="badge badge-success" title="Delivered live via <?= e($l['gateway']) ?>">
                ✓ <?= e(t('Delivered (Live)', 'ደርሷል (ቀጥታ)')) ?>
              </span>
            <?php else: ?>
              <span class="badge badge-pending"><?= e(ucfirst($l['status'])) ?> (<?= e($l['gateway']) ?>)</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<?php ui_footer();
