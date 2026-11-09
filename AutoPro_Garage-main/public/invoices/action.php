<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_login(); require_post(); require_permission('invoices.edit');
$id = post_int('id');
try {
    if (post('do') === 'sync') { InvoiceService::syncFromJob($id); flash('success', 'Invoice re-synchronised with the job card.'); }
    elseif (post('do') === 'discount') {
        $e = validate(['d' => post('discount') ?: '0', 'n' => post('notes')], ['d' => 'numeric', 'n' => 'max:500']);
        if ($e) { throw new DomainException(reset($e)); }
        InvoiceService::setDiscount($id, post('discount') ?: '0', post('notes')); flash('success', 'Invoice updated.');
    } else { abort(400, 'Unknown action.'); }
} catch (DomainException $e) { flash('error', $e->getMessage()); }
redirect('invoices/view.php?id=' . $id);
