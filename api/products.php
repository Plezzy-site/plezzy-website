<?php
/**
 * PLEZZY Product Catalog API (Hostinger MySQL & JSON Fallback)
 * Endpoints:
 *   GET    /api/products
 *   POST   /api/products
 *   DELETE /api/products?id=...
 */

require_once __DIR__ . '/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$pdo = PlezzyDB::getConnection();
$productsFile = __DIR__ . '/../assets/products.json';

// ── GET /api/products ──────────────────────────────────────────────────────────
if ($method === 'GET') {
    // If specific ID requested
    $reqId = isset($_GET['id']) ? trim($_GET['id']) : '';

    if ($pdo) {
        try {
            if (!empty($reqId)) {
                $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? OR slug = ? LIMIT 1");
                $stmt->execute([$reqId, $reqId]);
                $row = $stmt->fetch();
                if (!$row) {
                    PlezzyDB::json(['success' => false, 'error' => 'Product not found'], 404);
                }
                $product = formatProductRow($row);
                PlezzyDB::json(['success' => true, 'product' => $product]);
            }

            $stmt = $pdo->query("SELECT * FROM products ORDER BY created_at ASC");
            $rows = $stmt->fetchAll();
            $products = array_map('formatProductRow', $rows);

            // If MySQL table is empty, seed from products.json
            if (empty($products) && file_exists($productsFile)) {
                $products = PlezzyDB::readJson($productsFile);
            }

            PlezzyDB::json([
                'success' => true,
                'count' => count($products),
                'source' => 'mysql',
                'products' => $products
            ]);
        } catch (Exception $e) {
            error_log('[API Products] MySQL read error: ' . $e->getMessage());
        }
    }

    // JSON Fallback
    $products = PlezzyDB::readJson($productsFile);
    if (!empty($reqId)) {
        foreach ($products as $p) {
            if (($p['id'] ?? '') === $reqId || ($p['slug'] ?? '') === $reqId) {
                PlezzyDB::json(['success' => true, 'product' => $p]);
            }
        }
        PlezzyDB::json(['success' => false, 'error' => 'Product not found'], 404);
    }

    PlezzyDB::json([
        'success' => true,
        'count' => count($products),
        'source' => 'json_fallback',
        'products' => $products
    ]);
}

// ── POST /api/products (Create or Update) ─────────────────────────────────────
if ($method === 'POST' || $method === 'PUT') {
    $input = PlezzyDB::getJsonInput();
    if (empty($input['name'])) {
        PlezzyDB::json(['success' => false, 'error' => 'Product name is required'], 400);
    }

    $name = trim($input['name']);
    $slug = !empty($input['slug']) ? trim($input['slug']) : strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
    $id = !empty($input['id']) ? trim($input['id']) : 'plz-' . $slug . '-' . substr(time(), -4);
    $priceINR = intval($input['priceINR'] ?? ($input['price'] ?? 2499));
    $originalINR = intval($input['originalINR'] ?? ($priceINR + 1000));
    $priceUSD = $input['priceUSD'] ?? ('$' . round($priceINR / 80) . '.00');
    $rating = floatval($input['rating'] ?? 5.0);
    $reviewsCount = intval($input['reviewsCount'] ?? 1);
    $image = $input['image'] ?? '/assets/products/pocket-pebble-main.jpg';
    $subtitle = $input['subtitle'] ?? 'Sensory wellness edition';
    $category = $input['category'] ?? 'STANDARD';
    $badge = $input['badge'] ?? 'NEW';
    $role = $input['role'] ?? ($name . ' - daily sensory ritual');
    $tagline = $input['tagline'] ?? $subtitle;
    $desc1 = $input['desc1'] ?? 'Crafted with body-safe liquid silicone for daily wellness.';
    $desc2 = $input['desc2'] ?? 'Engineered with quiet acoustic dampening and magnetic charging.';
    $availability = $input['availability'] ?? 'in_stock';

    $imagesJson = json_encode($input['images'] ?? [
        ['src' => $image, 'label' => 'Front Portrait'],
        ['src' => $image, 'label' => 'Detail View']
    ]);
    $shadesJson = json_encode($input['shades'] ?? [
        ['name' => 'Signature', 'color' => '#f82793', 'desc' => 'Core finish', 'badge' => 'Signature']
    ]);
    $categoriesJson = json_encode($input['categories'] ?? [$category, 'HER', 'HIM']);
    $accordionsJson = json_encode($input['accordions'] ?? [
        ['title' => 'Benefits & Sensory Ritual', 'content' => 'Ultra-quiet acoustic dampening delivers deep physical relaxation.'],
        ['title' => 'Materials & Specs', 'content' => '100% body-safe hypoallergenic liquid silicone. Submersible waterproof.'],
        ['title' => 'Stealth Delivery & Guarantee', 'content' => 'Dispatched in plain, unbranded packaging with 1-year warranty.']
    ]);

    // Save to MySQL if active
    if ($pdo) {
        try {
            $sql = "INSERT INTO products (
                id, slug, name, subtitle, category, badge, role, tagline,
                price_inr, original_inr, price_usd, rating, reviews_count, image,
                images_json, shades_json, categories_json, desc1, desc2, accordions_json, availability
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?
            ) ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                subtitle = VALUES(subtitle),
                category = VALUES(category),
                badge = VALUES(badge),
                price_inr = VALUES(price_inr),
                original_inr = VALUES(original_inr),
                image = VALUES(image),
                images_json = VALUES(images_json),
                shades_json = VALUES(shades_json),
                desc1 = VALUES(desc1),
                desc2 = VALUES(desc2),
                accordions_json = VALUES(accordions_json),
                availability = VALUES(availability)";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $id, $slug, $name, $subtitle, $category, $badge, $role, $tagline,
                $priceINR, $originalINR, $priceUSD, $rating, $reviewsCount, $image,
                $imagesJson, $shadesJson, $categoriesJson, $desc1, $desc2, $accordionsJson, $availability
            ]);
        } catch (Exception $e) {
            error_log('[API Products] MySQL write error: ' . $e->getMessage());
        }
    }

    // Also sync to products.json for dual resilience
    $prods = PlezzyDB::readJson($productsFile);
    $idx = -1;
    foreach ($prods as $k => $item) {
        if (($item['id'] ?? '') === $id) { $idx = $k; break; }
    }

    $prodObj = [
        'id' => $id,
        'slug' => $slug,
        'name' => $name,
        'subtitle' => $subtitle,
        'category' => $category,
        'badge' => $badge,
        'role' => $role,
        'tagline' => $tagline,
        'priceINR' => $priceINR,
        'originalINR' => $originalINR,
        'priceUSD' => $priceUSD,
        'rating' => $rating,
        'reviewsCount' => $reviewsCount,
        'image' => $image,
        'images' => json_decode($imagesJson, true),
        'shades' => json_decode($shadesJson, true),
        'categories' => json_decode($categoriesJson, true),
        'desc1' => $desc1,
        'desc2' => $desc2,
        'accordions' => json_decode($accordionsJson, true),
        'availability' => $availability,
        'price' => $priceINR,
        'priceNumeric' => $priceINR
    ];

    if ($idx >= 0) {
        $prods[$idx] = array_merge($prods[$idx], $prodObj);
    } else {
        array_unshift($prods, $prodObj);
    }
    PlezzyDB::writeJson($productsFile, $prods);

    PlezzyDB::json(['success' => true, 'product' => $prodObj], 201);
}

// ── DELETE /api/products ──────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $delId = isset($_GET['id']) ? trim($_GET['id']) : '';
    if (empty($delId)) {
        $input = PlezzyDB::getJsonInput();
        $delId = $input['id'] ?? '';
    }

    if (empty($delId)) {
        PlezzyDB::json(['success' => false, 'error' => 'Product ID is required'], 400);
    }

    if ($pdo) {
        try {
            $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
            $stmt->execute([$delId]);
        } catch (Exception $e) {}
    }

    $prods = PlezzyDB::readJson($productsFile);
    $filtered = array_values(array_filter($prods, fn($p) => ($p['id'] ?? '') !== $delId));
    PlezzyDB::writeJson($productsFile, $filtered);

    PlezzyDB::json(['success' => true, 'deletedId' => $delId]);
}

/**
 * Format MySQL row into standard Plezzy Product Object
 */
function formatProductRow($row) {
    return [
        'id' => $row['id'],
        'slug' => $row['slug'],
        'name' => $row['name'],
        'subtitle' => $row['subtitle'],
        'category' => $row['category'],
        'badge' => $row['badge'],
        'role' => $row['role'],
        'tagline' => $row['tagline'],
        'priceINR' => intval($row['price_inr']),
        'originalINR' => intval($row['original_inr']),
        'priceUSD' => $row['price_usd'],
        'rating' => floatval($row['rating']),
        'reviewsCount' => intval($row['reviews_count']),
        'image' => $row['image'],
        'images' => !empty($row['images_json']) ? json_decode($row['images_json'], true) : [],
        'shades' => !empty($row['shades_json']) ? json_decode($row['shades_json'], true) : [],
        'categories' => !empty($row['categories_json']) ? json_decode($row['categories_json'], true) : [$row['category']],
        'desc1' => $row['desc1'],
        'desc2' => $row['desc2'],
        'accordions' => !empty($row['accordions_json']) ? json_decode($row['accordions_json'], true) : [],
        'availability' => $row['availability'],
        'price' => intval($row['price_inr']),
        'priceNumeric' => intval($row['price_inr']),
        'createdAt' => $row['created_at'],
        'updatedAt' => $row['updated_at']
    ];
}
