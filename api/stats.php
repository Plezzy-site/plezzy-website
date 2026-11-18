<?php
/**
 * PLEZZY Admin Statistics API (Hostinger MySQL & JSON Fallback)
 * Endpoint:
 *   GET /api/admin/stats
 */

require_once __DIR__ . '/db.php';

$pdo = PlezzyDB::getConnection();
$ordersFile = __DIR__ . '/../data/orders.json';

$orders = [];

if ($pdo) {
    try {
        $stmt = $pdo->query("SELECT * FROM orders ORDER BY created_at DESC");
        $rows = $stmt->fetchAll();
        if (!empty($rows)) {
            require_once __DIR__ . '/orders.php';
            $orders = array_map('formatOrderRow', $rows);
        }
    } catch (Exception $e) {
        error_log('[API Stats] MySQL error: ' . $e->getMessage());
    }
}

if (empty($orders)) {
    $orders = PlezzyDB::readJson($ordersFile, []);
}

$totalRevenue = array_reduce($orders, fn($s, $o) => $s + intval($o['pricing']['total'] ?? 0), 0);
$totalOrders = count($orders);
$avgOrderValue = $totalOrders > 0 ? intval(round($totalRevenue / $totalOrders)) : 0;

$statusCounts = [
    'processing' => 0,
    'confirmed' => 0,
    'packaging' => 0,
    'shipped' => 0,
    'delivered' => 0,
    'cancelled' => 0
];

$locationCounts = [];
$productCounts = [];
$paymentCounts = [];
$uniqueEmails = [];

foreach ($orders as $ord) {
    $st = strtolower($ord['status'] ?? 'processing');
    if (isset($statusCounts[$st])) {
        $statusCounts[$st]++;
    } else {
        $statusCounts['processing']++;
    }

    $email = strtolower(trim($ord['customer']['email'] ?? ''));
    if (!empty($email) && !in_array($email, $uniqueEmails)) {
        $uniqueEmails[] = $email;
    }

    $city = trim($ord['customer']['city'] ?? 'Mumbai');
    $locationCounts[$city] = ($locationCounts[$city] ?? 0) + 1;

    $pay = $ord['payment']['method'] ?? 'Card';
    $paymentCounts[$pay] = ($paymentCounts[$pay] ?? 0) + 1;

    foreach ($ord['items'] ?? [] as $it) {
        $name = $it['name'] ?? 'Plezzy Essential';
        if (!isset($productCounts[$name])) {
            $productCounts[$name] = [
                'name' => $name,
                'units' => 0,
                'revenue' => 0,
                'image' => $it['image'] ?? '/assets/products/pocket-pebble-main.jpg'
            ];
        }
        $qty = intval($it['quantity'] ?? 1);
        $productCounts[$name]['units'] += $qty;
        $productCounts[$name]['revenue'] += (intval($it['price'] ?? 0) * $qty);
    }
}

$topLocations = [];
foreach ($locationCounts as $city => $count) {
    $topLocations[] = ['city' => $city, 'count' => $count];
}
usort($topLocations, fn($a, $b) => $b['count'] - $a['count']);

$topProducts = array_values($productCounts);
usort($topProducts, fn($a, $b) => $b['units'] - $a['units']);

$stats = [
    'totalRevenue' => $totalRevenue,
    'totalOrders' => $totalOrders,
    'avgOrderValue' => $avgOrderValue,
    'uniqueCustomers' => count($uniqueEmails),
    'statusCounts' => $statusCounts,
    'topLocations' => $topLocations,
    'topProducts' => $topProducts,
    'paymentCounts' => $paymentCounts,
    'recentOrders' => array_slice($orders, 0, 5)
];

PlezzyDB::json([
    'success' => true,
    'source' => $pdo ? 'mysql' : 'json_fallback',
    'stats' => $stats
]);
