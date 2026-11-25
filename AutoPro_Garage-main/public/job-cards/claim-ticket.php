<?php
declare(strict_types=1);

require __DIR__ . '/../../app/bootstrap.php';
$user = require_login();

$id = get_int('id');
$j = JobService::find($id);
if (!$j) {
    abort(404, 'Job card record not found.');
}
if (!JobService::canAccess($j, $user)) {
    deny('jobs.view_all (object access)', 'job_cards', $id);
}

$garageName = Setting::get('garage_name', 'ALPHA GARAGE');
$garagePhone = Setting::get('garage_phone', '+251 911 000 000');
$garageAddress = Setting::get('garage_address', 'Bole Sub-City, Addis Ababa, Ethiopia');
$ticket = $j['claim_ticket_number'] ?: ('TKT-' . date('Ymd') . '-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT));
?>
<!DOCTYPE html>
<html lang="<?= e(current_lang()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Claim Ticket <?= e($ticket) ?> — <?= e($garageName) ?></title>
    <style>
        :root {
            --primary: #0D281E;
            --accent: #C5A059;
            --text: #1E293B;
            --border: #CBD5E1;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Nyala", "Abyssinica SIL", sans-serif;
            background: #F8FAFC;
            color: var(--text);
            padding: 30px 15px;
            display: flex;
            justify-content: center;
        }
        .ticket-card {
            background: #FFFFFF;
            width: 100%;
            max-width: 680px;
            border: 2px solid var(--primary);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 10px 25px rgba(0,0,0,0.08);
            position: relative;
        }
        .ticket-header {
            background: linear-gradient(135deg, #071711 0%, #0D281E 100%);
            color: #FFFFFF;
            padding: 24px;
            text-align: center;
            border-bottom: 3px solid var(--accent);
        }
        .ticket-header h1 {
            font-family: 'Cinzel', Georgia, serif;
            font-size: 22px;
            letter-spacing: 2px;
            color: #FFFFFF;
            margin-bottom: 4px;
        }
        .ticket-header p {
            color: var(--accent);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            font-weight: 600;
        }
        .ticket-badge-box {
            background: #F1F5F9;
            border-bottom: 1px dashed var(--border);
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .ticket-num-label {
            font-size: 11px;
            text-transform: uppercase;
            color: #64748B;
            font-weight: 700;
            letter-spacing: 1px;
        }
        .ticket-num {
            font-size: 22px;
            font-weight: 800;
            color: var(--primary);
            font-family: monospace;
            letter-spacing: 1px;
        }
        .plate-box {
            background: #0D281E;
            color: #FFFFFF;
            border: 2px solid var(--accent);
            border-radius: 6px;
            padding: 6px 16px;
            font-size: 18px;
            font-weight: 800;
            letter-spacing: 2px;
            font-family: monospace;
        }
        .ticket-body {
            padding: 24px;
        }
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 20px;
        }
        .kv {
            margin-bottom: 8px;
        }
        .kv dt {
            font-size: 11px;
            color: #64748B;
            text-transform: uppercase;
            font-weight: 600;
            margin-bottom: 2px;
        }
        .kv dd {
            font-size: 14px;
            font-weight: 600;
            color: var(--text);
        }
        .notice-box {
            background: #FEF3C7;
            border-left: 4px solid #F59E0B;
            padding: 12px 16px;
            border-radius: 0 6px 6px 0;
            margin: 20px 0;
            font-size: 12px;
            line-height: 1.5;
            color: #92400E;
        }
        .notice-am {
            font-family: 'Nyala', 'Abyssinica SIL', sans-serif;
            margin-top: 6px;
            font-size: 13px;
        }
        .signatures {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px dashed var(--border);
        }
        .sig-block {
            text-align: center;
        }
        .sig-line {
            height: 40px;
            border-bottom: 1px solid #94A3B8;
            margin-bottom: 6px;
        }
        .sig-label {
            font-size: 11px;
            color: #64748B;
            text-transform: uppercase;
            font-weight: 600;
        }
        .actions-bar {
            padding: 16px 24px;
            background: #F8FAFC;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .btn {
            display: inline-block;
            padding: 8px 18px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            border: none;
        }
        .btn-primary {
            background: var(--primary);
            color: #FFFFFF;
        }
        .btn-ghost {
            background: transparent;
            color: #475569;
            border: 1px solid var(--border);
        }
        @media print {
            body { padding: 0; background: #FFFFFF; }
            .ticket-card { border: none; box-shadow: none; max-width: 100%; }
            .actions-bar { display: none; }
        }
    </style>
</head>
<body>

<div class="ticket-card">
    <div class="ticket-header">
        <h1><?= e($garageName) ?></h1>
        <p>Official Vehicle Claim Ticket &bull; የደንበኛ የተሽከርካሪ ማረጋገጫ ቲኬት</p>
    </div>

    <div class="ticket-badge-box">
        <div>
            <div class="ticket-num-label">Official Claim Ticket #</div>
            <div class="ticket-num"><?= e($ticket) ?></div>
        </div>
        <div>
            <div class="plate-box"><?= e($j['plate_number']) ?></div>
        </div>
    </div>

    <div class="ticket-body">
        <div class="grid-2">
            <div>
                <dl class="kv">
                    <dt>Customer Name / ደንበኛ</dt>
                    <dd><?= e($j['customer_name']) ?></dd>
                </dl>
                <dl class="kv">
                    <dt>Phone / ስልክ ቁጥር</dt>
                    <dd><?= e($j['customer_phone']) ?></dd>
                </dl>
                <dl class="kv">
                    <dt>Date Received / የገባበት ቀን</dt>
                    <dd><?= e(fmt_date($j['date_received'])) ?></dd>
                </dl>
            </div>
            <div>
                <dl class="kv">
                    <dt>Vehicle / ተሽከርካሪ</dt>
                    <dd><?= e($j['make'] . ' ' . $j['model'] . ($j['vehicle_year'] ? ' (' . $j['vehicle_year'] . ')' : '')) ?></dd>
                </dl>
                <dl class="kv">
                    <dt>VIN Number / ሻሲ ቁጥር</dt>
                    <dd style="font-family: monospace;"><?= e($j['vin'] ?: '—') ?></dd>
                </dl>
                <dl class="kv">
                    <dt>Assigned Technician / መካኒክ</dt>
                    <dd><?= e($j['mechanic_name'] ?: 'Workshop Technical Team') ?></dd>
                </dl>
            </div>
        </div>

        <dl class="kv" style="margin-top: 10px;">
            <dt>Reported Problem / የተመዘገበው ችግር</dt>
            <dd style="background: #F8FAFC; padding: 8px 12px; border-radius: 6px; border: 1px solid #E2E8F0; font-size: 13px;">
                <?= e($j['reported_problem']) ?>
            </dd>
        </dl>

        <div class="notice-box">
            <strong>IMPORTANT NOTICE:</strong> Please present this physical or digital ticket upon collecting your vehicle. If there is any unpaid invoice balance, please settle it with the Finance Manager before vehicle release.
            <div class="notice-am">
                <strong>አስፈላጊ ማሳሰቢያ፦</strong> ተሽከርካሪዎን ለመረከብ ሲመጡ ይህንን ኦፊሴላዊ የይገባኛል ቲኬት በሪሴፕሽን ማሳየት አለብዎት። ያልተከፈለ የጥገና ሂሳብ ካለ ተሽከርካሪው ከመለቀቁ በፊት በፋይናንስ በኩል ማጠናቀቅ ያስፈልጋል።
            </div>
        </div>

        <div class="signatures">
            <div class="sig-block">
                <div class="sig-line"></div>
                <div class="sig-label">Customer Signature / የደንበኛ ፊርማ</div>
            </div>
            <div class="sig-block">
                <div class="sig-line"></div>
                <div class="sig-label">Receptionist / የሪሴፕሽን ፊርማ</div>
            </div>
        </div>

        <div style="text-align: center; margin-top: 25px; font-size: 11px; color: #94A3B8;">
            <?= e($garageAddress) ?> &bull; Tel: <?= e($garagePhone) ?> &bull; AutoPro Garage Management System
        </div>
    </div>

    <div class="actions-bar">
        <a class="btn btn-ghost" href="<?= e(url('job-cards/view.php?id=' . $id)) ?>">&larr; <?= e(t('Back to Job Card', 'Back to Job Card')) ?></a>
        <button class="btn btn-primary" onclick="window.print()"><?= e(t('Print Official Ticket', '🖨 Print Official Ticket')) ?></button>
    </div>
</div>

</body>
</html>
