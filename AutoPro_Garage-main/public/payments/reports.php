<?php
declare(strict_types=1);

require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';

require_login();
require_permission('payments.view');

$from = get('from') ?: date('Y-m-01');
$to = get('to') ?: date('Y-m-d');

// CSV Export
if (get('export') === 'csv') {
    $rows = ManualPaymentService::getCollectionsByProvider($from, $to);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="payment_collections_' . $from . '_to_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Provider Code', 'Provider Name', 'Type', 'Verified Transactions', 'Total Collected (ETB)']);
    foreach ($rows as $r) {
        fputcsv($out, [
            ManualPaymentService::sanitizeCsvCell($r['code']),
            ManualPaymentService::sanitizeCsvCell($r['name']),
            ManualPaymentService::sanitizeCsvCell($r['type']),
            (int)$r['total_count'],
            number_format((float)$r['total_amount'], 2, '.', '')
        ]);
    }
    fclose($out);
    exit;
}

$byProvider = ManualPaymentService::getCollectionsByProvider($from, $to);
$totalCollectedPeriod = array_sum(array_column($byProvider, 'total_amount'));
$totalTransactionsPeriod = array_sum(array_column($byProvider, 'total_count'));

$stats = ManualPaymentService::getSummaryStats();
$pendingBadge = ManualPaymentService::getPendingCount();

ui_header('Payment Reports', 'payments');
page_header(
    t('Payment Collection & Provider Reports', 'የክፍያ አሰባሰብ እና የአቅራቢዎች ሪፖርት'),
    t('Audited financial breakdown of collections by Ethiopian bank, Telebirr, and cash settlements',
      'በኢትዮጵያ ባንኮች፣ በቴሌብር እና በጥሬ ገንዘብ የተሰበሰቡ ክፍያዎች የተረጋገጠ የሂሳብ ዝርዝር')
);
?>

<!-- Payment Sub-Navigation -->
<div class="tabs mb2" style="display:flex;gap:8px;flex-wrap:wrap;border-bottom:1px solid var(--border-color,#334155);padding-bottom:12px;margin-bottom:24px;">
    <a href="<?= e(url('payments/')) ?>" class="btn btn-ghost btn-sm">
        <?= e(t('Payment History', 'የክፍያ ታሪክ')) ?>
    </a>
    <?php if (can('payments.verify')): ?>
    <a href="<?= e(url('payments/verification.php')) ?>" class="btn btn-ghost btn-sm" style="position:relative;">
        <?= e(t('Verification Queue', 'የማረጋገጫ ረድፍ')) ?>
        <?php if ($pendingBadge > 0): ?>
            <span style="background:#EF4444;color:#fff;font-size:0.75rem;font-weight:700;padding:2px 7px;border-radius:9999px;margin-left:6px;"><?= $pendingBadge ?></span>
        <?php endif; ?>
    </a>
    <?php endif; ?>
    <?php if (can('payments.methods')): ?>
    <a href="<?= e(url('payments/methods.php')) ?>" class="btn btn-ghost btn-sm">
        <?= e(t('Payment Methods', 'የክፍያ መንገዶች')) ?>
    </a>
    <?php endif; ?>
    <a href="<?= e(url('payments/reports.php')) ?>" class="btn btn-primary btn-sm">
        <?= e(t('Payment Reports', 'የክፍያ ሪፖርቶች')) ?>
    </a>
    <?php if (can('payments.record')): ?>
    <a href="<?= e(url('payments/record.php')) ?>" class="btn btn-ghost btn-sm">
        <?= e(t('Record Manual Payment', 'ክፍያ ይመዝግቡ')) ?>
    </a>
    <?php endif; ?>
</div>

<!-- Date Filter Form -->
<form class="filters mb3" method="get" action="<?= e(url('payments/reports.php')) ?>">
    <div class="field">
        <label for="from"><?= e(t('From Date', 'ከቀን')) ?></label>
        <input class="input" type="date" id="from" name="from" value="<?= e($from) ?>">
    </div>
    <div class="field">
        <label for="to"><?= e(t('To Date', 'እስከ ቀን')) ?></label>
        <input class="input" type="date" id="to" name="to" value="<?= e($to) ?>">
    </div>
    <button class="btn btn-primary" type="submit"><?= e(t('Apply Filter', 'አጣራ')) ?></button>
    <a href="<?= e(url('payments/reports.php?export=csv&from=' . urlencode($from) . '&to=' . urlencode($to))) ?>" class="btn btn-outline" style="border-color:var(--gold,#D4AF37);color:var(--gold,#D4AF37);">
        📥 <?= e(t('Export CSV', 'በሲኤስቪ አውርድ')) ?>
    </a>
</form>

<!-- Period KPI Cards -->
<div class="stats mb3" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;">
    <div class="stat green" style="background:var(--card-bg,#1E293B);">
        <div class="label" style="color:#10B981;font-weight:600;"><?= e(t('Verified Collections (Period)', 'የተረጋገጠ አሰባሰብ (በተመረጠው ጊዜ)')) ?></div>
        <div class="value" style="font-size:1.75rem;"><?= e(money($totalCollectedPeriod)) ?></div>
        <div class="muted small"><?= (int)$totalTransactionsPeriod ?> <?= e(t('verified payments', 'የተረጋገጡ ክፍያዎች')) ?></div>
    </div>
    <div class="stat" style="border-left:4px solid #F59E0B;background:var(--card-bg,#1E293B);">
        <div class="label" style="color:#F59E0B;font-weight:600;"><?= e(t('Pending in Queue', 'በማረጋገጫ ላይ ያለ')) ?></div>
        <div class="value" style="font-size:1.75rem;"><?= e(money($stats['pending_amount'])) ?></div>
        <div class="muted small"><?= (int)$stats['pending_count'] ?> <?= e(t('submissions awaiting review', 'ግምገማ የሚጠብቁ')) ?></div>
    </div>
    <div class="stat" style="border-left:4px solid #3B82F6;background:var(--card-bg,#1E293B);">
        <div class="label" style="color:#3B82F6;font-weight:600;"><?= e(t('Active Payment Providers', 'ንቁ የክፍያ አቅራቢዎች')) ?></div>
        <div class="value" style="font-size:1.75rem;"><?= count(ManualPaymentService::getActiveMethods()) ?></div>
        <div class="muted small"><?= e(t('Ethiopian banks & mobile channels', 'የኢትዮጵያ ባንኮችና ሞባይል ክፍያዎች')) ?></div>
    </div>
    <div class="stat" style="border-left:4px solid #EF4444;background:var(--card-bg,#1E293B);">
        <div class="label" style="color:#EF4444;font-weight:600;"><?= e(t('Total Rejections Recorded', 'ውድቅ የተደረጉ ድምር')) ?></div>
        <div class="value" style="font-size:1.75rem;"><?= (int)$stats['rejected_count'] ?></div>
        <div class="muted small"><?= e(t('Audited discrepancies', 'የተመዘገቡ ስህተቶች')) ?></div>
    </div>
</div>

<!-- Collections Breakdown Table & Share Bars -->
<div class="card">
    <h3 style="margin-top:0;margin-bottom:16px;"><?= e(t('Collections Breakdown by Provider & Bank', 'የክፍያ አሰባሰብ ዝርዝር በባንክ እና በአቅራቢ')) ?></h3>
    <div class="table-wrap">
        <table class="table stack">
            <thead>
                <tr>
                    <th><?= e(t('Payment Provider', 'የክፍያ አቅራቢ')) ?></th>
                    <th><?= e(t('Channel Type', 'የቻናል አይነት')) ?></th>
                    <th class="num"><?= e(t('Transactions', 'የክፍያ ብዛት')) ?></th>
                    <th class="num"><?= e(t('Total Collected', 'የተሰበሰበ ድምር')) ?></th>
                    <th style="width:28%;"><?= e(t('Volume Share (%)', 'የገበያ ድርሻ (%)')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($byProvider as $row): ?>
                <?php
                $pct = $totalCollectedPeriod > 0 ? round(($row['total_amount'] / $totalCollectedPeriod) * 100, 1) : 0;
                ?>
                <tr>
                    <td>
                        <strong><?= e($row['name']) ?></strong>
                        <span class="mono small muted">(<?= e($row['code']) ?>)</span>
                    </td>
                    <td>
                        <?php
                        $badges = [
                            'bank' => '<span class="badge" style="background:rgba(37,99,235,0.15);color:#3B82F6;border:1px solid rgba(37,99,235,0.3);">' . e(t('Bank Transfer', 'የባንክ ዝውውር')) . '</span>',
                            'mobile_money' => '<span class="badge" style="background:rgba(16,185,129,0.15);color:#10B981;border:1px solid rgba(16,185,129,0.3);">' . e(t('Mobile Wallet', 'ሞባይል ዋሌት')) . '</span>',
                            'cash' => '<span class="badge" style="background:rgba(217,119,6,0.15);color:#F59E0B;border:1px solid rgba(217,119,6,0.3);">' . e(t('Cash Desk', 'ጥሬ ገንዘብ')) . '</span>',
                        ];
                        echo $badges[$row['type']] ?? '<span class="badge">' . e($row['type']) . '</span>';
                        ?>
                    </td>
                    <td class="num"><strong><?= (int)$row['total_count'] ?></strong></td>
                    <td class="num"><strong style="color:#10B981;"><?= e(money($row['total_amount'])) ?></strong></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <div style="flex-grow:1;height:10px;background:rgba(255,255,255,0.08);border-radius:9999px;overflow:hidden;">
                                <div style="height:100%;width:<?= $pct ?>%;background:linear-gradient(90deg,#10B981,#D4AF37);border-radius:9999px;"></div>
                            </div>
                            <span class="small mono" style="min-width:45px;text-align:right;"><?= $pct ?>%</span>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$byProvider): ?>
                    <tr><td colspan="5" class="empty"><?= e(t('No verified collections found for this date range.', 'በዚህ ጊዜ ውስጥ ምንም የተረጋገጠ ክፍያ አልተገኘም።')) ?></td></tr>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr style="font-weight:700;border-top:2px solid var(--border-color,#334155);">
                    <td colspan="2"><?= e(t('Total Collections (Filtered Period)', 'ጠቅላላ የተሰበሰበ (በተመረጠው ጊዜ)')) ?></td>
                    <td class="num"><?= (int)$totalTransactionsPeriod ?></td>
                    <td class="num" style="color:#10B981;font-size:1.15rem;"><?= e(money($totalCollectedPeriod)) ?></td>
                    <td>100.0%</td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<?php ui_footer(); ?>
