<?php
declare(strict_types=1);

final class ContactInquiryService
{
    public static function validate(array $data): array
    {
        $errors = validate($data, [
            'name' => 'required|max:120',
            'email' => 'required|email|max:150',
            'phone' => 'max:40',
            'channel' => 'required|in:Telegram,Phone Call,Email,SMS',
            'subject' => 'required|max:150',
            'message' => 'required|max:5000',
        ]);

        if (($data['phone'] ?? '') !== '' && !preg_match('/^\+?[0-9 ()-]{7,40}$/', (string) $data['phone'])) {
            $errors['phone'] = 'Please enter a valid phone number.';
        }

        return $errors;
    }

    public static function create(array $data): int
    {
        return Database::insert(
            'INSERT INTO contact_inquiries (name, email, phone, channel, subject, message, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $data['name'],
                $data['email'],
                $data['phone'] !== '' ? $data['phone'] : null,
                $data['channel'],
                $data['subject'],
                $data['message'],
                client_ip(),
            ]
        );
    }
}
