<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — INTERNATIONALIZATION & LOCALIZATION ENGINE (i18n)
 * Provides comprehensive bilingual support for English ('en') and Amharic ('am').
 *
 * Requirements:
 * - Fail-closed: Fallback gracefully to default/English text.
 * - Persistent: Stored in session and cookie.
 * - Accessible: Proper lang attributes and font rendering for Ethiopic script.
 */

if (!function_exists('supported_languages')) {
    function supported_languages(): array
    {
        return ['en', 'am'];
    }
}

if (!function_exists('init_language')) {
    function init_language(): string
    {
        $lang = 'en';

        // 1. Check explicit URL query parameter ?lang=en or ?lang=am
        if (isset($_GET['lang']) && is_string($_GET['lang'])) {
            $candidate = strtolower(trim($_GET['lang']));
            if (in_array($candidate, supported_languages(), true)) {
                $lang = $candidate;
                if (session_status() === PHP_SESSION_ACTIVE) {
                    $_SESSION['lang'] = $lang;
                    $_SESSION['gms_lang'] = $lang;
                }
                $_COOKIE['GMS_LANG'] = $lang;
                $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
                if (!headers_sent()) {
                    setcookie('GMS_LANG', $lang, [
                        'expires' => time() + 31536000,
                        'path' => '/',
                        'secure' => $https,
                        'httponly' => false,
                        'samesite' => 'Lax',
                    ]);
                }
                return $lang;
            }
        }

        // 2. Check active session
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['lang']) && in_array($_SESSION['lang'], supported_languages(), true)) {
            $lang = (string) $_SESSION['lang'];
            $_COOKIE['GMS_LANG'] = $lang;
            return $lang;
        }

        // 3. Check persistent cookie
        if (!empty($_COOKIE['GMS_LANG']) && in_array($_COOKIE['GMS_LANG'], supported_languages(), true)) {
            $lang = (string) $_COOKIE['GMS_LANG'];
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['lang'] = $lang;
                $_SESSION['gms_lang'] = $lang;
            }
            return $lang;
        }

        return 'en';
    }
}

if (!function_exists('current_lang')) {
    function current_lang(): string
    {
        static $cached = null;
        if (isset($_GET['lang'])) {
            $cached = init_language();
        } elseif ($cached === null) {
            $cached = init_language();
        }
        return $cached;
    }
}

if (!function_exists('is_amharic')) {
    function is_amharic(): bool
    {
        return current_lang() === 'am';
    }
}

if (!function_exists('lang_switch_url')) {
    function lang_switch_url(string $targetLang): string
    {
        $targetLang = in_array($targetLang, supported_languages(), true) ? $targetLang : 'en';
        $params = $_GET ?? [];
        $params['lang'] = $targetLang;

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $path = parse_url($uri, PHP_URL_PATH);
        if (!$path || $path === '') {
            $path = url('index.php');
        } elseif (substr($path, -1) === '/') {
            $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
            if ($script !== '' && str_ends_with($script, '.php')) {
                $path = $script;
            } else {
                $path .= 'index.php';
            }
        }

        $cleanPath = preg_replace('#/+#', '/', (string)$path);
        $query = http_build_query($params);
        return $cleanPath . ($query !== '' ? '?' . $query : '');
    }
}

if (!function_exists('lang_switcher_html')) {
    function lang_switcher_html(string $extraClass = ''): string
    {
        $cur = current_lang();
        $enActive = $cur === 'en' ? ' active' : '';
        $amActive = $cur === 'am' ? ' active' : '';
        $enUrl = htmlspecialchars(lang_switch_url('en'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $amUrl = htmlspecialchars(lang_switch_url('am'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<div class="lang-switcher-pill ' . htmlspecialchars($extraClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" role="group" aria-label="Language selection">'
             . '<a href="' . $enUrl . '" class="lang-switch-btn' . $enActive . '" data-lang="en" title="English 🇬🇧" aria-pressed="' . ($cur === 'en' ? 'true' : 'false') . '">EN</a>'
             . '<span class="lang-switch-sep" aria-hidden="true">|</span>'
             . '<a href="' . $amUrl . '" class="lang-switch-btn' . $amActive . '" data-lang="am" title="አማርኛ 🇪🇹 (Amharic)" aria-pressed="' . ($cur === 'am' ? 'true' : 'false') . '">አማ</a>'
             . '</div>';
    }
}

if (!function_exists('load_language_file')) {
    function load_language_file(string $lang): array
    {
        static $cache = [];
        if (isset($cache[$lang])) {
            return $cache[$lang];
        }

        $langFile = defined('APP_ROOT') ? APP_ROOT . "/app/lang/{$lang}.php" : dirname(__DIR__) . "/lang/{$lang}.php";
        if (is_file($langFile)) {
            $data = require $langFile;
            if (is_array($data)) {
                $cache[$lang] = $data;
                return $data;
            }
        }

        $cache[$lang] = [];
        return [];
    }
}

if (!function_exists('amharic_dictionary')) {
    function amharic_dictionary(): array
    {
        static $dict = null;
        if ($dict !== null) {
            return $dict;
        }

        // Load structured amharic dictionary file
        $fileDict = load_language_file('am');

        // Legacy & literal phrase lookup table (maps English phrases directly to Amharic)
        $legacy = [
            // Branding & Top-Level
            'Alpha Garage' => 'አልፋ ጋራዥ',
            'Garage Management' => 'የጋራዥ አስተዳደር',
            'Garage Management System' => 'የጋራዥ አስተዳደር ሥርዓት',
            'Luxury Automotive Care' => 'የቅንጦት ተሽከርካሪዎች እንክብካቤ',
            'Back to Home' => 'ወደ መነሻ',
            'Back to Homepage' => 'ወደ መነሻ ገጽ ተመለስ',
            'Back to Sign In' => 'ወደ መግቢያ ተመለስ',
            'Sign In' => 'ግባ',
            'Sign Out' => 'ውጣ',
            'Sign in' => 'ግባ',
            'Sign out' => 'ውጣ',
            'Client sign in' => 'የደንበኛ መግቢያ',
            'Get Started' => 'አሁኑኑ ጀምር',
            'Register' => 'ተመዝገብ',
            'Create Account' => 'መለያ ፍጠር',
            'Create an account' => 'መለያ ይፍጠሩ',
            'Dashboard' => 'ዳሽቦርድ',
            'My Dashboard' => 'የእኔ ዳሽቦርድ',
            'My Account' => 'የእኔ መለያ',
            'Account' => 'መለያ',
            'Profile' => 'መገለጫ',
            'My profile' => 'የእኔ መገለጫ',
            'My profile & password' => 'የእኔ መገለጫ እና የይለፍ ቃል',
            'My profile and password' => 'የእኔ መገለጫ እና የይለፍ ቃል',
            'Account details and password' => 'የመለያ ዝርዝሮች እና የይለፍ ቃል',
            'Change password' => 'የይለፍ ቃል ቀይር',
            'Current password' => 'የአሁኑ የይለፍ ቃል',
            'New password' => 'አዲስ የይለፍ ቃል',
            'Confirm new password' => 'አዲሱን የይለፍ ቃል አረጋግጥ',
            'Update password' => 'የይለፍ ቃል አዘምን',
            'Full Name' => 'ሙሉ ስም',
            'Username' => 'የተጠቃሚ ስም',
            'Email' => 'ኢሜይል',
            'Customer' => 'ደንበኛ',
            'Client' => 'ደንበኛ',
            'Secure Portal' => 'ደህንነቱ የተጠበቀ ፖርታል',
            'Welcome' => 'እንኳን ደህና መጡ',
            'Welcome back' => 'እንኳን ደህና መጡ',

            // Navigation & Groups
            'My Garage' => 'የእኔ ጋራዥ',
            'Repair Orders' => 'የጥገና ትዕዛዞች',
            'Link Past Records' => 'የቀደሙ መዝገቦችን አገናኝ',
            'Overview' => 'አጠቃላይ እይታ',
            'Workshop' => 'ዎርክሾፕ',
            'Inventory' => 'ዕቃዎች ክምችት',
            'Finance' => 'ፋይናንስ',
            'Billing' => 'የክፍያ መጠየቂያዎች',
            'Administration' => 'አስተዳደር',
            'Home' => 'መነሻ',
            'About' => 'ስለ እኛ',
            'About Us' => 'ስለ እኛ',
            'About & FAQ' => 'ስለ እኛ እና ጥያቄዎች',
            'Services' => 'አገልግሎቶች',
            'Our Services' => 'አገልግሎቶቻችን',
            'How It Works' => 'እንዴት እንደሚሰራ',
            'Contact' => 'ያግኙን',
            'Contact Us' => 'ያግኙን',
            'Book Service' => 'አገልግሎት ያስይዙ',
            'Book Appointment' => 'ቀጠሮ ያስይዙ',
            'Appointments' => 'ቀጠሮዎች',
            'Customers' => 'ደንበኞች',
            'Vehicles' => 'ተሽከርካሪዎች',
            'Job Cards' => 'የሥራ ካርዶች',
            'My Jobs' => 'የእኔ ሥራዎች',
            'My job cards' => 'የእኔ የሥራ ካርዶች',
            'My Job Cards' => 'የእኔ የሥራ ካርዶች',
            'Mechanics' => 'መካኒኮች',
            'Suppliers' => 'አቅራቢዎች',
            'Invoices' => 'ደረሰኞች',
            'Payments' => 'ክፍያዎች',
            'Expenses' => 'ወጪዎች',
            'Reports' => 'ሪፖርቶች',
            'Users' => 'ተጠቃሚዎች',
            'Roles & Access' => 'ሚናዎች እና ፈቃዶች',
            'Garage Settings' => 'የጋራዥ ቅንብሮች',
            'Audit Logs' => 'የቁጥጥር መዝገቦች',
            'Privacy Policy' => 'የግላዊነት ፖሊሲ',
            'Terms of Service' => 'የአገልግሎት ውሎች',

            // Role Dashboard Headers
            'Command Center' => 'ዋና የቁጥጥር ማዕከል',
            'Financial & Operations Command Center' => 'የፋይናንስ እና የሥራዎች ዋና መቆጣጠሪያ ማዕከል',
            'Front Desk Operations' => 'የፊት ጠረጴዛ ሥራዎች',
            'Front Desk & Workshop Intake' => 'የፊት ጠረጴዛ እና የተሽከርካሪ መቀበያ',
            'Technician Workstation' => 'የቴክኒሻን የሥራ ጣቢያ',
            'Workstation' => 'የሥራ ጣቢያ',
            'Parts & Stock Management' => 'የዕቃዎች እና ክምችት አስተዳደር',
            'Stock Control' => 'የዕቃዎች ቁጥጥር',
            'Financial Operations & Ledger' => 'የፋይናንስ ሥራዎች እና ሒሳብ መዝገብ',
            'Accounts Receivable' => 'ተሰብሳቢ ሒሳቦች',
            'VIP Client Services' => 'ቪአይፒ የደንበኞች አገልግሎት',
            'Customer Portal' => 'የደንበኛ ፖርታል',

            // Customer Portal Specific Phrases
            'Customer Portal' => 'የደንበኞች ፖርታል',
            'VIP Client Services' => 'ቪአይፒ የደንበኞች አገልግሎት',
            'VIP Client Portal' => 'ቪአይፒ የደንበኞች ፖርታል',
            'ACCOUNT OVERVIEW' => 'የመለያ አጠቃላይ እይታ',
            'Live fleet & service status' => 'የቀጥታ ተሽከርካሪዎች እና የአገልግሎት ሁኔታ',
            'Fleet overview & workshop status' => 'የተሽከርካሪዎች አጠቃላይ እይታ እና የዎርክሾፕ ሁኔታ',
            'Real-time overview of your registered vehicles and workshop activity' => 'የተመዘገቡ ተሽከርካሪዎችዎ እና የዎርክሾፕ እንቅስቃሴ የቀጥታ እይታ',
            'WORKSTATION METRICS' => 'የሥራ ጣቢያ መለኪያዎች',
            '+ Book New Service' => '+ አዲስ አገልግሎት ያስይዙ',
            '+ Book service' => '+ አገልግሎት ያስይዙ',
            'In-Person Service Records:' => 'በአካል የተከናወኑ የአገልግሎት መዝገቦች፡',
            'Previously visited Alpha Garage in person? Request a secure link to connect past service records and vehicles matching your verified email.' => 'ቀደም ሲል በአካል አልፋ ጋራዥን ጎብኝተዋል? ከተረጋገጠው ኢሜይልዎ ጋር የሚዛመዱ የቀደሙ የአገልግሎት መዝገቦችን እና ተሽከርካሪዎችን ለማገናኘት ደህንነቱ የተጠበቀ ማገናኛ ይጠይቁ።',
            'Check & Link Records' => 'መዝገቦችን ፈትሽ እና አገናኝ',
            'Vehicles in your garage fleet' => 'በእርስዎ ጋራዥ ውስጥ ያሉ ተሽከርካሪዎች',
            'Active vehicles' => 'ንቁ ተሽከርካሪዎች',
            'Scheduled workshop visits' => 'የታቀዱ የዎርክሾፕ ጉብኝቶች',
            'Confirmed / Scheduled' => 'የተረጋገጡ / የታቀዱ',
            'Vehicles currently in workshop' => 'በአሁኑ ጊዜ በዎርክሾፕ ውስጥ ያሉ ተሽከርካሪዎች',
            'All repairs complete' => 'ሁሉም ጥገናዎች ተጠናቀዋል',
            'Under repair' => 'በጥገና ላይ',
            'Ready' => 'ዝግጁ',
            'Pending invoice payments' => 'ያልተከፈሉ የደረሰኝ ክፍያዎች',
            'All accounts settled' => 'ሁሉም ሒሳቦች ተከፍለዋል',
            'Payment due' => 'ክፍያ የሚጠበቅበት',
            'Settled' => 'የተከፈለ',
            'SERVICE ANALYTICS' => 'የአገልግሎት ትንታኔ',
            'Your vehicle service history' => 'የተሽከርካሪዎ የአገልግሎት ታሪክ',
            'Repair stages and 6-month scheduled maintenance timeline' => 'የጥገና ደረጃዎች እና የ6 ወራት የታቀደ የጥገና የጊዜ ሰሌዳ',
            'Repair Order Status' => 'የጥገና ትዕዛዝ ሁኔታ',
            'Distribution across repair stages' => 'የጥገና ደረጃዎች ስርጭት',
            '6-Month Service Activity' => 'የ6 ወራት የአገልግሎት እንቅስቃሴ',
            'Monthly workshop checkups & repairs' => 'ወርሃዊ የዎርክሾፕ ምርመራዎች እና ጥገናዎች',
            'No Service History Yet' => 'እስካሁን ምንም የአገልግሎት ታሪክ የለም',
            'Once your vehicle is serviced or diagnosed at Alpha Garage, your real-time repair progress and maintenance history charts will appear here.' => 'ተሽከርካሪዎ በአልፋ ጋራዥ ውስጥ አገልግሎት ወይም ምርመራ ከተደረገለት በኋላ፣ የቀጥታ የጥገና ሂደት እና የጥገና ታሪክ ገበታዎች እዚህ ይታያሉ።',
            'Schedule First Service' => 'የመጀመሪያውን አገልግሎት ቀጠሮ ይያዙ',
            'My Vehicles' => 'የእኔ ተሽከርካሪዎች',
            'Vehicles registered for maintenance' => 'ለጥገና የተመዘገቡ ተሽከርካሪዎች',
            'Plate No.' => 'የሰሌዳ ቁጥር',
            'Make & Model' => 'ዓይነት እና ሞዴል',
            'Fuel' => 'የነዳጅ ዓይነት',
            'Action' => 'እርምጃ',
            'Petrol' => 'ቤንዚን',
            'Diesel' => 'ናፍጣ',
            'Hybrid' => 'ሃይብሪድ',
            'Electric' => 'ኤሌክትሪክ',
            'Book visit' => 'ቀጠሮ ይያዙ',
            'Book Premium Service Visit' => 'ፕሪሚየም የአገልግሎት ጉብኝት ያስይዙ',
            'Schedule certified technician inspection & maintenance' => 'የተመሰከረላቸው የቴክኒሺያን ምርመራ እና ጥገና ያቅዱ',
            'Vehicle Information' => 'የተሽከርካሪ መረጃ',
            'Appointment Information' => 'የቀጠሮ መረጃ',
            'Additional Information' => 'ተጨማሪ መረጃ',
            'Choose from vehicles linked to your VIP client profile.' => 'ከእርስዎ የቪአይፒ ደንበኛ መለያ ጋር ከተገናኙ ተሽከርካሪዎች ይምረጡ።',
            'Select a standard maintenance package or request general technician diagnosis.' => 'መደበኛ የጥገና ጥቅል ይምረጡ ወይም አጠቃላይ የቴክኒሺያን ምርመራ ይጠይቁ።',
            'Help our certified technicians prepare needed tools and parts prior to arrival.' => 'የእኛ የተመሰከረላቸው ቴክኒሻኖች ከመድረሳቸው በፊት አስፈላጊ መሳሪያዎችን እና መለዋወጫዎችን እንዲያዘጋጁ ያግዙ።',
            'My Registered Vehicle' => 'የእኔ የተመዘገበ ተሽከርካሪ',
            '+ Add New Vehicle' => '+ አዲስ ተሽከርካሪ ጨምር',
            'Select Your Vehicle' => 'ተሽከርካሪዎን ይምረጡ',
            'License Plate' => 'የሰሌዳ ቁጥር',
            'Make' => 'የመኪናው ስም (Make)',
            'Model' => 'ሞዴል (Model)',
            'Year' => 'የተመረተበት ዓመት',
            'Requested Service Package' => 'የተጠየቀው የአገልግሎት ጥቅል',
            '-- General Inspection & Diagnosis --' => '-- አጠቃላይ ምርመራ እና ዲያግኖስቲክስ --',
            'Preferred Date' => 'የሚመረጥበት ቀን',
            'Preferred Time' => 'የሚመረጥበት ሰዓት',
            'Notes / Specific Concerns' => 'ማስታወሻዎች / ልዩ ስጋቶች',
            'Describe any symptoms, dashboard warning lights, or requested checks...' => 'ምልክቶችን፣ የዳሽቦርድ ማስጠንቀቂያ መብራቶችን ወይም የሚፈለጉ ምርመራዎችን ይግለጹ...',
            'Confirm & Book Service' => 'አረጋግጥ እና አገልግሎት ያዝ',
            'Your service appointment has been booked successfully! Our workshop reception team has been notified.' => 'የአገልግሎት ቀጠሮዎ በተሳካ ሁኔታ ተይዟል! የዎርክሾፕ መቀበያ ቡድናችን ማሳወቂያ ደርሶታል።',
            'Please select or register a vehicle for the appointment.' => 'እባክዎ ለቀጠሮው ተሽከርካሪ ይምረጡ ወይም አዲስ ይመዝግቡ።',
            'Appointments must be scheduled for today or a future date.' => 'ቀጠሮዎች ለዛሬ ወይም ለወደፊት ቀን መያዝ አለባቸው።',
            'Please select a preferred date and time.' => 'እባክዎ የሚመረጥበትን ቀን እና ሰዓት ይምረጡ።',
            'A vehicle with this plate number is already registered under another account.' => 'በዚህ የሰሌዳ ቁጥር የተመዘገበ ተሽከርካሪ በሌላ መለያ ተመዝግቧል።',
            'Selected vehicle does not belong to your account.' => 'የተመረጠው ተሽከርካሪ የእርስዎ መለያ አይደለም።',
            'Or open full booking page' => 'ወይም ሙሉውን የማስያዣ ገጽ ይክፈቱ',
            'No vehicles registered yet.' => 'እስካሁን ምንም የተመዘገበ ተሽከርካሪ የለም።',
            'Register a vehicle with your first booking' => 'በመጀመሪያው ቀጠሮዎ ተሽከርካሪ ይመዝግቡ',
            'Service Appointments' => 'የአገልግሎት ቀጠሮዎች',
            'Scheduled checkups and workshop bookings' => 'የታቀዱ ምርመራዎች እና የዎርክሾፕ ቀጠሮዎች',
            'Booking #' => 'የቀጠሮ ቁጥር',
            'Date & Time' => 'ቀን እና ሰዓት',
            'General Service' => 'አጠቃላይ አገልግሎት',
            'No service appointments on record.' => 'በመዝገብ ላይ ምንም የአገልግሎት ቀጠሮ የለም።',
            'Workshop Repair Status' => 'የዎርክሾፕ ጥገና ሁኔታ',
            'Live progress of your vehicle service orders' => 'የተሽከርካሪዎ የአገልግሎት ትዕዛዞች የቀጥታ ሂደት',
            'Job Card' => 'የሥራ ካርድ',
            'Reported Issue' => 'የተገለጸው ችግር',
            'Progress Stage' => 'የሂደት ደረጃ',
            'No vehicle repair job cards found for your account.' => 'ለመለያዎ ምንም የተሽከርካሪ ጥገና ሥራ ካርዶች አልተገኙም።',
            'Billing & Invoices' => 'የክፍያ መጠየቂያዎች እና ደረሰኞች',
            'Service statements and settlement receipts' => 'የአገልግሎት መግለጫዎች እና የክፍያ ደረሰኞች',
            'Invoice No.' => 'የደረሰኝ ቁጥር',
            'Issue Date' => 'የተሰጠበት ቀን',
            'By category' => 'በምድብ',
            'Status distribution' => 'የሁኔታ ስርጭት',
            'Units by category (active stock)' => 'ክፍሎች በምድብ (ንቁ ክምችት)',
            'Most requested services and revenue' => 'በብዛት የተጠየቁ አገልግሎቶች እና ገቢ',
            'No expense records in this period.' => 'በዚህ ጊዜ ውስጥ ምንም የወጪ መዝገብ የለም።',
            'No active inventory records.' => 'ምንም ንቁ የዕቃ መዝገብ የለም።',
            'No job service records in this period.' => 'በዚህ ጊዜ ውስጥ ምንም የሥራ አገልግሎት መዝገብ የለም።',
            'INVENTORY' => 'የዕቃ ክምችት',
            'Manage inventory' => 'የዕቃ ክምችት አስተዳድር',
            'left (reorder at' => 'ቀሪ (ማዘዣ በ',
            'Based on issued (non-returned) job parts' => 'ለሥራ በተሰጡ (ያልተመለሱ) መለዋወጫዎች ላይ የተመሠረተ',
            'issued' => 'የወጣ',
            'All inventory items are currently above safety stock thresholds.' => 'ሁሉም የዕቃ ክምችቶች በአሁኑ ጊዜ ከአስተማማኝ የክምችት መጠን በላይ ናቸው።',
            'Workshop jobs' => 'የዎርክሾፕ ሥራዎች',
            'part requests' => 'የዕቃ ጥያቄዎች',
            '0 pending' => '0 በመጠባበቅ ላይ',
            'Issue parts' => 'መለዋወጫዎችን አውጣ',
            'No workshop jobs currently waiting for parts.' => 'በአሁኑ ጊዜ መለዋወጫ የሚጠብቅ የዎርክሾፕ ሥራ የለም።',
            'Inventory ledger' => 'የዕቃ ክምችት መዝገብ',
            'Reference / Notes' => 'ማጣቀሻ / ማስታወሻዎች',
            'Purchase / Stock In' => 'ግዢ / ገቢ ዕቃ',
            'Issued to Job' => 'ለሥራ የወጣ',
            'Returned from Job' => 'ከሥራ የተመለሰ',
            'Adjustment (+)' => 'ማስተካከያ (+)',
            'Adjustment (-)' => 'ማስተካከያ (-)',
            'No inventory transaction records found.' => 'ምንም የዕቃ ልውውጥ መዝገቦች አልተገኙም።',
            'No payment transactions recorded yet.' => 'እስካሁን የተመዘገበ የክፍያ ልውውጥ የለም።',
            'Expense ledger' => 'የወጪ መዝገብ',
            'Vendor / Notes' => 'ሻጭ / ማስታወሻዎች',
            'No operating expense entries found.' => 'ምንም የሥራ ማስኬጃ ወጪዎች አልተገኙም።',
            'Expense ledger module is not enabled.' => 'የወጪ መዝገብ ሞጁል አልነቃም።',
            'Support & Telegram' => 'ድጋፍ እና ቴሌግራም',
            'Create an Account' => 'አካውንት ይፍጠሩ',
            'Register with :garage for precision service' => 'ለተሟላ እና ጥራት ላለው አገልግሎት በ:garage ይመዝገቡ',
            'Choose a unique username' => 'ልዩ የተጠቃሚ ስም ይምረጡ',
            'Minimum 10 characters with numbers & symbols' => 'ቢያንስ 10 ፊደላት ከቁጥሮች እና ምልክቶች ጋር',
            'Re-enter your password' => 'የይለፍ ቃልዎን በድጋሚ ያስገቡ',
            'I have read and agree to the' => 'አንብቤ ተስማምቻለሁ ከ',
            'and' => 'እና',
            'Work on job' => 'ሥራ ጀምር',
            'No active repair jobs assigned to your workstation at this time.' => 'በአሁኑ ጊዜ ለሥራ ጣቢያዎ የተመደበ ምንም ንቁ የጥገና ሥራ የለም።',
            'Completed history' => 'የተጠናቀቁ ታሪክ',
            'Review' => 'ይገምግሙ',
            'No completed job history recorded yet.' => 'እስካሁን የተመዘገበ የተጠናቀቀ የሥራ ታሪክ የለም።',
            'No active repair job cards at this time.' => 'በአሁኑ ጊዜ ምንም ንቁ የጥገና የሥራ ካርዶች የሉም።',
            'Customer directory' => 'የደንበኞች ማውጫ',
            'Revenue, expenses, net profit' => 'ገቢ፣ ወጪዎች፣ የተጣራ ትርፍ',
            'Receipts, job issuances, and audit adjustments' => 'ገቢ ዕቃዎች፣ ለሥራ የተሰጡ እና የኦዲት ማስተካከያዎች',
            'Parts depleted or below safety threshold' => 'ያለቁ ወይም ከአስተማማኝ ወለል በታች የሆኑ ዕቃዎች',
            'Total' => 'ጠቅላላ',
            'Balance Due' => 'ቀሪ ዕዳ',
            'No invoices issued for your account yet.' => 'እስካሁን ለመለያዎ የተሰጠ ምንም ደረሰኝ የለም።',
            'Confirm Service History Linking' => 'የአገልግሎት ታሪክ ማገናኘትን ያረጋግጡ',
            'Link Past In-Person Service Records' => 'ያለፉትን በአካል የተደረጉ የአገልግሎት መዝገቦች አገናኝ',
            'Connect your historical garage visits and vehicles to your portal account.' => 'ቀደም ሲል ያደረጓቸውን የጋራዥ ጉብኝቶች እና ተሽከርካሪዎች ከፖርታል መለያዎ ጋር ያገናኙ።',
            'Verification Failed:' => 'ማረጋገጫው አልተሳካም፡',
            'Verification links expire after 30 minutes, are single-use, and are bound to your verified email address. If your link expired or was issued to another account, you can request a fresh link from your portal.' => 'የማረጋገጫ ማገናኛዎች ከ30 ደቂቃዎች በኋላ ጊዜያቸው ያልፋል፣ ለአንድ ጊዜ ብቻ የሚያገለግሉ ናቸው፣ እና ከተረጋገጠው የኢሜይል አድራሻዎ ጋር የተያያዙ ናቸው። ማገናኛዎ ጊዜው ካለፈበት ወይም ለሌላ መለያ የተሰጠ ከሆነ፣ ከፖርታልዎ አዲስ ማገናኛ መጠየቅ ይችላሉ።',
            'Return to Customer Portal' => 'ወደ ደንበኛ ፖርታል ተመለስ',
            'You are currently signed in as' => 'በአሁኑ ጊዜ የገቡት እንደ',
            'Click Confirm & Link Service Records below to connect in-person service history and vehicle records matching your verified email to this account.' => 'ከተረጋገጠው ኢሜይልዎ ጋር የሚዛመዱ የአካል አገልግሎት ታሪክን እና የተሽከርካሪ መዝገቦችን ከዚህ መለያ ጋር ለማገናኘት ከታች ያለውን የአገልግሎት መዝገቦችን አረጋግጥ እና አገናኝ የሚለውን ይጫኑ።',
            'Confirm & Link Service Records' => 'የአገልግሎት መዝገቦችን አረጋግጥ እና አገናኝ',
            'Failed to link service history.' => 'የአገልግሎት ታሪክን ማገናኘት አልተሳካም።',
            'Customer service history linking is reserved for client accounts.' => 'የደንበኛ አገልግሎት ታሪክ ማገናኘት ለደንበኛ መለያዎች ብቻ የተጠበቀ ነው።',
            'Too many linking requests. Please check your inbox or try again in %d minute(s).' => 'በጣም ብዙ የማገናኘት ጥያቄዎች። እባክዎ የኢሜይል መልእክት ሳጥንዎን ያረጋግጡ ወይም ከ %d ደቂቃ(ቶች) በኋላ እንደገና ይሞክሩ።',
            'No verification token was provided. Please check the link in your email or request a link from your portal.' => 'ምንም የማረጋገጫ ማስረጃ አልቀረበም። እባክዎ በኢሜይልዎ ያለውን ማገናኛ ያረጋግጡ ወይም ከፖርታልዎ አዲስ ማገናኛ ይጠይቁ።',
            'Invalid or expired verification token.' => 'ልክ ያልሆነ ወይም ጊዜው ያለፈበት የማረጋገጫ ማስረጃ።',
            'If in-person service history matching your verified email exists, a verification link has been dispatched to your inbox. Please check your email.' => 'ከተረጋገጠው ኢሜይልዎ ጋር የሚዛመድ የአካል አገልግሎት ታሪክ ካለ፣ የማረጋገጫ ማገናኛ ወደ ኢሜይል ሳጥንዎ ተልኳል። እባክዎ ኢሜይልዎን ያረጋግጡ።',
            'This verification token has expired. Please request a new verification link.' => 'ይህ የማረጋገጫ ማስረጃ ጊዜው አልፎበታል። እባክዎ አዲስ የማረጋገጫ ማገናኛ ይጠይቁ።',
            'Security Error: This verification token was issued to a different account.' => 'የደህንነት ስህተት፡ ይህ የማረጋገጫ ማስረጃ ለሌላ መለያ የተሰጠ ነው።',
            'The target customer record is no longer active.' => 'የታለመው የደንበኛ መዝገብ ከአሁን በኋላ ንቁ አይደለም።',
            'This customer record has already been linked to an account.' => 'ይህ የደንበኛ መዝገብ አስቀድሞ ከአንድ መለያ ጋር ተገናኝቷል።',
            'Your account email no longer matches the customer record email.' => 'የመለያዎ ኢሜይል ከአሁን በኋላ ከደንበኛው መዝገብ ኢሜይል ጋር አይዛመድም።',
            'Concurrent linking collision: customer record was claimed by another request.' => 'የማገናኘት ግጭት ተከስቷል፡ የደንበኛው መዝገብ በሌላ ጥያቄ ተይዟል።',
            'Your in-person service history and vehicle records have been successfully linked to your account.' => 'የቀደመ የአካል አገልግሎት ታሪክዎ እና የተሽከርካሪ መዝገቦችዎ በተሳካ ሁኔታ ከመለያዎ ጋር ተገናኝተዋል።',
            'A valid verified email address is required to link records.' => 'መዝገቦችን ለማገናኘት ትክክለኛ የተረጋገጠ የኢሜይል አድራሻ ያስፈልጋል።',
            'Describe any symptoms, noises, or specific maintenance requested...' => 'ማናቸውንም ምልክቶች፣ ያልተለመዱ ድምፆች ወይም የሚፈለገውን ልዩ ጥገና ይግለጹ...',
            'Service Booking Portal' => 'የአገልግሎት ማስያዣ ፖርታል',
            'Schedule your vehicle maintenance or repair visit with our certified technicians.' => 'የተሽከርካሪዎን ጥገና ከተመሰከረላቸው ቴክኒሻኖቻችን ጋር ቀጠሮ ይያዙ።',
            'Staff Workshop Board' => 'የሠራተኞች ዎርክሾፕ ቦርድ',
            'Schedule Your Appointment' => 'ቀጠሮዎን ያስይዙ',
            'Select Vehicle' => 'ተሽከርካሪ ይምረጡ',
            'No vehicles currently registered on your account. Please enter your vehicle details below:' => 'በመለያዎ ላይ የተመዘገበ ተሽከርካሪ የለም። እባክዎ ከታች የተሽከርካሪዎን ዝርዝር ያስገቡ፡',
            'Plate Number' => 'የሰሌዳ ቁጥር',
            'Make' => 'ዓይነት',
            'Model' => 'ሞዴል',
            'Desired Service Package' => 'የሚፈለገው የአገልግሎት ጥቅል',
            'General Checkup / Workshop Inspection' => 'አጠቃላይ ምርመራ / የዎርክሾፕ ፍተሻ',
            'Preferred Date' => 'ተመራጭ ቀን',
            'Preferred Time Slot' => 'ተመራጭ የሰዓት ክፍለ ጊዜ',
            'Issue Description / Special Requests' => 'የችግሩ መግለጫ / ልዩ ጥያቄዎች',
            'Confirm & Book Appointment' => 'ቀጠሮውን አረጋግጥ እና ያዝ',
            'My Recent Bookings' => 'የቅርብ ጊዜ ቀጠሮዎቼ',
            'You have no previous service bookings on file.' => 'በመዝገብ ላይ ምንም ያለፈ የአገልግሎት ቀጠሮ የለዎትም።',
            'Need Immediate Roadside Assistance?' => 'አስቸኳይ የመንገድ ላይ እርዳታ ይፈልጋሉ?',
            'For urgent repairs or towing, contact our 24/7 emergency dispatch directly.' => 'ለአስቸኳይ ጥገና ወይም ጎታች መኪና፣ በቀጥታ የ24/7 የአስቸኳይ ጊዜ ክፍላችንን ያነጋግሩ።',
            'Booking Details' => 'የቀጠሮ ዝርዝሮች',
            'Vehicle' => 'ተሽከርካሪ',
            'Service' => 'አገልግሎት',
            'Scheduled Date' => 'የቀጠሮ ቀን',
            'Customer Name' => 'የደንበኛ ስም',
            'Notes / Description' => 'ማስታወሻዎች / መግለጫ',
            'Book Another Appointment' => 'ሌላ ቀጠሮ ያስይዙ',
            'Your appointment has been booked successfully! Our workshop reception team has been notified.' => 'ቀጠሮዎ በተሳካ ሁኔታ ተይዟል! የዎርክሾፕ መቀበያ ቡድናችን ተሳውቋል።',
            'Please select a valid booking date.' => 'እባክዎ ትክክለኛ የቀጠሮ ቀን ይምረጡ።',
            'Appointments must be scheduled for today or a future date.' => 'ቀጠሮዎች ለዛሬ ወይም ወደፊት ላለ ቀን መያዝ አለባቸው።',
            'Please select an appointment time slot.' => 'እባክዎ የቀጠሮ ሰዓት ይምረጡ።',
            'Vehicle license plate number is required.' => 'የተሽከርካሪው የሰሌዳ ቁጥር ያስፈልጋል።',
            'Vehicle make is required.' => 'የተሽከርካሪው ዓይነት (Make) ያስፈልጋል።',
            'Vehicle model is required.' => 'የተሽከርካሪው ሞዴል ያስፈልጋል።',
            'This license plate number is registered to another account.' => 'ይህ የሰሌዳ ቁጥር ለሌላ መለያ ተመዝግቧል።',
            'Please select a registered vehicle.' => 'እባክዎ የተመዘገበ ተሽከርካሪ ይምረጡ።',
            'Security rejection: The selected vehicle does not belong to your account.' => 'የደህንነት ክልከላ፡ የተመረጠው ተሽከርካሪ የእርስዎ መለያ አይደለም።',
            'General Checkup' => 'አጠቃላይ ምርመራ',
            'Inspection' => 'ምርመራ',
            'at' => 'በ',
            'All rights reserved.' => 'መብቱ በህግ የተጠበቀ ነው።',
            'Service Visits' => 'የአገልግሎት ጉብኝቶች',
            'No active repair orders' => 'ምንም ንቁ የጥገና ትዕዛዞች የሉም',
            'No history' => 'ምንም ታሪክ የለም',
            'Role' => 'ሚና',
            'You must choose a new password before you can continue.' => 'ከመቀጠልዎ በፊት አዲስ የይለፍ ቃል መምረጥ አለብዎት።',
            'Password updated.' => 'የይለፍ ቃል ተዘምኗል።',

            // Typewriter Animations
            'every drive.' => 'ለእያንዳንዱ ጉዞ።',
            'luxury vehicles.' => 'ለቅንጦት ተሽከርካሪዎች።',
            'peak performance.' => 'ለላቀ የተሽከርካሪ ብቃት።',
            'flawless performance.' => 'እንከን ለሌለው የተሽከርካሪ ብቃት።',
            'complete peace of mind.' => 'ለተሟላ የአእምሮ ሰላም።',
            'Confident care for' => 'አስተማማኝ እንክብካቤ',
            'Choose with confidence.' => 'በልበ ሙሉነት ይምረጡ።',
            'Expert diagnostics & care.' => 'የባለሙያ ምርመራ እና እንክብካቤ።',
            'Transparent pricing always.' => 'ሁልጊዜ ግልጽ ዋጋ።',
            'Certified technician care.' => 'የተመሰከረላቸው ባለሙያዎች አገልግሎት።',
            'We are here to listen.' => 'ለመስማት ሁሌም ዝግጁ ነን።',
            'Fast & friendly concierge.' => 'ፈጣን እና ወዳጃዊ አገልግሎት።',
            'Direct phone & Telegram support.' => 'የቀጥታ ስልክ እና ቴሌግራም ድጋፍ።',
            'Professional automotive advice.' => 'ሙያዊ የተሽከርካሪ ምክር።',
            'Clear answers.' => 'ግልጽ መልሶች።',
            'Thoughtful service.' => 'አስተማማኝ አገልግሎት።',
            'Confidence on the road.' => 'በመንገድ ላይ ሙሉ መተማመን።',

            // In-Place Customer Booking Modal
            'Book Service Visit' => 'የአገልግሎት ቀጠሮ ያስይዙ',
            'Select your vehicle and service package for priority workshop booking.' => 'ለቅድሚያ የዎርክሾፕ ቀጠሮ ተሽከርካሪዎን እና የአገልግሎት ጥቅልዎን ይምረጡ።',
            'Schedule your vehicle maintenance or repair visit in minutes.' => 'የተሽከርካሪዎን ጥገና በጥቂት ደቂቃዎች ውስጥ ቀጠሮ ይያዙ።',
            '+ Add New Vehicle' => '+ አዲስ ተሽከርካሪ ያክሉ',
            'Use Existing Vehicle' => 'የተመዘገበ ተሽከርካሪ ይጠቀሙ',
            'Estimated Duration' => 'ግምታዊ የሚወስደው ጊዜ',
            'Standard Cost' => 'መደበኛ ዋጋ',
            'Confirm Booking' => 'ቀጠሮ ያረጋግጡ',
            'Close' => 'ዝጋ',
            'Cancel' => 'ሰርዝ',
            'Current password is incorrect.' => 'የአሁኑ የይለፍ ቃል የተሳሳተ ነው።',
            'Passwords do not match.' => 'የይለፍ ቃላት አይዛመዱም።',
            'Choose a password you have not used before.' => 'ከዚህ በፊት ያልተጠቀሙበትን የይለፍ ቃል ይምረጡ።',

            // Action Buttons
            '+ New job card' => '+ አዲስ የሥራ ካርድ',
            '+ Record expense' => '+ ወጪ መዝግብ',
            '+ New appointment' => '+ አዲስ ቀጠሮ',
            '+ Book appointment' => '+ አዲስ ቀጠሮ',
            'Book appointment' => 'ቀጠሮ ያስይዙ',
            '+ Book New Service' => '+ አዲስ አገልግሎት ያስይዙ',
            '+ New customer' => '+ አዲስ ደንበኛ',
            '+ New vehicle' => '+ አዲስ ተሽከርካሪ',
            '+ Record payment' => '+ ክፍያ መዝግብ',
            '+ Add part' => '+ አዲስ ዕቃ ጨምር',
            '+ Add spare part' => '+ አዲስ መለዋወጫ ዕቃ ጨምር',
            '+ Generate invoice' => '+ ደረሰኝ አዘጋጅ',
            'Save' => 'አስቀምጥ',
            'Cancel' => 'ሰርዝ',
            'Confirm' => 'አረጋግጥ',
            'Please confirm' => 'እባክዎ ያረጋግጡ',
            'Delete' => 'አጥፋ',
            'Edit' => 'አስተካክል',
            'View' => 'እይ',
            'Print' => 'አትም',
            'Download' => 'አውርድ',
            'Export' => 'ላክ',
            'Filter' => 'አጣራ',
            'Search' => 'ፈልግ',
            'Close' => 'ዝጋ',
            'Submit' => 'አስገባ',
            'Theme' => 'ገጽታ',
            'Switch theme' => 'ገጽታ ቀይር',
            'Mark all read' => 'ሁሉንም እንደተነበቡ ምልክት አድርግ',
            'View all alerts' => 'ሁሉንም ማሳወቂያዎች እይ',
            'No unread notifications' => 'ያልተነበቡ ማሳወቂያዎች የሉም',
            'Alerts' => 'ማሳወቂያዎች',
            'Stock alerts' => 'የክምችት ማሳወቂያዎች',

            // Metrics & Labels
            'Total Revenue' => 'ጠቅላላ ገቢ',
            'Net Profit' => 'የተጣራ ትርፍ',
            'Pending Payments' => 'በመጠባበቅ ላይ ያሉ ክፍያዎች',
            'Active Jobs' => 'በሂደት ላይ ያሉ ሥራዎች',
            'Active Repairs' => 'በሂደት ላይ ያሉ ጥገናዎች',
            'Completion Rate' => 'የማጠናቀቅ ምጣኔ',
            'Low Stock Alerts' => 'ዝቅተኛ ክምችት ማስጠንቀቂያዎች',
            'Critical Stock' => 'አስቸኳይ ዕቃዎች',
            'Available Stock Units' => 'የሚገኙ ዕቃዎች ብዛት',
            'Inventory Value' => 'የክምችት ዋጋ',
            'Restock Required' => 'ማሟላት የሚያስፈልጋቸው',
            'Registered Customers' => 'የተመዘገቡ ደንበኞች',
            'Intake Queue' => 'የተሽከርካሪ መቀበያ ቅደም ተከተል',
            "Today's Service Schedule" => 'የዛሬው የአገልግሎት መርሃ ግብር',
            'Bay Queue' => 'የጥገና መስመር',
            'Assigned Tasks' => 'የተመደቡ ሥራዎች',
            'Completed Today' => 'ዛሬ የተጠናቀቁ',
            'Quick Actions' => 'ፈጣን እርምጃዎች',
            'Registered Vehicles' => 'የተመዘገቡ ተሽከርካሪዎች',
            'Upcoming Appointments' => 'መጪ ቀጠሮዎች',
            'Outstanding Balance' => 'ያልተከፈለ ቀሪ ሂሳብ',
            'Recent Invoices' => 'የቅርብ ጊዜ ደረሰኞች',
            'Recent Activity' => 'የቅርብ ጊዜ እንቅስቃሴዎች',
            'Repair Status' => 'የጥገና ሁኔታ',
            'Service History' => 'የአገልግሎት ታሪክ',
            'Total Invoices' => 'ጠቅላላ ደረሰኞች',
            'Total Outstanding' => 'ጠቅላላ ያልተከፈለ',
            'Total Received' => 'ጠቅላላ የተቀበለው',
            'All Systems Operational' => 'ሁሉም ሥርዓቶች በጥሩ ሁኔታ ላይ ናቸው',
            'Reporting period' => 'የሪፖርት ጊዜ',
            'Date range' => 'የቀን ክልል',
            'Today' => 'ዛሬ',
            'Last 7 days' => 'ያለፉት 7 ቀናት',
            'Last 30 days' => 'ያለፉት 30 ቀናት',
            'This month' => 'የዚህ ወር',
            'Last month' => 'ያለፈው ወር',
            'This year' => 'የዚህ ዓመት',
            'Custom date range' => 'የተመረጠ የቀን ክልል',
            'Filter this page…' => 'በዚህ ገጽ ላይ ፈልግ…',
            'records' => 'መዝገቦች',

            // Statuses & Priorities
            'Pending' => 'በመጠባበቅ ላይ',
            'Diagnosing' => 'ምርመራ ላይ',
            'Waiting for Parts' => 'ዕቃ በመጠባበቅ ላይ',
            'In Progress' => 'በመስራት ላይ',
            'Completed' => 'የተጠናቀቀ',
            'Delivered' => 'የተረከበ',
            'Cancelled' => 'የተሰረዘ',
            'Paid' => 'የተከፈለ',
            'Unpaid' => 'ያልተከፈለ',
            'Partially Paid' => 'በከፊል የተከፈለ',
            'Overdue' => 'ጊዜው ያለፈበት',
            'Draft' => 'ረቂቅ',
            'Urgent' => 'አስቸኳይ',
            'High' => 'ከፍተኛ',
            'Medium' => 'መካከለኛ',
            'Low' => 'ዝቅተኛ',
            'Healthy Stock' => 'በቂ ክምችት',
            'Low Stock' => 'ዝቅተኛ ክምችት',
            'Out of Stock' => 'ያለቀ ዕቃ',

            // Tables & Form Fields
            'Plate Number' => 'የሰሌዳ ቁጥር',
            'Make' => 'የመኪና ዓይነት',
            'Model' => 'ሞዴል',
            'Year' => 'ዓመት',
            'Customer' => 'ደንበኛ',
            'Vehicle' => 'ተሽከርካሪ',
            'Service' => 'አገልግሎት',
            'Mechanic' => 'መካኒክ',
            'Status' => 'ሁኔታ',
            'Priority' => 'ቅድሚያ',
            'Date' => 'ቀን',
            'Time' => 'ሰዓት',
            'Amount' => 'መጠን',
            'Subtotal' => 'ንዑስ ድምር',
            'Tax' => 'ታክስ',
            'Discount' => 'ቅናሽ',
            'Balance' => 'ቀሪ ሂሳብ',
            'Actions' => 'እርምጃዎች',
            'Notes' => 'ማስታወሻዎች',
            'Phone' => 'ስልክ',
            'Email' => 'ኢሜይል',
            'Full Name' => 'ሙሉ ስም',
            'Username' => 'የተጠቃሚ ስም',
            'Password' => 'የይለፍ ቃል',
            'Confirm Password' => 'የይለፍ ቃል ያረጋግጡ',
            'Remember me' => 'አስታውሰኝ',
            'Forgot password?' => 'የይለፍ ቃል ረሱ?',
            "Don't have an account?" => 'መለያ የለዎትም?',
            'Already have an account?' => 'ቀደም ሲል መለያ አለዎት?',
            'Sign In to Your Account' => 'ወደ መለያዎ ይግቡ',
            'Sign in to access your garage account' => 'የጋራዥ መለያዎን ለመጠቀም ይግቡ',
            'Reset Password' => 'የይለፍ ቃል አድስ',
            'Send Reset Link' => 'የማደሻ ሊንክ ላክ',

            // Public Landing Content
            'Confident care for every drive.' => 'ለሁሉም ጉዞ አስተማማኝ እንክብካቤ።',
            'Expert care. Clear choices.' => 'የባለሙያ እንክብካቤ። ግልጽ ምርጫዎች።',
            'Find the right service, book your visit, and stay informed while your vehicle is in our care.' => 'ተገቢውን አገልግሎት ያግኙ፣ ቀጠሮ ያስይዙ፣ እና መኪናዎ በእንክብካቤያችን ውስጥ እያለ መረጃ ይከታተሉ።',
            'Book Service Consultation' => 'የአገልግሎት ምክክር ቀጠሮ ያስይዙ',
            'Explore Services' => 'አገልግሎቶችን ይመልከቱ',
            'Contact Support' => 'ድጋፍ ያግኙ',
            'Care for every mile.' => 'ለሁሉም ማይል እንክብካቤ።',
            'A better kind of workshop experience' => 'የተሻለ የዎርክሾፕ ተሞክሮ',
            'Clear answers.' => 'ግልጽ መልሶች።',
            'Thoughtful service.' => 'አስተዋይ አገልግሎት።',
            'Confidence on the road.' => 'በመንገድ ላይ በራስ መተማመን።',
            'Good service starts with listening.' => 'ጥሩ አገልግሎት የሚጀምረው በማዳመጥ ነው።',
            'Our approach' => 'የእኛ አካሄድ',
            'Careful work. Clear communication.' => 'ጥንቁቅ ሥራ። ግልጽ ግንኙነት።',
            'Discover our approach' => 'አካሄዳችንን ይወቁ',
            'What kinds of vehicles can I ask about?' => 'ስለ ምን ዓይነት ተሽከርካሪዎች መጠየቅ እችላለሁ?',
            'What happens after I request an appointment?' => 'ቀጠሮ ከጠየኩ በኋላ ምን ይከሰታል?',
            'Will I know the estimated cost before work begins?' => 'ሥራ ከመጀመሩ በፊት ግምታዊ ወጪውን አውቃለሁ?',
            'Can I track my service request?' => 'የአገልግሎት ጥያቄዬን መከታተል እችላለሁ?',
            'How can I contact the garage?' => 'ጋራዡን እንዴት ማግኘት እችላለሁ?',
            'How do I reset my password?' => 'የይለፍ ቃሌን እንዴት መቀየር እችላለሁ?',
            'Meet the people, practical expertise, and customer-first approach behind' => 'ከጀርባ ያሉትን ባለሙያዎች፣ ተግባራዊ ዕውቀት እና ደንበኛ-ተኮር አካሄድ ይወቁ',

            // Announcements & Marquee
            'Welcome to Alpha Garage' => 'እንኳን ወደ አልፋ ጋራዥ በደህና መጡ',
            'Professional automotive diagnostics and repairs' => 'ሙያዊ የተሽከርካሪ ምርመራ እና ጥገናዎች',
            'Clear service guidance from our team' => 'ግልጽ የአገልግሎት መመሪያ ከቡድናችን',
            'Convenient online appointment booking' => 'ምቹ የኦንላይን ቀጠሮ ምዝገባ',
            'Care for every journey' => 'ለሁሉም ጉዞ እንክብካቤ',
            'Transparent updates throughout your visit' => 'በጉብኝትዎ ወቅት ግልጽ መረጃዎች',
            'Experienced automotive professionals' => 'ልምድ ያላቸው የተሽከርካሪ ባለሙያዎች',
            'Vehicle care tailored to your needs' => 'ለእርስዎ ፍላጎት የተበጀ የተሽከርካሪ እንክብካቤ',
            'Contact us' => 'ያግኙን',
            'THE ALPHA GARAGE DIFFERENCE' => 'የአልፋ ጋራዥ ልዩነት',
            'Clear service details' => 'ግልጽ የአገልግሎት ዝርዝሮች',
            'Standard prices to review' => 'ለመገምገም መደበኛ ዋጋዎች',
            'Estimated service times' => 'ግምታዊ የአገልግሎት ጊዜያት',
            'Helpful service guidance' => 'ጠቃሚ የአገልግሎት መመሪያ',
            'Vehicle-focused care' => 'ተሽከርካሪ-ተኮር እንክብካቤ',
            'Customer account access' => 'የደንበኛ መለያ መዳረሻ',
            'Easy ways to reach our team' => 'ቡድናችንን በቀላሉ ለማግኘት የሚያስችሉ መንገዶች',
            'Responsive on every device' => 'በሁሉም መሣሪያዎች ላይ የሚሰራ',
            'Support from request to visit' => 'ከጥያቄ እስከ ጉብኝት ድረስ ድጋፍ',

            // Hero CTAs & Prompts
            'Book a service' => 'አገልግሎት ይያዙ',
            'Explore services' => 'አገልግሎቶችን ይመልከቱ',
            'Convenient online booking' => 'ምቹ የኦንላይን ቀጠሮ ምዝገባ',
            'Your next step' => 'የሚቀጥለው እርምጃዎ',
            "Let's get you back on the road." => 'ወደ መንገድ እንመልስዎታለን።',
            'Choose a service or talk with our team if you are not sure where to start.' => 'አገልግሎት ይምረጡ ወይም ከየት መጀመር እንዳለብዎ እርግጠኛ ካልሆኑ ከቡድናችን ጋር ይነጋገሩ።',
            'Book online' => 'በኦንላይን ይያዙ',
            'Request an appointment in a few steps' => 'በጥቂት እርምጃዎች ቀጠሮ ይጠይቁ',
            'Call our team' => 'ቡድናችንን ይደውሉ',
            'New here? Visit our FAQ' => 'አዲስ ነዎት? ተደጋጋሚ ጥያቄዎችን ይመልከቱ',

            // Customer Promises & Carousel
            'Trusted customer care' => 'አስተማማኝ የደንበኞች እንክብካቤ',
            'Trust is earned at every visit' => 'እምነት በእያንዳንዱ ጉብኝት ይገነባል',
            'A thoughtful service experience starts with clear information, helpful people, and care that keeps you informed.' => 'አስተዋይ የአገልግሎት ተሞክሮ የሚጀምረው በግልጽ መረጃ፣ ረዳት በሆኑ ባለሙያዎች እና መረጃ በሚሰጥ እንክብካቤ ነው።',
            'THE ALPHA GARAGE PROMISE' => 'የአልፋ ጋራዥ ቃል ኪዳን',
            'Clear choices, before you book' => 'ከመያዝዎ በፊት ግልጽ ምርጫዎች',
            'Explore service details, standard pricing, and estimated time so you can choose your next step with confidence.' => 'ቀጣዩን እርምጃዎን በልበ ሙሉነት ለመምረጥ የአገልግሎት ዝርዝሮችን፣ መደበኛ ዋጋዎችን እና ግምታዊ ጊዜን ይመልከቱ።',
            'Explore our services' => 'አገልግሎቶቻችንን ይመልከቱ',
            'HERE WHEN YOU NEED US' => 'በሚፈልጉን ጊዜ ከጎንዎ ነን',
            'Helpful people, ready to listen' => 'ለማዳመጥ ዝግጁ የሆኑ ባለሙያዎች',
            'Not sure what your vehicle needs? Reach out to our team and get guidance on the best place to start.' => 'መኪናዎ ምን እንደሚያስፈልገው እርግጠኛ አይደሉም? ከየት መጀመር እንዳለብዎ መመሪያ ለማግኘት ቡድናችንን ያነጋግሩ።',
            'Talk with our team' => 'ከቡድናችን ጋር ይነጋገሩ',
            'CARE THAT KEEPS YOU INFORMED' => 'መረጃ የሚሰጥ እንክብካቤ',
            'A simple path from request to visit' => 'ከጥያቄ እስከ ጉብኝት ቀላል መንገድ',
            'Send your request online, hear from our team, and arrive knowing what to expect.' => 'ጥያቄዎን በኢንተርኔት ይላኩ፣ ከቡድናችን ምላሽ ያግኙ፣ እና ምን እንደሚጠብቁ አውቀው ይምጡ።',
            'Request' => 'ይጠይቁ',
            'We follow up' => 'ክትትል እናደርጋለን',
            'Visit the garage' => 'ጋራዡን ይጎብኙ',
            'Request a visit' => 'ጉብኝት ይጠይቁ',

            // About Story
            'Your car' => 'የእርስዎ መኪና',
            'Our care, every step' => 'እንክብካቤያችን፣ በእያንዳንዱ እርምጃ',
            'Automotive care, made personal' => 'የተሽከርካሪ እንክብካቤ፣ ግላዊ በሆነ መልኩ',
            'Expert help, without the guesswork' => 'ያለ ግምት የባለሙያ እርዳታ',
            'Tell us what your vehicle needs and our team will help you find the right service for your next visit.' => 'መኪናዎ ምን እንደሚያስፈልገው ይንገሩን እና ቡድናችን ለቀጣዩ ጉብኝትዎ ትክክለኛውን አገልግሎት እንዲያገኙ ይረዳዎታል።',
            'From your first question to your appointment request, we make it easy to understand the options and take the next step.' => 'ከመጀመሪያው ጥያቄዎ ጀምሮ እስከ ቀጠሮ ጥያቄዎ ድረስ አማራጮቹን መረዳት እና ቀጣዩን እርምጃ መውሰድ ቀላል እናደርጋለን።',
            'Meet the garage' => 'ጋራዡን ይወቁ',
            'Service details before you book' => 'ከመመዝገብዎ በፊት የአገልግሎት ዝርዝሮች',
            'Simple appointment requests' => 'ቀላል የቀጠሮ ጥያቄዎች',
            'A team ready to answer questions' => 'ጥያቄዎችን ለመመለስ ዝግጁ የሆነ ቡድን',
            'Support for your vehicle\'s needs' => 'ለተሽከርካሪዎ ፍላጎቶች ድጋፍ',

            // Services Showcase
            'Find the right care' => 'ተገቢውን እንክብካቤ ያግኙ',
            'Services made easier to choose' => 'ለመምረጥ ቀላል የተደረጉ አገልግሎቶች',
            'Compare service details, estimated time, and standard pricing, then book the option that fits your vehicle.' => 'የአገልግሎት ዝርዝሮችን፣ ግምታዊ ጊዜን እና መደበኛ ዋጋዎችን ያወዳድሩ፣ ከዚያም ለመኪናዎ የሚስማማውን አማራጭ ይያዙ።',
            'Standard price' => 'መደበኛ ዋጋ',
            'Estimated time' => 'ግምታዊ ጊዜ',
            'Book this service' => 'ይህን አገልግሎት ይያዙ',
            'Book an Inspection' => 'ምርመራ ያስይዙ',
            'View all services' => 'ሁሉንም አገልግሎቶች ይመልከቱ',
            'hrs' => 'ሰዓታት',

            // Why Us
            'The Alpha Garage Standard' => 'የአልፋ ጋራዥ ደረጃ',
            'Why Drivers Choose Alpha Garage' => 'አሽከርካሪዎች ለምን አልፋ ጋራዥን ይመርጣሉ',
            'Helpful guidance, clear service information, and simple ways to take your next step.' => 'ጠቃሚ መመሪያ፣ ግልጽ የአገልግሎት መረጃ እና ቀጣዩን እርምጃዎን ለመውሰድ ቀላል መንገዶች።',
            'A clear starting point' => 'ግልጽ መነሻ ነጥብ',
            'Tell us what you have noticed and we will help you choose an appropriate service to start with.' => 'ያስተዋሉትን ይንገሩን እና ለመጀመር ተስማሚ አገልግሎት እንዲመርጡ እንረዳዎታለን።',
            'Helpful service team' => 'ረዳት የአገልግሎት ቡድን',
            'Contact our team with questions about your vehicle or the services listed in the catalog.' => 'ስለ ተሽከርካሪዎ ወይም በካታሎጉ ውስጥ ስለተዘረዘሩት አገልግሎቶች ጥያቄዎችን ለቡድናችን ያቅርቡ።',
            'Know what to expect' => 'ምን እንደሚጠብቁ ይወቁ',
            'Review standard pricing and estimated service time before sending an appointment request.' => 'የቀጠሮ ጥያቄ ከመላክዎ በፊት መደበኛ ዋጋዎችን እና ግምታዊ የአገልግሎት ጊዜን ይገምግሙ።',
            'Stay in the loop' => 'ሁልጊዜ መረጃ ይከታተሉ',
            'Use your account to follow your appointment and service information in one place.' => 'የቀጠሮ እና የአገልግሎት መረጃዎን በአንድ ቦታ ለመከታተል መለያዎን ይጠቀሙ።',

            // Workflow Steps
            'Transparent Workflow' => 'ግልጽ የአሠራር ሂደት',
            'How Our Service Works' => 'አገልግሎታችን እንዴት እንደሚሰራ',
            'See how to choose a service, send an appointment request, and keep in touch with our team.' => 'አገልግሎት እንዴት እንደሚመረጥ፣ የቀጠሮ ጥያቄ እንዴት እንደሚላክ እና ከቡድናችን ጋር እንዴት መገናኘት እንደሚቻል ይመልከቱ።',
            'Schedule Online' => 'በኦንላይን ቀጠሮ ይያዙ',
            'Book your appointment online with your preferred time slot and initial symptoms description.' => 'በሚመርጡት የጊዜ ሰሌዳ እና የመነሻ ምልክቶች መግለጫ ቀጠሮዎን በኦንላይን ያስይዙ።',
            'Digital Intake & Scan' => 'ዲጂታል ምዝገባ እና ምርመራ',
            'Share your vehicle details and the concerns you would like our team to review.' => 'የተሽከርካሪዎን ዝርዝሮች እና ቡድናችን እንዲመረምርልዎ የሚፈልጉትን ስጋቶች ያካፍሉ።',
            'Precision Repair' => 'ጥንቁቅ ጥገና',
            'Our team reviews the service request and works with you on the next steps.' => 'ቡድናችን የአገልግሎት ጥያቄውን ይመረምራል እና በሚቀጥሉት እርምጃዎች ላይ ከእርስዎ ጋር ይሰራል።',
            'Quality Handover' => 'ጥራት ያለው ርክክብ',
            'We will help coordinate the follow-up and answer any questions about your service.' => 'ክትትሉን ለማስተባበር እና ስለ አገልግሎትዎ ለሚነሱ ማናቸውም ጥያቄዎች መልስ ለመስጠት እንረዳዎታለን።',

            // Next Steps
            'A smooth start' => 'ቀላል ጅማሮ',
            'A better service visit starts here' => 'የተሻለ የአገልግሎት ጉብኝት እዚህ ይጀምራል',
            'Start online or contact our team. We will help you find the right next step.' => 'በኢንተርኔት ይጀምሩ ወይም ቡድናችንን ያነጋግሩ። ትክክለኛውን ቀጣይ እርምጃ እንዲያገኙ እንረዳዎታለን።',
            'Choose a service' => 'አገልግሎት ይምረጡ',
            'Review the catalog and find the service that best matches what your vehicle needs.' => 'ካታሎጉን ይገምግሙ እና ከተሽከርካሪዎ ፍላጎት ጋር የሚስማማውን አገልግሎት ያግኙ።',
            'Browse services' => 'አገልግሎቶችን ያስሱ',
            'Sign in or create an account to send an appointment request online.' => 'የቀጠሮ ጥያቄ በኢንተርኔት ለመላክ ይግቡ ወይም መለያ ይፍጠሩ።',
            'Talk to our team' => 'ከቡድናችን ጋር ይነጋገሩ',
            'Ask a question before booking. We are happy to help you decide what to do next.' => 'ከመመዝገብዎ በፊት ጥያቄ ይጠይቁ። ቀጥሎ ምን ማድረግ እንዳለብዎ ለመወሰን በደስታ እንረዳዎታለን።',
            'Contact support' => 'ድጋፍ ሰጪዎችን ያነጋግሩ',

            // Contact Facility & Details
            'Get in Touch' => 'ያግኙን',
            'Visit Our Premier Facility' => 'ዘመናዊ ጋራዣችንን ይጎብኙ',
            'We welcome you to visit our clean, modern workshop. Our service advisors are ready to consult on your vehicle\'s specific requirements.' => 'ንፁህ እና ዘመናዊ ዎርክሾፓችንን እንዲጎበኙ በአክብሮት እንጋብዝዎታለን። የአገልግሎት አማካሪዎቻችን በተሽከርካሪዎ ልዩ ፍላጎቶች ላይ ለመመካከር ዝግጁ ናቸው።',
            'Workshop Location' => 'የዎርክሾፕ አድራሻ',
            'Direct Telephone' => 'ቀጥታ የስልክ መስመር',
            'Customer Inquiries' => 'የደንበኞች ጥያቄዎች',
            'Operating Hours' => 'የሥራ ሰዓታት',
            'Monday – Saturday: 8:00 AM – 6:30 PM · Sunday: Emergency Service' => 'ከሰኞ – ቅዳሜ፡ 8:00 ጥዋት – 6:30 ከሰዓት · እሑድ፡ የአስቸኳይ ጊዜ አገልግሎት',
            'Telegram Support' => 'የቴሌግራም ድጋፍ',
            'Instant Dispatch' => 'ፈጣን ምላሽ',
            'Ready for Premium Care?' => 'ለተሻለ እንክብካቤ ዝግጁ ነዎት?',
            'Create a client account or sign in to schedule your vehicle\'s upcoming maintenance appointment.' => 'የመኪናዎን መጪ የጥገና ቀጠሮ ለማስያዝ የደንበኛ መለያ ይፍጠሩ ወይም ይግቡ።',
            'Open Full Contact & Support Page' => 'ሙሉውን የመገናኛ እና የድጋፍ ገጽ ይክፈቱ',
            'Register an Account' => 'መለያ ይመዝገቡ',
            'Client Sign In' => 'የደንበኛ መግቢያ',
            'Call reception' => 'የእንግዳ መቀበያ ይደውሉ',
            'Send an inquiry' => 'ጥያቄ ይላኩ',
            'A real team, ready to listen' => 'ለማዳመጥ ዝግጁ የሆነ እውነተኛ ቡድን',
            'A good conversation starts here' => 'ጥሩ ውይይት እዚህ ይጀምራል',
            'How can we help?' => 'እንዴት ልንረዳዎ እንችላለን?',
            'Pick a contact option or send us a note with the details of your vehicle and question.' => 'የመገናኛ አማራጭ ይምረጡ ወይም የተሽከርካሪዎን ዝርዝሮች እና ጥያቄዎን የያዘ መልእክት ይላኩልን።',
            'Official Telegram Channel' => 'ይፋዊ የቴሌግራም ቻናል',
            'Join our official channel for priority service updates, maintenance advice, and message our live concierge team directly on Telegram.' => 'ለቅድሚያ አገልግሎት መረጃዎች፣ ለጥገና ምክሮች ይፋዊ ቻናላችንን ይቀላቀሉ እና የድጋፍ ቡድናችንን በቀጥታ በቴሌግራም ያነጋግሩ።',
            'Response Time: Under 15 Minutes' => 'የምላሽ ጊዜ፡ ከ15 ደቂቃ በታች',
            'Open Telegram Support →' => 'የቴሌግራም ድጋፍን ይክፈቱ →',
            'Mobile & Emergency Phone' => 'የሞባይል እና የአስቸኳይ ጊዜ ስልክ',
            'Direct telephone lines to our reception desk, master service advisors, and emergency recovery units.' => 'ቀጥታ የስልክ መስመሮች ወደ እንግዳ መቀበያ ጠረጴዛችን፣ ዋና የአገልግሎት አማካሪዎች እና የአስቸኳይ ጊዜ እርዳታ ክፍሎች።',
            'Call Reception Now' => 'አሁን ወደ እንግዳ መቀበያ ይደውሉ',
            'Official Email Support' => 'ይፋዊ የኢሜይል ድጋፍ',
            'Send formal diagnostic inquiries, insurance claims, parts telemetry, or corporate fleet proposals.' => 'መደበኛ የምርመራ ጥያቄዎችን፣ የኢንሹራንስ ጥያቄዎችን ወይም የድርጅት ጥያቄዎችን ይላኩ።',
            'Send an Email' => 'ኢሜይል ይላኩ',
            'Send Us an Inquiry' => 'ጥያቄ ይላኩልን',
            'Our master technicians and service managers respond within 2 hours during operational times.' => 'የእኛ ዋና ቴክኒሻኖች እና የአገልግሎት አስተዳዳሪዎች በሥራ ሰዓት ውስጥ በ2 ሰዓታት ውስጥ ምላሽ ይሰጣሉ።',
            'Your Name *' => 'ሙሉ ስምዎ *',
            'Email Address *' => 'የኢሜይል አድራሻ *',
            'Phone Number' => 'የስልክ ቁጥር',
            'Preferred Contact Channel' => 'ተመራጭ የመገናኛ መንገድ',
            'Subject *' => 'ጉዳዩ *',
            'How can we help? *' => 'እንዴት ልንረዳዎ እንችላለን? *',
            'Send Inquiry' => 'ጥያቄውን ይላኩ',

            // Footer
            'Excellence in every detail. Professional automotive diagnostics, certified repairs, and transparent digital service for discerning motorists.' => 'በሁሉም ዝርዝር ውስጥ የላቀ ጥራት። ሙያዊ የተሽከርካሪ ምርመራ፣ የተረጋገጠ ጥገና እና ግልጽ ዲጂታል አገልግሎት።',
            'Quick Links' => 'ፈጣን አገናኞች',
            'About Us & FAQ' => 'ስለ እኛ እና ጥያቄዎች',
            'Contact Support & Telegram' => 'ድጋፍ ያግኙ እና ቴሌግራም',
            'Client Access' => 'የደንበኛ መዳረሻ',
            'Workshop Dashboard' => 'የዎርክሾፕ ዳሽቦርድ',
            'Call or book a service' => 'ይደውሉ ወይም አገልግሎት ይያዙ',
            'Call' => 'ይደውሉ',
            'All rights reserved.' => 'መብቱ በህግ የተጠበቀ ነው።',
            'Professional Automotive Service & Repair' => 'ሙያዊ የተሽከርካሪ አገልግሎት እና ጥገና',
            'Telegram' => 'ቴሌግራም',
            'Forgot Password' => 'የይለፍ ቃል ረሱ',
            'Pause' => 'አፍታ አቁም',
            'Play' => 'አጫውት',
            'Previous step' => 'ቀዳሚ እርምጃ',
            'Next step' => 'ቀጣይ እርምጃ',
            'Choose a step' => 'እርምጃ ይምረጡ',
            'Show step 1' => 'እርምጃ 1 አሳይ',
            'Show step 2' => 'እርምጃ 2 አሳይ',
            'Show step 3' => 'እርምጃ 3 አሳይ',

            // About Page
            'We welcome inquiries about a range of makes and models. Share your vehicle details and the issue you are experiencing so our team can confirm the right service before you book.' => 'ስለተለያዩ የመኪና ዓይነቶች እና ሞዴሎች ጥያቄዎችን በደስታ እንቀበላለን። ከመያዝዎ በፊት ቡድናችን ተገቢውን አገልግሎት እንዲያረጋግጥ የተሽከርካሪዎን ዝርዝሮች እና እያጋጠመዎት ያለውን ችግር ያካፍሉ።',
            'Choose a service and preferred time through the booking flow. The garage team can review the request and follow up to confirm the visit and discuss the next steps.' => 'በቀጠሮ አያያዝ ሂደት በኩል አገልግሎት እና የሚመርጡትን ጊዜ ይምረጡ። የጋራዥ ቡድኑ ጥያቄውን በመገምገም ጉብኝቱን ለማረጋገጥ እና ቀጣይ እርምጃዎችን ለመወያየት ክትትል ያደርጋል።',
            'Ask for an estimate during your service consultation. The work required and any parts or additional costs should be discussed with you before you authorize repairs.' => 'በአገልግሎት ምክክር ወቅት ግምታዊ ወጪ ይጠይቁ። ጥገናውን ከመፍቀድዎ በፊት የሚያስፈልገው ሥራ እና ማናቸውም ዕቃዎች ወይም ተጨማሪ ወጪዎች ከእርስዎ ጋር መወያየት አለባቸው።',
            'Sign in to your account to access the customer features currently available to you. If you need an update, contact the garage team with your appointment details.' => 'በአሁኑ ጊዜ የሚገኙትን የደንበኛ አገልግሎቶች ለመጠቀም ወደ መለያዎ ይግቡ። ወቅታዊ መረጃ ከፈለጉ የቀጠሮ ዝርዝሮችዎን በመያዝ የጋራዡን ቡድን ያነጋግሩ።',
            'Use the contact page to send a service inquiry or find the available contact options.' => 'የአገልግሎት ጥያቄ ለመላክ ወይም ያሉትን የመገናኛ አማራጮች ለማግኘት የመገናኛ ገጹን ይጠቀሙ።',
            'Select “Forgot password?” on the sign-in page and follow the instructions. Password recovery depends on the email delivery settings for this installation.' => 'በመግቢያ ገጹ ላይ “የይለፍ ቃል ረሱ?” የሚለውን ይምረጡ እና መመሪያዎችን ይከተሉ። የይለፍ ቃል መልሶ ማግኘት በዚህ ጭነት የኢሜይል ቅንብሮች ላይ የተመሠረተ ነው።',
            'Every vehicle arrives with its own story. We start by understanding your concerns, inspecting the details, and explaining the options in language you can use to make an informed decision.' => 'እያንዳንዱ ተሽከርካሪ የራሱ ታሪክ አለው። ስጋቶችዎን በመረዳት፣ ዝርዝሩን በመመርመር እና በመረጃ ላይ የተመሠረተ ውሳኔ እንዲያደርጉ አማራጮቹን ግልጽ በሆነ ቋንቋ በማብራራት እንጀምራለን።',
            'From the first conversation through the final check, our aim is a straightforward experience: thoughtful diagnostics, clear communication, and care tailored to your vehicle.' => 'ከመጀመሪያው ውይይት እስከ መጨረሻው ፍተሻ ድረስ ግባችን ቀጥተኛ ተሞክሮ ነው፡ አስተዋይ ምርመራ፣ ግልጽ ግንኙነት እና ለተሽከርካሪዎ የተበጀ እንክብካቤ።',
            'People behind the service' => 'ከአገልግሎቱ ጀርባ ያሉ ባለሙያዎች',
            'A closer look at workshop care' => 'የዎርክሾፕ እንክብካቤን በጥልቀት መመልከት',
            'Explore common areas of vehicle care and what a technician may check. The right service depends on your vehicle and symptoms.' => 'የተለመዱ የተሽከርካሪ እንክብካቤ ዘርፎችን እና ቴክኒሻን ምን እንደሚፈትሽ ይመልከቱ። ትክክለኛው አገልግሎት በተሽከርካሪዎ እና በምልክቶቹ ላይ የተመሠረተ ነው።',
            'Diagnostics' => 'ምርመራ',
            'Find the cause, not just the symptom' => 'ምልክቱን ብቻ ሳይሆን መንስኤውን ማግኘት',
            'Engine & maintenance' => 'ሞተር እና ጥገና',
            'Keep essential systems in check' => 'ዋና ዋና ክፍሎችን በቁጥጥር ስር ማድረግ',
            'Brakes & suspension' => 'ፍሬን እና እገዳ',
            'Listen for changes in handling' => 'በአነዳድ ለውጦችን ማስተዋል',
            'Electrical systems' => 'የኤሌክትሪክ ሥርዓቶች',
            'Trace warning lights and faults' => 'የማስጠንቀቂያ መብራቶችን እና ብልሽቶችን መከታተል',
            'Workshop focus' => 'የዎርክሾፕ ትኩረት',
            'Start with the evidence' => 'ከማስረጃው ጀምር',
            'Vehicle diagnostics' => 'የተሽከርካሪ ምርመራ',
            'A technician can review warning indicators, listen to your observations, and inspect relevant systems to help identify possible causes before recommending next steps.' => 'አንድ ቴክኒሻን ቀጣይ እርምጃዎችን ከመምከሩ በፊት ሊሆኑ የሚችሉ ምክንያቶችን ለመለየት የማስጠንቀቂያ ጠቋሚዎችን መገምገም፣ የእርስዎን ምልከታዎች ማዳመጥ እና ተዛማጅ ክፍሎችን መመርመር ይችላል።',
            'View available services' => 'የሚገኙ አገልግሎቶችን ይመልከቱ',
            'A connected experience' => 'የተገናኘ ተሞክሮ',
            'Service details, thoughtfully organized.' => 'በጥንቃቄ የተደራጁ የአገልግሎት ዝርዝሮች።',
            'Digital tools help keep the practical details of a service visit in one place for the team.' => 'ዲጂታል መሣሪያዎች ለቡድኑ የአገልግሎት ጉብኝት ተግባራዊ ዝርዝሮችን በአንድ ቦታ ለማስቀመጥ ይረዳሉ።',
            'Appointment requests' => 'የቀጠሮ ጥያቄዎች',
            'Share your vehicle, preferred time, and service concern so the team has helpful context before your visit.' => 'ከጉብኝትዎ በፊት ቡድኑ ጠቃሚ መረጃ እንዲኖረው ተሽከርካሪዎን፣ የሚመርጡትን ጊዜ እና የአገልግሎት ፍላጎትዎን ያካፍሉ።',
            'Service records' => 'የአገልግሎት መዝገቦች',
            'Digital job details help organize vehicle concerns, work notes, and the next steps for a service visit.' => 'ዲጂታል የሥራ ዝርዝሮች የተሽከርካሪ ስጋቶችን፣ የሥራ ማስታወሻዎችን እና ለጉብኝቱ ቀጣይ እርምጃዎችን ለማደራጀት ይረዳሉ።',
            'Clear conversations' => 'ግልጽ ውይይቶች',
            'Keep questions and service updates centered on the work being discussed, rather than confusing jargon.' => 'ጥያቄዎችን እና የአገልግሎት መረጃዎችን ግራ በሚያጋቡ ቃላት ሳይሆን በሚወያዩበት ሥራ ላይ ብቻ ያተኩሩ።',
            'A considered handover' => 'የታሰበበት ርክክብ',
            'Review the work completed and ask any remaining questions with the team before heading back on the road.' => 'ወደ መንገድ ከመመለስዎ በፊት የተጠናቀቀውን ሥራ ይገምግሙ እና የቀሩትን ጥያቄዎች ከቡድኑ ጋር ይጠይቁ።',
            'Your visit, step by step' => 'ጉብኝትዎ፣ ደረጃ በደረጃ',
            'A clear path from check-in to handover' => 'ከመግቢያ እስከ ርክክብ ግልጽ መንገድ',
            'Every repair is different, but a thoughtful process helps keep you informed along the way.' => 'እያንዳንዱ ጥገና የተለየ ነው፣ ነገር ግን የታሰበበት ሂደት በመንገዱ ላይ መረጃ እንዲሰጥዎ ይረዳል።',
            'Tell us what you notice' => 'ያስተዋሉትን ይንገሩን',
            'Describe the sound, warning light, or change you have experienced.' => 'ያጋጠመዎትን ድምጽ፣ የማስጠንቀቂያ መብራት ወይም ለውጥ ይግለጹ።',
            'Inspect and explain' => 'መርምር እና አስረዳ',
            'Discuss the inspection and the options that may address the concern.' => 'ስለ ምርመራው እና ስጋቱን ሊፈቱ ስለሚችሉ አማራጮች ይወያዩ።',
            'Agree on next steps' => 'በቀጣይ እርምጃዎች ላይ መስማማት',
            'Review the proposed work and costs before deciding how to proceed.' => 'እንዴት እንደሚቀጥሉ ከመወሰንዎ በፊት የቀረበውን ሥራ እና ወጪዎችን ይገምግሙ።',
            'Review the handover' => 'ርክክቡን ይገምግሙ',
            'Go over the service and any follow-up recommendations with the team.' => 'አገልግሎቱን እና ማናቸውንም የክትትል ምክሮችን ከቡድኑ ጋር ይገምግሙ።',
            'Clear explanations' => 'ግልጽ ማብራሪያዎች',
            'Understand the concern and the proposed options.' => 'ስጋቱን እና የቀረቡትን አማራጮች ይረዱ።',
            'Informed decisions' => 'በመረጃ ላይ የተመሰረቱ ውሳኔዎች',
            'Know what is being discussed before authorizing work.' => 'ሥራ ከመፍቀድዎ በፊት ምን እየተወያየ እንደሆነ ይወቁ።',
            'Careful attention' => 'ጥንቃቄ የተሞላበት ትኩረት',
            'Give your vehicle concerns the time and detail they deserve.' => 'ለተሽከርካሪዎ ስጋቶች የሚገባቸውን ጊዜ እና ዝርዝር ይስጡ።',
            'Helpful follow-up' => 'ጠቃሚ ክትትል',
            'Ask questions and get guidance on your next steps.' => 'ጥያቄዎችን ይጠይቁ እና በቀጣይ እርምጃዎችዎ ላይ መመሪያ ያግኙ።',
            'Common Questions' => 'የተለመዱ ጥያቄዎች',
            'Frequently Asked Questions' => 'ተደጋግመው የሚጠየቁ ጥያቄዎች',
            'A few practical answers to help you plan your next visit.' => 'ቀጣዩን ጉብኝትዎን ለማቀድ የሚረዱ ጥቂት ተግባራዊ መልሶች።',
            'Let’s get you moving' => 'እንቅስቃሴዎን እናቀላጥፍ',
            'Have a question about your vehicle?' => 'ስለ ተሽከርካሪዎ ጥያቄ አለዎት?',
            'Tell us what is going on and our team can help you find a useful next step.' => 'ምን እየተከሰተ እንዳለ ይንገሩን እና ቡድናችን ጠቃሚ ቀጣይ እርምጃ እንዲያገኙ ይረዳዎታል።',
            'Browse Services' => 'አገልግሎቶችን ያስሱ',
            'Pioneering precision automotive engineering, transparent digital maintenance, and cryptographic data security.' => 'አቅኚ የሆነ የተሽከርካሪ ምህንድስና፣ ግልጽ ዲጂታል ጥገና እና የተጠበቀ የመረጃ ደህንነት።',
            'Skip to main content' => 'ወደ ዋና ይዘት ዝለል',

            // Services Page
            'Understand your service.' => 'አገልግሎትዎን ይረዱ።',
            'Choose with confidence.' => 'በልበ ሙሉነት ይምረጡ።',
            'Explore the work we offer, what a visit may involve, and what to share with our team. We’ll explain the options before you decide.' => 'የምንሰጠውን ሥራ፣ ጉብኝት ምን ሊያካትት እንደሚችል እና ለቡድናችን ምን ማጋራት እንዳለብዎ ይመልከቱ። ከመወሰንዎ በፊት አማራጮቹን እናብራራለን።',
            'Thoughtful care for every mile' => 'ለሁሉም ማይል የታሰበበት እንክብካቤ',
            'Work discussed before it begins' => 'ሥራ ከመጀመሩ በፊት የሚወያይበት',
            'The service catalog' => 'የአገልግሎት ካታሎግ',
            'Find the right place to start' => 'ለመጀመር ትክክለኛውን ቦታ ያግኙ',
            'These are the active services listed by the garage. Price and estimated time are shown when available in the catalog; your vehicle and final work may affect the estimate.' => 'እነዚህ በጋራዡ የተዘረዘሩ ንቁ አገልግሎቶች ናቸው። ዋጋ እና ግምታዊ ጊዜ በካታሎጉ ውስጥ ሲገኙ ይታያሉ፤ ተሽከርካሪዎ እና የመጨረሻው ሥራ ግምቱን ሊነኩ ይችላሉ።',
            'All services' => 'ሁሉም አገልግሎቶች',
            'Catalog price' => 'የካታሎግ ዋጋ',
            'Request this service' => 'ይህን አገልግሎት ይጠይቁ',
            'No services match that category.' => 'ከዚህ ምድብ ጋር የሚዛመድ አገልግሎት የለም።',
            'What to expect' => 'ምን እንደሚጠብቁ',
            'A little more about vehicle care' => 'ስለ ተሽከርካሪ እንክብካቤ ተጨማሪ መረጃ',
            'Every vehicle is different. These guides describe common reasons to ask for help and the kinds of details that can make a conversation with a service advisor more useful.' => 'እያንዳንዱ መኪና የተለየ ነው። እነዚህ መመሪያዎች እርዳታ ለመጠየቅ የተለመዱ ምክንያቶችን እና ከአማካሪ ጋር የሚደረገውን ውይይት ጠቃሚ የሚያደርጉ ዝርዝሮችን ይገልጻሉ።',
            'A helpful visit may include' => 'ጠቃሚ ጉብኝት የሚከተሉትን ሊያካትት ይችላል',
            'Ask about this service' => 'ስለዚህ አገልግሎት ይጠይቁ',
            'Before your visit' => 'ከጉብኝትዎ በፊት',
            'Help us make the first conversation count.' => 'የመጀመሪያውን ውይይት ውጤታማ ለማድረግ ይረዱን።',
            'A few details can help the team understand what you’re experiencing and discuss a suitable next step.' => 'ጥቂት ዝርዝሮች ቡድኑ እያጋጠመዎ ያለውን እንዲረዳ እና ተስማሚ ቀጣይ እርምጃ እንዲወያይ ይረዳሉ።',
            'Tell us the vehicle' => 'ተሽከርካሪውን ይንገሩን',
            'Share its make, model, year, and mileage if you know them.' => 'ካወቋቸው የመኪናውን ዓይነት፣ ሞዴል፣ ዓመት እና የሮጠውን ርቀት ያካፍሉ።',
            'Describe what changed' => 'ምን እንደተለወጠ ይግለጹ',
            'Mention warning lights, sounds, smells, or when the issue occurs.' => 'የማስጠንቀቂያ መብራቶችን፣ ድምጾችን፣ ሽታዎችን ወይም ችግሩ መቼ እንደሚከሰት ይጠቅሱ።',
            'Ask before approving work' => 'ሥራ ከመፍቀድዎ በፊት ይጠይቁ',
            'Review the proposed service and estimate with the team before repairs begin.' => 'ጥገና ከመጀመሩ በፊት የቀረበውን አገልግሎት እና ግምት ከቡድኑ ጋር ይገምግሙ።',
            'Need help deciding?' => 'ለመወሰን እርዳታ ይፈልጋሉ?',
            'Let’s find the right next step for your vehicle.' => 'ለተሽከርካሪዎ ትክክለኛውን ቀጣይ እርምጃ እናግኝ።',
            'Talk with our team or request an appointment when you’re ready.' => 'ሲዘጋጁ ከቡድናችን ጋር ይነጋገሩ ወይም ቀጠሮ ይጠይቁ።',
            'Discuss symptoms and when they occur' => 'ምልክቶችን እና መቼ እንደሚከሰቱ መወያየት',
            'Inspect relevant systems and diagnostic codes' => 'ተዛማጅ ክፍሎችን እና የምርመራ ኮዶችን መመርመር',
            'Explain findings and practical next steps' => 'ግኝቶችን እና ተግባራዊ ቀጣይ እርምጃዎችን ማብራራት',
            'Review mileage and service history' => 'የሮጠውን ርቀት እና የአገልግሎት ታሪክ መገምገም',
            'Discuss relevant fluids, filters, and inspections' => 'ስለ ፈሳሾች፣ ማጣሪያዎች እና ፍተሻዎች መወያየት',
            'Confirm the proposed work before it begins' => 'ሥራ ከመጀመሩ በፊት የቀረበውን ሥራ ማረጋገጥ',
            'Share changes in pedal feel or braking noise' => 'በፍሬን ረገጣ ወይም ድምጽ ላይ ያሉ ለውጦችን ማጋራት',
            'Inspect applicable brake components' => 'ተዛማጅ የፍሬን ክፍሎችን መመርመር',
            'Review findings and recommended work with you' => 'ግኝቶችን እና የተመከረውን ሥራ ከእርስዎ ጋር መገምገም',
            'Describe when the issue happens' => 'ችግሩ መቼ እንደሚከሰት መግለጽ',
            'Check the battery and charging system as appropriate' => 'ባትሪውን እና የኃይል መሙያ ክፍሉን እንደ አስፈላጊነቱ መፈተሽ',
            'Discuss test results and repair options' => 'የምርመራ ውጤቶችን እና የጥገና አማራጮችን መወያየት',
            'Describe handling changes and tire wear' => 'የአነዳድ ለውጦችን እና የጎማ መበላትን መግለጽ',
            'Inspect relevant steering and suspension areas' => 'ተዛማጅ የመሪ እና የእገዳ ክፍሎችን መመርመር',
            'Check alignment when suitable for the vehicle and concern' => 'ለተሽከርካሪው ተስማሚ በሚሆንበት ጊዜ አሰላለፍ መፈተሽ',
            'Describe the airflow or temperature issue' => 'የአየር ፍሰት ወይም የሙቀት መጠን ችግሩን መግለጽ',
            'Inspect relevant controls and system operation' => 'ተዛማጅ መቆጣጠሪያዎችን እና የአሠራር ሁኔታን መመርመር',
            'Discuss findings before any service is authorized' => 'አገልግሎት ከመፈቀዱ በፊት ግኝቶችን መወያየት',
            'Share when the change occurs and how it feels' => 'ለውጡ መቼ እንደሚከሰት እና ምን እንደሚሰማ ማጋራት',
            'Review the vehicle’s service information' => 'የተሽከርካሪውን የአገልግሎት መረጃ መገምገም',
            'Agree on diagnostic or maintenance steps together' => 'በምርመራ ወይም በጥገና እርምጃዎች ላይ በጋራ መስማማት',
            'Diagnostics & inspections' => 'ምርመራ እና ፍተሻ',
            'Routine maintenance' => 'መደበኛ ጥገና',
            'Brake service' => 'የፍሬን አገልግሎት',
            'Electrical & battery checks' => 'የኤሌክትሪክ እና የባትሪ ፍተሻ',
            'Steering, suspension & wheels' => 'መሪ፣ እገዳ እና ጎማዎች',
            'Air conditioning & comfort' => 'የአየር ማቀዝቀዣ እና ምቾት',
            'Transmission & drivetrain' => 'ማስተላለፊያ እና የሞተር ኃይል ማስተላለፊያ',
            'Warning lights, new sounds, or changes in how a vehicle drives can have several possible causes. A focused diagnostic visit starts with your observations, then uses appropriate checks to help narrow down the issue.' => 'የማስጠንቀቂያ መብራቶች፣ አዳዲስ ድምጾች ወይም የአነዳድ ለውጦች በርካታ ምክንያቶች ሊኖሯቸው ይችላሉ። ትኩረት የተደረገበት የምርመራ ጉብኝት በእርስዎ ምልከታዎች ይጀምራል፣ ከዚያም ችግሩን ለመለየት ተገቢውን ፍተሻ ይጠቀማል።',
            'Regular maintenance helps you keep up with your vehicle maker’s service schedule. The right work depends on the model, mileage, history, and how the vehicle is used.' => 'መደበኛ ጥገና ከተሽከርካሪዎ አምራች የአገልግሎት መርሃ ግብር ጋር እንዲራመዱ ይረዳዎታል። ትክክለኛው ሥራ በሞዴሉ፣ በሮጠው ርቀት፣ በታሪኩ እና መኪናው በሚያገለግልበት ሁኔታ ላይ የተመሠረተ ነው።',
            'Changes in braking feel, vibration, pulling, or unusual noises deserve attention. A brake inspection helps assess the relevant components and explain what may need servicing.' => 'በፍሬን ስሜት ላይ ያሉ ለውጦች፣ መንቀጥቀጥ፣ ወደ አንድ ወገን መሳብ ወይም እንግዳ ድምጾች ትኩረት ይገባቸዋል። የፍሬን ፍተሻ ተዛማጅ ክፍሎችን ለመገምገም እና ምን ጥገና እንደሚያስፈልገው ለማብራራት ይረዳል።',
            'Starting trouble, warning lights, or an intermittent electrical fault can come from different parts of a vehicle. Targeted testing helps guide the next checks instead of guessing at a replacement.' => 'የመነሳት ችግር፣ የማስጠንቀቂያ መብራቶች ወይም አልፎ አልፎ የሚከሰት የኤሌክትሪክ ብልሽት ከተለያዩ የተሽከርካሪ ክፍሎች ሊመጡ ይችላሉ። ትኩረት የተደረገበት ምርመራ በግምት ከመቀየር ይልቅ ቀጣይ ፍተሻዎችን ይመራል።',
            'Uneven tire wear, a change in handling, or a vehicle that pulls to one side can indicate that the wheels, steering, or suspension need a closer look.' => 'ያልተስተካከለ የጎማ መበላት፣ የአነዳድ ለውጥ ወይም ወደ አንድ ወገን የሚጎትት ተሽከርካሪ ጎማዎች፣ መሪ ወይም እገዳ በቅርበት መመርመር እንዳለባቸው ሊያመለክት ይችላል።',
            'Weak airflow or air that is not cooling as expected can have several causes. An inspection can help identify the next appropriate check before any service is recommended.' => 'ደካማ የአየር ፍሰት ወይም እንደተጠበቀው የማይቀዘቅዝ አየር ብዙ ምክንያቶች ሊኖሩት ይችላል። ፍተሻ ማንኛውም አገልግሎት ከመመከሩ በፊት ቀጣዩን ተገቢ ፍተሻ ለመለየት ይረዳል።',
            'Changes in shifting, vibration, or unusual drivetrain sounds should be discussed with a service advisor. Inspection and maintenance needs vary by vehicle and manufacturer guidance.' => 'በማርሽ መቀያየር ላይ ያሉ ለውጦች፣ መንቀጥቀጥ ወይም ያልተለመዱ ድምጾች ከአገልግሎት አማካሪ ጋር መወያየት አለባቸው። የፍተሻ እና የጥገና ፍላጎቶች እንደ ተሽከርካሪው እና የአምራቹ መመሪያ ይለያያሉ።',

            // Contact & Booking Pages
            'Client Support & Concierge' => 'የደንበኞች ድጋፍ እና አስተናጋጅ',
            'Active • Live Support' => 'ንቁ • የቀጥታ ድጋፍ',
            '24/7 Hotlines' => '24/7 የስልክ መስመሮች',
            'Encrypted Mail' => 'የተጠበቀ ኢሜይል',
            'Submit Inquiry Securely' => 'ጥያቄውን በጥንቃቄ ያስገቡ',
            'Workshop Location & Facility' => 'የዎርክሾፕ አድራሻ እና ተቋም',
            'GPS Coordinates & Directions' => 'የጂፒኤስ መጋጠሚያዎች እና አቅጣጫዎች',
            'Monday – Friday' => 'ከሰኞ – አርብ',
            'Saturday' => 'ቅዳሜ',
            'Sunday' => 'እሑድ',
            'Emergency Triage & Towing Only' => 'የአስቸኳይ ጊዜ እርዳታ እና ተጎታች ብቻ',
            '24/7 Roadside Rescue' => '24/7 የመንገድ ዳር እርዳታ',
            'Always On Standby' => 'ሁልጊዜ በተጠንቀቅ',
            'Telegram Message' => 'የቴሌግራም መልእክት',
            'Direct Phone Call' => 'ቀጥታ የስልክ ጥሪ',
            'Official Email' => 'ይፋዊ ኢሜይል',
            'SMS Text' => 'የአጭር የጽሑፍ መልእክት',
            'Service Booking Portal' => 'የአገልግሎት ማስያዣ ፖርታል',
            'Schedule your vehicle maintenance or repair visit with our certified technicians.' => 'ከተመሰከረላቸው ቴክኒሻኖቻችን ጋር የተሽከርካሪዎን የጥገና ወይም የአገልግሎት ጉብኝት ያቅዱ።',
            'Staff Workshop Board' => 'የሠራተኞች የሥራ ሰሌዳ',
            'Schedule Your Appointment' => 'ቀጠሮዎን ያስይዙ',
            'Select Vehicle' => 'ተሽከርካሪ ይምረጡ',
            'Desired Service Package' => 'የሚፈልጉት የአገልግሎት ጥቅል',
            'General Checkup / Workshop Inspection' => 'አጠቃላይ ፍተሻ / የዎርክሾፕ ምርመራ',
            'Preferred Date' => 'የሚመረጥ ቀን',
            'Preferred Time Slot' => 'የሚመረጥ ሰዓት',
            'Issue Description / Special Requests' => 'የችግሩ መግለጫ / ልዩ ጥያቄዎች',
            'Confirm & Book Appointment' => 'ቀጠሮውን አረጋግጥ እና ያዝ',
            'My Recent Bookings' => 'የቅርብ ጊዜ ቀጠሮዎቼ',
            'Need Immediate Roadside Assistance?' => 'አስቸኳይ የመንገድ ዳር እርዳታ ይፈልጋሉ?',
            'For urgent repairs or towing, contact our 24/7 emergency dispatch directly.' => 'ለአስቸኳይ ጥገና ወይም ጉተታ የ24/7 የድንገተኛ ክፍልን በቀጥታ ያነጋግሩ።',
            'Booking Details' => 'የቀጠሮ ዝርዝሮች',
            'Scheduled Date' => 'የተያዘበት ቀን',
            'Customer Name' => 'የደንበኛ ስም',
            'Notes / Description' => 'ማስታወሻዎች / መግለጫ',
            'Book Another Appointment' => 'ሌላ ቀጠሮ ያስይዙ',

            // Video Management & Video Gallery
            'Videos' => 'ቪዲዮዎች',
            'Video Gallery' => 'የቪዲዮ ማሳያ',
            'Video Management' => 'የቪዲዮ አስተዳደር',
            'Watch Video' => 'ቪዲዮ ይመልከቱ',
            'Explore our workshop in action' => 'የዎርክሾፕ ሥራዎቻችንን በተግባር ይመልከቱ',
            'All Videos' => 'ሁሉም ቪዲዮዎች',
            'Diagnostics' => 'የኮምፒውተር ምርመራ',
            'Engine & Transmission' => 'ሞተር እና ማስተላለፊያ',
            'Electrical & Battery' => 'ኤሌክትሪክ እና ባትሪ',
            'Detailing & Ceramic' => 'ማስዋብ እና ሴራሚክ',
            'Maintenance Tips' => 'የጥገና ምክሮች',
            'Garage Showcase' => 'የጋራዥ ማሳያ',
            'Play Video' => 'ቪዲዮውን ያጫውቱ',
            'Views' => 'እይታዎች',
            'Published' => 'የታተመ',
            'Draft' => 'ረቂቅ',
            'YouTube Video' => 'የዩቲዩብ ቪዲዮ',
            'Uploaded Video' => 'የተጫነ ቪዲዮ',
            'Add Video' => 'ቪዲዮ ጨምር',
            '+ Add Video' => '+ አዲስ ቪዲዮ ጨምር',
            'Edit Video' => 'ቪዲዮ አርትዕ',
            'Delete Video' => 'ቪዲዮ ሰርዝ',
            'Are you sure you want to delete this video?' => 'እርግጠኛ ነዎት ይህን ቪዲዮ መሰረዝ ይፈልጋሉ?',
            'Video Source' => 'የቪዲዮ ምንጭ',
            'YouTube URL' => 'የዩቲዩብ አገናኝ',
            'Upload Video File' => 'የቪዲዮ ፋይል ይጫኑ',
            'Custom Thumbnail' => 'ብጁ የምስል ቅድመ እይታ',
            'Preview Video' => 'ቪዲዮውን ቅድመ ዕይታ',
            'Close Preview' => 'ቅድመ እይታ ዝጋ',
            'No videos found.' => 'ምንም ቪዲዮ አልተገኘም።',
            'Search videos…' => 'ቪዲዮዎችን ፈልግ…',
            'Filter by category' => 'በምድብ አጣራ',
            'Sort Order' => 'ቅደም ተከተል',
            'Save Video' => 'ቪዲዮውን አስቀምጥ',
            'Video Title' => 'የቪዲዮ ርዕስ',
            'Video Description' => 'የቪዲዮ መግለጫ',
            'Video Duration (e.g. 05:30)' => 'የቪዲዮ ርዝመት (ምሳሌ 05:30)',
            'Duration' => 'ርዝመት',
            'Source' => 'ምንጭ',
            'Status' => 'ሁኔታ',
            'Actions' => 'እርምጃዎች',
            'Unpublish' => 'አትታተም',
            'Publish' => 'አትም',
            'Recent Client Registrations' => 'የቅርብ ጊዜ የደንበኞች ምዝገባ',
            'Newly enrolled customer profiles' => 'አዲስ የተመዘገቡ የደንበኛ መገለጫዎች',
            'Contact Phone' => 'የስልክ ቁጥር',
            'Email Address' => 'የኢሜይል አድራሻ',
            'Registered Date' => 'የተመዘገበበት ቀን',
            'Recently Completed Work' => 'በቅርቡ የተጠናቀቁ ሥራዎች',
            'Your latest successful repair handoffs' => 'የቅርብ ጊዜ የተሳኩ የጥገና ርክክቦች',
            'Problem Addressed' => 'የተስተካከለው ችግር',
            'Completion Date' => 'የተጠናቀቀበት ቀን',
            'Workshop Job Coordination' => 'የዎርክሾፕ ሥራዎች ቅንጅት',
            'Active repair orders requiring spare parts' => 'መለዋወጫ ዕቃ የሚፈልጉ ንቁ የጥገና ትዕዛዞች',
            'Pending Requests' => 'በመጠባበቅ ላይ ያሉ ጥያቄዎች',
            'Recent Stock Movements' => 'የቅርብ ጊዜ የክምችት እንቅስቃሴዎች',
            'Audit trail of receipts, issues, and manual adjustments' => 'የዕቃዎች ገቢ፣ ወጪ እና ማስተካከያዎች የቁጥጥር መዝገብ',
            'Part Description' => 'የዕቃው ዝርዝር መግለጫ',
            'Movement Type' => 'የእንቅስቃሴ ዓይነት',
            'Quantity' => 'ብዛት',
            'Balance After' => 'የቀረው ቀሪ',
            'Operator' => 'አከናዋኝ',
            'Operating Expenses' => 'የሥራ ማስኬጃ ወጪዎች',
            'Recorded disbursements and workshop overheads' => 'የተመዘገቡ ወጪዎች እና የዎርክሾፕ አጠቃላይ ወጪዎች',
            'Total inventory items' => 'ጠቅላላ የካታሎግ ዕቃዎች',
            'Available stock types' => 'ዝግጁ የሆኑ የዕቃ ዓይነቶች',
            'Low stock' => 'ዝቅተኛ ክምችት',
            'Out of stock' => 'ያለቀበት',
            'Depleted' => 'ያለቀ',
            'Inventory valuation' => 'የክምችት አጠቃላይ ግምት',
            'Inventory alerts' => 'የክምችት ማስጠንቀቂያዎች',
            'Click an item to review stock' => 'ክምችቱን ለመገምገም ዕቃውን ይጫኑ',
            'Most frequently used spare parts' => 'በብዛት ጥቅም ላይ የዋሉ መለዋወጫዎች',
            'Revenue, expenses, net profit' => 'ገቢ፣ ወጪዎች እና የተጣራ ትርፍ',
            'Net collected revenue trend' => 'የተጣራ የተሰበሰበ ገቢ አዝማሚያ',
            'Receipts, job issuances, and audit adjustments' => 'ገቢዎች፣ ለሥራ የወጡ እና የቁጥጥር ማስተካከያዎች',
            'Parts depleted or below safety threshold' => 'ያለቁ ወይም ከአስተማማኝ ወለል በታች የሆኑ መለዋወጫዎች',
            'Normal' => 'መደበኛ',
            'Front desk & workshop activity' => 'የፊት ጠረጴዛ እና የዎርክሾፕ እንቅስቃሴ',
            'upcoming scheduled appointments' => 'መጪ የተያዙ ቀጠሮዎች',
            'In diagnosis / repair' => 'በምርመራ / ጥገና ላይ',
            'Booking trends & status distribution' => 'የቀጠሮ አዝማሚያዎች እና የሁኔታ ክፍፍል',
            'Arrivals and scheduled services for today' => 'የዛሬ መጤዎች እና የታቀዱ አገልግሎቶች',
            'Live job cards sorted by priority' => 'የቀጥታ የሥራ ካርዶች በቅድሚያ ቅደም ተከተል',
            'Total vehicles serviced & completed' => 'ጠቅላላ የተስተናገዱ እና የተጠናቀቁ ተሽከርካሪዎች',
            'Cash in bank / drawer' => 'በባንክ / በካዝና ያለ ጥሬ ገንዘብ',
            'unpaid / partially paid invoices' => 'ያልተከፈሉ / በከፊል የተከፈሉ ደረሰኞች',
            'Recorded operating disbursements' => 'የተመዘገቡ የሥራ ማስኬጃ ክፍያዎች',
            'Latest cash, card & transfer credits' => 'የቅርብ ጊዜ የጥሬ ገንዘብ፣ ካርድ እና ባንክ ገቢዎች',
            'Method' => 'ዘዴ',
            'Invoice' => 'ደረሰኝ',
            'Cash & accrual tracking' => 'የጥሬ ገንዘብ እና ተሰብሳቢ ክትትል',
            'Real financial ledger records' => 'ትክክለኛ የፋይናንስ መዝገቦች',

            'Total Amount' => 'ጠቅላላ መጠን',
            'Amount Paid' => 'የተከፈለ መጠን',
            'Receipt No.' => 'የክፍያ ቁጥር',
            'Job No.' => 'የሥራ ቁጥር',
            'Customer & Vehicle' => 'ደንበኛ እና ተሽከርካሪ',
            'Vehicle & Customer' => 'ተሽከርካሪ እና ደንበኛ',
            'Reported Problem / Diagnosis' => 'የተገለጸው ችግር / ምርመራ',
            'SKU' => 'የዕቃ መለያ (SKU)',
            'Part Name' => 'የዕቃ ስም',
            'In Stock' => 'በክምችት ላይ',
            'Reorder At' => 'እንደገና የማዘዣ ወለል',
            '14-Day Booking Activity' => 'የ14-ቀናት የቀጠሮ እንቅስቃሴ',
            '6-Month Repair Throughput' => 'የ6-ወራት የጥገና አፈጻጸም',
            '6-Month Net Collections' => 'የ6-ወራት የተጣራ ገቢ',
            'Movement Activity by Type' => 'የዕቃዎች እንቅስቃሴ በዓይነት',
            'Critical Restock Alerts' => 'አስቸኳይ የክምችት ማስጠንቀቂያዎች',
            'Recent Payment Receipts' => 'የቅርብ ጊዜ የክፍያ ደረሰኞች',
            'All invoices are settled in full. Zero outstanding receivables!' => 'ሁሉም ደረሰኞች ሙሉ በሙሉ ተከፍለዋል! ምንም ያልተሰበሰበ ቀሪ የለም!',
            'No appointments scheduled for today.' => 'ለዛሬ የተያዘ ምንም ቀጠሮ የለም።',
            'View all' => 'ሁሉንም እይ',
            'Manage' => 'አስተዳድር',
            'History' => 'ታሪክ',
            'Record payment' => 'ክፍያ መዝግብ',
            'Payments log' => 'የክፍያዎች መዝገብ',
            'Total Expenses' => 'ጠቅላላ ወጪዎች',
            'Net Profit / Loss' => 'የተጣራ ትርፍ / ኪሳራ',
            'Profit Margin' => 'የትርፍ ምጣኔ',
            'Revenue vs Expenses' => 'ገቢ እና ወጪዎች',
            'Profit & Loss Analysis' => 'የትርፍ እና ኪሳራ ትንተና',
            'Revenue Performance' => 'የገቢ አፈጻጸም',
            'Expense Distribution' => 'የወጪዎች ክፍፍል',
            'Job Card Analysis' => 'የሥራ ካርዶች ትንተና',
            'Inventory Analytics' => 'የክምችት ትንተና',
            'Service Performance' => 'የአገልግሎት አፈጻጸም',
            'Active Workshop Repairs' => 'በሂደት ላይ ያሉ የዎርክሾፕ ጥገናዎች',
            'High & Urgent Priority' => 'ከፍተኛ እና አስቸኳይ ቅድሚያ',
            'Net Collected Revenue' => 'የተጣራ የተሰበሰበ ገቢ',
            'Outstanding Receivables' => 'ተሰብሳቢ ያልተከፈሉ ሒሳቦች',
            'Total Invoiced' => 'ጠቅላላ ደረሰኝ የወጣበት',
            'Open full list' => 'ሙሉ ዝርዝሩን ክፈት',
            'View all pending' => 'ሁሉንም በመጠባበቅ ላይ ያሉትን እይ',
            'My repair queue & performance' => 'የእኔ የጥገና መስመር እና አፈጻጸም',
            'Workload distribution & repair throughput' => 'የሥራ ጫና ክፍፍል እና የጥገና ፍጥነት',
            'Stock health & parts movements' => 'የክምችት ጤና እና የመለዋወጫ እንቅስቃሴዎች',
            'Revenue & receivables position' => 'የገቢ እና የተሰብሳቢዎች ሁኔታ',
            'Receivables aging & revenue performance' => 'የተሰብሳቢዎች የቆይታ ጊዜ እና የገቢ አፈጻጸም',
            'Revenue, expenses & profit' => 'ገቢ፣ ወጪዎች እና ትርፍ',
            'Financial & operations progress' => 'የፋይናንስ እና የሥራዎች እድገት',
            'Stock indicators & alerts' => 'የክምችት አመልካቾች እና ማስጠንቀቂያዎች',

            'Explore workshop specialties' => 'የዎርክሾፕ የሙያ መስኮችን ይመልከቱ',
            'Interactive specialty details require JavaScript. You can still browse all available services on our services page.' => 'ዝርዝር መረጃዎችን ለማየት ጃቫስክሪፕት ያስፈልጋል። አሁንም ሁሉንም አገልግሎቶች በአገልግሎቶች ገጻችን ላይ ማየት ይችላሉ።',
            'Professional automotive care and clear service guidance.' => 'ሙያዊ የተሽከርካሪ እንክብካቤ እና ግልጽ የአገልግሎት መመሪያ።',
            'Ask our team' => 'ቡድናችንን ይጠይቁ',
            'The live service list is temporarily unavailable. You can still read about common service areas below or contact the garage for current options.' => 'የአገልግሎቶች ዝርዝር ለጊዜው አይገኝም። ከዚህ በታች ስለተለመዱት የአገልግሎት ዘርፎች ማንበብ ወይም ለአማራጮች ጋራዡን ማነጋገር ይችላሉ።',
            'There are no active services in the catalog right now.' => 'በአሁኑ ጊዜ በካታሎጉ ውስጥ ምንም ንቁ አገልግሎቶች የሉም።',
            'HERE TO HELP' => 'እርስዎን ለመርዳት ዝግጁ ነን',
            'Let’s get you back on the road.' => 'ወደ መንገድ እንመልስዎታለን።',
            'Questions about your vehicle or a service visit? Choose the way that works for you, and tell our team what you need.' => 'ስለ ተሽከርካሪዎ ወይም ስለ አገልግሎት ጉብኝት ጥያቄ አለዎት? ለእርስዎ የሚመቸውን መንገድ ይምረጡና ለቡድናችን የሚፈልጉትን ይንገሩን።',
            'Channel' => 'የመገናኛ መስመር',
            'Response Time' => 'የምላሽ ጊዜ',
            'Under 15 Minutes' => 'ከ15 ደቂቃዎች በታች',
            'Direct Bot' => 'ቀጥታ ቦት',
            'Reception' => 'አስተናጋጅ',
            'Advisors' => 'አማካሪዎች',
            '24/7 Towing' => 'የ24/7 የተሽከርካሪ መጎተት',
            'Customer Care' => 'የደንበኞች እንክብካቤ',
            'Service & Parts' => 'አገልግሎት እና ዕቃዎች',
            'Invoicing' => 'ደረሰኝ አወጣጥ',
            'Message Received' => 'መልዕክት ደርሷል',
            'Website' => 'ድህረ ገጽ',
            'Main Navigation' => 'ዋና አሰሳ',
            'Welcome to Alpha Garage. Professional automotive care, clear service guidance, and convenient booking.' => 'ወደ አልፋ ጋራዥ እንኳን ደህና መጡ። ሙያዊ የተሽከርካሪ እንክብካቤ፣ ግልጽ መመሪያ እና ምቹ የቀጠሮ ምዝገባ።',
            'Quick booking information' => 'ፈጣን የቀጠሮ መረጃ',
            'Alpha Garage service features' => 'የአልፋ ጋራዥ አገልግሎት ገጽታዎች',
            'The Alpha Garage customer experience' => 'የአልፋ ጋራዥ የደንበኞች ተሞክሮ',
            'Appointment request steps' => 'የቀጠሮ መጠየቂያ እርምጃዎች',
            'Our service catalog is temporarily unavailable. Please contact us for current services and pricing.' => 'የአገልግሎት ካታሎጉ ለጊዜው አይገኝም። እባክዎ ለአሁኑ አገልግሎቶች እና ዋጋዎች ያግኙን።',
            'No services are currently listed. Please contact us for assistance.' => 'በአሁኑ ጊዜ የተዘረዘሩ አገልግሎቶች የሉም። እባክዎ ለእርዳታ ያግኙን።',
            'Helpful steps for your visit' => 'ለጉብኝትዎ ጠቃሚ እርምጃዎች',
            'Get started' => 'ይጀምሩ',
            'Skip to main content' => 'ወደ ዋና ይዘት ዝለል',
            'Luxury Automotive Engineering & Service' => 'የቅንጦት ተሽከርካሪዎች ምህንድስና እና አገልግሎት',
            'Convenient online booking' => 'ምቹ የኦንላይን ቀጠሮ ምዝገባ',
            'Helpful service guidance' => 'ጠቃሚ የአገልግሎት መመሪያ',
            'Vehicle-focused care' => 'ለተሽከርካሪ ትኩረት የሰጠ እንክብካቤ',
            'Customer account access' => 'የደንበኛ መለያ መዳረሻ',
            'Easy ways to reach our team' => 'ቡድናችንን በቀላሉ የሚያገኙባቸው መንገዶች',
            'Responsive on every device' => 'በማናቸውም መሣሪያ ላይ ተስማሚ',
            'Support from request to visit' => 'ከጥያቄ እስከ ጉብኝት ድጋፍ',
            'Clear service details' => 'ግልጽ የአገልግሎት ዝርዝሮች',
            'Standard prices to review' => 'የሚገመገሙ መደበኛ ዋጋዎች',
            'Estimated service times' => 'ግምታዊ የአገልግሎት ጊዜ',
            'Welcome Back' => 'እንኳን ደህና መጡ',
            'Username or Email' => 'የተጠቃሚ ስም ወይም ኢሜይል',
            'Enter username or email' => 'የተጠቃሚ ስም ወይም ኢሜይል ያስገቡ',
            'Enter your account password' => 'የመለያዎን የይለፍ ቃል ያስገቡ',
            'Remember me on this device' => 'በዚህ መሣሪያ ላይ አስታውሰኝ',
            'Don\'t have an account yet?' => 'እስካሁን መለያ የለዎትም?',
            'Why Choose Us' => 'ለምን እኛን ይመርጣሉ',
            'Password needs: ' => 'የይለፍ ቃል መስፈርቶች፡ ',
            'Return to Sign In' => 'ወደ መግቢያ ገጽ ተመለስ',
            'Client Portal' => 'የደንበኞች ፖርታል',
            'Workshop in Motion' => 'ዎርክሾፑ በተግባር',
            'Explore certified technicians, precision engine rebuilds, advanced diagnostics, and luxury detailing in action.' => 'የተመሰከረላቸው ቴክኒሻኖችን፣ ጥንቃቄ የተሞላበት የሞተር ግንባታ፣ የላቀ ምርመራ እና የቅንጦት እንክብካቤን በተግባር ይመልከቱ።',
            'Search videos by title or topic…' => 'ቪዲዮዎችን በርዕስ ወይም በይዘት ይፈልጉ…',
            'Search videos' => 'ቪዲዮዎችን ፈልግ',
            'Displaying' => 'የሚታየው',
            'featured videos' => 'ተለይተው የቀረቡ ቪዲዮዎች',
            'High Definition' => 'ከፍተኛ ጥራት',
            '1080p / 4K Playback' => '1080p / 4K ማጫወቻ',
            'Check back soon for new service overhauls and technical demonstrations.' => 'ለአዳዲስ የአገልግሎት ማሳያዎች እና ቴክኒካዊ ገለጻዎች በቅርቡ ይመለሱ።',
            'Try searching with different keywords or selecting another category.' => 'በተለያዩ ቃላት ለመፈለግ ይሞክሩ ወይም ሌላ ምድብ ይምረጡ።',
            'Click to play' => 'ለማጫወት ይጫኑ',
            'Video deleted successfully.' => 'ቪዲዮው በተሳካ ሁኔታ ተሰርዟል።',
            'Video updated successfully.' => 'ቪዲዮው በተሳካ ሁኔታ ተዘምኗል።',
            'Video created successfully.' => 'ቪዲዮው በተሳካ ሁኔታ ተፈጥሯል።',
            'Modify video metadata, replace media asset, or adjust publication status' => 'የቪዲዮ መረጃዎችን ያሻሽሉ፣ የሚዲያ ፋይሉን ይቀይሩ ወይም የማሳተም ሁኔታውን ያስተካክሉ',
            'Integrate a new YouTube walkthrough or upload a high-definition workshop video' => 'አዲስ የዩቲዩብ ቪዲዮ ያገናኙ ወይም ከፍተኛ ጥራት ያለው የዎርክሾፕ ቪዲዮ ይስቀሉ',
            'Back to Videos' => 'ወደ ቪዲዮዎች ተመለስ',
            'Paste YouTube watch / shorts / embed URL' => 'የዩቲዩብ ቪዲዮ አድራሻ (URL) እዚህ ይለጥፉ',
            'MP4, WebM, OGG, MOV (Up to 50MB)' => 'MP4፣ WebM፣ OGG፣ MOV (እስከ 50ሜባ)',
            'YouTube URL or Video ID' => 'የዩቲዩብ አድራሻ ወይም የቪዲዮ መለያ',
            'Supports youtube.com/watch?v=..., youtu.be/..., shorts/..., or direct 11-char ID.' => 'youtube.com/watch?v=..., youtu.be/..., shorts/..., ወይም ባለ 11-ፊደል መለያን ይደግፋል።',
            'Supported formats: MP4, WebM, OGG, MOV. Maximum file size: 50MB.' => 'የሚደገፉ ቅርጸቶች፡ MP4፣ WebM፣ OGG፣ MOV። ከፍተኛው የፋይል መጠን፡ 50ሜባ።',
            'Current stored video file:' => 'በአሁኑ ጊዜ የተቀመጠው የቪዲዮ ፋይል፡',
            'e.g. Advanced ECU Diagnostics & Calibration Workflow' => 'ለምሳሌ፡ የላቀ የECU ምርመራ እና ማስተካከያ',
            'Category' => 'ምድብ',
            'Duration (e.g. 08:45)' => 'የቆይታ ጊዜ (ለምሳሌ፡ 08:45)',
            'Custom Thumbnail Image (Optional)' => 'ብጁ የሽፋን ምስል (አማራጭ)',
            'Upload a custom cover image (JPG, PNG, WebP up to 5MB). If left empty for YouTube, YouTube thumbnail is used automatically.' => 'ብጁ የሽፋን ምስል ይስቀሉ (JPG, PNG, WebP እስከ 5ሜባ)። ለዩቲዩብ ባዶ ከተተወ የዩቲዩብ ሽፋን በራስ-ሰር ጥቅም ላይ ይውላል።',
            'Current custom thumbnail:' => 'የአሁኑ ብጁ ሽፋን፡',
            'Provide technical context, steps performed, or tools showcased in this video…' => 'በዚህ ቪዲዮ ውስጥ የታዩትን ቴክኒካዊ ዝርዝሮች፣ የተከናወኑ ደረጃዎችን ወይም መሣሪያዎችን ያብራሩ…',
            'Lower numbers display first.' => 'ዝቅተኛ ቁጥሮች መጀመሪያ ይታያሉ።',
            'Publish Video to Public Gallery' => 'ቪዲዮውን በይፋዊ ማሳያ ላይ አትም',
            'Unchecking keeps this video in draft state (visible only to admins).' => 'ምልክቱን ማንሳት ይህን ቪዲዮ በረቂቅ ሁኔታ ያቆየዋል (ለአስተዳዳሪዎች ብቻ የሚታይ)።',
            'Update Video' => 'ቪዲዮውን አዘምን',
            'Public Video Gallery' => 'ይፋዊ የቪዲዮ ማሳያ',
            'Manage workshop showcase videos, technical walkthroughs, and streaming media assets' => 'የዎርክሾፕ ማሳያ ቪዲዮዎችን፣ ቴክኒካዊ ትምህርቶችን እና የሚዲያ ሀብቶችን ያስተዳድሩ',
            'Search by title or description…' => 'በርዕስ ወይም በመግለጫ ፈልግ…',
            'All Categories' => 'ሁሉም ምድቦች',
            'Clear' => 'አጽዳ',
            'Preview' => 'ቅድመ እይታ',
            'No videos found matching the criteria.' => 'ከመስፈርቱ ጋር የሚዛመድ ምንም ቪዲዮ አልተገኘም።',
            'Add First Video' => 'የመጀመሪያ ቪዲዮ ጨምር',
            'Uploaded File' => 'የተሰቀለ ፋይል',
            'Unpublish this video from public gallery?' => 'ይህን ቪዲዮ ከይፋዊ ማሳያ ማውረድ ይፈልጋሉ?',
            'Are you sure you want to delete this video permanently?' => 'ይህን ቪዲዮ በቋሚነት ለማጥፋት እርግጠኛ ነዎት?',
            'Video publication status updated.' => 'የቪዲዮው የማሳተም ሁኔታ ተዘምኗል።',
            '‹ Prev' => '‹ ቀዳሚ',
            'Next ›' => 'ቀጣይ ›',
            'Accounts Receivable, Ledger & Cash Control' => 'ተሰብሳቢ ሒሳቦች፣ የሒሳብ መዝገብ እና የጥሬ ገንዘብ ቁጥጥር',
            'Outstanding invoices' => 'ያልተከፈሉ ደረሰኞች',
            'Financial report' => 'የፋይናንስ ሪፖርት',
            'Please provide all vehicle details (plate, make, model).' => 'እባክዎ ሁሉንም የተሽከርካሪ ዝርዝሮች (ታርጋ፣ ዓይነት፣ ሞዴል) ያቅርቡ።',
            'Parts Catalog & Supply Chain Logistics' => 'የመለዋወጫ ዕቃዎች ካታሎግ እና የአቅርቦት ሰንሰለት',
            'Stock catalog' => 'የክምችት ካታሎግ',
            'Browse parts' => 'መለዋወጫዎችን አስስ',
            'Service catalog' => 'የአገልግሎት ካታሎግ',
            'Front Desk & Workshop Intake Command' => 'የፊት ጠረጴዛ እና የተሽከርካሪ መቀበያ መቆጣጠሪያ',
            'Reporting period' => 'የሪፖርት ጊዜ',
            'Date range' => 'የቀን ክልል',
            'From' => 'ከ',
            'To' => 'እስከ',
            'Chart grouping' => 'የቻርት አደረጃጀት',
            'Update dashboard' => 'ዳሽቦርድ አዘምን',
            'FINANCIAL KPIs' => 'የፋይናንስ ቁልፍ አመልካቾች',
            'BUSINESS ANALYTICS' => 'የቢዝነስ ትንተና',
            'Not collected cash' => 'ያልተሰበሰበ ጥሬ ገንዘብ',
            'Active Job Cards' => 'በሂደት ላይ ያሉ የሥራ ካርዶች',
            'Open jobs received in selected period' => 'በተመረጠው ጊዜ ውስጥ የተቀበሉ ክፍት ሥራዎች',
            'Completed Jobs' => 'የተጠናቀቁ ሥራዎች',
            'Monthly targets compare to current calendar month actuals' => 'ወርሃዊ ግቦች ከአሁኑ ወር ትክክለኛ አፈጻጸም ጋር ይነጻጸራሉ',
            'CHARTS' => 'ቻርቶች',
            'Interactive financial analytics' => 'ተግባራዊ የፋይናንስ ትንተና',
            'OPERATIONAL OVERVIEW' => 'የሥራዎች አጠቃላይ እይታ',
            'Active client accounts' => 'ንቁ የደንበኞች መለያዎች',
            'Workshop directory' => 'የዎርክሾፕ ማውጫ',
            'Vehicles under care' => 'በእንክብካቤ ስር ያሉ ተሽከርካሪዎች',
            'Fleet inventory' => 'የተሽከርካሪዎች ዝርዝር',
            'Today\'s Appointments' => 'የዛሬ ቀጠሮዎች',
            'Customer schedule' => 'የደንበኞች መርሃ ግብር',
            'Workshop floor repair operations' => 'የዎርክሾፕ የጥገና ሥራዎች',
            'APPOINTMENT ANALYTICS' => 'የቀጠሮዎች ትንተና',
            'Appointment Status' => 'የቀጠሮ ሁኔታ',
            'Workshop booking lifecycle' => 'የዎርክሾፕ ማስያዣ ሂደት',
            'Daily customer appointment intake' => 'ዕለታዊ የተሽከርካሪ መቀበያ',
            'WORKSTATION METRICS' => 'የሥራ ጣቢያ መለኪያዎች',
            'Assigned tasks strictly scoped to your workstation' => 'ለእርስዎ ጣቢያ ብቻ የተመደቡ ሥራዎች',
            'Active Assigned Jobs' => 'ንቁ የተመደቡ ሥራዎች',
            'Vehicles currently in your queue' => 'በእርስዎ መስመር ውስጥ ያሉ ተሽከርካሪዎች',
            'Completed Repairs' => 'የተጠናቀቁ ጥገናዎች',
            'Lifetime completed' => 'በአጠቃላይ የተጠናቀቁ',
            'WORKSTATION ANALYTICS' => 'የሥራ ጣቢያ ትንተና',
            'Assigned vehicle distribution and monthly repair completions' => 'የተመደቡ ተሽከርካሪዎች ክፍፍል እና ወርሃዊ የጥገና ማጠናቀቂያዎች',
            'Assigned Job Status' => 'የተመደቡ ሥራዎች ሁኔታ',
            'Workstation load across all stages' => 'የሥራ ጫና በሁሉም ደረጃዎች',
            'Active Task Urgency' => 'የአስቸኳይነት ደረጃ',
            'Priority distribution of open jobs' => 'የክፍት ሥራዎች ቅድሚያ ክፍፍል',
            'Monthly completed vehicle handoffs' => 'በየወሩ የተጠናቀቁ ተሽከርካሪዎች ርክክብ',
            'My Active Repair Queue' => 'የእኔ ንቁ የጥገና መስመር',
            'Vehicles assigned to you requiring diagnosis, parts, or repair' => 'ምርመራ፣ ዕቃ ወይም ጥገና የሚያስፈልጋቸው ለእርስዎ የተመደቡ ተሽከርካሪዎች',
            'WAREHOUSE STATUS' => 'የመጋዘን ሁኔታ',
            'Inventory stock indicators' => 'የክምችት አመልካቾች',
            'Catalog Item Types' => 'የካታሎግ ዕቃዎች ዓይነቶች',
            'Active catalog' => 'ንቁ ካታሎግ',
            'Out of Stock' => 'ያለቀባቸው ዕቃዎች',
            'Low Stock Alerts' => 'ዝቅተኛ ክምችት ማስጠንቀቂያዎች',
            'Items at or below reorder threshold' => 'ከዝቅተኛው ወለል በታች የሆኑ ዕቃዎች',
            'Active Suppliers' => 'ንቁ አቅራቢዎች',
            'Verified parts supply partners' => 'የተረጋገጡ የመለዋወጫ አቅራቢዎች',
            'Procurement vendors' => 'የግዢ አቅራቢዎች',
            'INVENTORY ANALYTICS' => 'የክምችት ትንተና',
            'Stock Health Distribution' => 'የክምችት ጤና ክፍፍል',
            'Catalog items by stock level' => 'ዕቃዎች በክምችት ደረጃቸው',
            'Physical Units by Category' => 'አጠቃላይ ዕቃዎች በምድብ',
            'Top inventory categories by quantity' => 'በብዛት ቀዳሚ የሆኑ ምድቦች',
            'FINANCIAL LEDGER' => 'የፋይናንስ ሒሳብ መዝገብ',
            'Total payments less refunds recorded' => 'ጠቅላላ ክፍያዎች ከቅናሾች በኋላ',
            'Pending collection' => 'መሰብሰብ ያለበት',
            'Gross billed services and parts' => 'ጠቅላላ የተጠየቀ የአገልግሎት እና ዕቃዎች ዋጋ',
            'Lifetime invoiced' => 'በአጠቃላይ ደረሰኝ የተቆረጠበት',
            'FINANCIAL ANALYTICS' => 'የፋይናንስ ትንተና',
            'Invoice Settlement Status' => 'የደረሰኞች አከፋፈል ሁኔታ',
            'Distribution of billed invoices' => 'የደረሰኞች ክፍፍል',
            'Receivables Aging Brackets' => 'ያልተሰበሰቡ ሒሳቦች የቆይታ ጊዜ',
            'Uncollected balances by age' => 'ያልተሰበሰቡ ቀሪዎች በእድሜያቸው',
            'Monthly cash received minus refunds' => 'ወርሃዊ የተሰበሰበ ጥሬ ገንዘብ ከቅናሾች በኋላ',
            'Payment Channel Distribution' => 'የክፍያ መንገዶች ክፍፍል',
            'Transactions by payment tender' => 'ግብይቶች በክፍያ ዘዴ',
            'Outstanding Accounts Receivable' => 'ተሰብሳቢ ያልተከፈሉ ሒሳቦች',
            'Target not configured' => 'ዒላማ አልተዋቀረም',
            'Issue Date' => 'የተሰጠበት ቀን',
            'By category' => 'በምድብ',
            'Status distribution' => 'የሁኔታ ስርጭት',
            'Units by category (active stock)' => 'ክፍሎች በምድብ (ንቁ ክምችት)',
            'Most requested services and revenue' => 'በብዛት የተጠየቁ አገልግሎቶች እና ገቢ',
            'No expense records in this period.' => 'በዚህ ጊዜ ውስጥ ምንም የወጪ መዝገብ የለም።',
            'No active inventory records.' => 'ምንም ንቁ የዕቃ መዝገብ የለም።',
            'No job service records in this period.' => 'በዚህ ጊዜ ውስጥ ምንም የሥራ አገልግሎት መዝገብ የለም።',
            'INVENTORY' => 'የዕቃ ክምችት',
            'Manage inventory' => 'የዕቃ ክምችት አስተዳድር',
            'left (reorder at' => 'ቀሪ (ማዘዣ በ',
            'Based on issued (non-returned) job parts' => 'ለሥራ በተሰጡ (ያልተመለሱ) መለዋወጫዎች ላይ የተመሠረተ',
            'issued' => 'የወጣ',
            'All inventory items are currently above safety stock thresholds.' => 'ሁሉም የዕቃ ክምችቶች በአሁኑ ጊዜ ከአስተማማኝ የክምችት መጠን በላይ ናቸው።',
            'Workshop jobs' => 'የዎርክሾፕ ሥራዎች',
            'part requests' => 'የዕቃ ጥያቄዎች',
            '0 pending' => '0 በመጠባበቅ ላይ',
            'Issue parts' => 'መለዋወጫዎችን አውጣ',
            'No workshop jobs currently waiting for parts.' => 'በአሁኑ ጊዜ መለዋወጫ የሚጠብቅ የዎርክሾፕ ሥራ የለም።',
            'Inventory ledger' => 'የዕቃ ክምችት መዝገብ',
            'Reference / Notes' => 'ማጣቀሻ / ማስታወሻዎች',
            'Purchase / Stock In' => 'ግዢ / ገቢ ዕቃ',
            'Issued to Job' => 'ለሥራ የወጣ',
            'Returned from Job' => 'ከሥራ የተመለሰ',
            'Adjustment (+)' => 'ማስተካከያ (+)',
            'Adjustment (-)' => 'ማስተካከያ (-)',
            'No inventory transaction records found.' => 'ምንም የዕቃ ልውውጥ መዝገቦች አልተገኙም።',
            'No payment transactions recorded yet.' => 'እስካሁን የተመዘገበ የክፍያ ልውውጥ የለም።',
            'Expense ledger' => 'የወጪ መዝገብ',
            'Vendor / Notes' => 'ሻጭ / ማስታወሻዎች',
            'No operating expense entries found.' => 'ምንም የሥራ ማስኬጃ ወጪዎች አልተገኙም።',
            'Expense ledger module is not enabled.' => 'የወጪ መዝገብ ሞጁል አልነቃም።',
            'Support & Telegram' => 'ድጋፍ እና ቴሌግራም',
            'Create an Account' => 'አካውንት ይፍጠሩ',
            'Register with :garage for precision service' => 'ለተሟላ እና ጥራት ላለው አገልግሎት በ:garage ይመዝገቡ',
            'Choose a unique username' => 'ልዩ የተጠቃሚ ስም ይምረጡ',
            'Minimum 10 characters with numbers & symbols' => 'ቢያንስ 10 ፊደላት ከቁጥሮች እና ምልክቶች ጋር',
            'Re-enter your password' => 'የይለፍ ቃልዎን በድጋሚ ያስገቡ',
            'I have read and agree to the' => 'አንብቤ ተስማምቻለሁ ከ',
            'and' => 'እና',
            'Work on job' => 'ሥራ ጀምር',
            'No active repair jobs assigned to your workstation at this time.' => 'በአሁኑ ጊዜ ለሥራ ጣቢያዎ የተመደበ ምንም ንቁ የጥገና ሥራ የለም።',
            'Completed history' => 'የተጠናቀቁ ታሪክ',
            'Review' => 'ይገምግሙ',
            'No completed job history recorded yet.' => 'እስካሁን የተመዘገበ የተጠናቀቀ የሥራ ታሪክ የለም።',
            'No active repair job cards at this time.' => 'በአሁኑ ጊዜ ምንም ንቁ የጥገና የሥራ ካርዶች የሉም።',
            'Customer directory' => 'የደንበኞች ማውጫ',
            'Revenue, expenses, net profit' => 'ገቢ፣ ወጪዎች፣ የተጣራ ትርፍ',
            'Receipts, job issuances, and audit adjustments' => 'ገቢ ዕቃዎች፣ ለሥራ የተሰጡ እና የኦዲት ማስተካከያዎች',
            'Parts depleted or below safety threshold' => 'ያለቁ ወይም ከአስተማማኝ ወለል በታች የሆኑ ዕቃዎች',

        ];

        $dict = array_merge($fileDict, $legacy);
        return $dict;
    }
}

if (!function_exists('interpolate_params')) {
    function interpolate_params(string $text, array $params): string
    {
        if (empty($params)) {
            return $text;
        }
        $replace = [];
        foreach ($params as $key => $val) {
            $replace[':' . $key] = (string) $val;
            $replace['{' . $key . '}'] = (string) $val;
        }
        return strtr($text, $replace);
    }
}

if (!function_exists('__')) {
    function __(string $key, array $params = []): string
    {
        $lang = current_lang();
        $dict = ($lang === 'am') ? amharic_dictionary() : load_language_file('en');

        if (isset($dict[$key])) {
            return interpolate_params($dict[$key], $params);
        }

        $trimmed = trim($key);
        if (isset($dict[$trimmed])) {
            return interpolate_params($dict[$trimmed], $params);
        }

        if ($lang === 'en') {
            $enDict = load_language_file('en');
            if (isset($enDict[$key])) {
                return interpolate_params($enDict[$key], $params);
            }
        }

        return interpolate_params($key, $params);
    }
}

if (!function_exists('__e')) {
    function __e(string $key, array $params = []): string
    {
        return htmlspecialchars(__($key, $params), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('t')) {
    function t(string $key, ?string $default = null, array $params = []): string
    {
        $lang = current_lang();
        if ($lang !== 'am') {
            $val = $default !== null ? $default : $key;
            if (str_contains($key, '.')) {
                $enDict = load_language_file('en');
                if (isset($enDict[$key])) {
                    $val = $enDict[$key];
                }
            }
            return interpolate_params($val, $params);
        }

        $dict = amharic_dictionary();

        if (isset($dict[$key])) {
            return interpolate_params($dict[$key], $params);
        }

        $trimmed = trim($key);
        if (isset($dict[$trimmed])) {
            return interpolate_params($dict[$trimmed], $params);
        }

        $val = $default !== null ? $default : $key;
        return interpolate_params($val, $params);
    }
}

if (!function_exists('e_t')) {
    function e_t(string $key, ?string $default = null, array $params = []): string
    {
        return htmlspecialchars(t($key, $default, $params), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
