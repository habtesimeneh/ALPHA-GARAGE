<?php
declare(strict_types=1);

/** Machine-specific credentials may be kept in the git-ignored local.php. */
$localPath = __DIR__ . '/local.php';
$local = is_file($localPath) ? require $localPath : [];
if (!is_array($local)) {
    throw new RuntimeException('app/config/local.php must return a database configuration array.');
}

$values = [];
foreach ([
    'GMS_DB_HOST' => 'host',
    'GMS_DB_PORT' => 'port',
    'GMS_DB_NAME' => 'name',
    'GMS_DB_USER' => 'user',
    'GMS_DB_PASS' => 'pass',
] as $environmentName => $localName) {
    $environmentValue = getenv($environmentName);
    $value = $environmentValue !== false ? $environmentValue : ($local[$localName] ?? null);
    if (!is_string($value) && !is_int($value)) {
        throw new RuntimeException('Configure database credentials in app/config/local.php or the web-server environment.');
    }
    $value = (string) $value;
    if ($value === '') {
        throw new RuntimeException('Configure database credentials in app/config/local.php or the web-server environment.');
    }
    $values[$localName] = $value;
}

if (!ctype_digit($values['port']) || (int) $values['port'] < 1 || (int) $values['port'] > 65535) {
    throw new RuntimeException('GMS_DB_PORT must be a valid TCP port.');
}
if (!preg_match('/^[A-Za-z0-9_]+$/', $values['name'])) {
    throw new RuntimeException('GMS_DB_NAME contains invalid characters.');
}

$timezone = getenv('GMS_DB_TZ');
if ($timezone === false) {
    $timezone = $local['tz'] ?? '+03:00';
}
if (!is_string($timezone)) {
    throw new RuntimeException('Database timezone configuration must be a string.');
}
if (!preg_match('/^[+-](?:0\d|1[0-4]):[0-5]\d$/', $timezone)) {
    throw new RuntimeException('GMS_DB_TZ must use a signed offset such as +03:00.');
}

return [
    'host' => $values['host'],
    'port' => (int) $values['port'],
    'name' => $values['name'],
    'user' => $values['user'],
    'pass' => $values['pass'],
    'charset' => 'utf8mb4',
    'tz' => $timezone,
];
