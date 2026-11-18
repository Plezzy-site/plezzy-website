<?php
/**
 * PLEZZY Orders & Checkout API (Hostinger MySQL & JSON Fallback)
 * Endpoints:
 *   GET    /api/orders (List, Filter, Search, CSV Export)
 *   POST   /api/orders (Create Order)
 *   PATCH  /api/orders?id=... (Update Status, Carrier, Tracking)
 *   DELETE /api/orders?id=... (Remove Order)
 */

require_once __DIR__ . '/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$pdo = PlezzyDB::getConnection();
$ordersFile = __DIR__ . '/../data/orders.json';
$customersFile = __DIR__ . '/../data/customers.json';

// ── GET /api/orders ───────────────────────────────────────────────────────────
if ($method === 'GET') {
    $orderId = isset($_GET['id']) ? trim($_GET['id']) : '';
    $statusFilter = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : '';
    $query = isset($_GET['q']) ? strtolower(trim($_GET['q'])) : '';
    $isExport = isset($_GET['export']) && $_GET['export'] === 'csv';

    $orders = [];

    if ($pdo) {
        try {
            if (!empty($orderId)) {
                $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? LIMIT 1");
                $stmt->execute([$orderId]);
                $row = $stmt->fetch();
                if (!$row) {
                    PlezzyDB::json(['success' => false, 'error' => 'Order not found'], 404);
                }
                PlezzyDB::json(['success' => true, 'order' => formatOrderRow($row)]);
            }

            $sql = "SELECT * FROM orders WHERE 1=1";
            $params = [];

            if (!empty($statusFilter) && $statusFilter !== 'all') {
                $sql .= " AND LOWER(order_status) = ?";
                $params[] = $statusFilter;
            }

            if (!empty($query)) {
                $sql .= " AND (LOWER(id) LIKE ? OR LOWER(customer_name) LIKE ? OR LOWER(customer_email) LIKE ? OR LOWER(customer_phone) LIKE ? OR LOWER(city) LIKE ?)";
                $like = '%' . $query . '%';
                $params = array_merge($params, [$like, $like, $like, $like, $like]);
            }

            $sql .= " ORDER BY created_at DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            $orders = array_map('formatOrderRow', $rows);
        } catch (Exception $e) {
            error_log('[API Orders] MySQL read error: ' . $e->getMessage());
        }
    }

    // Fallback to JSON if MySQL returned nothing or failed
    if (empty($orders) && file_exists($ordersFile)) {
        $allOrders = PlezzyDB::readJson($ordersFile);

        if (!empty($orderId)) {
            foreach ($allOrders as $o) {
                if (($o['id'] ?? '') === $orderId) {
                    PlezzyDB::json(['success' => true, 'order' => $o]);
                }
            }
            PlezzyDB::json(['success' => false, 'error' => 'Order not found'], 404);
        }

        $orders = $allOrders;

        if (!empty($statusFilter) && $statusFilter !== 'all') {
            $orders = array_values(array_filter($orders, fn($o) => strtolower($o['status'] ?? '') === $statusFilter));
        }

        if (!empty($query)) {
            $orders = array_values(array_filter($orders, function($o) use ($query) {
                $idMatch = str_contains(strtolower($o['id'] ?? ''), $query);
                $nameMatch = str_contains(strtolower($o['customer']['fullName'] ?? ''), $query);
                $emailMatch = str_contains(strtolower($o['customer']['email'] ?? ''), $query);
                $phoneMatch = str_contains(strtolower($o['customer']['phone'] ?? ''), $query);
                $cityMatch = str_contains(strtolower($o['customer']['city'] ?? ''), $query);
                return $idMatch || $nameMatch || $emailMatch || $phoneMatch || $cityMatch;
            }));
        }

        usort($orders, fn($a, $b) => strtotime($b['createdAt'] ?? 'now') - strtotime($a['createdAt'] ?? 'now'));
    }

    // CSV Export
    if ($isExport) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="plezzy-customer-orders.csv"');
        header('Cache-Control: no-cache, no-store, must-revalidate');

        $out = fopen('php://output', 'w');
        fputcsv($out, [
            'Order ID', 'Date', 'Customer Name', 'Email', 'Phone',
            'City', 'State', 'Pincode', 'Status', 'Payment Method',
            'Items Count', 'Subtotal (INR)', 'Discount', 'Shipping', 'Total (INR)',
            'Carrier', 'Tracking Number'
        ]);

        foreach ($orders as $o) {
            fputcsv($out, [
                $o['id'] ?? '',
                $o['formattedDate'] ?? ($o['createdAt'] ?? ''),
                $o['customer']['fullName'] ?? '',
                $o['customer']['email'] ?? '',
                $o['customer']['phone'] ?? '',
                $o['customer']['city'] ?? '',
                $o['customer']['state'] ?? '',
                $o['customer']['pincode'] ?? '',
                $o['status'] ?? '',
                $o['payment']['method'] ?? '',
                count($o['items'] ?? []),
                $o['pricing']['subtotal'] ?? 0,
                $o['pricing']['discount'] ?? 0,
                $o['pricing']['shipping'] ?? 0,
                $o['pricing']['total'] ?? 0,
                $o['shipping']['carrier'] ?? '',
                $o['shipping']['trackingNumber'] ?? ''
            ]);
        }
        fclose($out);
        exit();
    }

    PlezzyDB::json([
        'success' => true,
        'count' => count($orders),
        'source' => $pdo ? 'mysql' : 'json_fallback',
        'orders' => $orders
    ]);
}

// ── POST /api/orders (Create Order) ───────────────────────────────────────────
if ($method === 'POST') {
    $payload = PlezzyDB::getJsonInput();

    // Check for status update tunnel via POST /api/orders?id=...&action=status
    $action = isset($_GET['action']) ? $_GET['action'] : ($payload['action'] ?? '');
    $updateId = isset($_GET['id']) ? trim($_GET['id']) : ($payload['id'] ?? '');

    if ($action === 'status' || (!empty($updateId) && (isset($payload['status']) || isset($payload['carrier'])))) {
        handleOrderStatusUpdate($updateId, $payload, $pdo, $ordersFile);
    }

    // Create New Order Flow
    $orders = PlezzyDB::readJson($ordersFile, []);
    $orderId = 'PLZ-' . mt_rand(10000, 99999);
    $orderNumber = count($orders) + 1001;
    $now = date('c');
    $formattedDate = date('d M Y, h:i A');

    $cust = $payload['shipping'] ?? ($payload['customer'] ?? []);
    $fullName = trim($cust['fullName'] ?? ($cust['name'] ?? 'Valued Customer'));
    $phone = trim($cust['phone'] ?? '+91 00000 00000');
    $email = strtolower(trim($cust['email'] ?? 'customer@plezzy.in'));
    $address = trim($cust['address'] ?? ($cust['street'] ?? 'Address on file'));
    $apartment = trim($cust['apartment'] ?? ($cust['suite'] ?? ''));
    $city = trim($cust['city'] ?? 'Mumbai');
    $state = trim($cust['state'] ?? 'Maharashtra');
    $pincode = trim($cust['pincode'] ?? ($cust['zip'] ?? '400001'));
    $country = trim($cust['country'] ?? 'India');
    $stealthInstructions = trim($cust['stealthInstructions'] ?? ($payload['stealthInstructions'] ?? '100% Plain packaging. Zero product names.'));

    // Normalize Items
    $items = [];
    $rawItems = $payload['items'] ?? ($payload['cart'] ?? []);
    foreach ($rawItems as $it) {
        $price = intval($it['priceINR'] ?? ($it['price'] ?? 1350));
        $qty = intval($it['quantity'] ?? 1);
        $items[] = [
            'id' => $it['id'] ?? 'item',
            'name' => $it['name'] ?? 'Plezzy Sensory Essential',
            'shade' => $it['shade'] ?? ($it['color'] ?? 'Signature'),
            'price' => $price,
            'quantity' => $qty,
            'image' => $it['image'] ?? '/assets/products/pocket-pebble-main.jpg'
        ];
    }

    $subtotal = array_reduce($items, fn($sum, $it) => $sum + ($it['price'] * $it['quantity']), 0);
    $discount = intval($payload['discountAmountINR'] ?? ($payload['discount'] ?? 0));
    $shipping = isset($payload['shippingCostINR']) ? intval($payload['shippingCostINR']) : ($subtotal >= 1000 ? 0 : 150);
    $total = max(0, $subtotal - $discount + $shipping);

    $paymentMethod = $payload['paymentMethod'] ?? 'card';
    $paymentMethodLabel = $paymentMethod === 'card' ? 'Card' : ($paymentMethod === 'cod' ? 'Cash on Delivery' : 'UPI');
    $paymentStatus = $paymentMethod === 'cod' ? 'Pending upon Delivery' : 'Paid';

    $trackingNumber = 'BD-' . mt_rand(10000000, 99999999);
    $carrier = 'BlueDart Discreet Express';
    $estimatedDelivery = '2-3 Business Days';
    $packagingType = '100% Plain Unmarked Eco-Kraft Box';
    $billingDescriptor = 'PLZ-WLNS CO';

    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    // Insert into MySQL
    if ($pdo) {
        try {
            $sql = "INSERT INTO orders (
                id, customer_name, customer_email, customer_phone,
                address, apartment, city, state, pincode, country,
                stealth_instructions, items_json, subtotal, discount, discount_code,
                shipping_cost, total_amount, currency, payment_method, payment_status,
                order_status, carrier, tracking_number, estimated_delivery,
                packaging_type, billing_descriptor, origin_ip, origin_city, origin_country, user_agent
            ) VALUES (
                ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, 'INR', ?, ?,
                'processing', ?, ?, ?,
                ?, ?, ?, ?, ?, ?
            )";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $orderId, $fullName, $email, $phone,
                $address, $apartment, $city, $state, $pincode, $country,
                $stealthInstructions, json_encode($items), $subtotal, $discount, $payload['appliedPromo'] ?? '',
                $shipping, $total, $paymentMethodLabel, $paymentStatus,
                $carrier, $trackingNumber, $estimatedDelivery,
                $packagingType, $billingDescriptor, $ip, $city, $country, $userAgent
            ]);

            // Update or Insert Customer
            updateCustomerInMySql($pdo, $email, $fullName, $phone, $city, $state, $country, $total, $orderId, $address);
        } catch (Exception $e) {
            error_log('[API Orders] MySQL insert error: ' . $e->getMessage());
        }
    }

    // Build Standard Order Object
    $newOrder = [
        'id' => $orderId,
        'orderNumber' => $orderNumber,
        'createdAt' => $now,
        'formattedDate' => $formattedDate,
        'status' => 'processing',
        'customer' => [
            'fullName' => $fullName,
            'phone' => $phone,
            'email' => $email,
            'address' => $address,
            'apartment' => $apartment,
            'city' => $city,
            'state' => $state,
            'pincode' => $pincode,
            'country' => $country,
            'stealthInstructions' => $stealthInstructions
        ],
        'origin' => [
            'ip' => $ip,
            'city' => $city,
            'country' => $country,
            'device' => 'Mobile / Desktop',
            'userAgent' => $userAgent
        ],
        'items' => $items,
        'pricing' => [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'discountCode' => $payload['appliedPromo'] ?? '',
            'shipping' => $shipping,
            'isFreeShipping' => $shipping === 0,
            'total' => $total,
            'currency' => 'INR',
            'formattedTotal' => '₹' . number_format($total)
        ],
        'payment' => [
            'method' => $paymentMethodLabel,
            'status' => $paymentStatus,
            'stealthBillingDescriptor' => $billingDescriptor
        ],
        'shipping' => [
            'carrier' => $carrier,
            'trackingNumber' => $trackingNumber,
            'estimatedDelivery' => $estimatedDelivery,
            'packagingType' => $packagingType
        ]
    ];

    // Dual-write to data/orders.json for JSON resilience
    array_unshift($orders, $newOrder);
    PlezzyDB::writeJson($ordersFile, $orders);

    PlezzyDB::json([
        'success' => true,
        'orderId' => $orderId,
        'order' => $newOrder
    ], 201);
}

// ── PATCH /api/orders (Update Order Status) ───────────────────────────────────
if ($method === 'PATCH' || $method === 'PUT') {
    $orderId = isset($_GET['id']) ? trim($_GET['id']) : '';
    $payload = PlezzyDB::getJsonInput();
    if (empty($orderId) && !empty($payload['id'])) {
        $orderId = $payload['id'];
    }
    handleOrderStatusUpdate($orderId, $payload, $pdo, $ordersFile);
}

// ── DELETE /api/orders ────────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $orderId = isset($_GET['id']) ? trim($_GET['id']) : '';
    if (empty($orderId)) {
        $input = PlezzyDB::getJsonInput();
        $orderId = $input['id'] ?? '';
    }

    if (empty($orderId)) {
        PlezzyDB::json(['success' => false, 'error' => 'Order ID is required'], 400);
    }

    if ($pdo) {
        try {
            $stmt = $pdo->prepare("DELETE FROM orders WHERE id = ?");
            $stmt->execute([$orderId]);
        } catch (Exception $e) {}
    }

    $orders = PlezzyDB::readJson($ordersFile, []);
    $filtered = array_values(array_filter($orders, fn($o) => ($o['id'] ?? '') !== $orderId));
    PlezzyDB::writeJson($ordersFile, $filtered);

    PlezzyDB::json(['success' => true, 'deletedId' => $orderId]);
}

/**
 * Handle Order Status & Logistics Update
 */
function handleOrderStatusUpdate($orderId, $payload, $pdo, $ordersFile) {
    if (empty($orderId)) {
        PlezzyDB::json(['success' => false, 'error' => 'Order ID is required'], 400);
    }

    $status = $payload['status'] ?? null;
    $carrier = $payload['carrier'] ?? null;
    $trackingNumber = $payload['trackingNumber'] ?? null;
    $adminNotes = $payload['adminNotes'] ?? null;
    $paymentStatus = $payload['paymentStatus'] ?? null;

    if ($pdo) {
        try {
            $updates = [];
            $params = [];
            if ($status) { $updates[] = "order_status = ?"; $params[] = $status; }
            if ($carrier) { $updates[] = "carrier = ?"; $params[] = $carrier; }
            if ($trackingNumber) { $updates[] = "tracking_number = ?"; $params[] = $trackingNumber; }
            if ($adminNotes) { $updates[] = "admin_notes = ?"; $params[] = $adminNotes; }
            if ($paymentStatus) { $updates[] = "payment_status = ?"; $params[] = $paymentStatus; }

            if (!empty($updates)) {
                $params[] = $orderId;
                $sql = "UPDATE orders SET " . implode(', ', $updates) . " WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            }
        } catch (Exception $e) {
            error_log('[API Orders Update] MySQL error: ' . $e->getMessage());
        }
    }

    // Update in orders.json
    $orders = PlezzyDB::readJson($ordersFile, []);
    $idx = -1;
    foreach ($orders as $k => $o) {
        if (($o['id'] ?? '') === $orderId) { $idx = $k; break; }
    }

    if ($idx >= 0) {
        if ($status) $orders[$idx]['status'] = $status;
        if ($carrier) $orders[$idx]['shipping']['carrier'] = $carrier;
        if ($trackingNumber) $orders[$idx]['shipping']['trackingNumber'] = $trackingNumber;
        if ($adminNotes) $orders[$idx]['adminNotes'] = $adminNotes;
        if ($paymentStatus) $orders[$idx]['payment']['status'] = $paymentStatus;
        $orders[$idx]['updatedAt'] = date('c');
        PlezzyDB::writeJson($ordersFile, $orders);
        PlezzyDB::json(['success' => true, 'order' => $orders[$idx]]);
    }

    PlezzyDB::json(['success' => true, 'message' => 'Order updated']);
}

/**
 * Format MySQL row into standard Order structure
 */
function formatOrderRow($row) {
    $items = !empty($row['items_json']) ? json_decode($row['items_json'], true) : [];
    $total = intval($row['total_amount']);

    return [
        'id' => $row['id'],
        'orderNumber' => intval($row['order_number']),
        'createdAt' => $row['created_at'],
        'formattedDate' => date('d M Y, h:i A', strtotime($row['created_at'])),
        'status' => $row['order_status'],
        'customer' => [
            'fullName' => $row['customer_name'],
            'phone' => $row['customer_phone'],
            'email' => $row['customer_email'],
            'address' => $row['address'],
            'apartment' => $row['apartment'],
            'city' => $row['city'],
            'state' => $row['state'],
            'pincode' => $row['pincode'],
            'country' => $row['country'],
            'stealthInstructions' => $row['stealth_instructions']
        ],
        'origin' => [
            'ip' => $row['origin_ip'],
            'city' => $row['origin_city'],
            'country' => $row['origin_country'],
            'device' => $row['device'] ?: 'Mobile / Desktop',
            'userAgent' => $row['user_agent']
        ],
        'items' => $items,
        'pricing' => [
            'subtotal' => intval($row['subtotal']),
            'discount' => intval($row['discount']),
            'discountCode' => $row['discount_code'] ?: '',
            'shipping' => intval($row['shipping_cost']),
            'isFreeShipping' => intval($row['shipping_cost']) === 0,
            'total' => $total,
            'currency' => $row['currency'],
            'formattedTotal' => '₹' . number_format($total)
        ],
        'payment' => [
            'method' => $row['payment_method'],
            'status' => $row['payment_status'],
            'stealthBillingDescriptor' => $row['billing_descriptor']
        ],
        'shipping' => [
            'carrier' => $row['carrier'],
            'trackingNumber' => $row['tracking_number'],
            'estimatedDelivery' => $row['estimated_delivery'],
            'packagingType' => $row['packaging_type']
        ],
        'adminNotes' => $row['admin_notes']
    ];
}

/**
 * Update or Insert Customer Profile in MySQL
 */
function updateCustomerInMySql($pdo, $email, $fullName, $phone, $city, $state, $country, $amount, $orderId, $address) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM customers WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $existing = $stmt->fetch();

        if ($existing) {
            $addresses = !empty($existing['addresses_json']) ? json_decode($existing['addresses_json'], true) : [];
            if (!in_array($address, $addresses)) $addresses[] = $address;

            $sql = "UPDATE customers SET
                total_orders = total_orders + 1,
                total_spent = total_spent + ?,
                last_order_id = ?,
                last_order_date = NOW(),
                addresses_json = ?
                WHERE email = ?";
            $upStmt = $pdo->prepare($sql);
            $upStmt->execute([$amount, $orderId, json_encode($addresses), $email]);
        } else {
            $custId = 'CUST-' . substr(abs(crc32($email)), 0, 6);
            $sql = "INSERT INTO customers (
                id, full_name, email, phone, city, state, country,
                total_orders, total_spent, currency, last_order_id, last_order_date, addresses_json
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, 'INR', ?, NOW(), ?)";
            $inStmt = $pdo->prepare($sql);
            $inStmt->execute([
                $custId, $fullName, $email, $phone, $city, $state, $country,
                $amount, $orderId, json_encode([$address])
            ]);
        }
    } catch (Exception $e) {
        error_log('[API Customer] MySQL sync error: ' . $e->getMessage());
    }
}
