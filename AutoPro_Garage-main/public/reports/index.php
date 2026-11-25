<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';
require_login();
require_permission('reports.view', 'reports.financial', 'reports.inventory', 'audit.view');

$avail = ReportService::available();
$key = get('report', (string) array_key_first($avail));
if (!isset(ReportService::REPORTS[$key])) { abort(404, 'Unknown report.'); }
if (!can(ReportService::REPORTS[$key][1])) { deny(ReportService::REPORTS[$key][1], 'reports'); }
$from = get('from', date('Y-m-01')); $to = get('to', date('Y-m-d')); $q = get('q');
if (validate(['f' => $from, 't' => $to], ['f' => 'date', 't' => 'date'])) { $from = date('Y-m-01'); $to = date('Y-m-d'); flash('warning', 'Invalid dates were reset.'); }
if ($from > $to) { [$from, $to] = [$to, $from]; }
$useDates = ReportService::REPORTS[$key][2];
$data = ReportService::build($key, $from, $to, $q);

if (get('export') === 'csv') {
    AuditLogger::log('report_exported', 'reports', null, null, ['report' => $key, 'from' => $from, 'to' => $to, 'rows' => count($data['rows'])]);
    send_csv($key . '_' . date('Ymd_His') . '.csv', $data['columns'], $data['rows']);
}
$qs = ['report' => $key, 'from' => $from, 'to' => $to, 'q' => $q];
ui_header('Reports', 'reports');
page_header('Reports & analytics', ReportService::REPORTS[$key][0], '<a class="btn btn-ghost" href="?' . e(http_build_query($qs + ['export' => 'csv'])) . '">' . e(t('Export CSV', 'Export CSV')) . '</a><button class="btn btn-ghost" type="button" data-print>' . e(t('Print', 'Print')) . '</button>');
?>
<div class="tabs"><?php foreach ($avail as $k => $r): ?><a class="tab<?= $k === $key ? ' active' : '' ?>" href="?report=<?= e($k) ?>"><?= e(t($r[0], $r[0])) ?></a><?php endforeach; ?></div>
<form class="filters" method="get"><input type="hidden" name="report" value="<?= e($key) ?>">
<?php if ($useDates): ?><div class="field"><label for="from"><?= e(t('From', 'From')) ?></label><input class="input" type="date" id="from" name="from" value="<?= e($from) ?>"></div><div class="field"><label for="to"><?= e(t('To', 'To')) ?></label><input class="input" type="date" id="to" name="to" value="<?= e($to) ?>"></div><?php endif; ?>
<div class="field grow"><label for="q"><?= e(t('Search', 'Search')) ?></label><input class="input" id="q" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('Filter rows', 'Filter rows')) ?>"></div><button class="btn btn-primary" type="submit"><?= e(t('Apply', 'Apply')) ?></button></form>
<div class="table-wrap"><table class="table"><thead><tr><?php foreach ($data['columns'] as $i => $c): ?><th class="<?= in_array($i, $data['numeric'], true) ? 'num' : '' ?>"><?= e(t($c, $c)) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach ($data['rows'] as $row): ?><tr><?php foreach ($row as $i => $cell): ?><td class="<?= in_array($i, $data['numeric'], true) ? 'num' : '' ?>"><?= is_numeric($cell) && str_contains((string) $cell, '.') ? e(number_format((float) $cell, 2)) : e($cell) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
<?php if (!$data['rows']): ?><tr><td colspan="<?= count($data['columns']) ?>" class="empty"><?= e(t('No data for this selection.', 'No data for this selection.')) ?></td></tr><?php endif; ?></tbody></table></div>
<p class="muted small"><?= count($data['rows']) ?> <?= e(t('row(s).', 'row(s).')) ?></p>
<?php ui_footer();
