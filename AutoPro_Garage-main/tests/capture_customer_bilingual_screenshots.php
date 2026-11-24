<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — CUSTOMER PORTAL BILINGUAL SCREENSHOT CAPTURE
 * Captures pixel-perfect screenshots of Customer Portal in English and Amharic,
 * including responsive mobile viewport (375x667).
 */

require_once __DIR__ . '/../app/bootstrap.php';

$phpBinary = 'C:\\MAMP\\bin\\php\\php8.3.1\\php.exe';
$phpIni = 'C:\\MAMP\\conf\\php8.3.1\\php.ini';
$chromePath = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';

$screenshotDir = APP_ROOT . '/storage/testing/screenshots/customer-portal';
if (!is_dir($screenshotDir)) {
    @mkdir($screenshotDir, 0777, true);
}

$serverPort = 8092;
$serverBase = "http://127.0.0.1:$serverPort/";
$serverLog = APP_ROOT . '/storage/testing/logs/test-server-customer-shots.log';

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

usleep(800000); // 800ms for bind

function http_test_req(string $method, string $path, array $data = [], string $jar = ''): array {
    global $serverBase;
    $url = $serverBase . ltrim($path, '/');
    $ch = curl_init();
    $opts = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_FORBID_REUSE => true,
        CURLOPT_FRESH_CONNECT => true,
        CURLOPT_HTTPHEADER => ['Connection: close'],
    ];
    if ($jar !== '') {
        $opts[CURLOPT_COOKIEJAR] = $jar;
        $opts[CURLOPT_COOKIEFILE] = $jar;
    }
    if (strtoupper($method) === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($data);
    }
    curl_setopt_array($ch, $opts);
    $raw = (string) curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    $size = $info['header_size'] ?? 0;
    return [
        'code' => (int) ($info['http_code'] ?? 0),
        'head' => substr($raw, 0, $size),
        'body' => substr($raw, $size),
    ];
}

function auth_cookie(string $username, string $password): string {
    $jar = tempnam(sys_get_temp_dir(), 'gms_cust_jar_');
    $get = http_test_req('GET', 'login.php', [], $jar);
    $csrf = '';
    if (preg_match('/name=["\']_csrf["\']\s+value=["\']([a-f0-9]{64})["\']/i', $get['body'], $m)) {
        $csrf = $m[1];
    } elseif (preg_match('/value=["\']([a-f0-9]{64})["\']\s+name=["\']_csrf["\']/i', $get['body'], $m)) {
        $csrf = $m[1];
    }

    echo "GET login: HTTP {$get['code']}, CSRF: " . substr($csrf, 0, 8) . "...\n";

    $post = http_test_req('POST', 'login.php', [
        '_csrf' => $csrf,
        'username' => $username,
        'password' => $password,
    ], $jar);

    echo "POST login: HTTP {$post['code']}\n";
    if ($post['code'] !== 302 && $post['code'] !== 200) {
        echo "POST head:\n" . substr($post['head'], 0, 300) . "\n";
        echo "POST body:\n" . substr($post['body'], 0, 300) . "\n";
    }

    $cookieHeader = '';
    if (is_file($jar)) {
        $lines = file($jar, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $cookies = [];
        foreach ($lines as $line) {
            if (str_starts_with($line, '#HttpOnly_')) {
                $line = substr($line, 10);
            } elseif (empty($line) || $line[0] === '#') {
                continue;
            }
            $parts = preg_split('/\t+/', $line);
            if (count($parts) >= 7) {
                $cookies[] = "{$parts[5]}={$parts[6]}";
            }
        }
        $cookieHeader = implode('; ', $cookies);
        @unlink($jar);
    }
    return $cookieHeader;
}

echo "Authenticating as Customer VIP (test_cust_alice)...\n";
$customerCookie = auth_cookie('test_cust_alice', 'Customer#Pass1');

if (empty($customerCookie)) {
    echo "Error: Failed to obtain customer session cookie.\n";
    if (is_resource($proc)) {
        proc_terminate($proc);
        proc_close($proc);
    }
    exit(1);
}

$targets = [
    [
        'name' => 'en_customer_portal_desktop.png',
        'url' => 'customer.php?lang=en&test_user=test_cust_alice',
        'width' => 1280,
        'height' => 900,
        'lang' => 'en',
    ],
    [
        'name' => 'am_customer_portal_desktop.png',
        'url' => 'customer.php?lang=am&test_user=test_cust_alice',
        'width' => 1280,
        'height' => 900,
        'lang' => 'am',
    ],
    [
        'name' => 'am_customer_portal_mobile.png',
        'url' => 'customer.php?lang=am&test_user=test_cust_alice',
        'width' => 375,
        'height' => 667,
        'lang' => 'am',
    ],
    [
        'name' => 'am_book_service_desktop.png',
        'url' => 'book.php?lang=am&test_user=test_cust_alice',
        'width' => 1280,
        'height' => 900,
        'lang' => 'am',
    ],
    [
        'name' => 'am_verify_customer_desktop.png',
        'url' => 'verify-customer.php?lang=am&test_user=test_cust_alice',
        'width' => 1280,
        'height' => 900,
        'lang' => 'am',
    ],
    [
        'name' => 'customer_booking_modal_inplace.png',
        'url' => 'customer.php?lang=en&test_user=test_cust_alice&open_booking=1',
        'width' => 1280,
        'height' => 900,
        'lang' => 'en',
    ],
];

echo "Capturing " . count($targets) . " Customer Portal screenshots...\n";
$captured = 0;

foreach ($targets as $tgt) {
    $outFile = $screenshotDir . '/' . $tgt['name'];
    $fullUrl = $serverBase . $tgt['url'];
    $cookieHeader = "GMS_LANG={$tgt['lang']}; {$customerCookie}";

    $cmd = escapeshellarg($chromePath) . " --headless=new --disable-gpu"
         . " --window-size={$tgt['width']},{$tgt['height']}"
         . " --no-first-run --no-default-browser-check --virtual-time-budget=2500 --hide-scrollbars"
         . " --screenshot=" . escapeshellarg($outFile)
         . " --header=" . escapeshellarg("Cookie: $cookieHeader")
         . " " . escapeshellarg($fullUrl) . " 2>&1";

    shell_exec($cmd);

    if (is_file($outFile) && filesize($outFile) > 5000) {
        $captured++;
        echo "  [✔] {$tgt['name']} (" . number_format(filesize($outFile)) . " bytes, {$tgt['width']}x{$tgt['height']})\n";
    } else {
        echo "  [✗] Failed to capture {$tgt['name']}\n";
    }
}

if (is_resource($proc)) {
    proc_terminate($proc);
    proc_close($proc);
}

echo "\nSummary: $captured / " . count($targets) . " screenshots captured successfully.\n";
if ($captured < count($targets)) {
    exit(1);
}
