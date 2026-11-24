<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — CUSTOMER SERVICE HISTORY VERIFICATION & LINKING CONTROLLER
 * Verifies email ownership before linking historical walk-in customer records.
 * 
 * Hardened in Phase 1.10E:
 * - SEC-110D-01: Safe GET/POST separation (GET is strictly read-only; POST performs linking).
 * - SEC-110D-02: Rate-limiting on outbound linking requests (max 3 per 900 seconds).
 * - SEC-110D-03: Secure login continuation (token persisted in server-side session without URL leakage).
 * - SEC-110D-04: Neutral non-disclosing feedback.
 */

require __DIR__ . '/../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';

// Capture verification token from GET, POST, or active server-side session
$rawToken = (string) (get('token') ?: post('token') ?: ($_SESSION['pending_link_token'] ?? ''));

// SEC-110D-03: Handle unauthenticated verification link clicks
if (!current_user()) {
    if ($rawToken !== '' && preg_match('/^[a-f0-9]{64}$/i', $rawToken)) {
        // Stash token in protected server session memory, never in outgoing redirect URL
        $_SESSION['pending_link_token'] = $rawToken;
    }
    redirect('login.php?redirect=' . urlencode('verify-customer.php'));
}

// User is authenticated
$user = require_login();

// Restrict to customer role (and administrator for preview/testing)
if ($user['role_name'] !== 'customer' && $user['role_name'] !== 'administrator') {
    abort(403, t('Customer service history linking is reserved for client accounts.', 'Customer service history linking is reserved for client accounts.'));
}

$garageName = Setting::get('garage_name', 'Alpha Garage');

// =========================================================================
// 1. SEC-110D-02: RATE-LIMITED OUTBOUND LINKING REQUEST DISPATCH (POST)
// =========================================================================
if (is_post() && post('action') === 'request_link') {
    verify_csrf();

    $limit = RateLimiter::check('link_req|' . $user['id'], 3, 900);
    if (!$limit['allowed']) {
        flash(
            'warning',
            sprintf(
                t('Too many linking requests. Please check your inbox or try again in %d minute(s).', 'Too many linking requests. Please check your inbox or try again in %d minute(s).'),
                max(1, (int) ceil($limit['retry_after'] / 60))
            )
        );
        redirect('customer.php');
    }

    $result = CustomerIdentityService::requestLinking($user);
    // SEC-110D-04: Always render neutral feedback
    flash('info', t($result['message'], $result['message']));
    redirect('customer.php');
}

// =========================================================================
// 2. SEC-110D-01: ATOMIC ACCOUNT LINKING CONFIRMATION (POST ONLY)
// =========================================================================
if (is_post() && post('action') === 'confirm_link') {
    verify_csrf();

    $tokenToConfirm = (string) (post('token') ?: ($_SESSION['pending_link_token'] ?? ''));
    $confirm = CustomerIdentityService::confirmLinking($tokenToConfirm, (int) $user['id']);

    // Always clear pending session token after confirmation attempt
    unset($_SESSION['pending_link_token']);

    if ($confirm['ok']) {
        flash('success', t($confirm['message'], $confirm['message']));
    } else {
        flash('error', t($confirm['error'] ?? 'Failed to link service history.', $confirm['error'] ?? 'Failed to link service history.'));
    }
    redirect('customer.php');
}

// =========================================================================
// 3. SEC-110D-01: READ-ONLY CONFIRMATION PAGE (GET ONLY — ZERO SIDE EFFECTS)
// =========================================================================
$error = '';
$inspection = null;

if ($rawToken === '') {
    $error = t('No verification token was provided. Please check the link in your email or request a link from your portal.', 'No verification token was provided. Please check the link in your email or request a link from your portal.');
} else {
    $inspection = CustomerIdentityService::inspectTokenForConfirmation($rawToken, (int) $user['id']);
    if (!$inspection['ok']) {
        unset($_SESSION['pending_link_token']);
        $error = t($inspection['error'] ?? 'Invalid or expired verification token.', $inspection['error'] ?? 'Invalid or expired verification token.');
    }
}

ui_header(t('Confirm Service History Linking', 'Confirm Service History Linking'), 'portal');
?>
<div class="card w-md" style="margin: 40px auto; padding: 28px; max-width: 600px;">
  <div class="card-head" style="margin-bottom: 20px;">
    <h3 style="color: #D4AF37; margin: 0 0 6px 0;"><?= e(t('Link Past In-Person Service Records', 'Link Past In-Person Service Records')) ?></h3>
    <p class="muted small" style="margin: 0;"><?= e(t('Connect your historical garage visits and vehicles to your portal account.', 'Connect your historical garage visits and vehicles to your portal account.')) ?></p>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-danger mb-4" role="alert">
      <strong><?= e(t('Verification Failed:', 'Verification Failed:')) ?></strong> <?= e(t($error, $error)) ?>
    </div>
    <p class="small text-muted">
      <?= e(t('Verification links expire after 30 minutes, are single-use, and are bound to your verified email address. If your link expired or was issued to another account, you can request a fresh link from your portal.', 'Verification links expire after 30 minutes, are single-use, and are bound to your verified email address. If your link expired or was issued to another account, you can request a fresh link from your portal.')) ?>
    </p>
    <div style="margin-top: 20px;">
      <a class="btn btn-primary" href="<?= e(url('customer.php')) ?>"><?= e(t('Return to Customer Portal', 'Return to Customer Portal')) ?></a>
    </div>
  <?php elseif ($inspection): ?>
    <div class="alert alert-info mb-4" role="status" style="background: rgba(19, 56, 35, 0.5); border: 1px solid rgba(212, 175, 55, 0.4); padding: 14px 18px; border-radius: 6px;">
      <p style="margin: 0 0 8px 0; color: #fff;">
        <?= e(t('You are currently signed in as', 'You are currently signed in as')) ?> <strong><?= e($user['full_name']) ?></strong> (<code><?= e($user['username']) ?></code> &middot; <code><?= e($user['email']) ?></code>).
      </p>
      <p style="margin: 0; font-size: 0.9rem; color: #e0e0e0;">
        <?= e(t('Click Confirm & Link Service Records below to connect in-person service history and vehicle records matching your verified email to this account.', 'Click Confirm & Link Service Records below to connect in-person service history and vehicle records matching your verified email to this account.')) ?> (<code><?= e($user['email']) ?></code>).
      </p>
    </div>

    <form method="post" action="<?= e(url('verify-customer.php')) ?>" style="margin: 0;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="confirm_link">
      <input type="hidden" name="token" value="<?= e($rawToken) ?>">

      <div class="form-actions" style="display: flex; gap: 12px; margin-top: 24px;">
        <button class="btn btn-primary" type="submit"><?= e(t('Confirm & Link Service Records', 'Confirm & Link Service Records')) ?></button>
        <a class="btn btn-ghost" href="<?= e(url('customer.php')) ?>"><?= e(t('Cancel', 'Cancel')) ?></a>
      </div>
    </form>
  <?php endif; ?>
</div>
<?php ui_footer();
