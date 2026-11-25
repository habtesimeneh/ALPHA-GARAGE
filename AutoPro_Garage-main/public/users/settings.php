<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';
$me = require_login();
require_permission('settings.manage');

$keys = ['garage_name', 'garage_address', 'garage_phone', 'garage_email', 'currency', 'tax_rate', 'labor_hourly_rate', 'invoice_due_days', 'payment_methods', 'invoice_footer',
         'monthly_revenue_target', 'monthly_profit_target', 'monthly_expense_budget'];
$v = []; foreach ($keys as $k) { $v[$k] = Setting::get($k); }
$errors = [];
if (is_post()) {
    foreach ($keys as $k) { $v[$k] = post($k); }
    $errors = validate($v, [
        'garage_name' => 'required|max:120', 'garage_address' => 'max:255', 'garage_phone' => 'max:30',
        'garage_email' => 'email|max:150', 'currency' => 'required|max:5',
        'tax_rate' => 'required|numeric|max_val:100', 'labor_hourly_rate' => 'required|numeric',
        'invoice_due_days' => 'required|int|min_val:0|max_val:365',
        'payment_methods' => 'required|max:255', 'invoice_footer' => 'max:500',
        'monthly_revenue_target' => 'numeric|max_val:9999999999.99',
        'monthly_profit_target' => 'numeric|max_val:9999999999.99',
        'monthly_expense_budget' => 'numeric|max_val:9999999999.99',
    ]);
    // Allow blank targets (means "not configured")
    foreach (['monthly_revenue_target','monthly_profit_target','monthly_expense_budget'] as $tk) {
        if (trim((string)$v[$tk]) === '') { unset($errors[$tk]); $v[$tk] = ''; }
        elseif (isset($v[$tk]) && (float)$v[$tk] <= 0) { $errors[$tk] = ucfirst(str_replace('_',' ',$tk)) . ' must be greater than zero, or leave blank.'; }
    }
    if (!$errors) {
        $old = []; foreach ($keys as $k) { $old[$k] = Setting::get($k); }
        $changed = array_filter($v, fn($val, $k) => $old[$k] !== $val, ARRAY_FILTER_USE_BOTH);
        if ($changed) {
            Database::transaction(function () use ($changed, $old, $me) {
                foreach ($changed as $k => $val) { Setting::set($k, (string)$val, (int) $me['id']); }
                AuditLogger::log('settings_changed', 'settings', null, array_intersect_key($old, $changed), $changed);
            });
        }
        flash('success', $changed ? 'Settings saved.' : 'No changes.');
        redirect('users/settings.php');
    }
}
ui_header('Garage settings', 'settings');
page_header('Garage settings', 'Garage details, billing defaults, and dashboard performance targets');
?>
<div class="card w-md"><form method="post" data-validate novalidate><?= csrf_field() ?><div class="form-grid">
<?php
form_input('garage_name', 'Garage name', $v['garage_name'], $errors, ['required' => true, 'maxlength' => 120]);
form_input('garage_phone', 'Phone', $v['garage_phone'], $errors, ['maxlength' => 30]);
echo '<div class="full">';
form_input('garage_address', 'Address', $v['garage_address'], $errors, ['maxlength' => 255]);
echo '</div>';
form_input('garage_email', 'Email', $v['garage_email'], $errors, ['type' => 'email']);
form_input('currency', 'Currency code', $v['currency'], $errors, ['required' => true, 'maxlength' => 5]);
form_input('tax_rate', 'Tax rate (%)', $v['tax_rate'], $errors, ['type' => 'number', 'step' => '0.01', 'min' => 0, 'max' => 100, 'required' => true, 'help' => 'Applied to new invoices; existing invoices keep their rate.']);
form_input('labor_hourly_rate', 'Labor rate per hour', $v['labor_hourly_rate'], $errors, ['type' => 'number', 'step' => '0.01', 'min' => 0, 'required' => true]);
form_input('invoice_due_days', 'Invoice due after (days)', $v['invoice_due_days'], $errors, ['type' => 'number', 'min' => 0, 'required' => true]);
echo '<div class="full">';
?>
<div style="background:var(--card-bg,#1E293B);border:1px solid var(--gold,#D4AF37);border-radius:8px;padding:16px;margin:16px 0;">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <div>
            <strong style="color:var(--gold,#D4AF37);font-size:1.05rem;display:flex;align-items:center;gap:6px;">
                <span>🏦</span> <?= e(t('Garage Bank & Telebirr Accounts', 'የጋራዡ የባንክ እና የቴሌብር ሂሳቦች')) ?>
            </strong>
            <p class="muted small" style="margin:4px 0 0 0;">
                <?= e(t('Directly edit garage bank account numbers (CBE, Awash, Dashen), Telebirr merchant till numbers, and account holder names.',
                       'የጋራዡን የባንክ ሂሳብ ቁጥሮች (ንግድ ባንክ፣ አዋሽ፣ ዳሽን)፣ የቴሌብር ነጋዴ ቲል ቁጥሮች እና የሂሳብ ባለቤት ስሞችን በቀጥታ ያርትዑ።')) ?>
            </p>
        </div>
        <a href="<?= e(url('payments/methods.php')) ?>" class="btn btn-primary btn-sm">
            <?= e(t('Edit Bank & Telebirr Accounts →', 'የባንክ እና ቴሌብር ሂሳቦችን አስተካክል →')) ?>
        </a>
    </div>
</div>
<?php
form_input('payment_methods', 'Payment methods (comma separated)', $v['payment_methods'], $errors, ['required' => true, 'maxlength' => 255]);
form_textarea('invoice_footer', 'Invoice footer', $v['invoice_footer'], $errors, ['maxlength' => 500]);
echo '</div>';
echo '<div class="full"><h3 class="form-section-title">' . e(t('Dashboard targets', 'Dashboard targets')) . '</h3><p class="muted small">' . e(t('Optional. Leave blank to show “Target not configured” on dashboard progress bars. Targets are compared to the current calendar month’s actuals.', 'Optional. Leave blank to show “Target not configured” on dashboard progress bars. Targets are compared to the current calendar month’s actuals.')) . '</p></div>';
form_input('monthly_revenue_target', 'Monthly revenue target (' . e($v['currency'] ?: 'ETB') . ')', $v['monthly_revenue_target'], $errors, ['type' => 'number', 'step' => '0.01', 'min' => '0.01', 'placeholder' => t('Not configured', 'Not configured')]);
form_input('monthly_profit_target', 'Monthly profit target (' . e($v['currency'] ?: 'ETB') . ')', $v['monthly_profit_target'], $errors, ['type' => 'number', 'step' => '0.01', 'min' => '0.01', 'placeholder' => t('Not configured', 'Not configured')]);
form_input('monthly_expense_budget', 'Monthly expense budget (' . e($v['currency'] ?: 'ETB') . ')', $v['monthly_expense_budget'], $errors, ['type' => 'number', 'step' => '0.01', 'min' => '0.01', 'placeholder' => t('Not configured', 'Not configured'), 'help' => t('Budget utilization shows actual expenses as a percentage of this budget.', 'Budget utilization shows actual expenses as a percentage of this budget.')]);
?>
</div>
<button class="btn btn-primary" type="submit"><?= e(t('Save settings', 'Save settings')) ?></button></form></div>
<?php ui_footer();
