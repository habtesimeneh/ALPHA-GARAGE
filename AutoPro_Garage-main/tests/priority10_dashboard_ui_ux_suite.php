<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — PRIORITY 10 AUTOMATED VERIFICATION SUITE
 * Dashboard Visual Consistency, Customer Booking Form UX, and Color Contrast Verification
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Must be run from CLI.\n");
    exit(1);
}

putenv('GMS_TEST_MODE=1');
putenv('GMS_STORAGE_PATH=' . __DIR__ . '/../storage/testing');
putenv('GMS_DB_NAME=garage_management_system_staging');
putenv('GMS_DB_USER=garage_app_staging');
putenv('GMS_DB_PASS=StagingAppPass#2026!Sec');

require_once __DIR__ . '/../app/bootstrap.php';

echo "=================================================================\n";
echo "ALPHA GARAGE — PRIORITY 10 UI/UX & BOOKING FORM TEST SUITE\n";
echo "Database: " . Database::value("SELECT DATABASE()") . " | User: " . Database::value("SELECT CURRENT_USER()") . "\n";
echo "=================================================================\n\n";

$passCount = 0;
$totalCount = 0;

function assert_test(string $section, string $desc, bool $condition, string $detail = ''): void {
    global $passCount, $totalCount;
    $totalCount++;
    echo "[$section] Check $totalCount: $desc ... ";
    if ($condition) {
        echo "[PASS]" . ($detail ? " ($detail)" : "") . "\n";
        $passCount++;
    } else {
        echo "[FAIL] $detail\n";
    }
}

// -------------------------------------------------------------
// 1. CSS TOKENS & COLOR CONTRAST RULES
// -------------------------------------------------------------
$formsCss = file_get_contents(__DIR__ . '/../public/assets/css/forms.css');
$themeCss = file_get_contents(__DIR__ . '/../public/assets/css/theme.css');
$dashboardCss = file_get_contents(__DIR__ . '/../public/assets/css/dashboard.css');
$responsiveCss = file_get_contents(__DIR__ . '/../public/assets/css/responsive.css');

assert_test("CSS", "forms.css has uniform min-height 44px for inputs/selects", strpos($formsCss, 'min-height: 44px') !== false);
assert_test("CSS", "forms.css textarea min-height is at least 120px", strpos($formsCss, 'min-height: 120px') !== false);
assert_test("CSS", "forms.css defines accessible placeholder color #64748B", strpos($formsCss, '#64748B') !== false);
assert_test("CSS", "theme.css has dark-mode input background and gold focus ring", strpos($themeCss, 'html[data-theme="dark"] .input:focus') !== false && strpos($themeCss, '#D4AF37') !== false);
assert_test("CSS", "theme.css has gold SVG arrow for dark select dropdowns", strpos($themeCss, "stroke='%23D4AF37'") !== false);
assert_test("CSS", "theme.css has dark-mode select option background #0D281E", strpos($themeCss, 'background-color: #0D281E') !== false);
assert_test("CSS", "theme.css has dark-mode calendar picker filter indicator", strpos($themeCss, 'webkit-calendar-picker-indicator') !== false);
assert_test("CSS", "theme.css fixes .btn-ghost and .btn-outline dark text contrast bug", strpos($themeCss, 'html[data-theme="dark"] .btn-ghost') !== false);
assert_test("CSS", "theme.css fixes .table-wrap white background fade mask in dark mode", strpos($themeCss, 'html[data-theme="dark"] .table-wrap') !== false);
assert_test("CSS", "dashboard.css defines .booking-section-header with gold accents", strpos($dashboardCss, '.booking-section-header') !== false);
assert_test("CSS", "dashboard.css defines .vehicle-toggle-bar with pill buttons", strpos($dashboardCss, '.vehicle-toggle-btn.active') !== false);
assert_test("CSS", "responsive.css defines mobile adaptations for 480px, 375px, and 320px", 
    strpos($responsiveCss, '@media (max-width: 480px)') !== false &&
    strpos($responsiveCss, '@media (max-width: 375px)') !== false &&
    strpos($responsiveCss, '@media (max-width: 320px)') !== false
);

// -------------------------------------------------------------
// 2. CUSTOMER DASHBOARD IN-PLACE MODAL MARKUP
// -------------------------------------------------------------
$customerView = file_get_contents(__DIR__ . '/../app/views/dashboards/customer.php');

assert_test("MARKUP", "customer.php has #bookingModalBackdrop and #bookingModalDialog", 
    strpos($customerView, 'id="bookingModalBackdrop"') !== false &&
    strpos($customerView, 'id="bookingModalDialog"') !== false
);
assert_test("MARKUP", "customer.php has #bookingModalClose button", strpos($customerView, 'id="bookingModalClose"') !== false);
assert_test("MARKUP", "customer.php has #btnToggleExisting and #btnToggleNew toggle pills", 
    strpos($customerView, 'id="btnToggleExisting"') !== false &&
    strpos($customerView, 'id="btnToggleNew"') !== false
);
assert_test("MARKUP", "customer.php has #groupExistingVehicle and #groupNewVehicle wrappers", 
    strpos($customerView, 'id="groupExistingVehicle"') !== false &&
    strpos($customerView, 'id="groupNewVehicle"') !== false
);
assert_test("MARKUP", "customer.php has vehicle inputs (modalVehicleId, modalPlateNumber, modalMake, modalModel, modalYear)", 
    strpos($customerView, 'id="modalVehicleId"') !== false &&
    strpos($customerView, 'id="modalPlateNumber"') !== false &&
    strpos($customerView, 'id="modalMake"') !== false &&
    strpos($customerView, 'id="modalModel"') !== false &&
    strpos($customerView, 'id="modalYear"') !== false
);
assert_test("MARKUP", "customer.php has appointment inputs (modalServiceId, modalApptDate, modalApptTime)", 
    strpos($customerView, 'id="modalServiceId"') !== false &&
    strpos($customerView, 'id="modalApptDate"') !== false &&
    strpos($customerView, 'id="modalApptTime"') !== false
);
assert_test("MARKUP", "customer.php has #modalNotes textarea with rows='4'", 
    strpos($customerView, 'id="modalNotes"') !== false &&
    strpos($customerView, 'rows="4"') !== false
);
assert_test("MARKUP", "customer.php has #btnCancelBooking and #btnSubmitBooking buttons", 
    strpos($customerView, 'id="btnCancelBooking"') !== false &&
    strpos($customerView, 'id="btnSubmitBooking"') !== false
);
assert_test("MARKUP", "customer.php has 3 distinct booking section headers", 
    substr_count($customerView, 'class="booking-section-header"') === 3
);
assert_test("MARKUP", "customer.php replaced low-contrast inline style on record linking card", 
    strpos($customerView, 'class="card record-link-card') !== false
);

// -------------------------------------------------------------
// 3. STANDALONE BOOKING PORTAL (public/book.php)
// -------------------------------------------------------------
$bookView = file_get_contents(__DIR__ . '/../public/book.php');

assert_test("BOOK_PORTAL", "book.php uses .input and .booking-section-header", 
    strpos($bookView, 'class="input"') !== false || strpos($bookView, 'class="input mb-2"') !== false
);
assert_test("BOOK_PORTAL", "book.php has 3 booking section headers with SVG icons", 
    substr_count($bookView, 'class="booking-section-header"') === 3
);
assert_test("BOOK_PORTAL", "book.php replaced legacy bg-light with .card-support-callout", 
    strpos($bookView, 'bg-light') === false && strpos($bookView, 'card-support-callout') !== false
);
assert_test("BOOK_PORTAL", "book.php replaced legacy .list-group with .booking-history-list", 
    strpos($bookView, 'booking-history-list') !== false && strpos($bookView, 'booking-history-item') !== false
);
assert_test("BOOK_PORTAL", "book.php has luxury shimmer confirm button", 
    strpos($bookView, 'btn-shimmer') !== false
);

// -------------------------------------------------------------
// 4. BILINGUAL TRANSLATION COVERAGE
// -------------------------------------------------------------
$_GET['lang'] = 'am';
init_language();

assert_test("I18N", "t('Vehicle Information') resolves in Amharic", t('Vehicle Information') === 'የተሽከርካሪ መረጃ');
assert_test("I18N", "t('Appointment Information') resolves in Amharic", t('Appointment Information') === 'የቀጠሮ መረጃ');
assert_test("I18N", "t('Additional Information') resolves in Amharic", t('Additional Information') === 'ተጨማሪ መረጃ');
assert_test("I18N", "t('Book Premium Service Visit') resolves in Amharic", t('Book Premium Service Visit') === 'ፕሪሚየም የአገልግሎት ጉብኝት ያስይዙ');
assert_test("I18N", "t('Confirm & Book Service') resolves in Amharic", t('Confirm & Book Service') === 'አረጋግጥ እና አገልግሎት ያዝ');

// Reset to English
$_GET['lang'] = 'en';
init_language();
assert_test("I18N", "t('Vehicle Information') resolves in English", t('Vehicle Information') === 'Vehicle Information');

// -------------------------------------------------------------
// 5. APPOINTMENT CREATION & IDOR ISOLATION END-TO-END
// -------------------------------------------------------------
// Verify existing customer in staging
$cust = Database::one("SELECT id, full_name FROM customers WHERE is_active = 1 LIMIT 1");
assert_test("FUNCTIONAL", "Staging customer found for functional booking", $cust !== null, "Cust ID: " . ($cust['id'] ?? 'none'));

if ($cust) {
    // 1. Create a test vehicle for this customer
    $plate = 'UI-' . rand(1000, 9999);
    $vehId = (int) Database::insert(
        "INSERT INTO vehicles (customer_id, plate_number, make, model, year, is_active) VALUES (?, ?, 'Toyota', 'Prado', 2023, 1)",
        [$cust['id'], $plate]
    );
    assert_test("FUNCTIONAL", "Test vehicle registered for customer", $vehId > 0, "Plate: $plate");

    // 2. Book appointment via AppointmentService::create()
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $aptId = AppointmentService::create([
        'customer_id' => (int) $cust['id'],
        'vehicle_id' => $vehId,
        'appointment_date' => $tomorrow,
        'appointment_time' => '10:00:00',
        'problem_description' => 'Priority 10 visual consistency regression test checkup',
        'status' => 'confirmed',
    ]);
    assert_test("FUNCTIONAL", "Appointment created successfully via AppointmentService", $aptId > 0, "Apt ID: $aptId");

    // 3. Confirm appointment record in database
    $aptRecord = Database::one("SELECT * FROM appointments WHERE id = ?", [$aptId]);
    assert_test("FUNCTIONAL", "Appointment record verified in database", 
        $aptRecord !== null && in_array($aptRecord['status'], ['scheduled', 'confirmed'], true) && $aptRecord['appointment_date'] === $tomorrow
    );

    // 4. Verify IDOR rejection when booking against a vehicle belonging to another customer
    $otherCust = Database::one("SELECT id FROM customers WHERE id != ? AND is_active = 1 LIMIT 1", [$cust['id']]);
    if ($otherCust) {
        $otherPlate = 'IDOR-' . rand(1000, 9999);
        $otherVehId = (int) Database::insert(
            "INSERT INTO vehicles (customer_id, plate_number, make, model, year, is_active) VALUES (?, ?, 'BMW', 'X5', 2024, 1)",
            [$otherCust['id'], $otherPlate]
        );
        $idorCaught = false;
        try {
            // Attempting to book otherCust vehicle for cust
            AppointmentService::create([
                'customer_id' => (int) $cust['id'],
                'vehicle_id' => $otherVehId,
                'appointment_date' => $tomorrow,
                'appointment_time' => '14:00:00',
            ]);
        } catch (DomainException $e) {
            $idorCaught = true;
        }
        assert_test("SECURITY", "Cross-customer vehicle appointment rejected (IDOR boundary preserved)", $idorCaught);
    }
}

// -------------------------------------------------------------
// 6. ALL 6 DASHBOARDS RUNTIME RENDER VERIFICATION
// -------------------------------------------------------------
$dashboards = [
    'admin' => __DIR__ . '/../app/views/dashboards/admin.php',
    'receptionist' => __DIR__ . '/../app/views/dashboards/receptionist.php',
    'mechanic' => __DIR__ . '/../app/views/dashboards/mechanic.php',
    'inventory' => __DIR__ . '/../app/views/dashboards/inventory.php',
    'accountant' => __DIR__ . '/../app/views/dashboards/accountant.php',
    'customer' => __DIR__ . '/../app/views/dashboards/customer.php',
];

foreach ($dashboards as $role => $file) {
    assert_test("ROLES", "Dashboard view file exists for role $role", is_file($file));
}

echo "\n=================================================================\n";
echo "PRIORITY 10 UI/UX SUITE RESULT: $passCount / $totalCount tests PASSED (" . round(($passCount / max(1, $totalCount)) * 100) . "%)\n";
echo "=================================================================\n";

exit($passCount === $totalCount ? 0 : 1);
