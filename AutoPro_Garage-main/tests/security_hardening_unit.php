<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/app/helpers/functions.php';
require APP_ROOT . '/app/services/ContactInquiryService.php';
require APP_ROOT . '/app/services/CryptoService.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$now = 1_700_000_000;
$token = str_repeat('a', 64);
check(csrf_token_is_valid($token, $token, $now - 7200, $now), 'CSRF token should be valid at its expiry boundary.');
check(!csrf_token_is_valid($token, $token, $now - 7201, $now), 'Expired CSRF token should be rejected.');
check(!csrf_token_is_valid($token, $token, $now + 1, $now), 'Future-dated CSRF token should be rejected.');
check(!csrf_token_is_valid($token, str_repeat('b', 64), $now, $now), 'Mismatched CSRF token should be rejected.');

$contact = [
    'name' => 'Example Customer',
    'email' => 'customer@example.com',
    'phone' => '+1 555 123 4567',
    'channel' => 'Email',
    'subject' => 'Service question',
    'message' => 'Please contact me about an appointment.',
];
check(ContactInquiryService::validate($contact) === [], 'Valid contact inquiry should pass validation.');
$contact['channel'] = 'Unknown';
$contact['phone'] = 'not-a-phone';
$errors = ContactInquiryService::validate($contact);
check(isset($errors['channel']) && isset($errors['phone']), 'Invalid contact channel and phone should be rejected.');

putenv('GMS_ENCRYPTION_KEY=not-a-valid-key');
$invalidKeyRejected = false;
try {
    CryptoService::getKey();
} catch (RuntimeException) {
    $invalidKeyRejected = true;
}
check($invalidKeyRejected, 'Malformed encryption key configuration should fail explicitly.');

$hexKey = str_repeat('12', 32);
putenv('GMS_ENCRYPTION_KEY=' . $hexKey);
check(CryptoService::getKey() === hex2bin($hexKey), '64-character hexadecimal key should decode to 32 bytes.');
$ciphertext = CryptoService::encrypt('hardening-unit-test');
check(CryptoService::decrypt($ciphertext) === 'hardening-unit-test', 'Encryption roundtrip should preserve plaintext.');

echo "Security hardening unit tests passed.\n";
