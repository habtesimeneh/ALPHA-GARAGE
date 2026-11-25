<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';
require_login();
require_permission('audit.view');   // administrators only by default

$verify = null;
if (is_post() && post('do') === 'verify') {
    $verify = AuditLogger::verifyChain();
    AuditLogger::logSafe('audit_chain_verified', 'audit', null, null, ['ok' => $verify['ok'], 'checked' => $verify['checked'], 'bad_id' => $verify['bad_id']], $verify['ok'] ? 'success' : 'failure');
}
$q = get('q'); $module = get('module'); $result = get('result'); $uid = get_int('user_id'); $from = get('from'); $to = get('to'); $action = get('action');
$where = ['1=1']; $params = [];
if ($q !== '') { $where[] = '(username LIKE ? OR action_type LIKE ? OR previous_value LIKE ? OR new_value LIKE ? OR ip_address LIKE ? OR record_id = ?)'; $l = like_escape($q); array_push($params, $l, $l, $l, $l, $l, (int) $q); }
if ($module !== '') { $where[] = 'module_name = ?'; $params[] = $module; }
if ($action !== '') { $where[] = 'action_type = ?'; $params[] = $action; }
if (in_array($result, ['success', 'failure', 'denied'], true)) { $where[] = 'action_result = ?'; $params[] = $result; }
if ($uid) { $where[] = 'user_id = ?'; $params[] = $uid; }
if ($from !== '' && !validate(['d' => $from], ['d' => 'date'])) { $where[] = 'created_at >= ?'; $params[] = $from . ' 00:00:00'; }
if ($to !== '' && !validate(['d' => $to], ['d' => 'date'])) { $where[] = 'created_at <= ?'; $params[] = $to . ' 23:59:59'; }
$w = implode(' AND ', $where);

if (get('export') === 'csv') {
    $rows = Database::all("SELECT id, created_at, username, user_role, action_type, module_name, record_id, action_result, ip_address, user_agent, previous_value, new_value, row_hash FROM audit_logs WHERE $w ORDER BY id DESC LIMIT 20000", $params);
    AuditLogger::log('audit_log_exported', 'audit', null, null, ['rows' => count($rows)]);
    send_csv('audit_log_' . date('Ymd_His') . '.csv', ['ID', 'Time', 'User', 'Role', 'Action', 'Module', 'Record', 'Result', 'IP', 'User agent', 'Previous', 'New', 'Hash'], $rows);
}
$pg = paginate((int) Database::value(
    "SELECT COUNT(*) FROM audit_logs WHERE $w", $params), 25, get_int('page') ?: 1);
$rows = Database::all(
    "SELECT * FROM audit_logs WHERE $w ORDER BY id DESC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}", $params);
$modules = array_column(Database::all(
    'SELECT DISTINCT module_name FROM audit_logs ORDER BY module_name'), 'module_name');
$actions = array_column(Database::all(
    'SELECT DISTINCT action_type FROM audit_logs ORDER BY action_type'), 'action_type');
$users = Database::all(
    'SELECT id, username FROM users ORDER BY username');
$qs = ['q' => $q, 'module' => $module, 'action' => $action, 'result' => $result, 'user_id' => $uid ?: '', 'from' => $from, 'to' => $to];

ui_header('Audit logs', 'audit');
page_header('Audit logs', 'Append-only and hash-chained: records cannot be edited or deleted through the application', '<a class="btn btn-ghost" href="?' . e(http_build_query($qs + ['export' => 'csv'])) . '">' . e(t('Export CSV', 'Export CSV')) . '</a>');
if ($verify): ?><div class="alert <?= $verify['ok'] ? 'alert-ok' : 'alert-danger' ?>" role="alert"><strong><?= $verify['ok'] ? e(t('✔ Chain verified.', '✔ Chain verified.')) : e(t('✖ Tampering detected.', '✖ Tampering detected.')) ?></strong> <?= e($verify['message']) ?></div><?php endif; ?>
<div class="card gap-row no-print"><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="do" value="verify"><button class="btn btn-primary btn-sm" type="submit"><?= e(t('Verify integrity of the whole chain', 'Verify integrity of the whole chain')) ?></button></form><span class="muted small"><?= e(t('Recomputes every SHA-256 link and compares it with the stored chain head.', 'Recomputes every SHA-256 link and compares it with the stored chain head.')) ?></span></div>
<form class="filters" method="get">
<div class="field grow">
    <label for="q"><?= e(t('Search', 'Search')) ?></label>
    <input class="input" id="q" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('User, action, IP, record ID, old/new value', 'User, action, IP, record ID, old/new value')) ?>"></div>
<div class="field">
    <label for="module"><?= e(t('Module', 'Module')) ?></label>
    <select class="input" id="module" name="module" data-autosubmit>
        <option value=""><?= e(t('All', 'All')) ?></option>
        <?php foreach ($modules as $m): ?>
            <option<?= $module === $m ? ' selected' : '' ?>>
                <?= e(t($m, $m)) ?></option><?php endforeach; ?>
            </select>
        </div>
<div class="field">
    <label for="action"><?= e(t('Action', 'Action')) ?></label>
    <select class="input" id="action" name="action" data-autosubmit>
        <option value=""><?= e(t('All', 'All')) ?></option>
        <?php foreach ($actions as $a): ?>
            <option<?= $action === $a ? ' selected' : '' ?>>
                <?= e(t($a, $a)) ?></option><?php endforeach; ?>
            </select>
        </div>
<div class="field">
    <label for="result"><?= e(t('Result', 'Result')) ?></label>
    <select class="input" id="result" name="result" data-autosubmit>
        <option value=""><?= e(t('All', 'All')) ?></option>
        <?php foreach (['success', 'failure', 'denied'] as $r): ?>
            <option<?= $result === $r ? ' selected' : '' ?>>
                <?= e(t($r, $r)) ?></option><?php endforeach; ?>
            </select>
        </div>
<div class="field">
    <label for="user_id"><?= e(t('User', 'User')) ?></label>
    <select class="input" id="user_id" name="user_id" data-autosubmit>
        <option value=""><?= e(t('All', 'All')) ?></option>
        <?php foreach ($users as $u): ?>
            <option value="<?= (int) $u['id'] ?>"<?= $uid === (int) $u['id'] ? ' selected' : '' ?>>
                <?= e($u['username']) ?></option><?php endforeach; ?>
            </select>
        </div>
<div class="field">
    <label for="from"><?= e(t('From', 'From')) ?></label>
    <input class="input" type="date" id="from" name="from" value="<?= e($from) ?>">
</div>
<div class="field">
    <label for="to"><?= e(t('To', 'To')) ?></label>
    <input class="input" type="date" id="to" name="to" value="<?= e($to) ?>">
</div>
<button class="btn btn-primary" type="submit"><?= e(t('Filter', 'Filter')) ?></button>
<a class="btn btn-ghost" href="<?= e(url('audit-logs/')) ?>"><?= e(t('Reset', 'Reset')) ?></a>
</form>
<div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>#</th>
                <th><?= e(t('Time', 'Time')) ?></th>
                <th><?= e(t('User', 'User')) ?></th>
                <th><?= e(t('Action', 'Action')) ?></th>
                <th><?= e(t('Module / record', 'Module / record')) ?></th>
                <th><?= e(t('Result', 'Result')) ?></th>
                <th>IP</th>
                <th>Changes</th>
            </tr>
        </thead>
        <tbody>
<?php foreach ($rows as $r): ?>
            <tr>
                <td class="mono"><?= (int) $r['id'] ?></td>
                <td class="nowrap small"><?= e(fmt_date($r['created_at'], 'd M Y H:i:s')) ?></td>
                <td><?= e($r['username'] ?? '—') ?><div class="muted small"><?= e($r['user_role'] ?? '') ?></div></td>
                <td><strong><?= e($r['action_type']) ?></strong></td>
                <td><?= e($r['module_name']) ?><?= $r['record_id'] ? ' #' . (int) $r['record_id'] : '' ?></td>
                <td><?= badge($r['action_result']) ?></td>
                <td class="mono small"><?= e($r['ip_address']) ?></td>
                <td class="small">
                    <?php if ($r['previous_value'] !== null): ?>
                        <details>
                            <summary>before</summary>
                            <code class="pre-wrap"><?= e($r['previous_value']) ?></code>
                        </details>
                    <?php endif; ?>
                    <?php if ($r['new_value'] !== null): ?>
                        <details>
                            <summary>after</summary>
                            <code class="pre-wrap"><?= e($r['new_value']) ?></code>
                        </details>
                    <?php endif; ?>
                    <div class="muted mono" title="SHA-256 of this record"><?= e(substr($r['row_hash'], 0, 12)) ?>…</div>
                </td>
            </tr>
<?php endforeach; ?>
<?php if (!$rows): ?>
            <tr>
                <td colspan="8" class="empty">No audit records match.</td>
            </tr>
<?php endif; ?>
        </tbody>
    </table>
</div>
<?php pagination_links($pg, $qs); ui_footer();
