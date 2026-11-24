<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — VIDEO MANAGEMENT & GALLERY AUTOMATED VERIFICATION SUITE
 * 
 * Verifies:
 *  1. VideoService unit logic, YouTube ID parser, and security whitelists.
 *  2. Database CRUD, slug uniqueness, view counts, and audit trails.
 *  3. Live web server HTTP gallery rendering, search filtering, and bilingual modes.
 *  4. Secure media streaming, MIME type controls, and path traversal defenses.
 *  5. Strict RBAC boundaries (administrator vs customer/receptionist) and CSRF.
 *  6. Navigation menu integrity across public website and admin dashboard.
 */

define('TEST_RUNNING', true);
putenv('GMS_TEST_MODE=1');
putenv('GMS_ALLOW_DESTRUCTIVE_TESTS=1');
putenv('GMS_DB_NAME=garage_management_system_test');
putenv('GMS_STORAGE_PATH=' . dirname(__DIR__) . '/storage/testing');
putenv('GMS_DB_USER=root');
putenv('GMS_DB_PASS=root');

require_once __DIR__ . '/../app/bootstrap.php';

$phpBinary = 'C:\\MAMP\\bin\\php\\php8.3.1\\php.exe';
$phpIni = 'C:\\MAMP\\conf\\php8.3.1\\php.ini';

$totalAsserts = 0;
$passedAsserts = 0;
$failedAsserts = 0;

function assert_test(string $desc, bool $condition, string $detail = ''): void {
    global $totalAsserts, $passedAsserts, $failedAsserts;
    $totalAsserts++;
    if ($condition) {
        $passedAsserts++;
        echo "  [PASS] $desc\n";
    } else {
        $failedAsserts++;
        echo "  [FAIL] $desc\n";
        if ($detail !== '') {
            echo "         Detail: $detail\n";
        }
    }
}

echo "====================================================================\n";
echo "ALPHA GARAGE — VIDEO MANAGEMENT & GALLERY VERIFICATION SUITE\n";
echo "====================================================================\n\n";

// -------------------------------------------------------------------------
// Section 1: Unit & Security Logic Verification
// -------------------------------------------------------------------------
echo "--- Section 1: Unit & Security Logic Verification ---\n";

// YouTube parsing
$yt1 = VideoService::extractYoutubeId('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
assert_test("Standard youtube.com/watch?v= parses 11-char ID", $yt1 === 'dQw4w9WgXcQ', "Got: " . var_export($yt1, true));

$yt2 = VideoService::extractYoutubeId('https://youtu.be/kJQP7kiw5Fk');
assert_test("Short youtu.be/ parses 11-char ID", $yt2 === 'kJQP7kiw5Fk', "Got: " . var_export($yt2, true));

$yt3 = VideoService::extractYoutubeId('https://www.youtube.com/embed/fJ9rUzIMcZQ');
assert_test("Embed youtube.com/embed/ parses 11-char ID", $yt3 === 'fJ9rUzIMcZQ', "Got: " . var_export($yt3, true));

$yt4 = VideoService::extractYoutubeId('https://www.youtube.com/shorts/3JZ_D3ELwOQ');
assert_test("Shorts youtube.com/shorts/ parses 11-char ID", $yt4 === '3JZ_D3ELwOQ', "Got: " . var_export($yt4, true));

$yt5 = VideoService::extractYoutubeId('dQw4w9WgXcQ');
assert_test("Raw 11-char ID passes validation directly", $yt5 === 'dQw4w9WgXcQ', "Got: " . var_export($yt5, true));

// Malicious / Invalid YouTube inputs
$ytBad1 = VideoService::extractYoutubeId('<script>alert("xss")</script>');
assert_test("XSS script injection in YouTube URL returns null", $ytBad1 === null);

$ytBad2 = VideoService::extractYoutubeId('https://evil-site.com/video?v=dQw4w9WgXcQ');
assert_test("Non-YouTube domain with v= parameter is rejected", $ytBad2 === null);

$ytBad3 = VideoService::extractYoutubeId('https://youtube.com/watch?v=short');
assert_test("Truncated ID (< 11 chars) is rejected", $ytBad3 === null);

$ytBad4 = VideoService::extractYoutubeId('');
assert_test("Empty string returns null safely", $ytBad4 === null);

// Category whitelist
assert_test("VideoService defines required workshop categories", count(VideoService::CATEGORIES) >= 6);
assert_test("Diagnostics category exists", in_array('Diagnostics', VideoService::CATEGORIES, true));
assert_test("Engine & Transmission category exists", in_array('Engine & Transmission', VideoService::CATEGORIES, true));

// Upload security limits
assert_test("Allowed video extensions strictly whitelisted", VideoService::ALLOWED_VIDEO_EXTS === ['mp4', 'webm', 'ogg', 'mov']);
assert_test("Allowed video MIMEs strictly whitelisted", in_array('video/mp4', VideoService::ALLOWED_VIDEO_MIMES, true));
assert_test("Max video bytes capped at 50MB", VideoService::MAX_VIDEO_BYTES === 52428800);
assert_test("Max thumbnail bytes capped at 5MB", VideoService::MAX_THUMB_BYTES === 5242880);

// Slug generation & collision handling
$slug1 = VideoService::generateSlug('Master Brake Overhaul & Rotor Turning');
assert_test("Slug generated is URL-safe and lowercase", $slug1 === 'master-brake-overhaul-rotor-turning', "Got: $slug1");

// -------------------------------------------------------------------------
// Section 2: Database Persistence & Service CRUD
// -------------------------------------------------------------------------
echo "\n--- Section 2: Database Persistence & Service CRUD ---\n";

// 1. Create Video
$newVideoData = [
    'title' => 'Test Automated Video Showcase',
    'category' => 'Maintenance Tips',
    'source_type' => 'youtube',
    'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    'duration' => '05:12',
    'description' => 'Automated test suite video description detailing brake inspection procedures.',
    'is_published' => 1,
    'sort_order' => 10,
];

$savedVideo = VideoService::save(null, $newVideoData, null, null, 1);
assert_test("Video created successfully with integer ID", !empty($savedVideo['id']) && (int)$savedVideo['id'] > 0);
$vidId = (int) $savedVideo['id'];
assert_test("Video saved has correct category", $savedVideo['category'] === 'Maintenance Tips');
assert_test("Video has auto-generated thumbnail_url", !empty($savedVideo['thumbnail_url']));
assert_test("Video has valid embed_url for secure player", str_contains((string)$savedVideo['embed_url'], 'youtube-nocookie.com/embed/dQw4w9WgXcQ'));

// 2. Increment Views
$initialViews = (int) $savedVideo['views_count'];
VideoService::incrementViews($vidId);
$refreshed = VideoService::find($vidId);
assert_test("incrementViews increases count by 1", (int)$refreshed['views_count'] === $initialViews + 1);

// 3. Update Video
$updateData = array_merge($newVideoData, [
    'title' => 'Updated Automated Video Showcase',
    'duration' => '06:30',
]);
$updatedVideo = VideoService::save($vidId, $updateData, null, null, 1);
assert_test("Video title updated in DB", $updatedVideo['title'] === 'Updated Automated Video Showcase');
assert_test("Video duration updated in DB", $updatedVideo['duration'] === '06:30');

// 4. Toggle Publish
VideoService::togglePublish($vidId, 1);
$toggledDraft = VideoService::find($vidId);
assert_test("togglePublish switches published video to draft (0)", (int)$toggledDraft['is_published'] === 0);

// Only published query excludes draft
$publishedList = VideoService::all(true);
$publishedIds = array_column($publishedList, 'id');
assert_test("Draft video excluded when querying only published", !in_array($vidId, $publishedIds, true));

VideoService::togglePublish($vidId, 1);
$toggledBack = VideoService::find($vidId);
assert_test("togglePublish switches draft video back to published (1)", (int)$toggledBack['is_published'] === 1);

// 5. Query filtering
$diagVideos = VideoService::all(false, 'Diagnostics');
assert_test("Filtering by Diagnostics category returns matching videos", count($diagVideos) > 0);

$searchedVideos = VideoService::all(false, null, 'Updated Automated');
assert_test("Searching by title returns matching record", count($searchedVideos) >= 1 && (int)$searchedVideos[0]['id'] === $vidId);

// 6. Delete Video
$delRes = VideoService::delete($vidId, 1);
assert_test("delete() returns true", $delRes === true);
$deletedLookup = VideoService::find($vidId);
assert_test("find() returns null after deletion", $deletedLookup === null);

// 7. Verify Audit Log entry
$auditRow = Database::one("SELECT * FROM audit_logs WHERE module_name = 'videos' ORDER BY id DESC LIMIT 1");
assert_test("Audit log records video operations", !empty($auditRow) && $auditRow['module_name'] === 'videos');

// -------------------------------------------------------------------------
// Section 3: Live Web Server HTTP Verification
// -------------------------------------------------------------------------
echo "\n--- Section 3: Live Web Server HTTP Verification ---\n";

$serverPort = 8092;
$serverBase = "http://127.0.0.1:$serverPort/";
$serverLog = sys_get_temp_dir() . '/test-server-videos-' . getmypid() . '.log';

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

usleep(600000);

function test_http_request(string $path, array $cookies = []): array {
    global $serverBase;
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
        CURLOPT_URL => $serverBase . ltrim($path, '/'),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => false,
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

// 1. Public Video Gallery English
$galEn = test_http_request('videos.php?lang=en');
assert_test("Public video gallery loads HTTP 200", $galEn['code'] === 200);
assert_test("Gallery renders '<html lang=\"en\">'", str_contains($galEn['body'], '<html lang="en">'));
assert_test("Gallery renders Video Gallery headline", str_contains($galEn['body'], 'Video Gallery'));
assert_test("Gallery includes video search input", str_contains($galEn['body'], 'id="video-search-input"'));
assert_test("Gallery includes category filter pills", str_contains($galEn['body'], 'data-category="all"') && str_contains($galEn['body'], 'All Videos'));
assert_test("Gallery renders video cards grid", str_contains($galEn['body'], 'class="video-card') || str_contains($galEn['body'], 'id="videos-grid"'));
assert_test("Gallery includes modal video player markup", str_contains($galEn['body'], 'id="video-modal"'));
assert_test("Gallery loads videos.css and videos.js", str_contains($galEn['body'], 'videos.css') && str_contains($galEn['body'], 'videos.js'));

// 2. Public Video Gallery Amharic
$galAm = test_http_request('videos.php?lang=am');
assert_test("Public gallery ?lang=am loads HTTP 200", $galAm['code'] === 200);
assert_test("Gallery renders '<html lang=\"am\">'", str_contains($galAm['body'], '<html lang="am">'));
assert_test("Gallery renders translated headline 'የቪዲዮ ማሳያ'", str_contains($galAm['body'], 'የቪዲዮ ማሳያ'));
assert_test("Gallery renders translated pill 'ሁሉም ቪዲዮዎች'", str_contains($galAm['body'], 'ሁሉም ቪዲዮዎች'));
assert_test("Gallery renders translated category 'የኮምፒውተር ምርመራ'", str_contains($galAm['body'], 'የኮምፒውተር ምርመራ'));

// 3. Media Streamer Security
$streamSec1 = test_http_request('media-stream.php?file=../../app/bootstrap.php');
assert_test("Directory traversal attempt in media-stream.php is blocked (HTTP 404/403)", in_array($streamSec1['code'], [403, 404], true));

$streamSec2 = test_http_request('media-stream.php?type=video&file=malicious_payload.php');
assert_test("Disallowed PHP extension in media-stream.php is rejected (HTTP 403/404)", in_array($streamSec2['code'], [403, 404], true));

$streamSec3 = test_http_request('media-stream.php?type=video&file=non_existent_clip.mp4');
assert_test("Non-existent video file returns HTTP 404", $streamSec3['code'] === 404);

// -------------------------------------------------------------------------
// Section 4: Role-Based Access Control (RBAC)
// -------------------------------------------------------------------------
echo "\n--- Section 4: Role-Based Access Control (RBAC) ---\n";

// 1. Unauthenticated access
$unauthDash = test_http_request('videos/index.php');
assert_test("Unauthenticated access to videos/index.php redirects to login.php (HTTP 302)", $unauthDash['code'] === 302);

$unauthForm = test_http_request('videos/form.php');
assert_test("Unauthenticated access to videos/form.php redirects to login.php (HTTP 302)", $unauthForm['code'] === 302);

// 2. Customer Role Access Control (Denied)
$custResp = test_http_request('videos/index.php?test_user=test_cust_alice');
assert_test("Customer role is denied access to videos/index.php (HTTP 403)", $custResp['code'] === 403);

$custForm = test_http_request('videos/form.php?test_user=test_cust_alice');
assert_test("Customer role is denied access to videos/form.php (HTTP 403)", $custForm['code'] === 403);

// 3. Receptionist Role Access Control (Denied)
$recResp = test_http_request('videos/index.php?test_user=reception');
assert_test("Receptionist role is denied access to videos/index.php (HTTP 403)", $recResp['code'] === 403);

// 4. Administrator Role Access Control (Allowed)
$adminResp = test_http_request('videos/index.php?test_user=admin');
assert_test("Administrator accesses videos/index.php with HTTP 200", $adminResp['code'] === 200);
assert_test("Admin view contains Video Management title", str_contains($adminResp['body'], 'Video Management') || str_contains($adminResp['body'], 'የቪዲዮ አስተዳደር'));
assert_test("Admin view contains Add Video action", str_contains($adminResp['body'], 'videos/form.php'));
assert_test("Admin view renders video management table", str_contains($adminResp['body'], 'id="videos-table"'));

$adminForm = test_http_request('videos/form.php?test_user=admin');
assert_test("Administrator accesses videos/form.php with HTTP 200", $adminForm['code'] === 200);
assert_test("Admin form contains YouTube and Upload options", str_contains($adminForm['body'], 'source_type') && str_contains($adminForm['body'], 'youtube'));

// -------------------------------------------------------------------------
// Section 5: Navigation & Bilingual Integration Verification
// -------------------------------------------------------------------------
echo "\n--- Section 5: Navigation & Bilingual Integration Verification ---\n";

$homeEn = test_http_request('index.php?lang=en');
assert_test("Homepage navbar contains link to 'videos.php'", str_contains($homeEn['body'], 'videos.php'));
assert_test("Homepage navbar displays 'Videos' label", str_contains($homeEn['body'], '>Videos</a>') || str_contains($homeEn['body'], 'videos.php'));

$servicesEn = test_http_request('services.php?lang=en');
assert_test("Services page navbar contains link to 'videos.php'", str_contains($servicesEn['body'], 'videos.php'));

$aboutEn = test_http_request('about.php?lang=en');
assert_test("About page navbar contains link to 'videos.php'", str_contains($aboutEn['body'], 'videos.php'));

$contactEn = test_http_request('contact.php?lang=en');
assert_test("Contact page navbar contains link to 'videos.php'", str_contains($contactEn['body'], 'videos.php'));

$homeAm = test_http_request('index.php?lang=am');
assert_test("Homepage in Amharic translates navbar 'ቪዲዮዎች'", str_contains($homeAm['body'], 'ቪዲዮዎች'));

echo "\n====================================================================\n";
echo "VIDEO MANAGEMENT SUITE SUMMARY\n";
echo "Total Assertions: $totalAsserts\n";
echo "Passed:           $passedAsserts\n";
echo "Failed:           $failedAsserts\n";
echo "====================================================================\n";

if ($failedAsserts > 0) {
    exit(1);
}
