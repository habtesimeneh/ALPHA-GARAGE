<?php
declare(strict_types=1);

/** Payments are immutable: there is no edit or delete. Mistakes are corrected through an authorised refund. */
final class PaymentService
{
    public static function record(int $invoiceId, string $amount, string $method, string $reference, string $notes, ?string $idempotencyKey = null): int
    {
        if (!in_array($method, Setting::paymentMethods(), true)) { throw new DomainException('Unknown payment method.'); }
        $cents = to_cents($amount);
        if ($cents <= 0) { throw new DomainException('Enter an amount greater than zero.'); }

        $u = function_exists('current_user') ? current_user() : null;
        $userId = (int) ($u['id'] ?? 0);

        // If an idempotency key is provided, evaluate through the idempotency engine
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $key = PaymentIdempotencyService::validateKey($idempotencyKey);
            $acquire = PaymentIdempotencyService::acquire($key, $userId, $invoiceId, 'payment', from_cents($cents), $method, $reference);
            if ($acquire['status'] === 'completed') {
                return (int) $acquire['payment_id'];
            }
        }

        try {
            $id = (int) Database::transaction(function () use ($invoiceId, $cents, $method, $reference, $notes) {
                $inv = InvoiceService::lock($invoiceId);
                if ($inv['payment_status'] === 'Refunded') { throw new DomainException('This invoice was fully refunded and is closed.'); }
                $balance = to_cents($inv['balance']);
                if ($cents > $balance) { throw new DomainException('The payment (' . money(from_cents($cents)) . ') exceeds the outstanding balance (' . money(from_cents($balance)) . ').'); }
                $pid = self::insertRow($invoiceId, 'payment', $cents, $method, $reference, $notes);
                $t = InvoiceService::recalculate($invoiceId);
                AuditLogger::log('payment_recorded', 'payments', $pid, ['payment_status' => $inv['payment_status'], 'balance' => $inv['balance']],
                    ['invoice' => $inv['invoice_number'], 'amount' => from_cents($cents), 'method' => $method, 'payment_status' => $t['payment_status'], 'balance' => $t['balance']]);
                return $pid;
            });

            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                $inv = InvoiceService::find($invoiceId);
                PaymentIdempotencyService::complete($idempotencyKey, $id, [
                    'payment_id' => $id,
                    'invoice_id' => $invoiceId,
                    'amount' => from_cents($cents),
                    'balance' => $inv['balance'] ?? '0.00',
                    'payment_status' => $inv['payment_status'] ?? '',
                ]);
            }

            return $id;
        } catch (Throwable $e) {
            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                PaymentIdempotencyService::fail($idempotencyKey, $e->getMessage());
            }
            throw $e;
        }
    }

    public static function refund(int $invoiceId, string $amount, string $method, string $reason, ?string $idempotencyKey = null): int
    {
        $cents = to_cents($amount);
        if ($cents <= 0) { throw new DomainException('Enter a refund amount greater than zero.'); }
        if ($reason === '') { throw new DomainException('A reason is required for every refund.'); }
        if (!in_array($method, Setting::paymentMethods(), true)) { throw new DomainException('Unknown payment method.'); }

        $u = function_exists('current_user') ? current_user() : null;
        $userId = (int) ($u['id'] ?? 0);

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $key = PaymentIdempotencyService::validateKey($idempotencyKey);
            $acquire = PaymentIdempotencyService::acquire($key, $userId, $invoiceId, 'refund', from_cents($cents), $method, $reason);
            if ($acquire['status'] === 'completed') {
                return (int) $acquire['payment_id'];
            }
        }

        try {
            $id = (int) Database::transaction(function () use ($invoiceId, $cents, $method, $reason) {
                $inv = InvoiceService::lock($invoiceId);
                $net = InvoiceService::netPaid($invoiceId)['net'];
                if ($cents > $net) { throw new DomainException('A refund cannot exceed the net amount paid (' . money(from_cents($net)) . ').'); }
                $pid = self::insertRow($invoiceId, 'refund', $cents, $method, '', $reason);
                $t = InvoiceService::recalculate($invoiceId);
                AuditLogger::log('payment_refunded', 'payments', $pid, ['payment_status' => $inv['payment_status'], 'amount_paid' => $inv['amount_paid']],
                    ['invoice' => $inv['invoice_number'], 'refund' => from_cents($cents), 'reason' => $reason, 'payment_status' => $t['payment_status'], 'amount_paid' => $t['amount_paid']]);
                return $pid;
            });

            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                $inv = InvoiceService::find($invoiceId);
                PaymentIdempotencyService::complete($idempotencyKey, $id, [
                    'payment_id' => $id,
                    'invoice_id' => $invoiceId,
                    'amount' => from_cents($cents),
                    'amount_paid' => $inv['amount_paid'] ?? '0.00',
                    'payment_status' => $inv['payment_status'] ?? '',
                ]);
            }

            return $id;
        } catch (Throwable $e) {
            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                PaymentIdempotencyService::fail($idempotencyKey, $e->getMessage());
            }
            throw $e;
        }
    }

    public static function find(int $id): ?array
    {
        return Database::one(
            'SELECT p.*, i.invoice_number, i.grand_total, i.amount_paid AS invoice_paid, i.balance AS invoice_balance, i.payment_status,
                    c.full_name AS customer_name, j.job_number, u.full_name AS recorded_by_name
             FROM payments p JOIN invoices i ON i.id = p.invoice_id JOIN customers c ON c.id = i.customer_id
             JOIN job_cards j ON j.id = i.job_card_id LEFT JOIN users u ON u.id = p.recorded_by WHERE p.id = ?', [$id]);
    }

    private static function insertRow(int $invoiceId, string $kind, int $cents, string $method, string $reference, string $notes): int
    {
        $recordedBy = function_exists('current_user') ? (current_user()['id'] ?? null) : null;
        $id = Database::insert(
            'INSERT INTO payments (receipt_number, invoice_id, kind, amount, method, reference, paid_at, notes, recorded_by) VALUES (?,?,?,?,?,?,?,?,?)',
            [Numbering::placeholder(), $invoiceId, $kind, from_cents($cents), $method, $reference ?: null, date('Y-m-d H:i:s'), $notes ?: null, $recordedBy]);
        Database::run('UPDATE payments SET receipt_number = ? WHERE id = ?', [Numbering::make($kind === 'refund' ? 'RFD' : 'RCT', $id), $id]);
        return $id;
    }
}
