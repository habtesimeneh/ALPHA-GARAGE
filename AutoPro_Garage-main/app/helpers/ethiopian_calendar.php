<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — ETHIOPIAN CALENDAR & TIME CONVERSION ENGINE (የኢትዮጵያ ዘመን እና ሰዓት አቆጣጠር)
 * Provides comprehensive conversion between Gregorian and Ethiopian calendars,
 * Ethiopian month and day names, and Ethiopian 12-hour cyclical time calculation.
 *
 * Principles:
 * - Standalone: Pure mathematical algorithms, zero external extension dependency (no php-calendar needed).
 * - Precise: Exact leap year handling (Pagume 5/6, Gregorian leap alignment).
 * - Non-destructive: Database stores ISO timestamps; display adapts dynamically based on language / locale.
 */

if (!function_exists('eth_gregorian_to_jdn')) {
    function eth_gregorian_to_jdn(int $year, int $month, int $day): int
    {
        $a = (int) floor((14 - $month) / 12);
        $y = $year + 4800 - $a;
        $m = $month + 12 * $a - 3;
        return $day + (int) floor((153 * $m + 2) / 5) + 365 * $y + (int) floor($y / 4) - (int) floor($y / 100) + (int) floor($y / 400) - 32045;
    }
}

if (!function_exists('eth_jdn_to_gregorian')) {
    function eth_jdn_to_gregorian(int $jdn): array
    {
        $a = $jdn + 32044;
        $b = (int) floor((4 * $a + 3) / 146097);
        $c = $a - (int) floor((146097 * $b) / 4);
        $d = (int) floor((4 * $c + 3) / 1461);
        $e = $c - (int) floor((1461 * $d) / 4);
        $m = (int) floor((5 * $e + 2) / 153);
        
        $day = $e - (int) floor((153 * $m + 2) / 5) + 1;
        $month = $m + 3 - 12 * (int) floor($m / 10);
        $year = 100 * $b + $d - 4800 + (int) floor($m / 10);
        
        return ['year' => $year, 'month' => $month, 'day' => $day];
    }
}

if (!function_exists('eth_jdn_to_ethiopian')) {
    function eth_jdn_to_ethiopian(int $jdn): array
    {
        // 1723856 is the Ethiopian Epoch JDN (Meskerem 1, 1 E.C. = Aug 29, 8 A.D. Julian)
        $r = ($jdn - 1723856) % 1461;
        $n = ($r % 365) + 365 * (int) floor($r / 1460);
        
        $year = 4 * (int) floor(($jdn - 1723856) / 1461) + (int) floor($r / 365) - (int) floor($r / 1460);
        $month = (int) floor($n / 30) + 1;
        $day = ($n % 30) + 1;
        
        return ['year' => $year, 'month' => $month, 'day' => $day];
    }
}

if (!function_exists('eth_ethiopian_to_jdn')) {
    function eth_ethiopian_to_jdn(int $year, int $month, int $day): int
    {
        return (int) (1723856 + 365 * $year + (int) floor($year / 4) + 30 * ($month - 1) + $day - 1);
    }
}

if (!function_exists('gregorian_to_ethiopian')) {
    function gregorian_to_ethiopian(int $year, int $month, int $day): array
    {
        $jdn = eth_gregorian_to_jdn($year, $month, $day);
        return eth_jdn_to_ethiopian($jdn);
    }
}

if (!function_exists('ethiopian_to_gregorian')) {
    function ethiopian_to_gregorian(int $year, int $month, int $day): array
    {
        $jdn = eth_ethiopian_to_jdn($year, $month, $day);
        return eth_jdn_to_gregorian($jdn);
    }
}

if (!function_exists('ethiopian_month_names')) {
    function ethiopian_month_names(): array
    {
        return [
            1  => ['am' => 'መስከረም', 'en' => 'Meskerem'],
            2  => ['am' => 'ጥቅምት',  'en' => 'Tikimt'],
            3  => ['am' => 'ኅዳር',   'en' => 'Hidar'],
            4  => ['am' => 'ታኅሣሥ',  'en' => 'Tahsas'],
            5  => ['am' => 'ጥር',     'en' => 'Tir'],
            6  => ['am' => 'የካቲት',  'en' => 'Yekatit'],
            7  => ['am' => 'መጋቢት',  'en' => 'Megabit'],
            8  => ['am' => 'ሚያዝያ',  'en' => 'Miyazya'],
            9  => ['am' => 'ግንቦት',  'en' => 'Ginbot'],
            10 => ['am' => 'ሰኔ',     'en' => 'Sene'],
            11 => ['am' => 'ሐምሌ',   'en' => 'Hamle'],
            12 => ['am' => 'ነሐሴ',   'en' => 'Nehase'],
            13 => ['am' => 'ጳጉሜ',   'en' => 'Pagume'],
        ];
    }
}

if (!function_exists('ethiopian_day_names')) {
    function ethiopian_day_names(): array
    {
        return [
            0 => ['am' => 'እሑድ',   'en' => 'Sunday'],
            1 => ['am' => 'ሰኞ',     'en' => 'Monday'],
            2 => ['am' => 'ማክሰኞ',  'en' => 'Tuesday'],
            3 => ['am' => 'ረቡዕ',    'en' => 'Wednesday'],
            4 => ['am' => 'ሐሙስ',   'en' => 'Thursday'],
            5 => ['am' => 'ዓርብ',    'en' => 'Friday'],
            6 => ['am' => 'ቅዳሜ',   'en' => 'Saturday'],
        ];
    }
}

if (!function_exists('gregorian_to_ethiopian_time')) {
    /**
     * Converts 24-hour Gregorian time to Ethiopian traditional 12-hour cyclical time.
     * In Ethiopia, the 12-hour cycle begins at sunrise (~06:00 AM Gregorian = 12:00 Daytime).
     *
     * @param int $gregHour 0 to 23
     * @param int $gregMinute 0 to 59
     * @param string $lang 'am' or 'en'
     * @return array{hour: int, minute: int, period: string, formatted: string}
     */
    function gregorian_to_ethiopian_time(int $gregHour, int $gregMinute, string $lang = 'am'): array
    {
        $eh = ($gregHour + 6) % 12;
        if ($eh === 0) {
            $eh = 12;
        }

        // Determine period name in Ethiopian context
        if ($gregHour >= 6 && $gregHour < 12) {
            $period = ($lang === 'am') ? 'ጥዋት' : 'Morning';
        } elseif ($gregHour >= 12 && $gregHour < 16) {
            $period = ($lang === 'am') ? 'ከሰዓት' : 'Afternoon';
        } elseif ($gregHour >= 16 && $gregHour < 19) {
            $period = ($lang === 'am') ? 'ከሰዓት' : 'Late Afternoon';
        } elseif ($gregHour >= 19 && $gregHour <= 23) {
            $period = ($lang === 'am') ? 'ምሽት' : 'Evening';
        } else { // 00:00 to 05:59
            $period = ($lang === 'am') ? 'ሌሊት' : 'Night';
        }

        return [
            'hour'      => $eh,
            'minute'    => $gregMinute,
            'period'    => $period,
            'formatted' => sprintf('%02d:%02d %s', $eh, $gregMinute, $period),
        ];
    }
}

if (!function_exists('ethiopian_format')) {
    /**
     * Formats a Unix timestamp or date string into an Ethiopian formatted string.
     *
     * Format modes:
     * - 'date': "ኅዳር 15, 2017 ዓ.ም" (or "Hidar 15, 2017 E.C.")
     * - 'datetime': "ኅዳር 15, 2017 ዓ.ም፣ 04:30 ከሰዓት"
     * - 'full': "ማክሰኞ፣ ኅዳር 15 ቀን 2017 ዓ.ም"
     * - 'today': "ማክሰኞ፣ ኅዳር 15 ቀን 2017 ዓ.ም" (Amharic) or "Tue, 24 Nov 2026 (Hidar 15, 2019 E.C.)" (English)
     */
    function ethiopian_format(mixed $datetime, string $mode = 'date', ?string $lang = null): string
    {
        if (!$datetime || $datetime === '—' || str_starts_with((string)$datetime, '0000')) {
            return '—';
        }

        $timestamp = is_numeric($datetime) ? (int)$datetime : strtotime((string)$datetime);
        if (!$timestamp) {
            return '—';
        }

        $lang = $lang ?? (function_exists('current_lang') ? current_lang() : 'en');
        $gy = (int) date('Y', $timestamp);
        $gm = (int) date('m', $timestamp);
        $gd = (int) date('d', $timestamp);
        $w  = (int) date('w', $timestamp);
        $gh = (int) date('H', $timestamp);
        $gmin = (int) date('i', $timestamp);

        $eth = gregorian_to_ethiopian($gy, $gm, $gd);
        $months = ethiopian_month_names();
        $days = ethiopian_day_names();

        $monthName = $months[$eth['month']][$lang === 'am' ? 'am' : 'en'] ?? (string)$eth['month'];
        $dayName = $days[$w][$lang === 'am' ? 'am' : 'en'] ?? '';

        $yearSuffix = ($lang === 'am') ? 'ዓ.ም' : 'E.C.';

        if ($mode === 'today') {
            if ($lang === 'am') {
                return sprintf('%s፣ %s %d ቀን %d %s', $dayName, $monthName, $eth['day'], $eth['year'], $yearSuffix);
            }
            return sprintf('%s, %s ( %s %d, %d %s )', date('D', $timestamp), date('d M Y', $timestamp), $monthName, $eth['day'], $eth['year'], $yearSuffix);
        }

        if ($mode === 'full') {
            if ($lang === 'am') {
                return sprintf('%s፣ %s %d ቀን %d %s', $dayName, $monthName, $eth['day'], $eth['year'], $yearSuffix);
            }
            return sprintf('%s, %s %d, %d %s', $dayName, $monthName, $eth['day'], $eth['year'], $yearSuffix);
        }

        if ($mode === 'datetime') {
            $etime = gregorian_to_ethiopian_time($gh, $gmin, $lang);
            if ($lang === 'am') {
                return sprintf('%s %d, %d %s፣ %s', $monthName, $eth['day'], $eth['year'], $yearSuffix, $etime['formatted']);
            }
            return sprintf('%s %d, %d %s, %s', $monthName, $eth['day'], $eth['year'], $yearSuffix, date('H:i', $timestamp));
        }

        // Default 'date'
        if ($lang === 'am') {
            return sprintf('%s %d, %d %s', $monthName, $eth['day'], $eth['year'], $yearSuffix);
        }
        return sprintf('%s %d, %d %s', $monthName, $eth['day'], $eth['year'], $yearSuffix);
    }
}

if (!function_exists('ethiopian_today')) {
    function ethiopian_today(string $mode = 'today', ?string $lang = null): string
    {
        return ethiopian_format(time(), $mode, $lang);
    }
}
