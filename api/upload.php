<?php
/**
 * PLEZZY Product Image Upload API
 * Endpoint:
 *   POST /api/upload
 */

require_once __DIR__ . '/db.php';

$uploadDir = __DIR__ . '/../assets/products';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    PlezzyDB::json(['success' => false, 'error' => 'Method not allowed'], 405);
}

if (empty($_FILES['image']) && empty($_FILES['file'])) {
    PlezzyDB::json(['success' => false, 'error' => 'No image file uploaded'], 400);
}

$file = $_FILES['image'] ?? $_FILES['file'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    PlezzyDB::json(['success' => false, 'error' => 'Upload error code: ' . $file['error']], 400);
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];
if (!in_array($ext, $allowed)) {
    PlezzyDB::json(['success' => false, 'error' => 'Invalid image format. Allowed: ' . implode(', ', $allowed)], 400);
}

// Generate clean unique filename
$baseName = preg_replace('/[^a-zA-Z0-9_-]/', '', pathinfo($file['name'], PATHINFO_FILENAME));
$filename = 'prod_' . $baseName . '_' . substr(md5(uniqid()), 0, 8) . '.' . $ext;
$targetPath = $uploadDir . '/' . $filename;

if (move_uploaded_file($file['tmp_name'], $targetPath)) {
    $publicUrl = '/assets/products/' . $filename;
    PlezzyDB::json([
        'success' => true,
        'url' => $publicUrl,
        'filename' => $filename
    ], 201);
} else {
    PlezzyDB::json(['success' => false, 'error' => 'Failed to save uploaded image'], 500);
}
