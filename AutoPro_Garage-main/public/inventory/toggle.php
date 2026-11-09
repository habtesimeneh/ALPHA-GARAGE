<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_login(); require_post(); require_permission('inventory.manage');
$id = post_int('id'); $a = post('action');
if (!in_array($a, ['activate', 'deactivate'], true)) { abort(400, 'Invalid action.'); }
try { InventoryService::setActive($id, $a === 'activate'); flash('success', 'Part ' . $a . 'd.'); } catch (DomainException $e) { flash('error', $e->getMessage()); }
redirect('inventory/form.php?id=' . $id);
