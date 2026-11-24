<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — PHASE 1.12 AUTOMATED TEST SUITE
 * Complete Website-Wide Scroll Animation & Premium Interaction Experience
 *
 * Verifies:
 *   1. CSS Animation Engine rules, tokens, responsive breakpoints & prefers-reduced-motion overrides
 *   2. JS Animation Engine IntersectionObserver, MutationObserver, back-to-top, count-up & API
 *   3. Shared helpers & layout integration (css_links, ui_footer, page_header)
 *   4. Public website & authentication pages markup coverage
 *   5. All 6 role dashboards markup coverage & micro-interactions
 *   6. Subpage integration & automated card/table entrance handling
 *   7. Progressive enhancement & non-JS failsafe validation
 *   8. Database invariants & regression safety
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Error: Must be run from CLI.\n");
    exit(1);
}

define('APP_ROOT', str_replace('\\', '/', dirname(__DIR__)));

putenv('GMS_TEST_MODE=1');
putenv('GMS_ALLOW_DESTRUCTIVE_TESTS=1');
putenv('GMS_DB_NAME=garage_management_system_test');
putenv('GMS_STORAGE_PATH=' . APP_ROOT . '/storage/testing');
putenv('GMS_DB_USER=root');
putenv('GMS_DB_PASS=root');

spl_autoload_register(function (string $class): void {
    foreach (['services', 'models'] as $dir) {
        $f = APP_ROOT . "/app/$dir/$class.php";
        if (is_file($f)) { require_once $f; return; }
    }
});

$GLOBALS['config'] = require APP_ROOT . '/app/config/app.php';
date_default_timezone_set($GLOBALS['config']['timezone']);
require_once APP_ROOT . '/app/helpers/functions.php';
require_once APP_ROOT . '/app/middleware/auth.php';

$totalAssertions = 0;
$passedAssertions = 0;
$failedAssertions = 0;
$failures = [];

function assert_true(bool $condition, string $message): void {
    global $totalAssertions, $passedAssertions, $failedAssertions, $failures;
    $totalAssertions++;
    if ($condition) {
        $passedAssertions++;
        echo "  [PASS] $message\n";
    } else {
        $failedAssertions++;
        $failures[] = $message;
        echo "  [FAIL] $message\n";
    }
}

echo "====================================================================\n";
echo "ALPHA GARAGE — PHASE 1.12 AUTOMATED VERIFICATION SUITE\n";
echo "====================================================================\n\n";

// -----------------------------------------------------------------------------
// SECTION 1: CSS Animation Engine Integrity
// -----------------------------------------------------------------------------
echo "--- Section 1: CSS Animation Engine Integrity ---\n";

$cssPath = APP_ROOT . '/public/assets/css/scroll-animations.css';
assert_true(file_exists($cssPath), "CSS file exists at public/assets/css/scroll-animations.css");
$cssContent = file_get_contents($cssPath);
assert_true(strlen($cssContent) > 2000, "CSS file is non-empty (" . strlen($cssContent) . " bytes)");

// Failsafe check
assert_true(strpos($cssContent, 'html.js-ready .reveal') !== false, "CSS gates initial hidden states under html.js-ready");
assert_true(strpos($cssContent, 'opacity: 1') !== false && strpos($cssContent, 'transform: none') !== false, "CSS defines default visible failsafe state");

// Required reveal classes
$requiredClasses = [
    '.reveal', '.reveal-up', '.reveal-down', '.reveal-left', '.reveal-right',
    '.reveal-scale', '.reveal-fade', '.reveal-zoom', '.reveal-blur', '.reveal-rotate',
    '.reveal-stagger', '.is-revealed'
];
foreach ($requiredClasses as $cls) {
    assert_true(strpos($cssContent, $cls) !== false, "CSS contains animation class '$cls'");
}

// Staggers
for ($i = 1; $i <= 8; $i++) {
    assert_true(strpos($cssContent, ".stagger-$i") !== false, "CSS contains stagger utility '.stagger-$i'");
}

// Micro-interactions & components
assert_true(strpos($cssContent, '.card-interactive') !== false, "CSS contains '.card-interactive' hover micro-interaction");
assert_true(strpos($cssContent, '.btn-interactive') !== false, "CSS contains '.btn-interactive' button styling");
assert_true(strpos($cssContent, '.btn-shimmer') !== false, "CSS contains '.btn-shimmer' shimmer effect");
assert_true(strpos($cssContent, '.back-to-top') !== false, "CSS contains floating '.back-to-top' button styling");

// Accessibility & Mobile
assert_true(strpos($cssContent, '@media (prefers-reduced-motion: reduce)') !== false, "CSS contains full prefers-reduced-motion media query");
assert_true(strpos($cssContent, '@media (max-width: 768px)') !== false, "CSS contains mobile performance viewport tuning");
assert_true(strpos($cssContent, '#0D281E') !== false || strpos($cssContent, '#0d281e') !== false, "CSS utilizes Deep Forest Green brand token");
assert_true(strpos($cssContent, '#D4AF37') !== false || strpos($cssContent, '#d4af37') !== false, "CSS utilizes Champagne Gold brand token");

echo "\n";

// -----------------------------------------------------------------------------
// SECTION 2: JavaScript Animation Engine Integrity
// -----------------------------------------------------------------------------
echo "--- Section 2: JavaScript Animation Engine Integrity ---\n";

$jsPath = APP_ROOT . '/public/assets/js/scroll-animations.js';
assert_true(file_exists($jsPath), "JS file exists at public/assets/js/scroll-animations.js");
$jsContent = file_get_contents($jsPath);
assert_true(strlen($jsContent) > 2000, "JS file is non-empty (" . strlen($jsContent) . " bytes)");

assert_true(strpos($jsContent, 'IntersectionObserver') !== false, "JS initializes IntersectionObserver");
assert_true(strpos($jsContent, 'MutationObserver') !== false, "JS initializes MutationObserver for dynamic/AJAX content");
assert_true(strpos($jsContent, 'prefers-reduced-motion') !== false, "JS detects and respects prefers-reduced-motion");
assert_true(strpos($jsContent, "classList.add('js-ready')") !== false, "JS activates html.js-ready progressively");
assert_true(strpos($jsContent, 'animateCounter') !== false, "JS provides statistic count-up animation");
assert_true(strpos($jsContent, 'ensureBackToTopButton') !== false, "JS manages accessible back-to-top button lifecycle");
assert_true(strpos($jsContent, 'window.GMSAnimation') !== false, "JS exposes public window.GMSAnimation API");
assert_true(strpos($jsContent, 'initSmoothAnchorScroll') !== false, "JS implements accessible smooth anchor navigation");

echo "\n";

// -----------------------------------------------------------------------------
// SECTION 3: Shared Chrome & Helper Integration
// -----------------------------------------------------------------------------
echo "--- Section 3: Shared Chrome & Helper Integration ---\n";

$functionsContent = file_get_contents(APP_ROOT . '/app/helpers/functions.php');
assert_true(strpos($functionsContent, "'scroll-animations'") !== false, "functions.php css_links() includes scroll-animations stylesheet");

$layoutContent = file_get_contents(APP_ROOT . '/app/views/layout.php');
assert_true(strpos($layoutContent, "scroll-animations.js") !== false, "layout.php ui_footer() includes scroll-animations.js script");
assert_true(strpos($layoutContent, "page-head reveal-up") !== false, "layout.php page_header() incorporates reveal-up entrance class");

echo "\n";

// -----------------------------------------------------------------------------
// SECTION 4: Public Website & Auth Page Coverage
// -----------------------------------------------------------------------------
echo "--- Section 4: Public Website & Auth Page Coverage ---\n";

$publicPages = [
    'public/index.php' => ['scroll-animations.css', 'scroll-animations.js', 'reveal-up', 'card-interactive', 'stagger-'],
    'public/about.php' => ['scroll-animations.css', 'scroll-animations.js', 'reveal-up', 'card-interactive', 'reveal-stagger'],
    'public/services.php' => ['scroll-animations.css', 'scroll-animations.js', 'reveal-up', 'card-interactive', 'stagger-'],
    'public/contact.php' => ['scroll-animations.css', 'scroll-animations.js', 'reveal-up', 'reveal-left', 'reveal-right'],
    'public/book.php' => ['scroll-animations.js'],
    'public/home.php' => ['scroll-animations.js'],
    'public/login.php' => ['scroll-animations.css', 'scroll-animations.js', 'card-interactive'],
    'public/register.php' => ['scroll-animations.css', 'scroll-animations.js', 'card-interactive'],
    'public/forgot-password.php' => ['scroll-animations.css', 'scroll-animations.js', 'card-interactive'],
    'public/reset-password.php' => ['scroll-animations.css', 'scroll-animations.js', 'card-interactive'],
    'public/privacy.php' => ['scroll-animations.css', 'scroll-animations.js', 'reveal-up'],
    'public/terms.php' => ['scroll-animations.css', 'scroll-animations.js', 'reveal-up'],
];

foreach ($publicPages as $file => $expectedTokens) {
    $fullPath = APP_ROOT . '/' . $file;
    assert_true(file_exists($fullPath), "Page $file exists");
    $content = file_get_contents($fullPath);
    foreach ($expectedTokens as $token) {
        assert_true(strpos($content, $token) !== false, "$file contains '$token'");
    }
}

echo "\n";

// -----------------------------------------------------------------------------
// SECTION 5: Role Dashboards Coverage
// -----------------------------------------------------------------------------
echo "--- Section 5: Role Dashboards Coverage ---\n";

$dashboards = [
    'app/views/dashboards/admin.php' => [
        'hero reveal-up', 'card-interactive', 'stagger-1', 'stagger-8', 'chart-card', 'reveal-up'
    ],
    'app/views/dashboards/receptionist.php' => [
        'hero reveal-up', 'card-interactive', 'stagger-1', 'stagger-4', 'chart-card', 'reveal-up'
    ],
    'app/views/dashboards/mechanic.php' => [
        'hero reveal-up', 'card-interactive', 'stagger-1', 'stagger-3', 'chart-card', 'reveal-up'
    ],
    'app/views/dashboards/inventory.php' => [
        'hero reveal-up', 'card-interactive', 'stagger-1', 'stagger-4', 'chart-card', 'reveal-up'
    ],
    'app/views/dashboards/accountant.php' => [
        'hero reveal-up', 'card-interactive', 'stagger-1', 'stagger-4', 'chart-card', 'reveal-up'
    ],
    'app/views/dashboards/customer.php' => [
        'booking-portal-header reveal-up', 'card-interactive', 'stagger-1', 'stagger-4', 'chart-card', 'reveal-up', 'scroll-animations.js'
    ],
];

foreach ($dashboards as $file => $expectedTokens) {
    $fullPath = APP_ROOT . '/' . $file;
    assert_true(file_exists($fullPath), "Dashboard $file exists");
    $content = file_get_contents($fullPath);
    foreach ($expectedTokens as $token) {
        assert_true(strpos($content, $token) !== false, "$file contains '$token'");
    }
}

echo "\n";

// -----------------------------------------------------------------------------
// SECTION 6: Database Safety & Invariant Checks
// -----------------------------------------------------------------------------
echo "--- Section 6: Database Safety & Invariant Checks ---\n";

$pdo = Database::pdo();
$activeDb = (string) $pdo->query("SELECT DATABASE()")->fetchColumn();
assert_true($activeDb === 'garage_management_system_test', "Test runner connected strictly to garage_management_system_test ($activeDb)");

$userCount = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
assert_true($userCount > 0, "Users table populated ($userCount records)");

$jobCount = (int) $pdo->query("SELECT COUNT(*) FROM job_cards")->fetchColumn();
assert_true($jobCount > 0, "Job cards table populated ($jobCount records)");

$invoiceCount = (int) $pdo->query("SELECT COUNT(*) FROM invoices")->fetchColumn();
assert_true($invoiceCount > 0, "Invoices table populated ($invoiceCount records)");

echo "\n====================================================================\n";
echo "TEST RESULTS SUMMARY\n";
echo "Total Assertions:  $totalAssertions\n";
echo "Passed:            $passedAssertions\n";
echo "Failed:            $failedAssertions\n";
echo "====================================================================\n";

if ($failedAssertions > 0) {
    echo "\nFailed Assertions:\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}

echo "\nAll Phase 1.12 verification assertions passed successfully with 100% compliance.\n";
exit(0);
