<?php
declare(strict_types=1);

/**
 * Dashboard analytics built from real database records.
 * Revenue = recorded payments minus refunds (cash received), scoped by paid_at.
 * Expenses = manual expenses (expense_date) + inventory stock purchases
 * (inventory_transactions purchase, excluding Opening stock), scoped by created_at.
 * Pending payments = invoice balances for Unpaid/Partially Paid invoices (all time
 * unless the caller applies period context explicitly); dashboard shows period-issued context.
 * Profit/Loss is a cash-basis management estimate, not audited accrual profit.
 */
final class DashboardAnalyticsService
{
    public static function currency(): string
    {
        try { return (string) Setting::get('currency', 'ETB'); } catch (Throwable) { return 'ETB'; }
    }

    public static function expenseLedgerReady(): bool
    {
        try {
            Database::one('SELECT 1 FROM expenses LIMIT 1');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public static function range(array $get, DateTimeImmutable $today): array
    {
        $options = ['today', 'last_7_days', 'last_30_days', 'this_month', 'last_month', 'this_year', 'custom'];
        $key = is_string($get['range'] ?? null) && in_array($get['range'], $options, true) ? $get['range'] : 'this_month';
        $error = '';
        $customDate = static function (mixed $v): ?DateTimeImmutable {
            if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return null;
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
            return $d && $d->format('Y-m-d') === $v ? $d : null;
        };
        $start = $today->modify('first day of this month');
        $end = $today;
        switch ($key) {
            case 'today': $start = $today; break;
            case 'last_7_days': $start = $today->modify('-6 days'); break;
            case 'last_30_days': $start = $today->modify('-29 days'); break;
            case 'last_month':
                $start = $today->modify('first day of last month');
                $end = $today->modify('last day of last month');
                break;
            case 'this_year': $start = $today->setDate((int) $today->format('Y'), 1, 1); break;
            case 'custom':
                $cs = $customDate($get['from'] ?? null);
                $ce = $customDate($get['to'] ?? null);
                if ($cs && $ce && $cs <= $ce && $ce <= $today && $cs->diff($ce)->days <= 365) {
                    $start = $cs; $end = $ce;
                } else {
                    $error = 'Choose a valid date range of up to 366 days ending today or earlier.';
                    $key = 'this_month';
                }
                break;
        }
        $startSql = $start->format('Y-m-d');
        $endSql = $end->format('Y-m-d');
        $endExclusive = $end->modify('+1 day')->format('Y-m-d');
        $days = max(1, (int) $start->diff($end)->days + 1);
        $prevStart = $start->modify('-' . $days . ' days')->format('Y-m-d');
        $period = is_string($get['period'] ?? null) && in_array($get['period'], ['daily', 'weekly', 'monthly', 'yearly'], true)
            ? $get['period'] : ($days <= 14 ? 'daily' : ($days <= 90 ? 'weekly' : 'monthly'));
        $label = match ($key) {
            'today' => 'Today',
            'last_7_days' => 'Last 7 days',
            'last_30_days' => 'Last 30 days',
            'this_month' => 'This month',
            'last_month' => 'Last month',
            'this_year' => 'This year',
            'custom' => $startSql . ' – ' . $endSql,
        };
        return compact('key','error','start','end','startSql','endSql','endExclusive','days','prevStart','period','label');
    }

    public static function comparison(float $current, float $previous): array
    {
        if ($previous <= 0.0) {
            return ['text' => $current > 0.0 ? 'New in selected period' : 'No change vs previous period', 'class' => $current > 0.0 ? 'trend-up' : 'trend-flat', 'pct' => null];
        }
        $pct = (($current - $previous) / $previous) * 100;
        if (abs($pct) < 0.05) return ['text' => 'No change vs previous period', 'class' => 'trend-flat', 'pct' => 0.0];
        return ['text' => ($pct > 0 ? '+' : '') . number_format($pct, 1) . '% vs previous period', 'class' => $pct > 0 ? 'trend-up' : 'trend-down', 'pct' => round($pct, 1)];
    }

    public static function revenueTotals(string $from, string $toExclusive): array
    {
        if (!function_exists('can') || !can('payments.view')) {
            return ['total' => 0.0, 'received' => 0.0, 'refunded' => 0.0, 'invoiced' => 0.0];
        }
        $r = Database::one(
            "SELECT
                COALESCE(SUM(CASE WHEN kind='payment' THEN amount ELSE 0 END),0) AS received,
                COALESCE(SUM(CASE WHEN kind='refund' THEN amount ELSE 0 END),0) AS refunded
             FROM payments WHERE paid_at >= ? AND paid_at < ?",
            [$from, $toExclusive]
        ) ?: [];
        $received = (float) ($r['received'] ?? 0);
        $refunded = (float) ($r['refunded'] ?? 0);
        $invoiced = 0.0;
        if (function_exists('can') && can('invoices.view')) {
            $invoiced = (float) Database::value(
                "SELECT COALESCE(SUM(grand_total),0) FROM invoices WHERE issue_date >= ? AND issue_date < ? AND payment_status <> 'Cancelled'",
                [$from, $toExclusive]
            );
        }
        return ['total' => round($received - $refunded, 2), 'received' => round($received, 2), 'refunded' => round($refunded, 2), 'invoiced' => round($invoiced, 2)];
    }

    public static function expenseTotals(string $from, string $toExclusive): array
    {
        $ready = self::expenseLedgerReady() && function_exists('can') && can('expenses.view');
        if (!$ready) return ['ready' => false, 'total' => 0.0, 'manual' => 0.0, 'inventory_purchases' => 0.0, 'by_category' => []];
        $manual = (float) Database::value(
            'SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date >= ? AND expense_date < ?',
            [$from, $toExclusive]
        );
        $purchases = (float) Database::value(
            "SELECT COALESCE(SUM(quantity * unit_cost),0) FROM inventory_transactions
             WHERE txn_type='purchase' AND COALESCE(reference,'') <> 'Opening stock' AND created_at >= ? AND created_at < ?",
            [$from, $toExclusive]
        );
        $cats = [];
        foreach (Database::all(
            'SELECT category, COALESCE(SUM(amount),0) total FROM expenses WHERE expense_date >= ? AND expense_date < ? GROUP BY category ORDER BY total DESC',
            [$from, $toExclusive]
        ) as $row) {
            $cats[] = ['label' => self::expenseCategoryLabel((string) $row['category']), 'value' => round((float) $row['total'], 2)];
        }
        if ($purchases > 0) $cats[] = ['label' => 'Inventory purchases', 'value' => round($purchases, 2)];
        usort($cats, fn($a,$b) => $b['value'] <=> $a['value']);
        return ['ready' => true, 'total' => round($manual + $purchases, 2), 'manual' => round($manual, 2), 'inventory_purchases' => round($purchases, 2), 'by_category' => $cats];
    }

    public static function expenseCategoryLabel(string $key): string
    {
        return [
            'payroll' => 'Payroll', 'rent' => 'Rent', 'utilities' => 'Utilities',
            'transport' => 'Fuel & transport', 'tools' => 'Tools & equipment',
            'maintenance' => 'Facility maintenance', 'insurance_tax' => 'Insurance & tax',
            'other' => 'Other operating expense',
        ][$key] ?? 'Other';
    }

    public static function buckets(DateTimeImmutable $start, DateTimeImmutable $end, string $period): array
    {
        $defs = [];
        $cursor = match ($period) {
            'daily' => $start,
            'weekly' => $start->modify('monday this week'),
            'monthly' => $start->modify('first day of this month'),
            'yearly' => $start->setDate((int) $start->format('Y'), 1, 1),
            default => $start->modify('first day of this month'),
        };
        while ($cursor <= $end) {
            $k = match ($period) {
                'daily' => $cursor->format('Y-m-d'),
                'weekly' => $cursor->format('o-\WW'),
                'monthly' => $cursor->format('Y-m'),
                'yearly' => $cursor->format('Y'),
                default => $cursor->format('Y-m'),
            };
            $label = match ($period) {
                'daily' => $cursor->format('M j'),
                'weekly' => $cursor->format('M j'),
                'monthly' => $cursor->format('M Y'),
                'yearly' => $cursor->format('Y'),
                default => $cursor->format('M Y'),
            };
            $defs[$k] = $label;
            $cursor = match ($period) {
                'daily' => $cursor->modify('+1 day'),
                'weekly' => $cursor->modify('+1 week'),
                'monthly' => $cursor->modify('first day of next month'),
                'yearly' => $cursor->modify('+1 year'),
                default => $cursor->modify('first day of next month'),
            };
        }
        return $defs;
    }

    private static function groupExpr(string $column, string $period): string
    {
        return match ($period) {
            'daily' => "DATE_FORMAT($column, '%Y-%m-%d')",
            'weekly' => "DATE_FORMAT($column, '%x-W%v')",
            'monthly' => "DATE_FORMAT($column, '%Y-%m')",
            'yearly' => "DATE_FORMAT($column, '%Y')",
            default => "DATE_FORMAT($column, '%Y-%m')",
        };
    }

    public static function revenueSeries(array $buckets, string $period, string $from, string $toExclusive): array
    {
        $g = self::groupExpr('paid_at', $period);
        $rows = array_column(Database::all(
            "SELECT $g AS b, COALESCE(SUM(CASE WHEN kind='payment' THEN amount ELSE -amount END),0) t FROM payments WHERE paid_at >= ? AND paid_at < ? GROUP BY b",
            [$from, $toExclusive]
        ), 't', 'b');
        $out = [];
        foreach ($buckets as $k => $label) $out[] = ['label' => $label, 'value' => round((float) ($rows[$k] ?? 0), 2)];
        return $out;
    }

    public static function financialSeries(array $buckets, string $period, string $from, string $toExclusive): array
    {
        $ready = self::expenseLedgerReady() && function_exists('can') && can('expenses.view');
        $revRows = [];
        if (function_exists('can') && can('payments.view')) {
            $g = self::groupExpr('paid_at', $period);
            $revRows = array_column(Database::all(
                "SELECT $g b, COALESCE(SUM(CASE WHEN kind='payment' THEN amount ELSE -amount END),0) t FROM payments WHERE paid_at >= ? AND paid_at < ? GROUP BY b",
                [$from, $toExclusive]
            ), 't', 'b');
        }
        $expRows = $invRows = [];
        if ($ready) {
            $g = self::groupExpr('expense_date', $period);
            $expRows = array_column(Database::all(
                "SELECT $g b, COALESCE(SUM(amount),0) t FROM expenses WHERE expense_date >= ? AND expense_date < ? GROUP BY b",
                [$from, $toExclusive]
            ), 't', 'b');
            $g2 = self::groupExpr('created_at', $period);
            $invRows = array_column(Database::all(
                "SELECT $g2 b, COALESCE(SUM(quantity * unit_cost),0) t FROM inventory_transactions WHERE txn_type='purchase' AND COALESCE(reference,'') <> 'Opening stock' AND created_at >= ? AND created_at < ? GROUP BY b",
                [$from, $toExclusive]
            ), 't', 'b');
        }
        $out = [];
        foreach ($buckets as $k => $label) {
            $r = round((float) ($revRows[$k] ?? 0), 2);
            $e = round((float) ($expRows[$k] ?? 0) + (float) ($invRows[$k] ?? 0), 2);
            $out[] = ['label' => $label, 'revenue' => $r, 'expenses' => $e, 'profit' => round($r - $e, 2)];
        }
        return $out;
    }

    public static function jobStatusCounts(string $where, array $params): array
    {
        $rows = array_column(Database::all(
            "SELECT status, COUNT(*) n FROM job_cards WHERE $where AND date_received >= ? AND date_received < ? GROUP BY status",
            $params
        ), 'n', 'status');
        $counts = [];
        foreach (['Pending','Diagnosing','Waiting for Parts','In Progress','Completed','Delivered','Cancelled'] as $s) $counts[$s] = (int) ($rows[$s] ?? 0);
        return $counts;
    }

    public static function inventorySummary(): array
    {
        if (!function_exists('can') || !can('inventory.view')) return ['items' => 0, 'available' => 0, 'low' => 0, 'out' => 0, 'value' => 0.0, 'top_used' => [], 'by_category' => []];
        $s = Database::one("SELECT COUNT(*) items, COALESCE(SUM(quantity>0),0) available, COALESCE(SUM(quantity=0),0) out_count, COALESCE(SUM(quantity>0 AND quantity<=reorder_level),0) low_count, COALESCE(SUM(quantity*unit_cost),0) value FROM spare_parts WHERE is_active=1") ?: [];
        $top = Database::all(
            "SELECT sp.id, sp.name, sp.sku, COALESCE(SUM(jp.quantity),0) used_qty FROM job_parts jp JOIN spare_parts sp ON sp.id=jp.part_id WHERE jp.returned=0 GROUP BY sp.id, sp.name, sp.sku ORDER BY used_qty DESC, sp.name ASC LIMIT 8"
        );
        $cats = Database::all(
            "SELECT COALESCE(NULLIF(category,''),'Uncategorized') label, COALESCE(SUM(quantity),0) units, COALESCE(SUM(quantity*unit_cost),0) value FROM spare_parts WHERE is_active=1 GROUP BY label ORDER BY units DESC LIMIT 8"
        );
        return [
            'items' => (int) ($s['items'] ?? 0),
            'available' => (int) ($s['available'] ?? 0),
            'low' => (int) ($s['low_count'] ?? 0),
            'out' => (int) ($s['out_count'] ?? 0),
            'value' => round((float) ($s['value'] ?? 0), 2),
            'top_used' => array_map(fn($r) => ['label' => $r['name'] . ' (' . $r['sku'] . ')', 'value' => (int) $r['used_qty']], $top),
            'by_category' => array_map(fn($r) => ['label' => (string) $r['label'], 'units' => (int) $r['units'], 'value' => round((float) $r['value'], 2)], $cats),
        ];
    }

    public static function topServices(string $from, string $toExclusive): array
    {
        if (!function_exists('can') || !can('services.view')) return [];
        try {
            $rows = Database::all(
                "SELECT COALESCE(NULLIF(s.name,''), NULLIF(js.service_name,''), 'Service') AS label,
                        COUNT(*) AS cnt,
                        COALESCE(SUM(CASE WHEN j.status IN ('Completed','Delivered') THEN COALESCE(js.price,0) ELSE 0 END),0) AS revenue
                 FROM job_services js
                 JOIN job_cards j ON j.id = js.job_id
                 LEFT JOIN services s ON s.id = js.service_id
                 WHERE j.date_received >= ? AND j.date_received < ?
                 GROUP BY label ORDER BY cnt DESC, revenue DESC LIMIT 8",
                [$from, $toExclusive]
            );
            return array_map(fn($r) => ['label' => (string) $r['label'], 'count' => (int) $r['cnt'], 'revenue' => round((float) $r['revenue'], 2)], $rows);
        } catch (Throwable) {
            return [];
        }
    }

    public static function profitMargin(float $revenue, float $profit): ?float
    {
        if (abs($revenue) < 0.00001) return null;
        return round(($profit / $revenue) * 100, 1);
    }

    public static function targets(): array
    {
        $keys = ['monthly_revenue_target','monthly_profit_target','monthly_expense_budget'];
        $out = [];
        foreach ($keys as $k) {
            try { $v = trim((string) Setting::get($k, '')); } catch (Throwable) { $v = ''; }
            $out[$k] = ($v !== '' && is_numeric($v) && (float) $v > 0) ? round((float) $v, 2) : null;
        }
        return $out;
    }

    public static function sparkline(string $kind, DateTimeImmutable $end): array
    {
        $points = [];
        for ($i = 5; $i >= 0; $i--) {
            $mStart = $end->modify("first day of -$i months")->format('Y-m-d');
            $mEnd = $end->modify("first day of -$i months")->modify('first day of next month')->format('Y-m-d');
            $val = 0.0;
            if ($kind === 'revenue' && function_exists('can') && can('payments.view')) {
                $val = (float) Database::value("SELECT COALESCE(SUM(CASE WHEN kind='payment' THEN amount ELSE -amount END),0) FROM payments WHERE paid_at >= ? AND paid_at < ?", [$mStart, $mEnd]);
            } elseif ($kind === 'expenses' && self::expenseLedgerReady() && function_exists('can') && can('expenses.view')) {
                $m = (float) Database::value('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date >= ? AND expense_date < ?', [$mStart, $mEnd]);
                $p = (float) Database::value("SELECT COALESCE(SUM(quantity*unit_cost),0) FROM inventory_transactions WHERE txn_type='purchase' AND COALESCE(reference,'') <> 'Opening stock' AND created_at >= ? AND created_at < ?", [$mStart, $mEnd]);
                $val = $m + $p;
            } elseif ($kind === 'jobs') {
                $val = (float) Database::value("SELECT COUNT(*) FROM job_cards WHERE date_received >= ? AND date_received < ?", [$mStart, $mEnd]);
            }
            $points[] = round($val, 2);
        }
        return $points;
    }
}
