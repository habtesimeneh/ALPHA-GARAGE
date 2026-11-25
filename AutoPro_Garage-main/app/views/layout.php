<?php
declare(strict_types=1);

/** Shared page chrome and small form/UI helpers (server-rendered, no inline JS or CSS). */

function nav_items(): array
{
    $u = current_user();
    if ($u && $u['role_name'] === 'customer') {
        return [
            ['dashboard',    'Customer Portal',    'customer.php',              true, 'M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z'],
            ['book',         'Book Service',       'customer.php#book-service', true, 'M19 3h-1V1h-2v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11zM7 10h5v5H7z'],
            ['vehicles',     'My Vehicles',        'customer.php#vehicles',      true, 'M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11c-.66 0-1.21.42-1.42 1.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.5 16c-.83 0-1.5-.67-1.5-1.5S5.67 13 6.5 13s1.5.67 1.5 1.5S7.33 16 6.5 16zm11 0c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zM5 11l1.5-4.5h11L19 11H5z'],
            ['appointments', 'Appointments',       'customer.php#appointments',  true, 'M19 3h-1V1h-2v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11zM7 10h5v5H7z'],
            ['repairs',      'Repair Orders',      'customer.php#repairs',       true, 'M19 3h-4.18C14.4 1.84 13.3 1 12 1s-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm2 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z'],
            ['invoices',     'Billing & Invoices', 'customer.php#invoices',      true, 'M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z'],
            ['link_records', 'Link Past Records',  'verify-customer.php',       true, 'M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-2 16l-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z'],
            ['profile',      'My Account',         'profile.php',               true, 'M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z'],
        ];
    }
    $mechanicOnly = can('jobs.view_assigned') && !can('jobs.view_all');
    return array_values(array_filter([
        ['dashboard',    'Dashboard',    'dashboard.php',    can('dashboard.view'),    'M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z'],
        ['appointments', 'Appointments', 'appointments/',   can('appointments.view'), 'M19 3h-1V1h-2v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11zM7 10h5v5H7z'],
        ['customers',    'Customers',    'customers/',       can('customers.view'),    'M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5s-3 1.34-3 3 1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5C15 14.17 10.33 13 8 13zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z'],
        ['vehicles',     'Vehicles',     'vehicles/',        can('vehicles.view'),     'M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11c-.66 0-1.21.42-1.42 1.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.5 16c-.83 0-1.5-.67-1.5-1.5S5.67 13 6.5 13s1.5.67 1.5 1.5S7.33 16 6.5 16zm11 0c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zM5 11l1.5-4.5h11L19 11H5z'],
        ['jobs',         $mechanicOnly ? 'My Jobs' : 'Job Cards', 'job-cards/', can_any('jobs.view_all', 'jobs.view_assigned'), 'M19 3h-4.18C14.4 1.84 13.3 1 12 1s-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm2 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z'],
        ['services',     'Services',     'services/',        can('services.view'),     'M19.43 12.98c.04-.32.07-.64.07-.98s-.03-.66-.07-.98l2.11-1.65c.19-.15.24-.42.12-.64l-2-3.46c-.12-.22-.39-.3-.61-.22l-2.49 1c-.52-.4-1.08-.73-1.69-.98l-.38-2.65C14.46 2.18 14.25 2 14 2h-4c-.25 0-.46.18-.49.42l-.38 2.65c-.61.25-1.17.59-1.69.98l-2.49-1c-.23-.09-.49 0-.61.22l-2 3.46c-.13.22-.07.49.12.64l2.11 1.65c-.04.32-.07.65-.07.98s.03.66.07.98l-2.11 1.65c-.19.15-.24.42-.12.64l2 3.46c.12.22.39.3.61.22l2.49-1c.52.4 1.08.73 1.69.98l.38 2.65c.03.24.24.42.49.42h4c.25 0 .46-.18.49-.42l.38-2.65c.61-.25 1.17-.59 1.69-.98l2.49 1c.23.09.49 0 .61-.22l2-3.46c.12-.22.07-.49-.12-.64l-2.11-1.65zM12 15.5c-1.93 0-3.5-1.57-3.5-3.5s1.57-3.5 3.5-3.5 3.5 1.57 3.5 3.5-1.57 3.5-3.5 3.5z'],
        ['mechanics',    'Mechanics',    'mechanics/',       can('mechanics.view'),    'M22.7 19l-9.1-9.1c.9-2.3.4-5-1.5-6.9-2-2-5-2.4-7.4-1.3L9 6 6 9 1.6 4.7C.4 7.1.9 10.1 2.9 12.1c1.9 1.9 4.6 2.4 6.9 1.5l9.1 9.1c.4.4 1 .4 1.4 0l2.3-2.3c.5-.4.5-1.1.1-1.4z'],
        ['suppliers',    'Suppliers',    'suppliers/',       can('suppliers.view'),    'M20 6h-4V4c0-1.11-.89-2-2-2h-4c-1.11 0-2 .89-2 2v2H4c-1.11 0-1.99.89-1.99 2L2 19c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V8c0-1.11-.89-2-2-2zm-6 0h-4V4h4v2z'],
        ['inventory',    'Inventory',    'inventory/',       can('inventory.view'),    'M20 2H4c-1 0-2 .9-2 2v3.01c0 .72.43 1.34 1 1.69V20c0 1.1 1.1 2 2 2h14c.9 0 2-.9 2-2V8.7c.57-.35 1-.97 1-1.69V4c0-1.1-1-2-2-2zm-5 12H9v-2h6v2zm5-7H4V4l16-.02V7z'],
        ['invoices',     'Invoices',     'invoices/',        can('invoices.view'),     'M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z'],
        ['payments',     'Payments',     'payments/',        can('payments.view'),     'M20 4H4c-1.11 0-1.99.89-1.99 2L2 18c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V6c0-1.11-.89-2-2-2zm0 14H4v-6h16v6zm0-10H4V6h16v2z'],
        ['expenses',     'Expenses',     'expenses/',        can('expenses.view'),    'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm1 15h-2v-1h-2v-2h5v-2H9V7h2V6h2v1h2v2h-5v2h5v5h-2v1z'],
        ['reports',      'Reports',      'reports/',         can_any('reports.view', 'reports.financial', 'reports.inventory', 'audit.view'), 'M5 9.2h3V19H5V9.2zM10.6 5h2.8v14h-2.8V5zm5.6 8H19v6h-2.8v-6z'],
        ['users',        'Users',        'users/',           can('users.manage'),      'M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z'],
        ['roles',        'Roles & Access', 'users/roles.php', can('roles.manage'),     'M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z'],
        ['settings',     'Garage Settings', 'users/settings.php', can('settings.manage'), 'M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z'],
        ['payment_methods', 'Bank & Telebirr Accounts', 'payments/methods.php', can('payments.methods'), 'M11.5 1L2 6v2h19V6m-17 4v7h3v-7m4 0v7h3v-7m4 0v7h3v-7M2 19v2h19v-2H2z'],
        ['videos',       'Video Management', 'videos/',          can('videos.view'),       'M17 10.5V7c0-.55-.45-1-1-1H4c-.55 0-1 .45-1 1v10c0 .55.45 1 1 1h12c.55 0 1-.45 1-1v-3.5l4 4v-11l-4 4z'],
        ['sms_logs',     'SMS Audit Logs', 'notifications/sms-logs.php', can_any('sms.view_logs', 'notifications.view', 'audit.view'), 'M20 2H4c-1.1 0-1.99.9-1.99 2L2 22l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zM9 11H7V9h2v2zm4 0h-2V9h2v2zm4 0h-2V9h2v2z'],
        ['audit',        'Audit Logs',   'audit-logs/',      can('audit.view'),        'M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-2 16l-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z'],
    ], fn($i) => $i[3]));
}

function ui_header(string $title, string $active = '', array $opts = []): void
{
    $u = current_user();
    $garage = Setting::get('garage_name', 'Alpha Garage');
    $bare = !$u || !empty($opts['bare']);
    $flash = pull_flash();
    ?><!doctype html>
<html lang="<?= e(current_lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($title) ?> · <?= e($garage) ?></title>
<link rel="icon" href="<?= e(asset('images/logo.svg')) ?>" type="image/svg+xml">
<meta name="theme-color" content="#0B2E1B">
<?= css_links() ?></head>
<body class="<?= $bare ? 'auth-body' : 'app-body' ?>">
<a class="skip-link" href="#content"><?= e(t('Skip to main content', 'Skip to main content')) ?></a>
<div id="toast-root" class="toast-root" aria-live="polite" data-flash="<?= e(json_encode($flash, JSON_UNESCAPED_UNICODE)) ?>"></div>
<div id="loader" class="loader" hidden><div class="spinner"></div></div>
<?php if (!$bare): ?>
<div class="shell">
  <aside class="sidebar" id="sidebar" aria-label="Main navigation">
    <a class="brand" href="<?= e(url('dashboard.php')) ?>">
      <img src="<?= e(asset('images/logo.svg')) ?>" alt="" width="36" height="36">
      <span><strong><?= e($garage) ?></strong><small><?= e(t('Garage Management', 'Garage Management')) ?></small></span>
    </a>
    <nav class="nav">
      <?php
        if ($u && $u['role_name'] === 'customer') {
            $groupOf = [
                'dashboard'    => 'Overview',
                'book'         => 'Services',
                'vehicles'     => 'My Garage',
                'appointments' => 'My Garage',
                'repairs'      => 'My Garage',
                'invoices'     => 'Billing',
                'link_records' => 'Account',
                'profile'      => 'Account'
            ];
        } else {
            $groupOf = [
                'dashboard' => 'Overview', 'appointments' => 'Workshop', 'customers' => 'Workshop', 'vehicles' => 'Workshop',
                'jobs' => 'Workshop', 'services' => 'Workshop', 'mechanics' => 'Workshop', 'suppliers' => 'Inventory', 'inventory' => 'Inventory',
                'invoices' => 'Finance', 'payments' => 'Finance', 'expenses' => 'Finance', 'reports' => 'Finance',
                'users' => 'Administration', 'roles' => 'Administration', 'settings' => 'Administration', 'payment_methods' => 'Administration', 'sms_logs' => 'Administration', 'audit' => 'Administration', 'videos' => 'Administration'
            ];
        }
        $lastGroup = '';
        foreach (nav_items() as [$key, $label, $href, , $icon]):
          $g = $groupOf[$key] ?? '';
          if ($g !== '' && $g !== $lastGroup): $lastGroup = $g; ?><div class="nav-group"><?= e(t($g, $g)) ?></div><?php endif; ?>
        <a class="nav-link<?= $key === $active ? ' active' : '' ?>" href="<?= e(url($href)) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>>
          <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="<?= e($icon) ?>"/></svg>
          <span><?= e(t($label, $label)) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-foot">
      <a class="user-chip" href="<?= e(url('profile.php')) ?>" title="<?= e(t('My profile and password', 'My profile and password')) ?>">
        <span class="avatar"><?= e(mb_strtoupper(mb_substr($u['full_name'], 0, 1))) ?></span>
        <span><strong><?= e($u['full_name']) ?></strong><small><?= e($u['role_label']) ?></small></span>
      </a>
      <form method="post" action="<?= e(url('logout.php')) ?>"><?= csrf_field() ?><button class="btn btn-ghost btn-block" type="submit"><?= e(t('Sign out', 'Sign out')) ?></button></form>
    </div>
  </aside>
  <div class="sidebar-backdrop" id="sidebar-backdrop"></div>
  <div class="main">
    <header class="topbar">
      <button class="icon-btn menu-btn" id="menu-btn" type="button" aria-label="Open menu" aria-controls="sidebar"><svg viewBox="0 0 24 24" width="22" height="22"><path fill="currentColor" d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z"/></svg></button>
      <button class="icon-btn sidebar-toggle" id="sidebar-toggle" type="button" aria-label="Collapse or expand sidebar" aria-controls="sidebar"><svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M4 5h16v2H4V5zm0 6h10v2H4v-2zm0 6h16v2H4v-2z"/></svg></button>
      <h1 class="topbar-title"><?= e(t($title, $title)) ?></h1>
      <div class="topbar-search" role="search" id="topbar-search" hidden>
        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M15.5 14h-.79l-.28-.27A6.47 6.47 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
        <input type="search" id="topbar-search-input" placeholder="<?= e(t('Filter this page…', 'Filter this page…')) ?>" aria-label="Filter rows on this page" autocomplete="off">
      </div>
      <div class="topbar-right">
        <?= lang_switcher_html() ?>
        <a class="btn btn-back-home btn-sm topbar-home-btn" href="<?= e(url('index.php')) ?>" title="Back to public homepage">
          <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" style="vertical-align:text-bottom;margin-right:4px;"><path fill="currentColor" d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
          <?= e(t('Back to Home', 'Back to Home')) ?>
        </a>
        <button class="icon-btn theme-toggle" type="button" aria-label="Switch to dark theme" aria-pressed="false" title="Switch theme">
          <span aria-hidden="true">◐</span>
        </button>
        <span class="today" title="<?= e(is_amharic() ? 'የኢትዮጵያ ዘመን አቆጣጠር' : 'Ethiopian & Gregorian Calendar') ?>">
          <svg class="today-cal-icon" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" style="vertical-align:text-bottom;margin-right:4px;"><path fill="currentColor" d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.11 0-1.99.9-1.99 2L3 20c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V10h14v10zm0-12H5V6h14v2z"/></svg>
          <?= e(ethiopian_today('today', is_amharic() ? 'am' : 'en')) ?>
        </span>
        <?php if (can('inventory.view')): $lowCount = count(InventoryService::lowStock(99)); ?>
        <a class="icon-btn notif" href="<?= e(url('inventory/?stock=low')) ?>" aria-label="<?= $lowCount ? e($lowCount . ' low-stock alert' . ($lowCount === 1 ? '' : 's')) : 'No stock alerts' ?>" title="Stock alerts">
          <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="currentColor" d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 14l-5-5 1.41-1.41L12 14.17l5.59-5.59L19 10l-7 7z"/></svg>
          <?php if ($lowCount): ?><span class="notif-dot"><?= (int) $lowCount ?></span><?php endif; ?>
        </a>
        <?php endif; ?>

        <?php 
          $notifCount = NotificationService::unreadCount();
          $recentNotifs = NotificationService::unreadForCurrentUser();
        ?>
        <details class="dropdown" id="notif-menu">
          <summary class="icon-btn notif" aria-label="<?= $notifCount ? e($notifCount . ' unread alerts') : 'No notifications' ?>" title="System alerts">
            <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="currentColor" d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.89 2 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/></svg>
            <?php if ($notifCount > 0): ?><span class="notif-dot"><?= (int) $notifCount ?></span><?php endif; ?>
          </summary>
          <div class="dropdown-menu notification-dropdown">
            <div class="dropdown-head notification-dropdown-head">
              <strong><?= e(t('Alerts', 'Alerts')) ?> (<?= (int) $notifCount ?>)</strong>
              <?php if ($notifCount > 0): ?>
                <form method="post" action="<?= e(url('notifications/mark_read.php')) ?>" class="notification-mark-read">
                  <?= csrf_field() ?>
                  <button type="submit" class="btn btn-ghost btn-sm"><?= e(t('Mark all read', 'Mark all read')) ?></button>
                </form>
              <?php endif; ?>
            </div>
            <?php if (empty($recentNotifs)): ?>
              <div class="notification-empty"><?= e(t('No unread notifications', 'No unread notifications')) ?></div>
            <?php else: ?>
              <?php foreach ($recentNotifs as $nt): ?>
                <a href="<?= e(url($nt['link'] ?: 'notifications/')) ?>" class="notification-item">
                  <strong><?= e($nt['title']) ?></strong>
                  <span><?= e($nt['message']) ?></span>
                </a>
              <?php endforeach; ?>
            <?php endif; ?>
            <div class="notification-all">
              <a class="notification-all-link" href="<?= e(url('notifications/')) ?>"><?= e(t('View all alerts', 'View all alerts')) ?></a>
            </div>
          </div>
        </details>
        <details class="dropdown" id="user-menu">
          <summary aria-label="Account menu">
            <span class="avatar"><?= e(mb_strtoupper(mb_substr($u['full_name'], 0, 1))) ?></span>
            <span class="dropdown-name small"><?= e(explode(' ', $u['full_name'])[0]) ?></span>
            <svg class="caret" viewBox="0 0 20 20" aria-hidden="true"><path d="M5.5 7.5l4.5 4.5 4.5-4.5" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </summary>
          <div class="dropdown-menu">
            <div class="dropdown-head"><strong><?= e($u['full_name']) ?></strong><small><?= e($u['role_label']) ?></small></div>
            <a href="<?= e(url('profile.php')) ?>"><?= e(t('My profile & password', 'My profile & password')) ?></a>
            <form method="post" action="<?= e(url('logout.php')) ?>"><?= csrf_field() ?><button type="submit"><?= e(t('Sign out', 'Sign out')) ?></button></form>
          </div>
        </details>
      </div>
    </header>
    <main class="content" id="content">
<?php endif;
}

function ui_footer(): void
{
    $u = current_user();
    ?>
<?php if ($u): ?>
    </main>
  </div>
</div>
<?php endif; ?>
<div class="modal-backdrop" id="modal" hidden>
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modal-title">
    <h3 id="modal-title"><?= e(t('Please confirm', 'Please confirm')) ?></h3>
    <p id="modal-text"></p>
    <div class="modal-actions"><button class="btn btn-ghost" type="button" id="modal-cancel"><?= e(t('Cancel', 'Cancel')) ?></button><button class="btn btn-primary" type="button" id="modal-ok"><?= e(t('Confirm', 'Confirm')) ?></button></div>
  </div>
</div>
<script src="<?= e(asset('js/i18n.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<script src="<?= e(asset('js/validation.js')) ?>"></script>
<script src="<?= e(asset('js/scroll-animations.js')) ?>"></script>
<?php if (in_array(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')), ['dashboard.php', 'customer.php'], true)): ?>
<script src="<?= e(asset('js/vendor/chart.umd.min.js')) ?>"></script>
<script src="<?= e(asset('js/dashboard.js')) ?>"></script>
<?php endif; ?>
</body>
</html>
<?php
}

function page_header(string $title, string $subtitle = '', string $actionsHtml = ''): void
{
    echo '<div class="page-head reveal-up"><div><h2>' . e(t($title, $title)) . '</h2>' . ($subtitle !== '' ? '<p class="muted">' . e(t($subtitle, $subtitle)) . '</p>' : '') . '</div>'
       . ($actionsHtml !== '' ? '<div class="page-actions">' . $actionsHtml . '</div>' : '') . '</div>';
}

function form_input(string $name, string $label, mixed $value = '', array $errors = [], array $attrs = []): void
{
    $type = $attrs['type'] ?? 'text';
    unset($attrs['type']);
    $help = $attrs['help'] ?? ''; unset($attrs['help']);
    $a = '';
    foreach ($attrs as $k => $v) {
        $a .= $v === true ? ' ' . e($k) : ' ' . e($k) . '="' . e($v) . '"';
    }
    $err = $errors[$name] ?? '';
    $id = 'f_' . $name;
    echo '<div class="field' . ($err ? ' has-error' : '') . '"><label for="' . e($id) . '">' . e(t($label, $label))
       . (isset($attrs['required']) ? ' <span class="req">*</span>' : '') . '</label>';
    
    if ($type === 'password') {
        echo '<div class="password-input-wrap">'
           . '<input class="input password-input" id="' . e($id) . '" name="' . e($name) . '" type="password" value="' . e($value) . '"' . $a . '>'
           . '<button type="button" class="toggle-password" data-target="' . e($id) . '" aria-label="Toggle password visibility" title="Toggle visibility">👁</button>'
           . '</div>';
    } else {
        echo '<input class="input" id="' . e($id) . '" name="' . e($name) . '" type="' . e($type) . '" value="' . e($value) . '"' . $a . '>';
    }

    echo ($help ? '<small class="help">' . e(t($help, $help)) . '</small>' : '')
       . '<div class="field-error">' . e($err) . '</div></div>';
}

function form_textarea(string $name, string $label, mixed $value = '', array $errors = [], array $attrs = []): void
{
    $a = '';
    foreach ($attrs as $k => $v) {
        $a .= $v === true ? ' ' . e($k) : ' ' . e($k) . '="' . e($v) . '"';
    }
    $err = $errors[$name] ?? '';
    echo '<div class="field' . ($err ? ' has-error' : '') . '"><label for="f_' . e($name) . '">' . e(t($label, $label))
       . (isset($attrs['required']) ? ' <span class="req">*</span>' : '') . '</label>'
       . '<textarea class="input" id="f_' . e($name) . '" name="' . e($name) . '" rows="3"' . $a . '>' . e($value) . '</textarea>'
       . '<div class="field-error">' . e($err) . '</div></div>';
}

/** @param array<string,string> $options value => label */
function form_select(string $name, string $label, array $options, mixed $selected = '', array $errors = [], array $attrs = []): void
{
    $a = '';
    foreach ($attrs as $k => $v) {
        $a .= $v === true ? ' ' . e($k) : ' ' . e($k) . '="' . e($v) . '"';
    }
    $err = $errors[$name] ?? '';
    echo '<div class="field' . ($err ? ' has-error' : '') . '"><label for="f_' . e($name) . '">' . e(t($label, $label))
       . (isset($attrs['required']) ? ' <span class="req">*</span>' : '') . '</label><select class="input" id="f_' . e($name) . '" name="' . e($name) . '"' . $a . '>';
    foreach ($options as $v => $l) {
        echo '<option value="' . e($v) . '"' . ((string) $v === (string) $selected ? ' selected' : '') . '>' . e(t($l, $l)) . '</option>';
    }
    echo '</select><div class="field-error">' . e($err) . '</div></div>';
}

function pagination_links(array $p, array $query = []): void
{
    if ($p['pages'] <= 1) { return; }
    $link = fn(int $n, string $label, bool $active = false, bool $disabled = false) => $disabled
        ? '<span class="page disabled">' . $label . '</span>'
        : '<a class="page' . ($active ? ' active' : '') . '" href="?' . e(http_build_query(array_merge($query, ['page' => $n]))) . '">' . $label . '</a>';
    echo '<nav class="pagination" aria-label="Pagination">' . $link($p['page'] - 1, t('‹ Prev', '‹ Prev'), false, $p['page'] <= 1);
    $start = max(1, $p['page'] - 2); $end = min($p['pages'], $p['page'] + 2);
    for ($i = $start; $i <= $end; $i++) { echo $link($i, (string) $i, $i === $p['page']); }
    echo $link($p['page'] + 1, t('Next ›', 'Next ›'), false, $p['page'] >= $p['pages']);
    echo '<span class="muted page-info">' . (int) $p['total'] . ' ' . e(t('records', 'records')) . '</span></nav>';
}

/** Small POST button with an optional confirmation modal (handled by app.js, still works without JS). */
function post_button(string $action, array $fields, string $label, string $class = 'btn btn-ghost btn-sm', string $confirm = ''): string
{
    $h = '<form method="post" action="' . e($action) . '" class="inline-form"' . ($confirm !== '' ? ' data-confirm="' . e(t($confirm, $confirm)) . '"' : '') . '>' . csrf_field();
    foreach ($fields as $k => $v) {
        $h .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    }
    return $h . '<button type="submit" class="' . e($class) . '">' . e(t($label, $label)) . '</button></form>';
}

