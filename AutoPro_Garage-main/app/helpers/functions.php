<?php
declare(strict_types=1);

/* ---------------------------------------------------------------- output / urls */
function e(mixed $v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_url(): string
{
    static $base = null;
    if ($base === null) {
        $public = realpath(APP_ROOT . '/public');
        $doc    = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
        $base   = '';
        if ($public && $doc && str_starts_with($public, $doc) && $public !== $doc) {
            $base = str_replace('\\', '/', substr($public, strlen($doc)));
        }
    }
    return $base;
}

function url(string $path = ''): string
{
    return base_url() . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    $file = APP_ROOT . '/public/assets/' . ltrim($path, '/');
    $v = is_file($file) ? (string) filemtime($file) : '1';
    return url('assets/' . ltrim($path, '/')) . '?v=' . $v;
}

function redirect(string $path): never
{
    header('Location: ' . (preg_match('~^https?://~i', $path) ? url('dashboard.php') : url($path)));
    exit;
}

function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';   // never trust X-Forwarded-For without a trusted proxy
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function user_agent(): string
{
    return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
}

/* ---------------------------------------------------------------- flash / csrf */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function pull_flash(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function csrf_token(): string
{
    $createdAt = (int) ($_SESSION['csrf_created_at'] ?? 0);
    if (empty($_SESSION['csrf']) || $createdAt < 1 || time() - $createdAt > 7200) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_created_at'] = time();
    }
    return $_SESSION['csrf'];
}

function csrf_token_is_valid(mixed $submitted, mixed $token, int $createdAt, int $now): bool
{
    return is_string($submitted)
        && is_string($token)
        && $token !== ''
        && $createdAt > 0
        && $now >= $createdAt
        && $now - $createdAt <= 7200
        && hash_equals($token, $submitted);
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!csrf_token_is_valid(
        $sent,
        $_SESSION['csrf'] ?? null,
        (int) ($_SESSION['csrf_created_at'] ?? 0),
        time()
    )) {
        AuditLogger::logSafe('csrf_validation_failed', 'security', null, null, ['uri' => $_SERVER['REQUEST_URI'] ?? ''], 'denied');
        abort(419, 'Your session form token is invalid or has expired. Go back, reload the page and try again.');
    }
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function require_post(): void
{
    if (!is_post()) {
        abort(405, 'This action only accepts POST requests.');
    }
}

/* ---------------------------------------------------------------- errors */
function abort(int $code, string $message = ''): never
{
    http_response_code($code);
    $titles = [403 => 'Access denied', 404 => 'Not found', 405 => 'Method not allowed', 419 => 'Session expired', 429 => 'Too many requests', 500 => 'Something went wrong'];
    $title = $titles[$code] ?? 'Error';
    $message = $message ?: 'The request could not be completed.';
    if (!headers_sent() && function_exists('current_user') && current_user()) {
        $pageTitle = $title; $active = '';
        require_once APP_ROOT . '/app/views/layout.php';
        ui_header($title, '');
        echo '<div class="card empty-state"><div class="empty-code">' . (int) $code . '</div><h2>' . e($title) . '</h2><p>' . e($message) . '</p><a class="btn btn-primary" href="' . e(url('dashboard.php')) . '">Back to dashboard</a></div>';
        ui_footer();
    } else {
        echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($title) . '</title>'
           . css_links() . '<body class="auth-body"><div class="card empty-state"><div class="empty-code">' . (int) $code . '</div><h2>' . e($title) . '</h2><p>' . e($message) . '</p><a class="btn btn-primary" href="' . e(url('login.php')) . '">Go to sign in</a></div></body>';
    }
    exit;
}

function app_log(string $message): void
{
    $dir = APP_ROOT . '/storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents($dir . '/app.log', '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/* ---------------------------------------------------------------- input / validation */
function post(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function get(string $key, string $default = ''): string
{
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function get_int(string $key): int
{
    return max(0, (int) ($_GET[$key] ?? 0));
}

function post_int(string $key): int
{
    return max(0, (int) ($_POST[$key] ?? 0));
}

/**
 * Tiny server-side validator.
 * Rules: required, max:N, min:N, email, int, numeric, date, in:a,b, min_val:N, max_val:N, phone, plate
 * @return array<string,string> field => error message
 */
function validate(array $data, array $rules): array
{
    $errors = [];
    foreach ($rules as $field => $ruleString) {
        $value = trim((string) ($data[$field] ?? ''));
        $label = ucfirst(str_replace('_', ' ', $field));
        foreach (explode('|', $ruleString) as $rule) {
            if ($rule === '' || isset($errors[$field])) { continue; }
            [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
            if ($name === 'required' && $value === '') { $errors[$field] = "$label is required."; continue; }
            if ($value === '' && $name !== 'required') { continue; }
            switch ($name) {
                case 'max':     if (mb_strlen($value) > (int) $arg) { $errors[$field] = "$label must be at most $arg characters."; } break;
                case 'min':     if (mb_strlen($value) < (int) $arg) { $errors[$field] = "$label must be at least $arg characters."; } break;
                case 'email':   if (!filter_var($value, FILTER_VALIDATE_EMAIL)) { $errors[$field] = "$label must be a valid email address."; } break;
                case 'int':     if (!preg_match('/^-?\d+$/', $value)) { $errors[$field] = "$label must be a whole number."; } break;
                case 'numeric': if (!preg_match('/^\d+(\.\d{1,2})?$/', $value)) { $errors[$field] = "$label must be a positive amount (max 2 decimals)."; } break;
                case 'date':    $d = DateTime::createFromFormat('Y-m-d', $value); if (!$d || $d->format('Y-m-d') !== $value) { $errors[$field] = "$label must be a valid date."; } break;
                case 'in':      if (!in_array($value, explode(',', (string) $arg), true)) { $errors[$field] = "$label has an invalid value."; } break;
                case 'min_val': if ((float) $value < (float) $arg) { $errors[$field] = "$label must be at least $arg."; } break;
                case 'max_val': if ((float) $value > (float) $arg) { $errors[$field] = "$label must be at most $arg."; } break;
                case 'phone':   if (!preg_match('/^\+?[0-9 ()\-]{7,20}$/', $value)) { $errors[$field] = "$label must be a valid phone number."; } break;
                case 'plate':   if (!preg_match('/^[A-Za-z0-9 \-]{2,20}$/', $value)) { $errors[$field] = "$label may contain letters, digits, spaces and dashes only."; } break;
                case 'vin':     if (!preg_match('/^[A-HJ-NPR-Za-hj-npr-z0-9]{11,17}$/', $value)) { $errors[$field] = "$label must be 11-17 letters/digits (no I, O, Q)."; } break;
            }
        }
    }
    return $errors;
}

function like_escape(string $s): string
{
    return '%' . addcslashes($s, '%_\\') . '%';
}

/* ---------------------------------------------------------------- money (integer cents, no float drift) */
function to_cents(mixed $amount): int
{
    $s = trim((string) $amount);
    if (!preg_match('/^(-)?(\d+)(?:\.(\d{1,2}))?$/', $s, $m)) {
        return 0;
    }
    $cents = ((int) $m[2]) * 100 + (int) str_pad($m[3] ?? '0', 2, '0');
    return $m[1] === '-' ? -$cents : $cents;
}

function from_cents(int $cents): string
{
    $sign = $cents < 0 ? '-' : '';
    $cents = abs($cents);
    return $sign . intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
}

function money(mixed $amount, bool $withCurrency = true): string
{
    $n = number_format((float) $amount, 2, '.', ',');
    return $withCurrency ? Setting::get('currency', 'ETB') . ' ' . $n : $n;
}

function fmt_date(?string $d, string $format = 'd M Y'): string
{
    if (!$d || str_starts_with($d, '0000')) { return '—'; }
    $t = strtotime($d);
    return $t ? date($format, $t) : '—';
}

function fmt_datetime(?string $d): string
{
    return fmt_date($d, 'd M Y, H:i');
}

/* ---------------------------------------------------------------- pagination */
function paginate(int $total, int $perPage, int $page): array
{
    $pages = max(1, (int) ceil($total / $perPage));
    $page  = min(max(1, $page), $pages);
    return ['page' => $page, 'pages' => $pages, 'per_page' => $perPage, 'offset' => ($page - 1) * $perPage, 'total' => $total];
}

function status_class(string $s): string
{
    return 'badge-' . strtolower(preg_replace('/[^a-z0-9]+/i', '-', $s));
}

/** Ordered list of stylesheet <link> tags (design system). Used by the layout and the anonymous error page. */
function css_links(array $extra = []): string
{
    $files = array_merge(['variables', 'reset', 'typography', 'layout', 'components', 'forms', 'tables', 'dashboard', 'auth'], $extra, ['responsive', 'theme']);
    $out = '';
    foreach ($files as $f) {
        $out .= '<link rel="stylesheet" href="' . e(asset('css/' . $f . '.css')) . '">' . "\n";
    }
    return $out;
}

function badge(string $text, ?string $class = null): string
{
    return '<span class="badge ' . e($class ?? status_class($text)) . '">' . e($text) . '</span>';
}

/** Spreadsheet-formula injection guard for CSV exports. */
function csv_safe(mixed $v): string
{
    $s = (string) ($v ?? '');
    return ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)) ? "'" . $s : $s;
}

function send_csv(string $filename, array $columns, array $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $filename) . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_map('csv_safe', $columns));
    foreach ($rows as $r) {
        fputcsv($out, array_map('csv_safe', array_values($r)));
    }
    fclose($out);
    exit;
}
