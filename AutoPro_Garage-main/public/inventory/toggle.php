<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_login(); require_post(); require_permission('inventory.manage');
$id = post_int('id'); $a = post('action');
if (!in_array($a, ['activate', 'deactivate'], true)) { abort(400, t('Invalid action.', 'Invalid action.')); }
try { InventoryService::setActive($id, $a === 'activate'); flash('success', $a === 'activate' ? t('Part reactivated.', 'Part reactivated.') : t('Part deactivated.', 'Part deactivated.')); } catch (DomainException $e) { flash('error', $e->getMessage()); }
redirect('inventory/form.php?id=' . $id);
