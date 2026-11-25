<?php
/**
 * Manager / Garage Owner Operations & Inventory Approval/Removal Suite
 * Tests:
 * 1. Part Request Approval by Manager
 * 2. Part Request Rejection/Removal by Manager
 * 3. Return / Removal of Issued Job Part
 * 4. Catalog Part Removal / Deletion (Unused vs Historical)
 */

declare(strict_types=1);

chdir(__DIR__ . '/..');
require_once __DIR__ . '/../app/bootstrap.php';

$pdo = Database::pdo();
$passed = 0;
$total = 0;

function assertTest(string $desc, bool $condition, ?string $failDetail = null): void {
    global $passed, $total;
    $total++;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$desc}\n";
    } else {
        echo "  [FAIL] {$desc}" . ($failDetail ? " - {$failDetail}" : "") . "\n";
    }
}

echo "\n=======================================================\n";
echo "ALPHA GARAGE — INVENTORY MANAGER / OWNER APPROVAL & REMOVAL SUITE\n";
echo "=======================================================\n\n";

try {
    // 1. Setup test spare part
    $partSku = 'TEST-SKU-' . bin2hex(random_bytes(4));
    $stmt = $pdo->prepare("INSERT INTO spare_parts (sku, name, category, unit, unit_cost, unit_price, quantity, reorder_level, is_active, created_at)
        VALUES (?, 'Test Oil Filter', 'Filters', 'pcs', 100.00, 150.00, 20, 5, 1, NOW())");
    $stmt->execute([$partSku]);
    $partId = (int)$pdo->lastInsertId();
    assertTest("Created test spare part in stock (Qty: 20)", $partId > 0);

    // 2. Select or setup test job card with eligible status (e.g. In Progress)
    $jobId = (int)$pdo->query("SELECT id FROM job_cards WHERE status IN ('Pending', 'Diagnosing', 'Waiting for Parts', 'In Progress') ORDER BY id DESC LIMIT 1")->fetchColumn();
    assertTest("Found valid active job card #{$jobId}", $jobId > 0);

    // 3. Mechanic submits a part request for 3 units
    $stmt = $pdo->prepare("INSERT INTO part_requests (job_id, part_id, requested_by, quantity, note, status, created_at)
        VALUES (?, ?, 1, 3, 'Mechanic needs 3 filters', 'pending', NOW())");
    $stmt->execute([$jobId, $partId]);
    $requestId1 = (int)$pdo->lastInsertId();
    assertTest("Technician submitted part request #{$requestId1} for 3 units", $requestId1 > 0);

    // 4. Manager Approves Part Request #1 using InventoryService::issue
    InventoryService::issue($jobId, $partId, 3, $requestId1);
    
    // Check request is now 'issued'
    $stmt = $pdo->prepare("SELECT status FROM part_requests WHERE id = ?");
    $stmt->execute([$requestId1]);
    $reqStatus = $stmt->fetchColumn();
    assertTest("Manager approved request #{$requestId1} -> status is 'issued'", $reqStatus === 'issued');

    // Check stock was deducted (20 - 3 = 17)
    $stmt = $pdo->prepare("SELECT quantity FROM spare_parts WHERE id = ?");
    $stmt->execute([$partId]);
    $currQty = (int)$stmt->fetchColumn();
    assertTest("Inventory quantity deducted from 20 to 17", $currQty === 17, "Found {$currQty}");

    // Check job_parts record exists and returned = 0
    $stmt = $pdo->prepare("SELECT id, returned FROM job_parts WHERE job_id = ? AND part_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$jobId, $partId]);
    $jobPart = $stmt->fetch(PDO::FETCH_ASSOC);
    $jobPartId = (int)($jobPart['id'] ?? 0);
    assertTest("Job part recorded on job card (job_parts id: {$jobPartId}) with returned=0", $jobPartId > 0 && (int)$jobPart['returned'] === 0);

    // 5. Manager Returns/Removes issued part line (returnSinglePart)
    InventoryService::returnSinglePart($jobId, $jobPartId, 'Owner cancelled installation');
    
    // Check job_parts is returned = 1
    $stmt = $pdo->prepare("SELECT returned FROM job_parts WHERE id = ?");
    $stmt->execute([$jobPartId]);
    $isReturned = (int)$stmt->fetchColumn();
    assertTest("Issued part removed/returned from job -> returned is now 1", $isReturned === 1);

    // Check stock is restored (17 + 3 = 20)
    $stmt = $pdo->prepare("SELECT quantity FROM spare_parts WHERE id = ?");
    $stmt->execute([$partId]);
    $restoredQty = (int)$stmt->fetchColumn();
    assertTest("Inventory stock restored back to 20", $restoredQty === 20, "Found {$restoredQty}");

    // 6. Mechanic submits request #2, Manager Rejects/Removes it
    $stmt = $pdo->prepare("INSERT INTO part_requests (job_id, part_id, requested_by, quantity, note, status, created_at)
        VALUES (?, ?, 1, 5, 'Mechanic request for wrong part', 'pending', NOW())");
    $stmt->execute([$jobId, $partId]);
    $requestId2 = (int)$pdo->lastInsertId();
    
    InventoryService::rejectRequest($requestId2, 'Wrong part requested by mechanic');
    
    $stmt = $pdo->prepare("SELECT status FROM part_requests WHERE id = ?");
    $stmt->execute([$requestId2]);
    $req2Status = $stmt->fetchColumn();
    assertTest("Manager rejected/removed request #{$requestId2} -> status is 'rejected'", $req2Status === 'rejected');

    // 7. Catalog Part Deletion - Part with history is archived (is_active = 0)
    $deleteResult1 = InventoryService::deletePart($partId);
    assertTest("Part with historical records is safely archived instead of hard-deleted", ($deleteResult1['archived'] ?? false) === true);
    
    $stmt = $pdo->prepare("SELECT is_active FROM spare_parts WHERE id = ?");
    $stmt->execute([$partId]);
    $isActive = (int)$stmt->fetchColumn();
    assertTest("Archived part has is_active = 0", $isActive === 0);

    // 8. Catalog Part Deletion - Unused part is cleanly hard-deleted
    $partSkuUnused = 'TEST-UNUSED-' . bin2hex(random_bytes(4));
    $stmt = $pdo->prepare("INSERT INTO spare_parts (sku, name, category, unit, unit_cost, unit_price, quantity, reorder_level, is_active, created_at)
        VALUES (?, 'Unused Part', 'Filters', 'pcs', 50.00, 75.00, 10, 2, 1, NOW())");
    $stmt->execute([$partSkuUnused]);
    $unusedPartId = (int)$pdo->lastInsertId();

    $deleteResult2 = InventoryService::deletePart($unusedPartId);
    assertTest("Unused part is cleanly hard-deleted", ($deleteResult2['deleted'] ?? false) === true);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM spare_parts WHERE id = ?");
    $stmt->execute([$unusedPartId]);
    $countUnused = (int)$stmt->fetchColumn();
    assertTest("Unused part no longer exists in database", $countUnused === 0);

    // 9. Cleanup test data
    $pdo->prepare("DELETE FROM part_requests WHERE id IN (?, ?)")->execute([$requestId1, $requestId2]);
    $pdo->prepare("DELETE FROM job_parts WHERE id = ?")->execute([$jobPartId]);
    $pdo->prepare("DELETE FROM inventory_transactions WHERE part_id = ?")->execute([$partId]);
    $pdo->prepare("DELETE FROM spare_parts WHERE id = ?")->execute([$partId]);
    assertTest("Cleaned up temporary test artifacts", true);

} catch (\Throwable $e) {
    assertTest("Exception caught during test execution", false, $e->getMessage() . "\n" . $e->getTraceAsString());
}

echo "\n-------------------------------------------------------\n";
echo "SUMMARY: {$passed} of {$total} tests passed.\n";
if ($passed === $total) {
    echo "STATUS: ALL APPROVAL & REMOVAL TESTS PASSED!\n";
} else {
    echo "STATUS: SOME TESTS FAILED.\n";
}
echo "-------------------------------------------------------\n\n";
