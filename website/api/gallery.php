<?php
// api/gallery.php — Image gallery upload & listing
// FIX: Added require helpers.php (was missing — caused requireAuth/respond/ok to be undefined).
require_once __DIR__ . '/helpers.php';
cors();

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDB();

// ── LIST GALLERY ─────────────────────────────────────────────
if ($method === 'GET') {
    $limit    = min((int) ($_GET['limit'] ?? 200), 500);
    $category = $_GET['category'] ?? '';
    $sql      = 'SELECT * FROM gallery';
    $params   = [];
    if ($category) {
        $sql    .= ' WHERE category = ?';
        $params[] = $category;
    }
    $sql .= ' ORDER BY created_at DESC LIMIT ?';
    $params[] = $limit;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    ok($stmt->fetchAll());
}

// ── UPLOAD IMAGE ─────────────────────────────────────────────
elseif ($method === 'POST') {
    requireAuth();

    if (empty($_FILES['image'])) {
        fail('No image file uploaded');
    }

    $file     = $_FILES['image'];
    $caption  = trim($_POST['caption']  ?? '');
    $category = trim($_POST['category'] ?? 'General');

    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    if (!in_array($file['type'], $allowed)) {
        fail('Only JPG, PNG, WEBP, GIF images are allowed');
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        fail('File too large — maximum size is 5 MB');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        fail('Upload error — code ' . $file['error'], 500);
    }

    $uploadDir = __DIR__ . '/../uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = 'img_' . uniqid() . '.' . $ext;
    $dest     = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        fail('Upload failed — check server write permissions', 500);
    }

    $stmt = $db->prepare('INSERT INTO gallery (filename, caption, category) VALUES (?, ?, ?)');
    $stmt->execute([$filename, $caption, $category]);

    ok([
        'id'       => $db->lastInsertId(),
        'filename' => $filename,
        'url'      => UPLOAD_URL . $filename,
    ], 'Image uploaded successfully');
}

// ── DELETE IMAGE ─────────────────────────────────────────────
elseif ($method === 'DELETE') {
    requireAuth();

    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) {
        fail('Image ID required');
    }

    $stmt = $db->prepare('SELECT filename FROM gallery WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if ($row) {
        $path = __DIR__ . '/../uploads/' . $row['filename'];
        if (file_exists($path)) {
            unlink($path);
        }
        $db->prepare('DELETE FROM gallery WHERE id = ?')->execute([$id]);
    }

    ok(null, 'Image deleted');
}

else {
    fail('Method not allowed', 405);
}
