<?php
declare(strict_types=1);
/**
 * JSON data endpoint for the New/Edit Job Card form (same-origin, auth+permission gated).
 * Actions: customers, vehicles (requires customer_id), mechanics, services.
 */
require __DIR__ . '/../../app/bootstrap.php';
$user = require_login();
$id = get_int('id');
$perm = $id ? 'jobs.edit' : 'jobs.create';
if (!can($perm)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Access denied.'], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$action = get('action');
$q = trim(get('q'));
$like = $q !== '' ? like_escape($q) : null;

try {
    switch ($action) {
        case 'customers':
            $sql = 'SELECT id, full_name, phone FROM customers WHERE is_active = 1';
            $params = [];
            if ($like !== null) {
                $sql .= ' AND (full_name LIKE ? OR phone LIKE ? OR email LIKE ?)';
                array_push($params, $like, $like, $like);
            }
            $sql .= ' ORDER BY full_name ASC LIMIT 500';
            $rows = Database::all($sql, $params);
            $data = array_map(function ($r) {
                return [
                    'id' => (int) $r['id'],
                    'label' => $r['full_name'] . ' (' . $r['phone'] . ')',
                    'name' => $r['full_name'],
                    'phone' => $r['phone'],
                ];
            }, $rows);
            echo json_encode(['ok' => true, 'items' => $data], JSON_UNESCAPED_UNICODE);
            break;

        case 'vehicles':
            $customerId = get_int('customer_id');
            if ($customerId < 1) {
                echo json_encode(['ok' => true, 'items' => [], 'message' => 'Select a customer first.'], JSON_UNESCAPED_UNICODE);
                break;
            }
            $sql = 'SELECT id, customer_id, plate_number, make, model, year, is_active FROM vehicles WHERE customer_id = ? AND is_active = 1';
            $params = [$customerId];
            if ($like !== null) {
                $sql .= ' AND (plate_number LIKE ? OR make LIKE ? OR model LIKE ? OR vin LIKE ?)';
                array_push($params, $like, $like, $like, $like);
            }
            $sql .= ' ORDER BY plate_number ASC LIMIT 200';
            $rows = Database::all($sql, $params);
            $data = array_map(function ($r) {
                $label = $r['plate_number'] . ' — ' . trim($r['make'] . ' ' . $r['model']);
                if (!empty($r['year'])) { $label .= ' (' . $r['year'] . ')'; }
                return ['id' => (int) $r['id'], 'customer_id' => (int) $r['customer_id'], 'label' => $label];
            }, $rows);
            echo json_encode(['ok' => true, 'items' => $data], JSON_UNESCAPED_UNICODE);
            break;

        case 'mechanics':
            $sql = "SELECT id, full_name, specialty, availability, is_active FROM mechanics WHERE is_active = 1";
            $params = [];
            if ($like !== null) {
                $sql .= ' AND (full_name LIKE ? OR specialty LIKE ?)';
                array_push($params, $like, $like);
            }
            $sql .= ' ORDER BY full_name ASC LIMIT 200';
            $rows = Database::all($sql, $params);
            $data = array_map(function ($r) {
                $parts = [$r['full_name']];
                if (!empty($r['specialty'])) { $parts[] = $r['specialty']; }
                $parts[] = str_replace('_', ' ', (string) $r['availability']);
                return ['id' => (int) $r['id'], 'label' => implode(' · ', $parts), 'availability' => $r['availability']];
            }, $rows);
            echo json_encode(['ok' => true, 'items' => $data], JSON_UNESCAPED_UNICODE);
            break;

        case 'services':
            $sql = 'SELECT id, code, name, category, description, standard_price, estimated_hours, is_active FROM services WHERE is_active = 1';
            $params = [];
            if ($like !== null) {
                $sql .= ' AND (name LIKE ? OR code LIKE ? OR category LIKE ?)';
                array_push($params, $like, $like, $like);
            }
            $sql .= ' ORDER BY category ASC, name ASC LIMIT 300';
            $rows = Database::all($sql, $params);
            $currency = Setting::get('currency', 'ETB');
            $data = array_map(function ($r) use ($currency) {
                $price = (string) $r['standard_price'];
                $label = $r['name'] . ' — ' . $currency . ' ' . number_format((float) $price, 2);
                return [
                    'id' => (int) $r['id'],
                    'label' => $label,
                    'name' => $r['name'],
                    'category' => $r['category'],
                    'description' => $r['description'] ?? '',
                    'price' => $price,
                ];
            }, $rows);
            echo json_encode(['ok' => true, 'items' => $data, 'currency' => $currency], JSON_UNESCAPED_UNICODE);
            break;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unknown action.'], JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    app_log('job-cards/data.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => true, 'items' => [], 'error' => 'Unable to load data. Please try again.'], JSON_UNESCAPED_UNICODE);
}
