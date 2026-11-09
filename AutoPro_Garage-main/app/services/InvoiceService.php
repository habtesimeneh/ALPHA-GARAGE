<?php
declare(strict_types=1);

/** Invoices. All arithmetic uses integer cents; DECIMAL columns only store the final values. */
final class InvoiceService
{
    public static function find(int $id): ?array
    {
        return Database::one(
            'SELECT i.*, j.job_number, j.status AS job_status, c.full_name AS customer_name, c.phone AS customer_phone, c.email AS customer_email,
                    c.address AS customer_address, v.plate_number, v.make, v.model, v.year AS vehicle_year, v.vin
             FROM invoices i
             JOIN job_cards j ON j.id = i.job_card_id
             JOIN customers c ON c.id = i.customer_id
             JOIN vehicles v ON v.id = i.vehicle_id WHERE i.id = ?', [$id]);
    }

    public static function items(int $invoiceId): array
    {
        return Database::all('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY item_type ASC, id ASC', [$invoiceId]);
    }

    public static function createForJob(int $jobId): int
    {
        return (int) Database::transaction(function () use ($jobId) {
            $job = Database::one('SELECT * FROM job_cards WHERE id = ? FOR UPDATE', [$jobId]);
            if (!$job) { throw new DomainException('Job card not found.'); }
            if (!in_array($job['status'], ['Completed', 'Delivered'], true)) {
                throw new DomainException('Invoices can only be generated for completed jobs.');
            }
            if (Database::value('SELECT id FROM invoices WHERE job_card_id = ?', [$jobId])) {
                throw new DomainException('An invoice already exists for this job card.');
            }
            $due = (int) Setting::get('invoice_due_days', '14');
            $id = Database::insert(
                'INSERT INTO invoices (invoice_number, job_card_id, customer_id, vehicle_id, issue_date, due_date, tax_rate, created_by) VALUES (?,?,?,?,?,?,?,?)',
                [Numbering::placeholder(), $jobId, $job['customer_id'], $job['vehicle_id'], date('Y-m-d'),
                 date('Y-m-d', strtotime("+$due days")), number_format((float) Setting::get('tax_rate', '0'), 2, '.', ''), current_user()['id'] ?? null]);
            $number = Numbering::make('INV', $id);
            Database::run('UPDATE invoices SET invoice_number = ? WHERE id = ?', [$number, $id]);
            self::rebuildItems($id, $job);
            $t = self::recalculate($id);
            AuditLogger::log('invoice_created', 'invoices', $id, null, ['invoice_number' => $number, 'job_card' => $job['job_number'], 'grand_total' => $t['grand_total']]);
            return $id;
        });
    }

    /** Re-read labor/parts from the job card. Refused once any payment exists. */
    public static function syncFromJob(int $id): void
    {
        Database::transaction(function () use ($id) {
            $inv = self::lock($id);
            if ((int) Database::value('SELECT COUNT(*) FROM payments WHERE invoice_id = ?', [$id]) > 0) {
                throw new DomainException('Invoices with recorded payments can no longer be re-synchronised.');
            }
            $job = Database::one('SELECT * FROM job_cards WHERE id = ?', [$inv['job_card_id']]);
            $before = ['grand_total' => $inv['grand_total'], 'subtotal' => $inv['subtotal']];
            self::rebuildItems($id, $job);
            $t = self::recalculate($id);
            AuditLogger::log('invoice_synced', 'invoices', $id, $before, ['grand_total' => $t['grand_total'], 'subtotal' => $t['subtotal']]);
        });
    }

    public static function setDiscount(int $id, string $discount, string $notes): void
    {
        Database::transaction(function () use ($id, $discount, $notes) {
            $inv = self::lock($id);
            $before = ['discount' => $inv['discount'], 'grand_total' => $inv['grand_total'], 'notes' => $inv['notes']];
            $d = to_cents($discount);
            if ($d < 0 || $d > to_cents($inv['subtotal'])) { throw new DomainException('Discount cannot exceed the invoice subtotal.'); }
            $newGrand = self::totals(to_cents($inv['labor_total']), to_cents($inv['parts_total']), $d, (float) $inv['tax_rate'])['grand'];
            $netPaid = self::netPaid($id);
            if ($newGrand < $netPaid['net']) { throw new DomainException('The discount would make the invoice total lower than the amount already paid.'); }
            Database::run('UPDATE invoices SET discount = ?, notes = ? WHERE id = ?', [from_cents($d), $notes ?: null, $id]);
            $t = self::recalculate($id);
            AuditLogger::log('invoice_updated', 'invoices', $id, $before, ['discount' => $t['discount'], 'grand_total' => $t['grand_total'], 'notes' => $notes]);
        });
    }

    /** Recompute totals, paid amount, balance and status from items + payments. Call inside a transaction. */
    public static function recalculate(int $id): array
    {
        $inv = Database::one('SELECT * FROM invoices WHERE id = ?', [$id]);
        $labor = $parts = 0;
        foreach (Database::all('SELECT item_type, line_total FROM invoice_items WHERE invoice_id = ?', [$id]) as $it) {
            if ($it['item_type'] === 'labor') { $labor += to_cents($it['line_total']); } else { $parts += to_cents($it['line_total']); }
        }
        $discount = min(to_cents($inv['discount']), $labor + $parts);
        $t = self::totals($labor, $parts, $discount, (float) $inv['tax_rate']);
        $p = self::netPaid($id);
        $balance = max(0, $t['grand'] - $p['net']);
        $status = self::statusFor($t['grand'], $p['net'], $p['refunded']);
        Database::run('UPDATE invoices SET labor_total=?, parts_total=?, subtotal=?, discount=?, tax_amount=?, grand_total=?, amount_paid=?, balance=?, payment_status=? WHERE id=?',
            [from_cents($labor), from_cents($parts), from_cents($labor + $parts), from_cents($discount), from_cents($t['tax']), from_cents($t['grand']),
             from_cents($p['net']), from_cents($balance), $status, $id]);
        return ['labor_total' => from_cents($labor), 'parts_total' => from_cents($parts), 'subtotal' => from_cents($labor + $parts), 'discount' => from_cents($discount),
                'tax_amount' => from_cents($t['tax']), 'grand_total' => from_cents($t['grand']), 'amount_paid' => from_cents($p['net']),
                'balance' => from_cents($balance), 'payment_status' => $status];
    }

    /** @return array{tax:int,grand:int} */
    public static function totals(int $laborCents, int $partsCents, int $discountCents, float $taxRate): array
    {
        $taxable = max(0, $laborCents + $partsCents - $discountCents);
        $tax = (int) round($taxable * $taxRate / 100);
        return ['tax' => $tax, 'grand' => $taxable + $tax];
    }

    public static function statusFor(int $grand, int $net, int $refunded): string
    {
        if ($refunded > 0 && $net <= 0) { return 'Refunded'; }
        if ($net >= $grand && $grand >= 0 && ($net > 0 || $grand === 0)) { return 'Paid'; }
        return $net > 0 ? 'Partially Paid' : 'Unpaid';
    }

    /** @return array{paid:int,refunded:int,net:int} */
    public static function netPaid(int $invoiceId): array
    {
        $r = Database::one("SELECT COALESCE(SUM(CASE WHEN kind='payment' THEN ROUND(amount*100) END),0) AS paid,
                                   COALESCE(SUM(CASE WHEN kind='refund'  THEN ROUND(amount*100) END),0) AS refunded
                            FROM payments WHERE invoice_id = ?", [$invoiceId]);
        $paid = (int) $r['paid']; $ref = (int) $r['refunded'];
        return ['paid' => $paid, 'refunded' => $ref, 'net' => $paid - $ref];
    }

    public static function lock(int $id): array
    {
        $inv = Database::one('SELECT * FROM invoices WHERE id = ? FOR UPDATE', [$id]);
        if (!$inv) { throw new DomainException('Invoice not found.'); }
        return $inv;
    }

    private static function rebuildItems(int $invoiceId, array $job): void
    {
        Database::run('DELETE FROM invoice_items WHERE invoice_id = ?', [$invoiceId]);

        // 1. Attached catalog services
        $jobServices = Database::all('SELECT * FROM job_services WHERE job_id = ? AND status != "Cancelled"', [$job['id']]);
        $serviceSum = 0;
        foreach ($jobServices as $js) {
            $line = to_cents($js['price']);
            $serviceSum += $line;
            Database::run("INSERT INTO invoice_items (invoice_id, item_type, description, quantity, unit_price, line_total) VALUES (?, 'labor', ?, 1, ?, ?)",
                [$invoiceId, 'Service: ' . $js['service_name'], $js['price'], $js['price']]);
        }

        // 2. Remaining or direct labor charges
        $remainingLabor = to_cents($job['labor_cost']) - $serviceSum;
        if ($remainingLabor > 0) {
            $remFormatted = from_cents($remainingLabor);
            Database::run("INSERT INTO invoice_items (invoice_id, item_type, description, quantity, unit_price, line_total) VALUES (?, 'labor', ?, 1, ?, ?)",
                [$invoiceId, empty($jobServices) ? ('Labor charges — ' . $job['job_number']) : 'Additional Workshop Labor', $remFormatted, $remFormatted]);
        } elseif (empty($jobServices) && to_cents($job['labor_cost']) > 0) {
            Database::run("INSERT INTO invoice_items (invoice_id, item_type, description, quantity, unit_price, line_total) VALUES (?, 'labor', ?, 1, ?, ?)",
                [$invoiceId, 'Labor charges — ' . $job['job_number'], $job['labor_cost'], $job['labor_cost']]);
        }

        // 3. Parts consumed
        $parts = Database::all(
            'SELECT jp.part_id, sp.sku, sp.name, jp.unit_price, SUM(jp.quantity) AS qty
             FROM job_parts jp JOIN spare_parts sp ON sp.id = jp.part_id
             WHERE jp.job_id = ? AND jp.returned = 0 GROUP BY jp.part_id, sp.sku, sp.name, jp.unit_price ORDER BY sp.name', [$job['id']]);
        foreach ($parts as $p) {
            $line = to_cents($p['unit_price']) * (int) $p['qty'];
            Database::run("INSERT INTO invoice_items (invoice_id, item_type, part_id, description, quantity, unit_price, line_total) VALUES (?, 'part', ?, ?, ?, ?, ?)",
                [$invoiceId, $p['part_id'], $p['name'] . ' (' . $p['sku'] . ')', $p['qty'], $p['unit_price'], from_cents($line)]);
        }
    }
}
