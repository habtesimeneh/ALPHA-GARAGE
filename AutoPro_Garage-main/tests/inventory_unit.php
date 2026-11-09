<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/app/helpers/functions.php';
require APP_ROOT . '/app/services/InventoryService.php';

function expect_domain_exception(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (DomainException) {
        return;
    }
    throw new RuntimeException($message);
}

expect_domain_exception(
    static fn() => InventoryService::purchase(1, 1, '-0.01', '', '', ''),
    'Negative purchase cost must be rejected before database access.'
);
expect_domain_exception(
    static fn() => InventoryService::purchase(1, 100001, '1.00', '', '', ''),
    'Oversized stock purchase quantity must be rejected before database access.'
);
expect_domain_exception(
    static fn() => InventoryService::adjust(1, 'sideways', 1, 'count correction'),
    'Invalid adjustment direction must be rejected before database access.'
);
expect_domain_exception(
    static fn() => InventoryService::savePart(null, [
        'quantity' => '0',
        'reorder_level' => '1',
        'unit_cost' => '-1.00',
        'unit_price' => '1.00',
    ]),
    'Negative part cost must be rejected before database access.'
);
expect_domain_exception(
    static fn() => InventoryService::issueMany(1, [
        ['part_id' => '4', 'quantity' => '1'],
        ['part_id' => '4', 'quantity' => '2'],
    ]),
    'Duplicate part lines must be rejected before database access.'
);

echo "Inventory unit tests passed.\n";
