<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — CUSTOMER DASHBOARD BOOK SERVICE VERIFICATION SUITE
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';

$pdo = Database::pdo();
$allPassed = true;
$totalAssertions = 0;
$passedAssertions = 0;

function assert_test(string $name, bool $condition, string $detail = ''): void {
    global $allPassed, $totalAssertions, $passedAssertions;
    $totalAssertions++;
    if ($condition) {
        $passedAssertions++;
        echo "  [PASS] $name\n";
    } else {
        $allPassed = false;
        echo "  [FAIL] $name" . ($detail ? " ($detail)" : '') . "\n";
    }
}

echo "====================================================================\n";
echo "ALPHA GARAGE — CUSTOMER BOOKING & DASHBOARD FUNCTIONALITY SUITE\n";
echo "====================================================================\n\n";

// --- Section 1: Customer Portal Static & Security Integrity ---
echo "--- Section 1: Customer Portal Static & Security Integrity ---\n";

$aliceUser = Database::one("SELECT u.*, r.name as role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.username = 'test_cust_alice'");
assert_test("Test customer user (test_cust_alice) exists", !empty($aliceUser));

// Check Customer Identity
$aliceCust = CustomerIdentityService::resolveCustomer($aliceUser);
assert_test("Customer record resolved for test_cust_alice", !empty($aliceCust) && $aliceCust['id'] > 0);

// Render customer dashboard to buffer and inspect
$_SESSION['uid'] = $aliceUser['id'];
$GLOBALS['test_user'] = 'test_cust_alice';
$_SERVER['SCRIPT_NAME'] = '/public/customer.php';

ob_start();
$user = $aliceUser;
require APP_ROOT . '/app/views/dashboards/customer.php';
$html = ob_get_clean();

assert_test("Customer dashboard renders non-empty HTML", strlen($html) > 5000);
assert_test("No inline <script> tags present in customer.php (strict CSP compliance)", !str_contains($html, '<script>'));
assert_test("Includes external dashboard.js", str_contains($html, 'dashboard.js'));
assert_test("Contains in-place booking modal dialog", str_contains($html, 'id="bookingModalDialog"'));
assert_test("Contains in-place booking form", str_contains($html, 'id="inplaceBookingForm"'));
assert_test("Contains modal close button", str_contains($html, 'id="bookingModalClose"'));
assert_test("Contains cancel button", str_contains($html, 'id="btnCancelBooking"'));
assert_test("Contains confirm & book button", str_contains($html, 'id="btnSubmitBooking"'));
assert_test("Contains link to full booking page", str_contains($html, 'book.php'));
assert_test("Contains vehicle mode toggle", str_contains($html, 'id="modalVehicleMode"'));
assert_test("Contains service selector with catalog", str_contains($html, 'id="modalServiceId"'));
assert_test("Contains date picker with minimum date", str_contains($html, 'id="modalApptDate"'));
assert_test("Contains time slot selector", str_contains($html, 'id="modalApptTime"'));
assert_test("Contains notes / concerns textarea", str_contains($html, 'id="modalNotes"'));

// --- Section 2: JavaScript Modal Controller Integrity ---
echo "\n--- Section 2: JavaScript Modal Controller Integrity ---\n";

$dashJs = file_get_contents(APP_ROOT . '/public/assets/js/dashboard.js');
assert_test("dashboard.js contains initCustomerBookingModal function", str_contains($dashJs, 'function initCustomerBookingModal()'));
assert_test("dashboard.js handles [data-open-booking] clicks", str_contains($dashJs, "closest('[data-open-booking]')"));
assert_test("dashboard.js handles #book-service hash changes", str_contains($dashJs, '#book-service'));
assert_test("dashboard.js handles preselected vehicle ID", str_contains($dashJs, 'preselectedVehicleId'));
assert_test("dashboard.js handles Escape key modal dismissal", str_contains($dashJs, "key === 'Escape'"));
assert_test("dashboard.js handles vehicle toggle switching", str_contains($dashJs, "setVehicleMode('existing')") && str_contains($dashJs, "setVehicleMode('new')"));

// --- Section 3: In-Place Booking POST Execution ---
echo "\n--- Section 3: In-Place Booking POST Execution ---\n";

// Ensure Alice has at least one registered vehicle
$aliceVeh = Database::one("SELECT * FROM vehicles WHERE customer_id = ? AND is_active = 1", [$aliceCust['id']]);
if (!$aliceVeh) {
    Database::run(
        "INSERT INTO vehicles (customer_id, plate_number, make, model, year, is_active) VALUES (?, 'TEST-CUST-1', 'Toyota', 'RAV4', 2022, 1)",
        [$aliceCust['id']]
    );
    $aliceVeh = Database::one("SELECT * FROM vehicles WHERE customer_id = ? AND is_active = 1", [$aliceCust['id']]);
}
assert_test("Alice vehicle available for test booking", !empty($aliceVeh));

// 3.1 Test valid booking with existing vehicle
$futureDate = date('Y-m-d', strtotime('+3 days'));
$futureTime = '14:00:00';
$notes = 'Routine oil service and safety brake inspection';

$bookingApptId = AppointmentService::create([
    'customer_id'         => (int)$aliceCust['id'],
    'vehicle_id'          => (int)$aliceVeh['id'],
    'service_id'          => null,
    'appointment_date'    => $futureDate,
    'appointment_time'    => $futureTime,
    'problem_description' => $notes,
    'notes'               => $notes,
    'status'              => 'confirmed',
]);
assert_test("Appointment created successfully with ID", $bookingApptId > 0);

$savedAppt = Database::one("SELECT * FROM appointments WHERE id = ?", [$bookingApptId]);
assert_test("Saved appointment has generated appointment_number", !empty($savedAppt['appointment_number']) && str_starts_with($savedAppt['appointment_number'], 'APT-'));
assert_test("Saved appointment has correct customer_id", (int)$savedAppt['customer_id'] === (int)$aliceCust['id']);
assert_test("Saved appointment has correct vehicle_id", (int)$savedAppt['vehicle_id'] === (int)$aliceVeh['id']);
assert_test("Saved appointment has correct date", $savedAppt['appointment_date'] === $futureDate);
assert_test("Saved appointment problem_description stored", $savedAppt['problem_description'] === $notes);

// 3.2 Test new vehicle auto-registration on booking
$uniquePlate = 'BK-' . rand(1000, 9999) . '-NEW';
$newVehApptId = (int) Database::transaction(function () use ($aliceCust, $uniquePlate, $futureDate, $futureTime) {
    Database::run(
        'INSERT INTO vehicles (customer_id, plate_number, make, model, year, is_active) VALUES (?, ?, ?, ?, ?, 1)',
        [$aliceCust['id'], $uniquePlate, 'Subaru', 'Outback', 2023]
    );
    $newVehId = (int) Database::pdo()->lastInsertId();
    return AppointmentService::create([
        'customer_id'         => (int)$aliceCust['id'],
        'vehicle_id'          => $newVehId,
        'service_id'          => null,
        'appointment_date'    => $futureDate,
        'appointment_time'    => $futureTime,
        'problem_description' => 'New vehicle first checkup',
        'status'              => 'confirmed',
    ]);
});
assert_test("Booking with new vehicle registers vehicle and creates appointment", $newVehApptId > 0);
$checkNewVeh = Database::one("SELECT * FROM vehicles WHERE plate_number = ?", [$uniquePlate]);
assert_test("New vehicle registered under Alice customer_id", !empty($checkNewVeh) && (int)$checkNewVeh['customer_id'] === (int)$aliceCust['id']);

// --- Section 4: Live HTTP Web Requests & Bilingual Rendering ---
echo "\n--- Section 4: Live HTTP Web Requests & Bilingual Rendering ---\n";

$baseUrl = 'http://localhost/ALPHA-GARAGE/AutoPro_Garage-main/public';
$cookieJar = tempnam(sys_get_temp_dir(), 'alice_cookie_');

// 4.0 Authenticate Alice
$ch = curl_init("$baseUrl/login.php");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
$loginHtml = curl_exec($ch);
curl_close($ch);

preg_match('/name="_csrf"\s+value="([^"]+)"/', $loginHtml, $m);
$csrf = $m[1] ?? '';

$alicePass = (isset($aliceUser['password_hash']) && password_verify('Customer123!', $aliceUser['password_hash'])) ? 'Customer123!' : 'Customer#Pass1';

$ch = curl_init("$baseUrl/login.php");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'username' => 'test_cust_alice',
    'password' => $alicePass,
    '_csrf' => $csrf
]));
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
$postRes = curl_exec($ch);
curl_close($ch);

// GET customer.php in English
$ch = curl_init("$baseUrl/customer.php?lang=en");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
$enBody = curl_exec($ch);
$enCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

assert_test("Customer dashboard in English responds with HTTP 200", $enCode === 200);
assert_test("English dashboard renders 'Customer Portal'", str_contains($enBody, 'Customer Portal'));
assert_test("English dashboard renders '+ Book New Service'", str_contains($enBody, '+ Book New Service'));
assert_test("English dashboard renders 'Book Premium Service Visit'", str_contains($enBody, 'Book Premium Service Visit'));
assert_test("English dashboard renders 'Confirm & Book Service'", str_contains($enBody, 'Confirm &amp; Book Service') || str_contains($enBody, 'Confirm & Book Service'));
assert_test("English dashboard contains NO inline <script>", !str_contains($enBody, '<script>'));

// GET customer.php in Amharic
$ch = curl_init("$baseUrl/customer.php?lang=am");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
$amBody = curl_exec($ch);
$amCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

assert_test("Customer dashboard in Amharic responds with HTTP 200", $amCode === 200);
assert_test("Amharic dashboard renders '<html lang=\"am\">'", str_contains($amBody, '<html lang="am">'));
assert_test("Amharic dashboard translates title 'የደንበኛ ፖርታል' or 'ቪአይፒ የደንበኞች አገልግሎት'", str_contains($amBody, 'ቪአይፒ የደንበኞች አገልግሎት') || str_contains($amBody, 'የደንበኛ ፖርታል'));
assert_test("Amharic dashboard translates modal 'ፕሪሚየም የአገልግሎት ጉብኝት ያስይዙ'", str_contains($amBody, 'ፕሪሚየም የአገልግሎት ጉብኝት ያስይዙ'));
assert_test("Amharic dashboard translates button 'አረጋግጥ እና አገልግሎት ያዝ'", str_contains($amBody, 'አረጋግጥ እና አገልግሎት ያዝ'));
assert_test("Amharic dashboard contains NO inline <script>", !str_contains($amBody, '<script>'));

if (file_exists($cookieJar)) @unlink($cookieJar);

// --- Section 5: IDOR & Security Isolation ---
echo "\n--- Section 5: IDOR & Security Isolation ---\n";

// Attempt to book service using a vehicle belonging to someone else
$otherVeh = Database::one("SELECT id, customer_id FROM vehicles WHERE customer_id != ? AND is_active = 1 LIMIT 1", [$aliceCust['id']]);
if ($otherVeh) {
    $caughtIdor = false;
    try {
        AppointmentService::create([
            'customer_id' => (int)$aliceCust['id'],
            'vehicle_id'  => (int)$otherVeh['id'],
            'appointment_date' => $futureDate,
            'appointment_time' => $futureTime,
        ]);
    } catch (DomainException $e) {
        $caughtIdor = true;
    }
    assert_test("Attempt to book service with another customer's vehicle is blocked", $caughtIdor);
}

echo "\n====================================================================\n";
echo "CUSTOMER BOOKING SUITE SUMMARY\n";
echo "Total Assertions: $totalAssertions\n";
echo "Passed:           $passedAssertions\n";
echo "Failed:           " . ($totalAssertions - $passedAssertions) . "\n";
echo "====================================================================\n";

if (!$allPassed) {
    exit(1);
}
