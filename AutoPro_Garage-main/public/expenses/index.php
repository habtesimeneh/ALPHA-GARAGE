<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';
require_login();
require_permission('expenses.view');

$categories = [
    'payroll' => 'Payroll',
    'rent' => 'Rent',
    'utilities' => 'Utilities',
    'transport' => 'Fuel & transport',
    'tools' => 'Tools & equipment',
    'maintenance' => 'Facility maintenance',
    'insurance_tax' => 'Insurance & tax',
    'other' => 'Other operating expense',
];
$today = date('Y-m-d');
$errors = [];
$values = ['category' => 'other', 'description' => '', 'amount' => '', 'expense_date' => $today, 'reference' => ''];

if (is_post()) {
    require_post();
    require_permission('expenses.manage');
    $values = [
        'category' => post('category'),
        'description' => post('description'),
        'amount' => post('amount'),
        'expense_date' => post('expense_date'),
        'reference' => post('reference'),
    ];
    $errors = validate($values, [
        'category' => 'required|in:' . implode(',', array_keys($categories)),
        'description' => 'required|min:3|max:255',
        'amount' => 'required|numeric|min_val:0.01|max_val:9999999999.99',
        'expense_date' => 'required|date',
        'reference' => 'max:80',
    ]);
    if (!$errors && $values['expense_date'] > $today) {
        $errors['expense_date'] = 'Expense date cannot be in the future.';
    }

    if (!$errors) {
        Database::transaction(static function () use ($values, &$expenseId): void {
            $expenseId = Database::insert(
                'INSERT INTO expenses (category, description, amount, expense_date, reference, recorded_by)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $values['category'],
                    $values['description'],
                    from_cents(to_cents($values['amount'])),
                    $values['expense_date'],
                    $values['reference'] !== '' ? $values['reference'] : null,
                    (int) current_user()['id'],
                ]
            );
            AuditLogger::log('expense_recorded', 'expenses', $expenseId, null, [
                'category' => $values['category'],
                'amount' => from_cents(to_cents($values['amount'])),
                'expense_date' => $values['expense_date'],
                'reference' => $values['reference'],
            ]);
        });
        flash('success', 'Operating expense recorded.');
        redirect('expenses/');
    }
}

$from = get('from');
$to = get('to');
$where = ['1=1'];
$params = [];
if ($from !== '' && !validate(['date' => $from], ['date' => 'date'])) {
    $where[] = 'e.expense_date >= ?';
    $params[] = $from;
}
if ($to !== '' && !validate(['date' => $to], ['date' => 'date'])) {
    $where[] = 'e.expense_date <= ?';
    $params[] = $to;
}
$whereSql = implode(' AND ', $where);
$base = 'FROM expenses e LEFT JOIN users u ON u.id = e.recorded_by';
$total = (float) Database::value("SELECT COALESCE(SUM(e.amount), 0) $base WHERE $whereSql", $params);
$pagination = paginate(
    (int) Database::value("SELECT COUNT(*) $base WHERE $whereSql", $params),
    $GLOBALS['config']['per_page'],
    get_int('page') ?: 1
);
$expenses = Database::all(
    "SELECT e.*, u.full_name AS recorded_by_name $base WHERE $whereSql
     ORDER BY e.expense_date DESC, e.id DESC LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}",
    $params
);

ui_header('Operating expenses', 'expenses');
page_header('Operating expenses', 'Append-only expense records for the cash-basis dashboard summary.');
?>
<div class="alert alert-info">
  Inventory purchases recorded through stock-in are included automatically in dashboard expenses.
  Do not enter those purchases here as well, or they will be counted twice.
</div>
<?php if (!empty($errors['_'])): ?><div class="alert alert-danger" role="alert"><?= e($errors['_']) ?></div><?php endif; ?>
<?php if (can('expenses.manage')): ?>
<div class="card w-md">
  <h3>Record an operating expense</h3>
  <form method="post" data-validate novalidate>
    <?= csrf_field() ?>
    <div class="form-grid">
      <?php
        $categoryOptions = [];
        foreach ($categories as $key => $label) {
            $categoryOptions[$key] = $label;
        }
        form_select('category', 'Category', $categoryOptions, $values['category'], $errors, ['required' => true]);
        form_input('expense_date', 'Date paid', $values['expense_date'], $errors, ['type' => 'date', 'required' => true, 'max' => $today]);
        form_input('amount', 'Amount (' . Setting::get('currency') . ')', $values['amount'], $errors, ['type' => 'number', 'min' => '0.01', 'max' => '9999999999.99', 'step' => '0.01', 'required' => true]);
        form_input('reference', 'Receipt / reference', $values['reference'], $errors, ['maxlength' => 80]);
        echo '<div class="full">';
        form_input('description', 'Description', $values['description'], $errors, ['maxlength' => 255, 'required' => true]);
        echo '</div>';
      ?>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Save expense</button></div>
  </form>
</div>
<?php endif; ?>

<div class="stats">
  <div class="stat red"><div class="label">Operating expenses</div><div class="value"><?= e(money($total)) ?></div><div class="sub">Total for selected records</div></div>
</div>
<form class="filters" method="get">
  <div class="field"><label for="expense-from">From</label><input class="input" type="date" id="expense-from" name="from" value="<?= e($from) ?>"></div>
  <div class="field"><label for="expense-to">To</label><input class="input" type="date" id="expense-to" name="to" value="<?= e($to) ?>"></div>
  <button class="btn btn-primary" type="submit">Filter</button>
  <a class="btn btn-ghost" href="<?= e(url('expenses/')) ?>">Clear</a>
</form>
<div class="table-wrap">
  <table class="table stack">
    <thead><tr><th>Date</th><th>Category</th><th>Description</th><th>Reference</th><th>Recorded by</th><th class="num">Amount</th></tr></thead>
    <tbody>
      <?php foreach ($expenses as $expense): ?>
      <tr>
        <td class="nowrap"><?= e(fmt_date($expense['expense_date'])) ?></td>
        <td><?= e($categories[$expense['category']] ?? 'Other operating expense') ?></td>
        <td><?= e($expense['description']) ?></td>
        <td><?= e($expense['reference'] ?: '—') ?></td>
        <td><?= e($expense['recorded_by_name'] ?: '—') ?></td>
        <td class="num"><?= e(money($expense['amount'])) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$expenses): ?><tr><td colspan="6" class="empty">No operating expenses found for this period.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php pagination_links($pagination, ['from' => $from, 'to' => $to]); ui_footer();
