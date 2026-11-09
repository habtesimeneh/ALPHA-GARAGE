<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';
$user = require_login();

$id = get_int('id');
$job = $id ? JobService::find($id) : null;
if ($id && !$job) { abort(404, 'Job card not found.'); }
require_permission($job ? 'jobs.edit' : 'jobs.create');
if ($job && !JobService::canAccess($job, $user)) { deny('jobs.view_all', 'job_cards', $id); }

$isCreate = !$job;
$currency = Setting::get('currency', 'ETB');

$v = $job ?: [
    'customer_id' => get_int('customer_id') ?: '',
    'vehicle_id' => get_int('vehicle_id') ?: '',
    'mechanic_id' => '',
    'date_received' => date('Y-m-d'),
    'reported_problem' => '',
    'initial_condition' => '',
    'required_repairs' => '',
    'labor_cost' => '0.00',
    'estimated_completion' => '',
    'mileage_in' => '',
    'priority' => 'Normal',
];
$selectedServiceIds = [];
$errors = [];

if (is_post()) {
    $selectedServiceIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['service_ids'] ?? [])), function ($x) { return $x > 0; })));
    $v = [
        'customer_id' => post('customer_id', (string) ($job['customer_id'] ?? '')),
        'vehicle_id' => post('vehicle_id', (string) ($job['vehicle_id'] ?? '')),
        'mechanic_id' => can('jobs.assign') && $isCreate ? post('mechanic_id') : (string) ($job['mechanic_id'] ?? ''),
        'date_received' => post('date_received'),
        'reported_problem' => post('reported_problem'),
        'initial_condition' => post('initial_condition'),
        'required_repairs' => post('required_repairs'),
        'labor_cost' => post('labor_cost', '0') ?: '0',
        'estimated_completion' => post('estimated_completion'),
        'mileage_in' => post('mileage_in'),
        'priority' => post('priority', 'Normal') ?: 'Normal',
    ];
    $rules = [
        'date_received' => 'required|date',
        'reported_problem' => 'required|min:5|max:2000',
        'initial_condition' => 'max:2000',
        'required_repairs' => 'max:2000',
        'labor_cost' => 'numeric',
        'estimated_completion' => 'date',
        'mileage_in' => 'int|min_val:0',
        'priority' => 'in:Low,Normal,High,Urgent',
    ];
    if ($isCreate) {
        $rules['customer_id'] = 'required|int';
        $rules['vehicle_id'] = 'required|int';
        $rules['mechanic_id'] = 'int';
    }
    $errors = validate($v, $rules);
    if (!$errors && $v['estimated_completion'] !== '' && $v['estimated_completion'] < $v['date_received']) {
        $errors['estimated_completion'] = 'Must not be before the received date.';
    }
    if ($isCreate && !can('jobs.assign')) {
        $v['mechanic_id'] = '';
    }
    if (!$errors) {
        try {
            if ($job) {
                JobService::update($id, $v);
                $newId = $id;
            } else {
                $v['service_ids'] = $selectedServiceIds;
                $newId = JobService::create($v);
            }
            flash('success', $job ? 'Job card updated.' : 'Job card created.');
            redirect('job-cards/view.php?id=' . $newId);
        } catch (DomainException $e) {
            $errors['_'] = $e->getMessage();
        } catch (Throwable $e) {
            app_log('job-cards/form.php submit: ' . $e->getMessage());
            $errors['_'] = 'Could not save the job card. Please try again.';
        }
    }
}

// Initial payload for create form (small, searchable list; data.php provides on-demand search).
$customers = [];
$vehicles = [];
$mechanics = Database::all("SELECT id, full_name, specialty, availability FROM mechanics WHERE is_active = 1 ORDER BY full_name ASC LIMIT 500");
$services = Database::all("SELECT id, code, name, category, description, standard_price FROM services WHERE is_active = 1 ORDER BY category ASC, name ASC LIMIT 500");
$preCustomer = $isCreate && !empty($v['customer_id']) ? Database::one('SELECT id, full_name, phone, is_active FROM customers WHERE id = ?', [(int) $v['customer_id']]) : null;
$preVehicle = $isCreate && !empty($v['vehicle_id']) ? Database::one('SELECT id, customer_id, plate_number, make, model, year, is_active FROM vehicles WHERE id = ?', [(int) $v['vehicle_id']]) : null;

if ($isCreate) {
    $customers = Database::all('SELECT id, full_name, phone FROM customers WHERE is_active = 1 ORDER BY full_name ASC LIMIT 500');
    if ($preCustomer && $preCustomer['is_active'] && !in_array((int) $preCustomer['id'], array_column($customers, 'id'), true)) {
        array_unshift($customers, ['id' => $preCustomer['id'], 'full_name' => $preCustomer['full_name'], 'phone' => $preCustomer['phone']]);
    }
    if ($preCustomer && $preCustomer['is_active']) {
        $vehicles = Database::all('SELECT id, customer_id, plate_number, make, model, year FROM vehicles WHERE customer_id = ? AND is_active = 1 ORDER BY plate_number ASC LIMIT 200', [(int) $preCustomer['id']]);
    }
    if ($preVehicle && $preVehicle['is_active'] && (string) $preVehicle['customer_id'] === (string) ($v['customer_id'] ?? '') && !in_array((int) $preVehicle['id'], array_column($vehicles, 'id'), true)) {
        array_unshift($vehicles, $preVehicle);
    }
}

$formConfig = [
    'dataUrl' => url('job-cards/data.php'),
    'currency' => $currency,
    'isCreate' => $isCreate,
    'csrf' => '',
    'selected' => [
        'customer_id' => (string) ($v['customer_id'] ?? ''),
        'vehicle_id' => (string) ($v['vehicle_id'] ?? ''),
        'mechanic_id' => (string) ($v['mechanic_id'] ?? ''),
        'service_ids' => array_map('intval', $selectedServiceIds),
    ],
];

ui_header($job ? 'Edit job card' : 'New job card', 'jobs');
page_header($job ? 'Edit ' . $job['job_number'] : 'New job card', $job ? $job['customer_name'] . ' · ' . $job['plate_number'] : 'Create a job card with real customer, vehicle, mechanic, and service data');
if (!empty($errors['_'])): ?><div class="alert alert-danger" role="alert"><?= e($errors['_']) ?></div><?php endif; ?>
<div class="card jobcard-form-card">
  <form method="post" data-validate data-jobcard-form novalidate autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" id="f_customer_id" name="customer_id" value="<?= e((string) ($v['customer_id'] ?? '')) ?>">
    <input type="hidden" id="f_vehicle_id" name="vehicle_id" value="<?= e((string) ($v['vehicle_id'] ?? '')) ?>">
    <input type="hidden" id="f_mechanic_id" name="mechanic_id" value="<?= e((string) ($v['mechanic_id'] ?? '')) ?>">
    <div id="service_ids_container"><?php foreach ($selectedServiceIds as $sid): ?><input type="hidden" name="service_ids[]" value="<?= (int) $sid ?>"><?php endforeach; ?></div>

    <script type="application/json" id="jobcard-form-config"><?= json_encode($formConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

    <?php if ($isCreate): ?>
    <section class="form-section" aria-labelledby="sec-customer">
      <h3 id="sec-customer" class="form-section-title">1. Customer Information</h3>
      <div class="form-grid">
        <div class="field<?= isset($errors['customer_id']) ? ' has-error' : '' ?>" data-combobox-root="customer">
          <label for="cb_customer">Customer <span class="req">*</span></label>
          <div class="combobox" data-combobox="customer">
            <input class="input" id="cb_customer" type="text" role="combobox" aria-expanded="false" aria-controls="cb_customer_list" aria-autocomplete="list" autocomplete="off" placeholder="Search customer by name or phone" value="<?= e($preCustomer ? $preCustomer['full_name'] . ' (' . $preCustomer['phone'] . ')' : '') ?>">
            <button type="button" class="combobox-toggle" aria-label="Toggle customer list" tabindex="-1">▾</button>
            <div class="combobox-panel" id="cb_customer_list" role="listbox" hidden>
              <div class="combobox-status" data-combobox-status>Loading customers…</div>
              <ul class="combobox-list" data-combobox-list></ul>
            </div>
          </div>
          <small class="help">Select an active registered customer. The vehicle list will load for that customer.</small>
          <div class="field-error" id="err_customer_id"><?= e($errors['customer_id'] ?? '') ?></div>
        </div>

        <div class="field<?= isset($errors['vehicle_id']) ? ' has-error' : '' ?>" data-combobox-root="vehicle">
          <label for="cb_vehicle">Vehicle <span class="req">*</span></label>
          <div class="combobox" data-combobox="vehicle">
            <input class="input" id="cb_vehicle" type="text" role="combobox" aria-expanded="false" aria-controls="cb_vehicle_list" aria-autocomplete="list" autocomplete="off" placeholder="Select a customer first" value="<?= e($preVehicle ? $preVehicle['plate_number'] . ' — ' . trim($preVehicle['make'] . ' ' . $preVehicle['model']) . (!empty($preVehicle['year']) ? ' (' . $preVehicle['year'] . ')' : '') : '') ?>" <?= empty($v['customer_id']) ? 'disabled' : '' ?>>
            <button type="button" class="combobox-toggle" aria-label="Toggle vehicle list" tabindex="-1">▾</button>
            <div class="combobox-panel" id="cb_vehicle_list" role="listbox" hidden>
              <div class="combobox-status" data-combobox-status>Select a customer to load vehicles.</div>
              <ul class="combobox-list" data-combobox-list></ul>
            </div>
          </div>
          <small class="help">Only vehicles belonging to the selected customer are shown.</small>
          <div class="field-error" id="err_vehicle_id"><?= e($errors['vehicle_id'] ?? '') ?></div>
        </div>
      </div>
    </section>
    <?php else: ?>
    <section class="form-section">
      <h3 class="form-section-title">Customer &amp; Vehicle</h3>
      <p class="muted small">Customer and vehicle are preserved for existing job cards. <strong><?= e($job['customer_name']) ?></strong> · <?= e($job['plate_number']) ?></p>
    </section>
    <?php endif; ?>

    <section class="form-section" aria-labelledby="sec-mechanic">
      <h3 id="sec-mechanic" class="form-section-title"><?= $isCreate ? '2. Mechanic Assignment' : 'Assignment' ?></h3>
      <div class="form-grid">
        <?php if ($isCreate && can('jobs.assign')): ?>
        <div class="field<?= isset($errors['mechanic_id']) ? ' has-error' : '' ?>" data-combobox-root="mechanic">
          <label for="cb_mechanic">Assigned mechanic</label>
          <div class="combobox" data-combobox="mechanic">
            <input class="input" id="cb_mechanic" type="text" role="combobox" aria-expanded="false" aria-controls="cb_mechanic_list" aria-autocomplete="list" autocomplete="off" placeholder="Search mechanic or assign later" value="">
            <button type="button" class="combobox-toggle" aria-label="Toggle mechanic list" tabindex="-1">▾</button>
            <div class="combobox-panel" id="cb_mechanic_list" role="listbox" hidden>
              <div class="combobox-status" data-combobox-status>Loading mechanics…</div>
              <ul class="combobox-list" data-combobox-list></ul>
            </div>
          </div>
          <small class="help">Active mechanics only. Leave blank to assign later.</small>
          <div class="field-error" id="err_mechanic_id"><?= e($errors['mechanic_id'] ?? '') ?></div>
        </div>
        <?php else: ?>
          <div class="field"><label>Mechanic</label><input class="input" value="<?= e($job['mechanic_name'] ?? 'Assignment managed from job card details') ?>" disabled></div>
        <?php endif; ?>
      </div>
    </section>

    <?php if ($isCreate): ?>
    <section class="form-section" aria-labelledby="sec-services">
      <h3 id="sec-services" class="form-section-title">3. Job &amp; Service Information</h3>
      <div class="form-grid">
        <div class="field full" data-combobox-root="service">
          <label for="cb_service">Services</label>
          <div class="combobox combobox-multi" data-combobox="service">
            <input class="input" id="cb_service" type="text" role="combobox" aria-expanded="false" aria-controls="cb_service_list" aria-autocomplete="list" autocomplete="off" placeholder="Search and add active services">
            <button type="button" class="combobox-toggle" aria-label="Toggle service list" tabindex="-1">▾</button>
            <div class="combobox-panel" id="cb_service_list" role="listbox" hidden>
              <div class="combobox-status" data-combobox-status>Loading services…</div>
              <ul class="combobox-list" data-combobox-list></ul>
            </div>
          </div>
          <small class="help">Select one or more active services. Duplicate selections are prevented automatically.</small>
          <div class="selected-services" id="selected_services" aria-live="polite"></div>
        </div>
      </div>
    </section>
    <?php endif; ?>

    <section class="form-section" aria-labelledby="sec-schedule">
      <h3 id="sec-schedule" class="form-section-title"><?= $isCreate ? '4. Priority and Scheduling' : 'Scheduling &amp; Cost' ?></h3>
      <div class="form-grid">
        <?php form_input('date_received', 'Date received', $v['date_received'], $errors, ['type' => 'date', 'required' => true]); ?>
        <?php form_input('estimated_completion', 'Estimated completion', $v['estimated_completion'], $errors, ['type' => 'date']); ?>
        <?php form_input('mileage_in', 'Mileage on arrival (km)', $v['mileage_in'], $errors, ['type' => 'number', 'min' => 0, 'step' => '1']); ?>
        <?php if ($isCreate): ?>
          <?php form_select('priority', 'Priority', ['Low' => 'Low', 'Normal' => 'Normal', 'High' => 'High', 'Urgent' => 'Urgent'], $v['priority'] ?? 'Normal', $errors, ['required' => true]); ?>
        <?php endif; ?>
        <?php form_input('labor_cost', 'Base labor cost (' . $currency . ')', $v['labor_cost'], $errors, ['type' => 'number', 'min' => 0, 'step' => '0.01', 'help' => $isCreate ? 'Selected service prices are added to this estimate.' : 'Mechanics add time-based labor on top of this while working.']); ?>
      </div>
    </section>

    <?php if ($isCreate): ?>
    <section class="form-section" aria-labelledby="sec-cost">
      <h3 id="sec-cost" class="form-section-title">5. Cost Summary</h3>
      <div class="cost-summary" id="cost_summary" data-currency="<?= e($currency) ?>">
        <div class="cost-row"><span>Base labor</span><strong id="cost_base_labor"><?= e($currency) ?> 0.00</strong></div>
        <div class="cost-row"><span>Selected services</span><strong id="cost_services"><?= e($currency) ?> 0.00</strong></div>
        <div class="cost-row cost-total"><span>Total estimated cost</span><strong id="cost_total"><?= e($currency) ?> 0.00</strong></div>
      </div>
      <p class="muted small">Service costs come from the active services catalog. The final job labor includes selected service prices.</p>
    </section>
    <?php endif; ?>

    <section class="form-section" aria-labelledby="sec-notes">
      <h3 id="sec-notes" class="form-section-title"><?= $isCreate ? '6. Additional Notes' : 'Job Details' ?></h3>
      <div class="form-grid">
        <div class="full"><?php form_textarea('reported_problem', 'Reported problem', $v['reported_problem'], $errors, ['required' => true, 'maxlength' => 2000, 'placeholder' => 'Describe the customer complaint or symptoms']); ?></div>
        <div class="full"><?php form_textarea('initial_condition', 'Initial vehicle condition', $v['initial_condition'], $errors, ['maxlength' => 2000, 'placeholder' => 'Scratches, dents, fuel level, personal items…']); ?></div>
        <div class="full"><?php form_textarea('required_repairs', 'Required repairs (initial)', $v['required_repairs'], $errors, ['maxlength' => 2000, 'placeholder' => 'Initial repair plan or inspection notes']); ?></div>
      </div>
    </section>

    <div class="form-actions">
      <button class="btn btn-primary" type="submit" id="jobcard_submit"><?= $job ? 'Save changes' : 'Create job card' ?></button>
      <a class="btn btn-ghost" href="<?= e(url($job ? 'job-cards/view.php?id=' . $id : 'job-cards/')) ?>">Cancel</a>
    </div>
  </form>
</div>
<noscript><p class="muted">JavaScript is disabled. Searchable dropdowns and live cost calculation require JavaScript; server-side validation still protects submissions.</p></noscript>
<script src="<?= e(asset('js/job-card-form.js')) ?>"></script>
<?php ui_footer();
