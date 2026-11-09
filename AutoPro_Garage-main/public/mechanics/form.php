<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';
require_login();
require_permission('mechanics.manage');

$id = get_int('id');
$m = Database::one('SELECT * FROM mechanics WHERE id = ?', [$id]);
if (!$m) { abort(404, 'Mechanic not found.'); }
$v = $m; $errors = [];
if (is_post()) {
    $v = ['full_name' => post('full_name'), 'phone' => post('phone'), 'specialty' => post('specialty'), 'availability' => post('availability'), 'is_active' => isset($_POST['is_active']) ? 1 : 0];
    $errors = validate($v, ['full_name' => 'required|max:120', 'phone' => 'phone', 'specialty' => 'max:100', 'availability' => 'required|in:available,busy,off_duty']);
    if (!$errors && !$v['is_active'] && (int) Database::value("SELECT COUNT(*) FROM job_cards WHERE mechanic_id = ? AND status IN ('Pending','Diagnosing','Waiting for Parts','In Progress')", [$id]) > 0) {
        $errors['is_active'] = 'Reassign this mechanic\'s open jobs before deactivating.';
    }
    if (!$errors) {
        Database::transaction(function () use ($id, $m, $v) {
            Database::run('UPDATE mechanics SET full_name=?, phone=?, specialty=?, availability=?, is_active=? WHERE id=?', [$v['full_name'], $v['phone'] ?: null, $v['specialty'] ?: null, $v['availability'], $v['is_active'], $id]);
            AuditLogger::log('mechanic_updated', 'mechanics', $id, array_diff_key($m, ['created_at' => 1]), $v);
        });
        flash('success', 'Mechanic updated.');
        redirect('mechanics/');
    }
}
ui_header('Edit mechanic', 'mechanics');
page_header('Edit mechanic', $m['full_name']);
?>
<div class="card w-md"><form method="post" data-validate novalidate><?= csrf_field() ?><div class="form-grid">
<?php form_input('full_name', 'Full name', $v['full_name'], $errors, ['required' => true, 'maxlength' => 120]); form_input('phone', 'Phone', $v['phone'], $errors, ['maxlength' => 20]); form_input('specialty', 'Specialty', $v['specialty'], $errors, ['maxlength' => 100]);
form_select('availability', 'Availability', ['available' => 'Available', 'busy' => 'Busy', 'off_duty' => 'Off duty'], $v['availability'], $errors); ?>
<div class="full field"><label class="check"><input type="checkbox" name="is_active" value="1" <?= $v['is_active'] ? 'checked' : '' ?>> Active</label><div class="field-error"><?= e($errors['is_active'] ?? '') ?></div></div></div>
<div class="form-actions"><button class="btn btn-primary" type="submit">Save</button><a class="btn btn-ghost" href="<?= e(url('mechanics/')) ?>">Cancel</a></div></form></div>
<?php ui_footer();
