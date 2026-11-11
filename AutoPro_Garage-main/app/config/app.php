<?php
declare(strict_types=1);

/**
 * Application configuration.
 * Machine-specific values go into app/config/local.php (git-ignored) or
 * environment variables, never into public files. See local.php.example.
 */
$config = [
    'name'     => 'Alpha Garage',
    'app_url'  => '',                    // absolute public base URL for outbound links; GMS_APP_URL overrides
    'timezone' => 'Africa/Addis_Ababa',
    'debug'    => false,                 // never true in production: errors are written to storage/logs only
    'session'  => [
        'name'          => 'GMSSESSID',
        'idle_timeout'  => 1800,         // 30 minutes of inactivity
        'absolute_life' => 28800,        // 8 hours maximum
    ],
    'security' => [
        'max_attempts_user' => 5,        // failed logins per username+IP in the window
        'max_attempts_ip'   => 20,       // failed logins per IP in the window
        'lockout_minutes'   => 15,
        'min_password_len'  => 10,
    ],
    'remember' => [
        'enabled'      => true,          // Remember Me feature on login page
        'duration_days' => 30,           // how long a remember-me cookie is valid
    ],
    'per_page' => 15,
];

$local = __DIR__ . '/local.php';
if (is_file($local)) {
    $config = array_replace_recursive($config, (array) require $local);
}
if (($v = getenv('GMS_DEBUG')) !== false) {
    $config['debug'] = filter_var($v, FILTER_VALIDATE_BOOLEAN);
}
return $config;
