<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — PHASE 1.13 BROWSER SCREENSHOT GENERATOR
 * Captures 22 high-resolution screenshots (11 views × 2 languages: English and Amharic)
 * using Headless Google Chrome with authentic sessions and Ethiopic font rendering.
 */

require_once __DIR__ . '/../app/bootstrap.php';

$phpBinary = 'C:\\MAMP\\bin\\php\\php8.3.1\\php.exe';
$phpIni = 'C:\\MAMP\\conf\\php8.3.1\\php.ini';
$chromePath = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';

$screenshotDir = APP_ROOT . '/storage/testing/screenshots/phase-1.13';
if (!is_dir($screenshotDir)) {
    @mkdir($screenshotDir, 0777, true);
}

$serverPort = 8091;
$serverBase = "http://127.0.0.1:$serverPort/";
$serverLog = APP_ROOT . '/storage/testing/logs/test-server-screenshots.log';

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['file', $serverLog, 'a'],
    2 => ['file', $serverLog, 'a'],
];

$serverEnv = array_merge($_ENV, getenv(), [
    'GMS_TEST_MODE' => '1',
    'GMS_ALLOW_DESTRUCTIVE_TESTS' => '1',
    'GMS_DB_NAME' => 'garage_management_system_test',
    'GMS_STORAGE_PATH' => APP_ROOT . '/storage/testing',
    'GMS_DB_USER' => 'root',
    'GMS_DB_PASS' => 'root',
]);

$serverCmd = escapeshellarg($phpBinary) . ' -c ' . escapeshellarg($phpIni) . ' -S 127.0.0.1:' . $serverPort . ' -t ' . escapeshellarg(APP_ROOT . '/public');
$proc = proc_open($serverCmd, $descriptors, $pipes, APP_ROOT, $serverEnv);

if (!is_resource($proc)) {
    echo "Error: Failed to launch PHP web server on port $serverPort\n";
    exit(1);
}

usleep(400000); // 400ms for bind

function auth_cookie(string $username, string $password): string {
    global $serverBase;
    $jar = tempnam(sys_get_temp_dir(), 'gms_shot_jar_');
    
    // 1. GET login to grab CSRF
    $ch = curl_init($serverBase . 'login.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_TIMEOUT => 6,
    ]);
    $html = (string) curl_exec($ch);
    curl_close($ch);
    
    $csrf = '';
    if (preg_match('/name=["\']_csrf["\']\s+value=["\']([a-f0-9]{64})["\']/i', $html, $m)) {
        $csrf = $m[1];
    } elseif (preg_match('/value=["\']([a-f0-9]{64})["\']\s+name=["\']_csrf["\']/i', $html, $m)) {
        $csrf = $m[1];
    }

    // 2. POST login
    $ch = curl_init($serverBase . 'login.php');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            '_csrf' => $csrf,
            'username' => $username,
            'password' => $password,
        ]),
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 6,
    ]);
    curl_exec($ch);
    curl_close($ch);

    // Extract cookie
    $cookieHeader = '';
    if (is_file($jar)) {
        $lines = file($jar, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if ($line[0] === '#') continue;
            $parts = preg_split('/\t+/', $line);
            if (count($parts) >= 7) {
                $cookieHeader = "{$parts[5]}={$parts[6]}";
            }
        }
        @unlink($jar);
    }
    return $cookieHeader;
}

echo "Generating authenticated session cookies...\n";
$cookies = [
    'admin' => auth_cookie('admin', 'Admin#TestPass2026'),
    'reception' => auth_cookie('reception', 'Demo#TestPass2026'),
    'mechanic' => auth_cookie('mechanic1', 'Demo#TestPass2026'),
    'stock' => auth_cookie('stock', 'Demo#TestPass2026'),
    'accounts' => auth_cookie('accounts', 'Demo#TestPass2026'),
    'customer' => auth_cookie('test_cust_alice', 'Customer#Pass1'),
];

$views = [
    // Public & Auth views
    ['name' => 'homepage', 'url' => 'index.php', 'auth' => null],
    ['name' => 'about', 'url' => 'about.php', 'auth' => null],
    ['name' => 'services', 'url' => 'services.php', 'auth' => null],
    ['name' => 'contact', 'url' => 'contact.php', 'auth' => null],
    ['name' => 'login', 'url' => 'login.php', 'auth' => null],
    // Role Dashboards
    ['name' => 'admin_dashboard', 'url' => 'dashboard.php', 'auth' => 'admin'],
    ['name' => 'receptionist_dashboard', 'url' => 'dashboard.php', 'auth' => 'reception'],
    ['name' => 'mechanic_dashboard', 'url' => 'dashboard.php', 'auth' => 'mechanic'],
    ['name' => 'inventory_dashboard', 'url' => 'dashboard.php', 'auth' => 'stock'],
    ['name' => 'accountant_dashboard', 'url' => 'dashboard.php', 'auth' => 'accounts'],
    ['name' => 'customer_portal', 'url' => 'customer.php', 'auth' => 'customer'],
];

$captured = 0;
$total = count($views) * 2;

echo "Capturing $total screenshots via Headless Chrome...\n";

foreach (['en', 'am'] as $lang) {
    foreach ($views as $view) {
        $viewName = $view['name'];
        $file = $screenshotDir . "/{$lang}_{$viewName}.png";
        $url = $serverBase . $view['url'] . "?lang={$lang}";
        
        $cookiePart = "GMS_LANG={$lang}";
        if ($view['auth'] && !empty($cookies[$view['auth']])) {
            $cookiePart .= "; " . $cookies[$view['auth']];
        }

        $cmd = escapeshellarg($chromePath) . " --headless=new --disable-gpu --window-size=1280,900"
             . " --no-first-run --no-default-browser-check --virtual-time-budget=2000 --hide-scrollbars"
             . " --screenshot=" . escapeshellarg($file)
             . " --header=" . escapeshellarg("Cookie: $cookiePart")
             . " " . escapeshellarg($url) . " 2>&1";

        shell_exec($cmd);

        if (is_file($file) && filesize($file) > 5000) {
            $captured++;
            echo "  [✔] Captured ({$captured}/{$total}): {$lang}_{$viewName}.png (" . number_format(filesize($file)) . " bytes)\n";
        } else {
            echo "  [✗] Failed: {$lang}_{$viewName}.png\n";
        }
    }
}

if (is_resource($proc)) {
    proc_terminate($proc);
    proc_close($proc);
}

echo "\nScreenshot capture complete: $captured / $total captured successfully.\n";
if ($captured < $total) {
    exit(1);
}
