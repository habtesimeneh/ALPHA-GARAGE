<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — BILINGUAL (ENGLISH / AMHARIC) LOCALIZATION VERIFICATION SUITE
 * Tests language detection, dictionary resolution, persistence, XSS hardening,
 * public page bilingual rendering, and role dashboard localization.
 */

define('TEST_RUNNING', true);
putenv('GMS_TEST_MODE=1');
putenv('GMS_ALLOW_DESTRUCTIVE_TESTS=1');
putenv('GMS_DB_NAME=garage_management_system_test');
putenv('GMS_STORAGE_PATH=' . dirname(__DIR__) . '/storage/testing');
putenv('GMS_DB_USER=root');
putenv('GMS_DB_PASS=root');

require_once __DIR__ . '/../app/bootstrap.php';

$phpBinary = PHP_BINARY;
$phpIni = 'C:\\MAMP\\conf\\php8.2.14\\php.ini';
$extDir = 'C:\\MAMP\\bin\\php\\php8.2.14\\ext';

$testCount = 0;
$passedCount = 0;
$failedCount = 0;

function assert_test(string $desc, bool $condition, string $detail = ''): void {
    global $testCount, $passedCount, $failedCount;
    $testCount++;
    if ($condition) {
        $passedCount++;
        echo "  [PASS] $desc\n";
    } else {
        $failedCount++;
        echo "  [FAIL] $desc\n";
        if ($detail !== '') {
            echo "         Detail: $detail\n";
        }
    }
}

echo "====================================================================\n";
echo "ALPHA GARAGE — BILINGUAL LOCALIZATION (EN / AM) SUITE\n";
echo "====================================================================\n\n";

// -------------------------------------------------------------------------
// Section 1: Helper and Dictionary Unit Verification
// -------------------------------------------------------------------------
echo "--- Section 1: Helper and Dictionary Unit Verification ---\n";

assert_test("Supported languages contains 'en' and 'am'", supported_languages() === ['en', 'am']);

// Default language check
$_SESSION = [];
$_COOKIE = [];
unset($_GET['lang']);
assert_test("Default language without cookies or parameters is 'en'", init_language() === 'en');

// Parameter override
$_GET['lang'] = 'am';
assert_test("Parameter ?lang=am switches language to 'am'", init_language() === 'am');
assert_test("is_amharic() returns true when active language is 'am'", current_lang() === 'am');

// Amharic translation resolution
$amBackHome = t('Back to Home', 'Back to Home');
assert_test("t('Back to Home') in Amharic returns 'ወደ መነሻ'", $amBackHome === 'ወደ መነሻ', "Got: $amBackHome");

$amDash = t('Dashboard', 'Dashboard');
assert_test("t('Dashboard') in Amharic returns 'ዳሽቦርድ'", $amDash === 'ዳሽቦርድ', "Got: $amDash");

$amSignOut = t('Sign Out', 'Sign Out');
assert_test("t('Sign Out') in Amharic returns 'ውጣ'", $amSignOut === 'ውጣ', "Got: $amSignOut");

$amServices = t('Our Services', 'Our Services');
assert_test("t('Our Services') in Amharic returns 'አገልግሎቶቻችን'", $amServices === 'አገልግሎቶቻችን', "Got: $amServices");

$amOverview = t('Overview', 'Overview');
assert_test("t('Overview') in Amharic returns 'አጠቃላይ እይታ'", $amOverview === 'አጠቃላይ እይታ', "Got: $amOverview");

$amWorkshop = t('Workshop', 'Workshop');
assert_test("t('Workshop') in Amharic returns 'ዎርክሾፕ'", $amWorkshop === 'ዎርክሾፕ', "Got: $amWorkshop");

$fallbackRes = t('NonExistentTermXYZ', 'Default Term');
assert_test("Unmatched key gracefully falls back to default", $fallbackRes === 'Default Term', "Got: $fallbackRes");

// Switch back to English
$_GET['lang'] = 'en';
assert_test("Parameter ?lang=en switches back to 'en'", init_language() === 'en');
$enBackHome = t('Back to Home', 'Back to Home');
assert_test("t('Back to Home') in English returns 'Back to Home'", $enBackHome === 'Back to Home', "Got: $enBackHome");

// Malicious input hardening
$_GET['lang'] = '<script>alert(1)</script>';
$sanitizedLang = init_language();
assert_test("Malicious script injection in ?lang is rejected and defaults to 'en'", $sanitizedLang === 'en', "Got: $sanitizedLang");

$_GET['lang'] = '../../etc/passwd';
assert_test("Directory traversal in ?lang is rejected and defaults to 'en'", init_language() === 'en');

// Ethiopian Calendar & Time Verification
$ethDate = gregorian_to_ethiopian(2026, 11, 24);
assert_test("gregorian_to_ethiopian(2026, 11, 24) converts to 2019-03-15", $ethDate === ['year' => 2019, 'month' => 3, 'day' => 15], json_encode($ethDate));

$gregReconverted = ethiopian_to_gregorian(2019, 3, 15);
assert_test("ethiopian_to_gregorian(2019, 3, 15) roundtrips back to 2026-11-24", $gregReconverted === ['year' => 2026, 'month' => 11, 'day' => 24], json_encode($gregReconverted));

$ethTime = gregorian_to_ethiopian_time(14, 30);
assert_test("gregorian_to_ethiopian_time(14, 30) converts 2:30 PM to 08:30 ከሰዓት", $ethTime['hour'] === 8 && $ethTime['minute'] === 30 && $ethTime['period'] === 'ከሰዓት', json_encode($ethTime));

// Amharic formatting
$_GET['lang'] = 'am';
init_language();
$formattedEthDate = fmt_date('2026-11-24');
assert_test("fmt_date('2026-11-24') in Amharic returns 'ኅዳር 15, 2019 ዓ.ም'", $formattedEthDate === 'ኅዳር 15, 2019 ዓ.ም', "Got: $formattedEthDate");

$formattedEthTime = fmt_datetime('2026-11-24 14:30:00');
assert_test("fmt_datetime('2026-11-24 14:30:00') in Amharic returns 'ኅዳር 15, 2019 ዓ.ም፣ 08:30 ከሰዓት'", $formattedEthTime === 'ኅዳር 15, 2019 ዓ.ም፣ 08:30 ከሰዓት', "Got: $formattedEthTime");

// English formatting preserved
$_GET['lang'] = 'en';
init_language();
$formattedEnDate = fmt_date('2026-11-24');
assert_test("fmt_date('2026-11-24') in English returns '24 Nov 2026'", $formattedEnDate === '24 Nov 2026', "Got: $formattedEnDate");

// -------------------------------------------------------------------------
// Section 2: Language Switcher UI Component Integrity
// -------------------------------------------------------------------------
echo "\n--- Section 2: Language Switcher UI Component Integrity ---\n";

$switcherHtml = lang_switcher_html();
assert_test("Switcher HTML contains '.lang-switcher-pill'", str_contains($switcherHtml, 'lang-switcher-pill'));
assert_test("Switcher HTML contains EN button", str_contains($switcherHtml, 'data-lang="en"') && str_contains($switcherHtml, '>EN</a>'));
assert_test("Switcher HTML contains Amharic button", str_contains($switcherHtml, 'data-lang="am"') && str_contains($switcherHtml, '>አማ</a>'));
assert_test("Switcher HTML is accessible with role='group'", str_contains($switcherHtml, 'role="group"'));
assert_test("Switcher HTML has aria-label", str_contains($switcherHtml, 'aria-label="Language selection"'));

// -------------------------------------------------------------------------
// Section 3: Live Web Server HTTP Bilingual Verification
// -------------------------------------------------------------------------
echo "\n--- Section 3: Live Web Server HTTP Bilingual Verification ---\n";

$serverPort = 8089;
$apacheCheck = @get_headers('http://127.0.0.1/ALPHA-GARAGE/AutoPro_Garage-main/public/index.php');
if ($apacheCheck && (str_contains($apacheCheck[0], '200') || str_contains($apacheCheck[0], '302'))) {
    $serverBase = 'http://127.0.0.1/ALPHA-GARAGE/AutoPro_Garage-main/public/';
    $proc = null;
    $serverLog = '';
} else {
    $serverBase = "http://127.0.0.1:$serverPort/";
    $serverLog = sys_get_temp_dir() . '/test-server-i18n-' . getmypid() . '.log';

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

    $serverCmd = escapeshellarg($phpBinary) . ' -d extension_dir=' . escapeshellarg($extDir) . ' -d extension=pdo_mysql -d extension=openssl -d extension=mbstring -c ' . escapeshellarg($phpIni) . ' -S 127.0.0.1:' . $serverPort . ' -t ' . escapeshellarg(APP_ROOT . '/public');
    $proc = proc_open($serverCmd, $descriptors, $pipes, APP_ROOT, $serverEnv);

    if (!is_resource($proc)) {
        echo "  [FAIL] Failed to start test web server on port $serverPort\n";
        exit(1);
    }

    register_shutdown_function(function () use ($proc, $serverLog) {
        if (is_resource($proc)) {
            proc_terminate($proc);
            proc_close($proc);
        }
        @unlink($serverLog);
    });

    // Wait for server to bind
    usleep(650000);
}

function curl_get(string $url, array $cookies = []): array {
    $ch = curl_init();
    $cookieHeaders = [];
    foreach ($cookies as $k => $v) {
        $cookieHeaders[] = "$k=$v";
    }
    $headers = ['Connection: close'];
    if ($cookieHeaders) {
        $headers[] = 'Cookie: ' . implode('; ', $cookieHeaders);
    }
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_HTTPHEADER => $headers,
    ]);
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

// Test A: Homepage English default
$homeEn = curl_get($serverBase . 'index.php');
assert_test("index.php loads HTTP 200", $homeEn['code'] === 200);
assert_test("index.php default lang is 'en'", str_contains($homeEn['body'], '<html lang="en">'));
assert_test("index.php contains language switcher pill", str_contains($homeEn['body'], 'lang-switcher-pill'));
assert_test("index.php includes i18n.js", str_contains($homeEn['body'], 'i18n.js'));

// Test B: Homepage Amharic mode via ?lang=am
$homeAm = curl_get($serverBase . 'index.php?lang=am');
assert_test("index.php?lang=am loads HTTP 200", $homeAm['code'] === 200);
assert_test("index.php?lang=am renders '<html lang=\"am\">'", str_contains($homeAm['body'], '<html lang="am">'));
assert_test("index.php?lang=am translates navigation 'መነሻ'", str_contains($homeAm['body'], 'መነሻ'));
assert_test("index.php?lang=am translates navigation 'አገልግሎቶቻችን'", str_contains($homeAm['body'], 'አገልግሎቶቻችን'));
assert_test("index.php?lang=am translates navigation 'ስለ እኛ እና ጥያቄዎች'", str_contains($homeAm['body'], 'ስለ እኛ እና ጥያቄዎች'));
assert_test("index.php?lang=am translates 'ያግኙን'", str_contains($homeAm['body'], 'ያግኙን'));
assert_test("index.php?lang=am translates Why Choose Us 'አሽከርካሪዎች ለምን አልፋ ጋራዥን ይመርጣሉ'", str_contains($homeAm['body'], 'አሽከርካሪዎች ለምን አልፋ ጋራዥን ይመርጣሉ'));
assert_test("index.php?lang=am translates How It Works 'አገልግሎታችን እንዴት እንደሚሰራ'", str_contains($homeAm['body'], 'አገልግሎታችን እንዴት እንደሚሰራ'));
assert_test("index.php?lang=am translates Journey 'የተሻለ የአገልግሎት ጉብኝት እዚህ ይጀምራል'", str_contains($homeAm['body'], 'የተሻለ የአገልግሎት ጉብኝት እዚህ ይጀምራል'));
assert_test("index.php?lang=am translates Facility 'ዘመናዊ ጋራዣችንን ይጎብኙ'", str_contains($homeAm['body'], 'ዘመናዊ ጋራዣችንን ይጎብኙ'));
assert_test("index.php?lang=am translates Footer 'ፈጣን አገናኞች' and 'የደንበኛ መዳረሻ'", str_contains($homeAm['body'], 'ፈጣን አገናኞች') && str_contains($homeAm['body'], 'የደንበኛ መዳረሻ'));

// Test C: About Us Amharic mode
$aboutAm = curl_get($serverBase . 'about.php?lang=am');
assert_test("about.php?lang=am loads HTTP 200", $aboutAm['code'] === 200);
assert_test("about.php?lang=am renders '<html lang=\"am\">'", str_contains($aboutAm['body'], '<html lang="am">'));
assert_test("about.php?lang=am translates 'ስለ እኛ'", str_contains($aboutAm['body'], 'ስለ እኛ'));
assert_test("about.php?lang=am translates FAQs question 'ስለ ምን ዓይነት ተሽከርካሪዎች መጠየቅ እችላለሁ?'", str_contains($aboutAm['body'], 'ስለ ምን ዓይነት ተሽከርካሪዎች መጠየቅ እችላለሁ?'));
assert_test("about.php?lang=am translates FAQs answer 'ስለተለያዩ የመኪና ዓይነቶች'", str_contains($aboutAm['body'], 'ስለተለያዩ የመኪና ዓይነቶች'));
assert_test("about.php?lang=am translates specialties 'ከአገልግሎቱ ጀርባ ያሉ ባለሙያዎች'", str_contains($aboutAm['body'], 'ከአገልግሎቱ ጀርባ ያሉ ባለሙያዎች'));

// Test D: Services Amharic mode
$servicesAm = curl_get($serverBase . 'services.php?lang=am');
assert_test("services.php?lang=am loads HTTP 200", $servicesAm['code'] === 200);
assert_test("services.php?lang=am renders '<html lang=\"am\">'", str_contains($servicesAm['body'], '<html lang="am">'));
assert_test("services.php?lang=am translates 'አገልግሎትዎን ይረዱ።'", str_contains($servicesAm['body'], 'አገልግሎትዎን ይረዱ።'));
assert_test("services.php?lang=am translates 'በልበ ሙሉነት ይምረጡ።'", str_contains($servicesAm['body'], 'በልበ ሙሉነት ይምረጡ።'));
assert_test("services.php?lang=am translates 'የአገልግሎት ካታሎግ'", str_contains($servicesAm['body'], 'የአገልግሎት ካታሎግ'));
assert_test("services.php?lang=am translates filter 'ሁሉም አገልግሎቶች'", str_contains($servicesAm['body'], 'ሁሉም አገልግሎቶች'));

// Test E: Contact Amharic mode
$contactAm = curl_get($serverBase . 'contact.php?lang=am');
assert_test("contact.php?lang=am loads HTTP 200", $contactAm['code'] === 200);
assert_test("contact.php?lang=am renders '<html lang=\"am\">'", str_contains($contactAm['body'], '<html lang="am">'));
assert_test("contact.php?lang=am translates 'የደንበኞች ድጋፍ እና አስተናጋጅ'", str_contains($contactAm['body'], 'የደንበኞች ድጋፍ እና አስተናጋጅ'));
assert_test("contact.php?lang=am translates 'ይፋዊ የቴሌግራም ቻናል'", str_contains($contactAm['body'], 'ይፋዊ የቴሌግራም ቻናል'));
assert_test("contact.php?lang=am translates 'የሞባይል እና የአስቸኳይ ጊዜ ስልክ'", str_contains($contactAm['body'], 'የሞባይል እና የአስቸኳይ ጊዜ ስልክ'));
assert_test("contact.php?lang=am translates 'ይፋዊ የኢሜይል ድጋፍ'", str_contains($contactAm['body'], 'ይፋዊ የኢሜይል ድጋፍ'));
assert_test("contact.php?lang=am translates 'ጥያቄ ይላኩልን'", str_contains($contactAm['body'], 'ጥያቄ ይላኩልን'));

// Test F: Login page Amharic mode
$loginAm = curl_get($serverBase . 'login.php?lang=am');
assert_test("login.php?lang=am loads HTTP 200", $loginAm['code'] === 200);
assert_test("login.php?lang=am renders '<html lang=\"am\">'", str_contains($loginAm['body'], '<html lang="am">'));
assert_test("login.php?lang=am translates 'ወደ መነሻ ገጽ ተመለስ'", str_contains($loginAm['body'], 'ወደ መነሻ ገጽ ተመለስ'));
assert_test("login.php?lang=am translates 'ደህንነቱ የተጠበቀ ፖርታል'", str_contains($loginAm['body'], 'ደህንነቱ የተጠበቀ ፖርታል'));

// Test G: Cookie Persistence (User sets language cookie GMS_LANG=am)
$cookieAm = curl_get($serverBase . 'index.php', ['GMS_LANG' => 'am']);
assert_test("Cookie GMS_LANG=am without query parameter preserves Amharic mode", str_contains($cookieAm['body'], '<html lang="am">'));

// -------------------------------------------------------------------------
// Section 4: Role Dashboards Authenticated Bilingual Verification
// -------------------------------------------------------------------------
echo "\n--- Section 4: Role Dashboards Authenticated Bilingual Verification ---\n";

function http_test_request(string $method, string $path, array $data = [], string $jar = ''): array {
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

function curl_auth_login(string $username, string $password): string {
    $jar = tempnam(sys_get_temp_dir(), 'gms_bilingual_jar_');
    $get = http_test_request('GET', 'login.php', [], $jar);
    $csrf = '';
    if (preg_match('/name=["\']_csrf["\']\s+value=["\']([a-f0-9]{64})["\']/i', $get['body'], $m)) {
        $csrf = $m[1];
    } elseif (preg_match('/value=["\']([a-f0-9]{64})["\']\s+name=["\']_csrf["\']/i', $get['body'], $m)) {
        $csrf = $m[1];
    }
    http_test_request('POST', 'login.php', [
        '_csrf' => $csrf,
        'username' => $username,
        'password' => $password,
    ], $jar);
    return $jar;
}

// 1. Staff Admin Dashboard in Amharic
$adminJar = curl_auth_login('admin', 'Admin#TestPass2026');
$adminAm = http_test_request('GET', 'dashboard.php?lang=am', [], $adminJar);
assert_test("Administrator dashboard?lang=am loads HTTP 200", $adminAm['code'] === 200, "Code: " . $adminAm['code']);
assert_test("Admin dashboard renders '<html lang=\"am\">'", str_contains($adminAm['body'], '<html lang="am">'));
assert_test("Admin dashboard sidebar groups translated to Amharic ('አጠቃላይ እይታ')", str_contains($adminAm['body'], 'አጠቃላይ እይታ'));
assert_test("Admin dashboard sidebar groups translated to Amharic ('ዎርክሾፕ')", str_contains($adminAm['body'], 'ዎርክሾፕ'));
assert_test("Admin dashboard sidebar groups translated to Amharic ('ፋይናንስ')", str_contains($adminAm['body'], 'ፋይናንስ'));
assert_test("Admin dashboard sidebar groups translated to Amharic ('አስተዳደር')", str_contains($adminAm['body'], 'አስተዳደር'));
assert_test("Admin dashboard topbar Back to Home translated ('ወደ መነሻ')", str_contains($adminAm['body'], 'ወደ መነሻ'));
assert_test("Admin dashboard hero translated ('እንኳን ደህና መጡ')", str_contains($adminAm['body'], 'እንኳን ደህና መጡ'));

// Admin subpage tests in Amharic
$apptSubAm = http_test_request('GET', 'appointments/index.php?lang=am', [], $adminJar);
assert_test("Appointments subpage?lang=am loads HTTP 200", $apptSubAm['code'] === 200);
assert_test("Appointments subpage translates 'የአገልግሎት ቀጠሮዎች' or 'ቀጠሮዎች'", str_contains($apptSubAm['body'], 'ቀጠሮዎች'));

$custSubAm = http_test_request('GET', 'customers/index.php?lang=am', [], $adminJar);
assert_test("Customers subpage?lang=am loads HTTP 200", $custSubAm['code'] === 200);
assert_test("Customers subpage translates 'ደንበኞች'", str_contains($custSubAm['body'], 'ደንበኞች'));
@unlink($adminJar);

// 2. Receptionist Dashboard in Amharic
$recJar = curl_auth_login('reception', 'Demo#TestPass2026');
$recAm = http_test_request('GET', 'dashboard.php?lang=am', [], $recJar);
assert_test("Receptionist dashboard?lang=am loads HTTP 200", $recAm['code'] === 200, "Code: " . $recAm['code']);
assert_test("Receptionist dashboard renders '<html lang=\"am\">'", str_contains($recAm['body'], '<html lang="am">'));
assert_test("Receptionist dashboard translates '+ አዲስ ቀጠሮ'", str_contains($recAm['body'], '+ አዲስ ቀጠሮ'));
assert_test("Receptionist dashboard translates '+ አዲስ ደንበኛ'", str_contains($recAm['body'], '+ አዲስ ደንበኛ'));
assert_test("Receptionist dashboard translates '+ አዲስ ተሽከርካሪ'", str_contains($recAm['body'], '+ አዲስ ተሽከርካሪ'));
@unlink($recJar);

// 3. Mechanic Dashboard in Amharic
$mechJar = curl_auth_login('mechanic1', 'Demo#TestPass2026');
$mechAm = http_test_request('GET', 'dashboard.php?lang=am', [], $mechJar);
assert_test("Mechanic dashboard?lang=am loads HTTP 200", $mechAm['code'] === 200, "Code: " . $mechAm['code']);
assert_test("Mechanic dashboard renders '<html lang=\"am\">'", str_contains($mechAm['body'], '<html lang="am">'));
assert_test("Mechanic dashboard translates 'የእኔ የሥራ ካርዶች' or 'የሥራ ካርዶች'", str_contains($mechAm['body'], 'የሥራ ካርዶች'));
@unlink($mechJar);

// 4. Inventory Manager Dashboard in Amharic
$stockJar = curl_auth_login('stock', 'Demo#TestPass2026');
$stockAm = http_test_request('GET', 'dashboard.php?lang=am', [], $stockJar);
assert_test("Inventory Manager dashboard?lang=am loads HTTP 200", $stockAm['code'] === 200, "Code: " . $stockAm['code']);
assert_test("Inventory Manager dashboard renders '<html lang=\"am\">'", str_contains($stockAm['body'], '<html lang="am">'));
assert_test("Inventory Manager dashboard translates '+ አዲስ መለዋወጫ ዕቃ ጨምር' or 'ዕቃዎች ክምችት'", str_contains($stockAm['body'], 'ዕቃዎች') || str_contains($stockAm['body'], 'መለዋወጫ'));
@unlink($stockJar);

// 5. Accountant Dashboard in Amharic
$acctJar = curl_auth_login('accounts', 'Demo#TestPass2026');
$acctAm = http_test_request('GET', 'dashboard.php?lang=am', [], $acctJar);
assert_test("Accountant dashboard?lang=am loads HTTP 200", $acctAm['code'] === 200, "Code: " . $acctAm['code']);
assert_test("Accountant dashboard renders '<html lang=\"am\">'", str_contains($acctAm['body'], '<html lang="am">'));
assert_test("Accountant dashboard translates '+ ክፍያ መዝግብ'", str_contains($acctAm['body'], '+ ክፍያ መዝግብ'));
assert_test("Accountant dashboard translates '+ ወጪ መዝግብ'", str_contains($acctAm['body'], '+ ወጪ መዝግብ'));
@unlink($acctJar);

// 6. Customer VIP Portal in Amharic & English + Subpages
$custJar = curl_auth_login('test_cust_alice', 'Customer#Pass1');

// Customer Portal English mode
$custEn = http_test_request('GET', 'customer.php?lang=en', [], $custJar);
assert_test("Customer VIP portal?lang=en loads HTTP 200", $custEn['code'] === 200, "Code: " . $custEn['code']);
assert_test("Customer portal renders '<html lang=\"en\">'", str_contains($custEn['body'], '<html lang="en">'));
assert_test("Customer portal displays English title 'Customer Portal'", str_contains($custEn['body'], 'Customer Portal'));
assert_test("Customer portal displays KPI 'Registered Vehicles'", str_contains($custEn['body'], 'Registered Vehicles'));
assert_test("Customer portal displays KPI 'Upcoming Appointments'", str_contains($custEn['body'], 'Upcoming Appointments'));
assert_test("Customer portal displays KPI 'Active Repairs'", str_contains($custEn['body'], 'Active Repairs'));
assert_test("Customer portal displays KPI 'Outstanding Balance'", str_contains($custEn['body'], 'Outstanding Balance'));
assert_test("Customer portal displays table 'My Vehicles'", str_contains($custEn['body'], 'My Vehicles'));
assert_test("Customer portal displays table 'Service Appointments'", str_contains($custEn['body'], 'Service Appointments'));
assert_test("Customer portal displays table 'Workshop Repair Status'", str_contains($custEn['body'], 'Workshop Repair Status'));
assert_test("Customer portal displays table 'Billing & Invoices'", str_contains($custEn['body'], 'Billing & Invoices'));

// Customer Portal Amharic mode
$custAm = http_test_request('GET', 'customer.php?lang=am', [], $custJar);
assert_test("Customer VIP portal?lang=am loads HTTP 200", $custAm['code'] === 200, "Code: " . $custAm['code']);
assert_test("Customer portal renders '<html lang=\"am\">'", str_contains($custAm['body'], '<html lang="am">'));
assert_test("Customer portal translates 'ቪአይፒ የደንበኞች አገልግሎት'", str_contains($custAm['body'], 'ቪአይፒ የደንበኞች አገልግሎት'));
assert_test("Customer portal Back to Home button translated ('ወደ መነሻ')", str_contains($custAm['body'], 'ወደ መነሻ'));
assert_test("Customer portal navigation contains 'አገልግሎት ያስይዙ'", str_contains($custAm['body'], 'አገልግሎት ያስይዙ'));
assert_test("Customer portal translates KPI 'የተመዘገቡ ተሽከርካሪዎች'", str_contains($custAm['body'], 'የተመዘገቡ ተሽከርካሪዎች'));
assert_test("Customer portal translates KPI 'መጪ ቀጠሮዎች'", str_contains($custAm['body'], 'መጪ ቀጠሮዎች'));
assert_test("Customer portal translates KPI 'በሂደት ላይ ያሉ ጥገናዎች'", str_contains($custAm['body'], 'በሂደት ላይ ያሉ ጥገናዎች'));
assert_test("Customer portal translates KPI 'ያልተከፈለ ቀሪ ሂሳብ'", str_contains($custAm['body'], 'ያልተከፈለ ቀሪ ሂሳብ'));
assert_test("Customer portal translates table 'የእኔ ተሽከርካሪዎች'", str_contains($custAm['body'], 'የእኔ ተሽከርካሪዎች'));
assert_test("Customer portal translates table 'የአገልግሎት ቀጠሮዎች'", str_contains($custAm['body'], 'የአገልግሎት ቀጠሮዎች'));
assert_test("Customer portal translates table 'የዎርክሾፕ ጥገና ሁኔታ'", str_contains($custAm['body'], 'የዎርክሾፕ ጥገና ሁኔታ'));
assert_test("Customer portal translates table 'የክፍያ መጠየቂያዎች እና ደረሰኞች'", str_contains($custAm['body'], 'የክፍያ መጠየቂያዎች እና ደረሰኞች'));
assert_test("Customer portal translates action 'መዝገቦችን ፈትሽ እና አገናኝ'", str_contains($custAm['body'], 'መዝገቦችን ፈትሽ እና አገናኝ'));

// Customer Booking Subpage in Amharic
$bookAm = http_test_request('GET', 'book.php?lang=am', [], $custJar);
assert_test("Booking page?lang=am loads HTTP 200", $bookAm['code'] === 200, "Code: " . $bookAm['code']);
assert_test("Booking page renders '<html lang=\"am\">'", str_contains($bookAm['body'], '<html lang="am">'));
assert_test("Booking page translates 'የአገልግሎት ማስያዣ ፖርታል'", str_contains($bookAm['body'], 'የአገልግሎት ማስያዣ ፖርታል'));
assert_test("Booking page translates nav 'የእኔ ዳሽቦርድ'", str_contains($bookAm['body'], 'የእኔ ዳሽቦርድ'));

// Customer History Verification Page in Amharic
$verifyAm = http_test_request('GET', 'verify-customer.php?lang=am', [], $custJar);
assert_test("Verify customer page?lang=am loads HTTP 200", $verifyAm['code'] === 200, "Code: " . $verifyAm['code']);
assert_test("Verify customer page renders '<html lang=\"am\">'", str_contains($verifyAm['body'], '<html lang="am">'));
assert_test("Verify customer page translates 'የአገልግሎት ታሪክ ማገናኘትን ያረጋግጡ'", str_contains($verifyAm['body'], 'የአገልግሎት ታሪክ ማገናኘትን ያረጋግጡ'));
assert_test("Verify customer page translates 'ያለፉትን በአካል የተደረጉ የአገልግሎት መዝገቦች አገናኝ'", str_contains($verifyAm['body'], 'ያለፉትን በአካል የተደረጉ የአገልግሎት መዝገቦች አገናኝ'));

// Customer Profile Page in Amharic
$profileAm = http_test_request('GET', 'profile.php?lang=am', [], $custJar);
assert_test("Profile page?lang=am loads HTTP 200", $profileAm['code'] === 200, "Code: " . $profileAm['code']);
assert_test("Profile page renders '<html lang=\"am\">'", str_contains($profileAm['body'], '<html lang="am">'));
assert_test("Profile page translates 'የእኔ መገለጫ'", str_contains($profileAm['body'], 'የእኔ መገለጫ'));
assert_test("Profile page translates 'የይለፍ ቃል ቀይር'", str_contains($profileAm['body'], 'የይለፍ ቃል ቀይር'));

@unlink($custJar);

// -------------------------------------------------------------------------
// Section 5: Admin & Staff Subpages Bilingual Verification
// -------------------------------------------------------------------------
echo "\n--- Section 5: Admin & Staff Subpages Bilingual Verification ---\n";

$adminJar = curl_auth_login('admin', 'Admin#TestPass2026');

// Services index
$srvAm = http_test_request('GET', 'services/index.php?lang=am', [], $adminJar);
assert_test("services/index.php?lang=am loads HTTP 200", $srvAm['code'] === 200, "Code: " . $srvAm['code']);
assert_test("services/index.php translates 'የአገልግሎት ስም'", str_contains($srvAm['body'], 'የአገልግሎት ስም'));
assert_test("services/index.php translates '+ አዲስ አገልግሎት'", str_contains($srvAm['body'], '+ አዲስ አገልግሎት'));

// Users index
$usrAm = http_test_request('GET', 'users/index.php?lang=am', [], $adminJar);
assert_test("users/index.php?lang=am loads HTTP 200", $usrAm['code'] === 200, "Code: " . $usrAm['code']);
assert_test("users/index.php translates 'የተጠቃሚ ስም'", str_contains($usrAm['body'], 'የተጠቃሚ ስም'));
assert_test("users/index.php translates '+ ተጠቃሚ መዝግብ'", str_contains($usrAm['body'], '+ ተጠቃሚ መዝግብ'));

// Vehicles index
$vehAm = http_test_request('GET', 'vehicles/index.php?lang=am', [], $adminJar);
assert_test("vehicles/index.php?lang=am loads HTTP 200", $vehAm['code'] === 200, "Code: " . $vehAm['code']);
assert_test("vehicles/index.php translates 'የሰሌዳ ቁጥር'", str_contains($vehAm['body'], 'የሰሌዳ ቁጥር'));
assert_test("vehicles/index.php translates '+ ተሽከርካሪ መዝግብ'", str_contains($vehAm['body'], '+ ተሽከርካሪ መዝግብ'));

// Customers index
$cusAm = http_test_request('GET', 'customers/index.php?lang=am', [], $adminJar);
assert_test("customers/index.php?lang=am loads HTTP 200", $cusAm['code'] === 200, "Code: " . $cusAm['code']);
assert_test("customers/index.php translates 'ደንበኞች'", str_contains($cusAm['body'], 'ደንበኞች'));
assert_test("customers/index.php translates '+ ደንበኛ መዝግብ'", str_contains($cusAm['body'], '+ ደንበኛ መዝግብ'));

// Inventory index
$invAm = http_test_request('GET', 'inventory/index.php?lang=am', [], $adminJar);
assert_test("inventory/index.php?lang=am loads HTTP 200", $invAm['code'] === 200, "Code: " . $invAm['code']);
assert_test("inventory/index.php translates 'መለዋወጫ ዕቃዎች እና ክምችት'", str_contains($invAm['body'], 'መለዋወጫ ዕቃዎች እና ክምችት'));
assert_test("inventory/index.php translates '+ ዕቃ መዝግብ'", str_contains($invAm['body'], '+ ዕቃ መዝግብ'));

// Inventory form (Add / Edit Part)
$invFormAm = http_test_request('GET', 'inventory/form.php?lang=am', [], $adminJar);
assert_test("inventory/form.php?lang=am loads HTTP 200", $invFormAm['code'] === 200, "Code: " . $invFormAm['code']);
assert_test("inventory/form.php translates 'መለዋወጫ ዕቃ መዝግብ'", str_contains($invFormAm['body'], 'መለዋወጫ ዕቃ መዝግብ'));
assert_test("inventory/form.php translates 'የመጀመሪያ ክምችት'", str_contains($invFormAm['body'], 'የመጀመሪያ ክምችት'));
assert_test("inventory/form.php translates 'ዕቃ አስቀምጥ'", str_contains($invFormAm['body'], 'ዕቃ አስቀምጥ'));

// Inventory stock in / adjust
$invStockAm = http_test_request('GET', 'inventory/stock.php?lang=am', [], $adminJar);
assert_test("inventory/stock.php?lang=am loads HTTP 200", $invStockAm['code'] === 200, "Code: " . $invStockAm['code']);
assert_test("inventory/stock.php translates 'የዕቃ ግዢ መዝግብ (ገቢ)'", str_contains($invStockAm['body'], 'የዕቃ ግዢ መዝግብ (ገቢ)'));
assert_test("inventory/stock.php translates 'የክምችት ማስተካከያ (ወጪ / እርማት)'", str_contains($invStockAm['body'], 'የክምችት ማስተካከያ (ወጪ / እርማት)'));

// Inventory stock movement history
$invHistAm = http_test_request('GET', 'inventory/history.php?lang=am', [], $adminJar);
assert_test("inventory/history.php?lang=am loads HTTP 200", $invHistAm['code'] === 200, "Code: " . $invHistAm['code']);
assert_test("inventory/history.php translates 'የክምችት ዝውውር ታሪክ'", str_contains($invHistAm['body'], 'የክምችት ዝውውር ታሪክ'));
assert_test("inventory/history.php translates filter button 'አጣራ'", str_contains($invHistAm['body'], 'አጣራ'));

// Inventory issue parts to job cards
$invIssueAm = http_test_request('GET', 'inventory/issue.php?lang=am', [], $adminJar);
assert_test("inventory/issue.php?lang=am loads HTTP 200", $invIssueAm['code'] === 200, "Code: " . $invIssueAm['code']);
assert_test("inventory/issue.php translates 'መለዋወጫዎችን በቀጥታ አውጣ'", str_contains($invIssueAm['body'], 'መለዋወጫዎችን በቀጥታ አውጣ'));
assert_test("inventory/issue.php translates 'በመጠባበቅ ላይ ያሉ የመካኒክ የዕቃ ጥያቄዎች'", str_contains($invIssueAm['body'], 'በመጠባበቅ ላይ ያሉ የመካኒክ የዕቃ ጥያቄዎች'));

// Mechanics index
$mecAm = http_test_request('GET', 'mechanics/index.php?lang=am', [], $adminJar);
assert_test("mechanics/index.php?lang=am loads HTTP 200", $mecAm['code'] === 200, "Code: " . $mecAm['code']);
assert_test("mechanics/index.php translates 'ልዩ ሙያ'", str_contains($mecAm['body'], 'ልዩ ሙያ'));

// Suppliers index
$supAm = http_test_request('GET', 'suppliers/index.php?lang=am', [], $adminJar);
assert_test("suppliers/index.php?lang=am loads HTTP 200", $supAm['code'] === 200, "Code: " . $supAm['code']);
assert_test("suppliers/index.php translates 'የመለዋወጫ አቅራቢዎች'", str_contains($supAm['body'], 'የመለዋወጫ አቅራቢዎች'));
assert_test("suppliers/index.php translates '+ አዲስ አቅራቢ'", str_contains($supAm['body'], '+ አዲስ አቅራቢ'));

// Expenses index
$expAm = http_test_request('GET', 'expenses/index.php?lang=am', [], $adminJar);
assert_test("expenses/index.php?lang=am loads HTTP 200", $expAm['code'] === 200, "Code: " . $expAm['code']);
assert_test("expenses/index.php translates 'የስራ ማስኬጃ ወጪዎች'", str_contains($expAm['body'], 'የስራ ማስኬጃ ወጪዎች'));

// Reports index
$repAm = http_test_request('GET', 'reports/index.php?lang=am', [], $adminJar);
assert_test("reports/index.php?lang=am loads HTTP 200", $repAm['code'] === 200, "Code: " . $repAm['code']);
assert_test("reports/index.php translates 'ሪፖርቶች እና ትንታኔዎች'", str_contains($repAm['body'], 'ሪፖርቶች እና ትንታኔዎች'));

// Audit logs index
$audAm = http_test_request('GET', 'audit-logs/index.php?lang=am', [], $adminJar);
assert_test("audit-logs/index.php?lang=am loads HTTP 200", $audAm['code'] === 200, "Code: " . $audAm['code']);
assert_test("audit-logs/index.php translates 'የቁጥጥር መዝገቦች'", str_contains($audAm['body'], 'የቁጥጥር መዝገቦች'));

// SMS audit logs index
$smsAm = http_test_request('GET', 'notifications/sms-logs.php?lang=am', [], $adminJar);
assert_test("notifications/sms-logs.php?lang=am loads HTTP 200", $smsAm['code'] === 200, "Code: " . $smsAm['code']);
assert_test("notifications/sms-logs.php translates 'አጠቃላይ የተላኩ የሞባይል ኤስኤምኤሶች'", str_contains($smsAm['body'], 'አጠቃላይ የተላኩ የሞባይል ኤስኤምኤሶች'));

@unlink($adminJar);

// Stop the test server
if (is_resource($proc)) {
    proc_terminate($proc);
    proc_close($proc);
}

// -------------------------------------------------------------------------
// Summary
// -------------------------------------------------------------------------
echo "\n====================================================================\n";
echo "BILINGUAL LOCALIZATION SUITE SUMMARY\n";
echo "Total Assertions: $testCount\n";
echo "Passed:           $passedCount\n";
echo "Failed:           $failedCount\n";
echo "====================================================================\n";

if ($failedCount > 0) {
    exit(1);
}
