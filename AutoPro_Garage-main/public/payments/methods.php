<?php
declare(strict_types=1);

require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';

require_login();
require_permission('payments.methods');

$u = current_user();
$errors = [];
$editMethod = null;

// Handle POST actions (1-click status toggle, create, update) with server-side CSRF validation
if (is_post()) {
    verify_csrf();

    $action = post('action');

    if ($action === 'toggle') {
        $id = post_int('id');
        $active = post_int('active') === 1;
        try {
            ManualPaymentService::toggleMethodStatus($id, $active, (int)$u['id']);
            $m = ManualPaymentService::getMethod($id);
            $name = $m ? $m['name'] : 'Payment method';
            flash('success', $active ? "$name has been activated." : "$name has been deactivated.");
        } catch (DomainException $e) {
            flash('danger', $e->getMessage());
        }
        redirect('payments/methods.php');
    }

    if ($action === 'save') {
        $id = post_int('id') ?: null;
        try {
            ManualPaymentService::saveMethod($_POST, $id, (int)$u['id']);
            flash('success', $id ? t('Payment provider updated successfully.', 'የክፍያ አቅራቢው በተሳካ ሁኔታ ተሻሽሏል።') : t('New payment provider added successfully.', 'አዲስ የክፍያ አቅራቢ በተሳካ ሁኔታ ተፈጥሯል።'));
            redirect('payments/methods.php');
        } catch (DomainException $e) {
            $errors['general'] = $e->getMessage();
        }
    }
}

// Check if editing a specific method
$editId = get_int('edit');
if ($editId > 0) {
    $editMethod = ManualPaymentService::getMethod($editId);
}

$allMethods = ManualPaymentService::getAllMethods();
$pendingBadge = ManualPaymentService::getPendingCount();

ui_header(t('Bank & Telebirr Accounts Management', 'የባንክ እና የቴሌብር ሂሳቦች አስተዳደር'), 'payment_methods');
page_header(
    t('Bank & Telebirr Accounts Management', 'የባንክ እና የቴሌብር ሂሳቦች አስተዳደር'),
    t('Directly configure Ethiopian commercial bank account numbers (CBE, Awash, Dashen), Telebirr merchant till numbers, account holder names, and active status.',
      'የኢትዮጵያ የንግድ ባንክ ሂሳብ ቁጥሮችን (ንግድ ባንክ፣ አዋሽ፣ ዳሽን)፣ የቴሌብር ነጋዴ ቲል ቁጥሮችን፣ የሂሳብ ባለቤት ስሞችን እና የስራ ላይ ሁኔታዎችን በቀጥታ ያዋቅሩ።')
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
    <a href="<?= e(url('payments/methods.php')) ?>" class="btn btn-primary btn-sm">
        <?= e(t('Bank & Telebirr Accounts', 'የባንክ እና ቴሌብር ሂሳቦች')) ?>
    </a>
    <?php if (can_any('payments.view', 'reports.financial')): ?>
    <a href="<?= e(url('payments/reports.php')) ?>" class="btn btn-ghost btn-sm">
        <?= e(t('Payment Reports', 'የክፍያ ሪፖርቶች')) ?>
    </a>
    <?php endif; ?>
    <?php if (can('payments.record')): ?>
    <a href="<?= e(url('payments/record.php')) ?>" class="btn btn-ghost btn-sm">
        <?= e(t('Record Manual Payment', 'ክፍያ ይመዝግቡ')) ?>
    </a>
    <?php endif; ?>
</div>

<!-- Primary Garage Accounts Quick Cards -->
<?php
$featuredCodes = ['cbe', 'telebirr', 'awash', 'dashen', 'boa', 'cash'];
$featuredMap = [];
foreach ($allMethods as $m) {
    if (in_array($m['code'], $featuredCodes, true)) {
        $featuredMap[$m['code']] = $m;
    }
}
?>
<div class="card mb3" style="border:1px solid rgba(212,175,55,0.3);background:rgba(212,175,55,0.02);padding:18px;">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:14px;">
        <div>
            <strong style="color:var(--gold,#D4AF37);font-size:1.05rem;display:flex;align-items:center;gap:6px;">
                <span>⚡</span> <?= e(t('Quick Edit: Primary Garage Accounts', 'ፈጣን ማስተካከያ: ዋና የጋራዡ ሂሳቦች')) ?>
            </strong>
            <span class="muted small"><?= e(t('Direct 1-click editing for your most essential commercial bank and mobile money accounts.', 'ለዋና ዋናዎቹ የባንክ እና የሞባይል ገንዘብ ሂሳቦችዎ የቀጥታ የ1-ጠቅታ ማስተካከያ።')) ?></span>
        </div>
        <span class="badge badge-active"><?= e(t('Administrator Access Only', 'የአድሚን ፈቃድ ብቻ')) ?></span>
    </div>

    <div class="grid-3" style="gap:14px;">
        <?php foreach ($featuredCodes as $fCode):
            if (!isset($featuredMap[$fCode])) continue;
            $fm = $featuredMap[$fCode];
            $rawVal = $fm['account_number'] ?: ($fm['merchant_number'] ?: ($fm['till_number'] ?: ($fm['phone_number'] ?: '')));
            $idBadge = $fm['type'] === 'bank' ? t('Bank A/C:', 'የባንክ ሂሳብ:') : ($fm['type'] === 'mobile_money' ? t('Phone / Till:', 'ስልክ / ቲል:') : t('Payment Channel:', 'የክፍያ ቻናል:'));
        ?>
        <div style="background:var(--card-bg,#1E293B);border:1px solid rgba(255,255,255,0.08);border-radius:8px;padding:12px;display:flex;flex-direction:column;justify-content:space-between;">
            <div>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                    <strong style="font-size:0.95rem;color:var(--gold,#D4AF37);">
                        <?= e(current_lang() === 'am' && !empty($fm['name_am']) ? $fm['name_am'] : $fm['name']) ?>
                    </strong>
                    <span class="badge <?= !empty($fm['is_active']) ? 'badge-active' : 'badge-inactive' ?>" style="font-size:0.7rem;">
                        <?= !empty($fm['is_active']) ? e(t('Active', 'ንቁ')) : e(t('Inactive', 'ቦዝኗል')) ?>
                    </span>
                </div>
                <div class="muted small" style="margin-bottom:4px;">
                    <span><?= e(t('Account Name:', 'የሂሳብ ባለቤት:')) ?></span>
                    <strong style="color:var(--text,#fff);"><?= e($fm['account_name'] ?: 'ALPHA GARAGE PLC') ?></strong>
                </div>
                <div style="font-size:0.85rem;margin-bottom:6px;">
                    <span class="muted small"><?= e($idBadge) ?></span>
                    <span class="mono" style="font-weight:700;color:var(--text,#fff);"><?= e($rawVal ?: '—') ?></span>
                </div>
            </div>
            <div style="border-top:1px solid rgba(255,255,255,0.06);padding-top:8px;margin-top:6px;display:flex;justify-content:flex-end;">
                <a href="<?= e(url('payments/methods.php?edit=' . (int)$fm['id'] . '#account-form')) ?>" class="btn btn-ghost btn-sm" style="font-size:0.8rem;padding:3px 8px;">
                    ✏️ <?= e(t('Edit Account', 'ሂሳብ አስተካክል')) ?>
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php if (!empty($errors['general'])): ?>
    <div class="alert alert-danger mb2"><?= e($errors['general']) ?></div>
<?php endif; ?>

<!-- Provider Form (Edit or Add) -->
<?php if ($editMethod || get('new') === '1'): ?>
<div class="card mb3" id="account-form" style="border:1px solid var(--gold,#D4AF37);background:var(--card-bg,#1E293B);">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
        <div>
            <h3 style="margin:0;color:var(--gold,#D4AF37);">
                <?= $editMethod ? e(t('Edit Account Details: ', 'የሂሳብ መረጃ ያርትዑ: ') . ($editMethod['name_am'] ?? $editMethod['name'])) : e(t('Add New Payment Provider', 'አዲስ የክፍያ አቅራቢ ያክሉ')) ?>
            </h3>
            <span class="muted small"><?= e(t('Changes made here immediately update customer invoices, online payment portals, and receptionist records.', 'እዚህ የሚደረጉ ለውጦች ወዲያውኑ በደንበኛ ደረሰኞች፣ በኦንላይን መክፈያ እና በሪሴፕሽን መዝገብ ላይ ይዘምናሉ።')) ?></span>
        </div>
        <a href="<?= e(url('payments/methods.php')) ?>" class="btn btn-ghost btn-sm"><?= e(t('Close Form', 'ዝጋ')) ?></a>
    </div>

    <form method="post" action="<?= e(url('payments/methods.php')) ?>" data-validate novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <?php if ($editMethod): ?>
            <input type="hidden" name="id" value="<?= (int)$editMethod['id'] ?>">
        <?php endif; ?>

        <div class="grid-2">
            <div class="field">
                <label for="name"><?= e(t('Provider Display Name (English)', 'የአቅራቢ ስም (እንግሊዝኛ)')) ?> *</label>
                <input class="input" type="text" id="name" name="name" required
                       value="<?= e(post('name', $editMethod['name'] ?? '')) ?>"
                       placeholder="e.g. Commercial Bank of Ethiopia (CBE)">
            </div>
            <div class="field">
                <label for="name_am"><?= e(t('Provider Display Name (Amharic)', 'የአቅራቢ ስም (አማርኛ)')) ?></label>
                <input class="input" type="text" id="name_am" name="name_am"
                       value="<?= e(post('name_am', $editMethod['name_am'] ?? '')) ?>"
                       placeholder="ምሳሌ: የኢትዮጵያ ንግድ ባንክ (CBE)">
            </div>
        </div>

        <div class="grid-3">
            <div class="field">
                <label for="type"><?= e(t('Provider Category', 'የክፍያ ምድብ')) ?> *</label>
                <select class="input" id="type" name="type" required>
                    <?php
                    $types = [
                        'bank' => t('Commercial Bank', 'የንግድ ባንክ'),
                        'mobile_money' => t('Mobile Wallet / Money', 'ሞባይል ዋሌት'),
                        'cash' => t('Cash (Reception Desk)', 'ጥሬ ገንዘብ (የፊት ዴስክ)'),
                        'other' => t('Merchant Account / Other', 'የነጋዴ ሂሳብ / ሌላ'),
                    ];
                    $curType = post('type', $editMethod['type'] ?? 'bank');
                    foreach ($types as $k => $lbl): ?>
                        <option value="<?= e($k) ?>" <?= $curType === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="code"><?= e(t('Provider Identifier Code', 'የአቅራቢ ኮድ')) ?></label>
                <input class="input" type="text" id="code" name="code"
                       value="<?= e(post('code', $editMethod['code'] ?? '')) ?>"
                       placeholder="e.g. cbe, telebirr, mpesa (auto-generated if empty)">
            </div>
            <div class="field" id="field_account_name">
                <label for="account_name"><?= e(t('Account Holder Name', 'የሂሳብ ባለቤት ስም')) ?></label>
                <input class="input" type="text" id="account_name" name="account_name"
                       value="<?= e(post('account_name', $editMethod['account_name'] ?? 'ALPHA GARAGE PLC')) ?>">
            </div>
        </div>

        <!-- Dynamic Bank Account Fields -->
        <div id="bank_fields_row" class="grid-3" style="<?= $curType !== 'bank' ? 'display:none;' : '' ?>">
            <div class="field">
                <label for="bank_name"><?= e(t('Bank Name', 'የባንክ ስም')) ?></label>
                <input class="input" type="text" id="bank_name" name="bank_name"
                       value="<?= e(post('bank_name', $editMethod['bank_name'] ?? '')) ?>"
                       placeholder="e.g. Commercial Bank of Ethiopia">
            </div>
            <div class="field">
                <label for="account_number"><?= e(t('Bank Account Number', 'የባንክ ሂሳብ ቁጥር')) ?></label>
                <input class="input" type="text" id="account_number" name="account_number"
                       value="<?= e(post('account_number', $editMethod['account_number'] ?? '')) ?>"
                       placeholder="e.g. 1000123456789">
            </div>
            <div class="field">
                <label for="branch"><?= e(t('Branch (Optional)', 'ቅርንጫፍ (አማራጭ)')) ?></label>
                <input class="input" type="text" id="branch" name="branch"
                       value="<?= e(post('branch', $editMethod['branch'] ?? '')) ?>"
                       placeholder="e.g. Bole Medhanialem">
            </div>
        </div>

        <!-- Dynamic Mobile Money / Merchant Fields -->
        <div id="mobile_fields_row" class="grid-3" style="<?= !in_array($curType, ['mobile_money', 'other'], true) ? 'display:none;' : '' ?>">
            <div class="field">
                <label for="phone_number"><?= e(t('Mobile / Wallet Phone Number', 'የሞባይል / ዋሌት ስልክ ቁጥር')) ?></label>
                <input class="input" type="text" id="phone_number" name="phone_number"
                       value="<?= e(post('phone_number', $editMethod['phone_number'] ?? '')) ?>"
                       placeholder="e.g. 0911002233">
            </div>
            <div class="field">
                <label for="merchant_number"><?= e(t('Merchant Number (Optional)', 'የነጋዴ ቁጥር (አማራጭ)')) ?></label>
                <input class="input" type="text" id="merchant_number" name="merchant_number"
                       value="<?= e(post('merchant_number', $editMethod['merchant_number'] ?? '')) ?>"
                       placeholder="e.g. MERCH-1002">
            </div>
            <div class="field">
                <label for="till_number"><?= e(t('Till Number (Optional)', 'የቲል ቁጥር (አማራጭ)')) ?></label>
                <input class="input" type="text" id="till_number" name="till_number"
                       value="<?= e(post('till_number', $editMethod['till_number'] ?? '')) ?>"
                       placeholder="e.g. TILL-504">
            </div>
        </div>

        <div class="grid-2">
            <div class="field">
                <label for="instructions"><?= e(t('Payment Instructions (English)', 'የክፍያ መመሪያ (እንግሊዝኛ)')) ?></label>
                <textarea class="input" id="instructions" name="instructions" rows="2"
                          placeholder="Transfer instructions for customer..."><?= e(post('instructions', $editMethod['instructions'] ?? '')) ?></textarea>
            </div>
            <div class="field">
                <label for="instructions_am"><?= e(t('Payment Instructions (Amharic)', 'የክፍያ መመሪያ (አማርኛ)')) ?></label>
                <textarea class="input" id="instructions_am" name="instructions_am" rows="2"
                          placeholder="ለደንበኛው የሚተላለፍ የክፍያ መመሪያ..."><?= e(post('instructions_am', $editMethod['instructions_am'] ?? '')) ?></textarea>
            </div>
        </div>

        <div style="display:flex;gap:24px;align-items:center;margin:16px 0;flex-wrap:wrap;">
            <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;">
                <input type="checkbox" name="is_active" value="1"
                       <?= post_int('is_active', (int)($editMethod['is_active'] ?? 1)) ? 'checked' : '' ?>>
                <span><strong><?= e(t('Active (Available to Customers)', 'ንቁ (ለደንበኞች ክፍት)')) ?></strong></span>
            </label>
            <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;">
                <input type="checkbox" name="requires_reference" value="1"
                       <?= post_int('requires_reference', (int)($editMethod['requires_reference'] ?? 1)) ? 'checked' : '' ?>>
                <span><?= e(t('Require Transaction Reference', 'የግብይት ቁጥር ያስፈልጋል')) ?></span>
            </label>
            <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;">
                <input type="checkbox" name="requires_screenshot" value="1"
                       <?= post_int('requires_screenshot', (int)($editMethod['requires_screenshot'] ?? 1)) ? 'checked' : '' ?>>
                <span><?= e(t('Require Proof Screenshot / Receipt', 'የደረሰኝ ስክሪንሾት ያስፈልጋል')) ?></span>
            </label>
            <div style="display:inline-flex;align-items:center;gap:8px;">
                <label for="display_order" style="margin:0;"><?= e(t('Display Order:', 'ቅደም ተከተል:')) ?></label>
                <input class="input" type="number" id="display_order" name="display_order" style="width:80px;"
                       value="<?= post_int('display_order', (int)($editMethod['display_order'] ?? 0)) ?>">
            </div>
        </div>

        <div style="display:flex;gap:12px;margin-top:20px;">
            <button class="btn btn-primary" type="submit">
                <?= $editMethod ? e(t('Update Provider', 'አቅራቢ አዘምን')) : e(t('Create Provider', 'አቅራቢ ፍጠር')) ?>
            </button>
            <a href="<?= e(url('payments/methods.php')) ?>" class="btn btn-ghost"><?= e(t('Cancel', 'ሰርዝ')) ?></a>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- Payment Providers List -->
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:12px;">
        <div>
            <h3 style="margin:0;"><?= e(t('Configured Ethiopian Banks & Payment Providers', 'የተዋቀሩ የኢትዮጵያ ባንኮች እና የክፍያ አቅራቢዎች')) ?></h3>
            <span class="muted small"><?= e(t('Manage accounts, sensitive identifiers, and 1-click availability toggles with CSRF protection.', 'የሂሳብ ቁጥሮችን፣ ሚስጥራዊ መለያዎችን እና የ1-ጠቅታ አሰራርን በደህንነት ያስተዳድሩ።')) ?></span>
        </div>
        <?php if (!$editMethod && get('new') !== '1'): ?>
            <a href="<?= e(url('payments/methods.php?new=1')) ?>" class="btn btn-primary btn-sm">
                + <?= e(t('Add Payment Provider', 'አዲስ የክፍያ አቅራቢ ያክሉ')) ?>
            </a>
        <?php endif; ?>
    </div>

    <div class="table-wrap">
        <table class="table stack">
            <thead>
                <tr>
                    <th><?= e(t('Order', 'ተራ')) ?></th>
                    <th><?= e(t('Provider / Bank', 'አቅራቢ / ባንክ')) ?></th>
                    <th><?= e(t('Category', 'ምድብ')) ?></th>
                    <th><?= e(t('Account Holder', 'የሂሳብ ባለቤት')) ?></th>
                    <th><?= e(t('Account / Till Identifier', 'የሂሳብ / ቲል መለያ')) ?></th>
                    <th><?= e(t('Status', 'ሁኔታ')) ?></th>
                    <th style="text-align:right;"><?= e(t('1-Click Status Toggle & Actions', 'የ1-ጠቅታ ሁኔታ መቆጣጠሪያ')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($allMethods as $m): ?>
                <tr style="<?= empty($m['is_active']) ? 'opacity:0.65;background:rgba(0,0,0,0.02);' : '' ?>">
                    <td class="mono"><?= (int)$m['display_order'] ?></td>
                    <td>
                        <strong><?= e(current_lang() === 'am' && !empty($m['name_am']) ? $m['name_am'] : $m['name']) ?></strong>
                        <?php if (current_lang() === 'am' && !empty($m['name_am'])): ?>
                            <div class="muted small"><?= e($m['name']) ?></div>
                        <?php endif; ?>
                        <span class="mono small muted"><?= e($m['code']) ?></span>
                    </td>
                    <td>
                        <?php
                        $typeBadges = [
                            'bank' => '<span class="badge" style="background:rgba(37,99,235,0.15);color:#3B82F6;border:1px solid rgba(37,99,235,0.3);">' . e(t('Commercial Bank', 'የንግድ ባንክ')) . '</span>',
                            'mobile_money' => '<span class="badge" style="background:rgba(16,185,129,0.15);color:#10B981;border:1px solid rgba(16,185,129,0.3);">' . e(t('Mobile Wallet', 'ሞባይል ዋሌት')) . '</span>',
                            'cash' => '<span class="badge" style="background:rgba(217,119,6,0.15);color:#F59E0B;border:1px solid rgba(217,119,6,0.3);">' . e(t('Cash (Reception Desk)', 'ጥሬ ገንዘብ (የፊት ዴስክ)')) . '</span>',
                            'other' => '<span class="badge">' . e(t('Merchant Account', 'የነጋዴ ሂሳብ')) . '</span>',
                        ];
                        echo $typeBadges[$m['type']] ?? '<span class="badge">' . e($m['type']) . '</span>';
                        ?>
                    </td>
                    <td>
                        <?= e($m['account_name'] ?: '—') ?>
                    </td>
                    <td>
                        <?php
                        $rawId = $m['account_number'] ?: ($m['merchant_number'] ?: ($m['till_number'] ?: ($m['phone_number'] ?: '')));
                        $masked = ManualPaymentService::maskIdentifier($rawId);
                        ?>
                        <?php if ($rawId !== ''): ?>
                            <div>
                                <span class="mono identifier-masked" id="mask_<?= $m['id'] ?>"><?= e($masked) ?></span>
                                <span class="mono identifier-raw" id="raw_<?= $m['id'] ?>" style="display:none;color:var(--gold,#D4AF37);"><?= e($rawId) ?></span>
                                <button type="button" class="btn btn-ghost btn-sm toggle-mask-btn" data-id="<?= $m['id'] ?>" style="padding:1px 5px;font-size:0.75rem;margin-left:4px;" title="<?= e(t('Toggle unmasked view', 'የተሟላ ቁጥር አሳይ')) ?>">
                                    👁
                                </button>
                            </div>
                        <?php else: ?>
                            <span class="muted small">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($m['is_active'])): ?>
                            <span class="badge badge-active"><?= e(t('Active', 'ንቁ')) ?></span>
                        <?php else: ?>
                            <span class="badge badge-inactive"><?= e(t('Inactive', 'ቦዝኗል')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;">
                        <div style="display:inline-flex;gap:8px;align-items:center;justify-content:flex-end;">
                            <!-- 1-Click Status Toggle Button with CSRF Token -->
                            <form method="post" action="<?= e(url('payments/methods.php')) ?>" style="margin:0;display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                                <?php if (!empty($m['is_active'])): ?>
                                    <input type="hidden" name="active" value="0">
                                    <button class="btn btn-outline btn-sm" type="submit"
                                            title="<?= e(t('Click to immediately deactivate this provider', 'ይህን አቅራቢ ለማቦዘን ጠቅ ያድርጉ')) ?>"
                                            style="color:#EF4444;border-color:rgba(239,68,68,0.4);">
                                        <?= e(t('Deactivate', 'አቦዝን')) ?>
                                    </button>
                                <?php else: ?>
                                    <input type="hidden" name="active" value="1">
                                    <button class="btn btn-outline btn-sm" type="submit"
                                            title="<?= e(t('Click to immediately activate this provider', 'ይህን አቅራቢ ለማንቃት ጠቅ ያድርጉ')) ?>"
                                            style="color:#10B981;border-color:rgba(16,185,129,0.4);">
                                        <?= e(t('Activate', 'አንቃ')) ?>
                                    </button>
                                <?php endif; ?>
                            </form>

                            <!-- Edit Details Button -->
                            <a class="btn btn-ghost btn-sm" href="<?= e(url('payments/methods.php?edit=' . $m['id'])) ?>">
                                <?= e(t('Edit', 'አርትዕ')) ?>
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$allMethods): ?>
                    <tr><td colspan="7" class="empty"><?= e(t('No payment methods configured.', 'የተዋቀሩ የክፍያ መንገዶች የሉም።')) ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Dynamic category selector in Add/Edit form
    var typeSelect = document.getElementById('type');
    var bankRow = document.getElementById('bank_fields_row');
    var mobileRow = document.getElementById('mobile_fields_row');

    if (typeSelect) {
        typeSelect.addEventListener('change', function () {
            var val = this.value;
            if (bankRow) {
                bankRow.style.display = (val === 'bank') ? 'grid' : 'none';
            }
            if (mobileRow) {
                mobileRow.style.display = (val === 'mobile_money' || val === 'other') ? 'grid' : 'none';
            }
        });
    }

    // Toggle masked identifiers
    document.querySelectorAll('.toggle-mask-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = this.getAttribute('data-id');
            var maskSpan = document.getElementById('mask_' + id);
            var rawSpan = document.getElementById('raw_' + id);
            if (maskSpan && rawSpan) {
                if (rawSpan.style.display === 'none') {
                    rawSpan.style.display = 'inline';
                    maskSpan.style.display = 'none';
                    this.textContent = '🔒';
                } else {
                    rawSpan.style.display = 'none';
                    maskSpan.style.display = 'inline';
                    this.textContent = '👁';
                }
            }
        });
    });
});
</script>

<?php ui_footer(); ?>
