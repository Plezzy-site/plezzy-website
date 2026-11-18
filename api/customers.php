<?php
/**
 * PLEZZY Customers API (Hostinger MySQL & JSON Fallback)
 * Endpoint:
 *   GET /api/customers
 */

require_once __DIR__ . '/db.php';

$pdo = PlezzyDB::getConnection();
$customersFile = __DIR__ . '/../data/customers.json';

if ($pdo) {
    try {
        $stmt = $pdo->query("SELECT * FROM customers ORDER BY total_spent DESC, last_order_date DESC");
        $rows = $stmt->fetchAll();

        if (!empty($rows)) {
            $customers = array_map(function($r) {
                return [
                    'id' => $r['id'],
                    'fullName' => $r['full_name'],
                    'email' => $r['email'],
                    'phone' => $r['phone'],
                    'city' => $r['city'],
                    'state' => $r['state'],
                    'country' => $r['country'],
                    'totalOrders' => intval($r['total_orders']),
                    'totalSpent' => intval($r['total_spent']),
                    'currency' => $r['currency'],
                    'lastOrderDate' => $r['last_order_date'],
                    'lastOrderNumber' => $r['last_order_id'],
                    'addresses' => !empty($r['addresses_json']) ? json_decode($r['addresses_json'], true) : []
                ];
            }, $rows);

            PlezzyDB::json([
                'success' => true,
                'count' => count($customers),
                'source' => 'mysql',
                'customers' => $customers
            ]);
        }
    } catch (Exception $e) {
        error_log('[API Customers] MySQL error: ' . $e->getMessage());
    }
}

// Fallback to customers.json or generate from orders.json
$customers = PlezzyDB::readJson($customersFile, []);
if (empty($customers)) {
    $orders = PlezzyDB::readJson(__DIR__ . '/../data/orders.json', []);
    $map = [];
    foreach ($orders as $o) {
        $cust = $o['customer'] ?? [];
        $key = strtolower(trim($cust['email'] ?? ($cust['phone'] ?? 'unknown')));
        if (!isset($map[$key])) {
            $map[$key] = [
                'id' => 'CUST-' . substr(abs(crc32($key)), 0, 6),
                'fullName' => $cust['fullName'] ?? 'Anonymous Customer',
                'email' => $cust['email'] ?? '',
                'phone' => $cust['phone'] ?? '',
                'city' => $cust['city'] ?? 'Mumbai',
                'state' => $cust['state'] ?? 'Maharashtra',
                'country' => $cust['country'] ?? 'India',
                'totalOrders' => 0,
                'totalSpent' => 0,
                'currency' => 'INR',
                'lastOrderDate' => $o['createdAt'] ?? date('c'),
                'lastOrderNumber' => $o['id'] ?? '',
                'addresses' => []
            ];
        }
        $map[$key]['totalOrders'] += 1;
        $map[$key]['totalSpent'] += intval($o['pricing']['total'] ?? 0);
        if (!empty($cust['address']) && !in_array($cust['address'], $map[$key]['addresses'])) {
            $map[$key]['addresses'][] = $cust['address'];
        }
    }
    $customers = array_values($map);
    usort($customers, fn($a, $b) => $b['totalSpent'] - $a['totalSpent']);
    PlezzyDB::writeJson($customersFile, $customers);
}

PlezzyDB::json([
    'success' => true,
    'count' => count($customers),
    'source' => 'json_fallback',
    'customers' => $customers
]);
