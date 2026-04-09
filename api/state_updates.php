<?php
// api/state_updates.php (with image upload)
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();

if ($method === 'GET') {
    $state  = $_GET['state']  ?? '';
    $status = $_GET['status'] ?? 'Published';
    $sql = 'SELECT * FROM state_updates WHERE 1=1';
    $params = [];
    if ($state)            { $sql .= ' AND state = ?';  $params[] = $state; }
    if ($status !== 'all') { $sql .= ' AND status = ?'; $params[] = $status; }
    $sql .= ' ORDER BY update_date DESC, created_at DESC LIMIT 100';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    respond($stmt->fetchAll());
}

function saveStateImage($imageFile) {
    if (!$imageFile || $imageFile['error'] !== UPLOAD_ERR_OK) return '';
    $allowed = ['image/jpeg','image/png','image/webp','image/gif'];
    if (!in_array($imageFile['type'], $allowed)) respond(['error' => 'Only JPG, PNG, WEBP, GIF allowed'], 400);
    if ($imageFile['size'] > 5 * 1024 * 1024) respond(['error' => 'Image too large (max 5MB)'], 400);
    $uploadDir = __DIR__ . '/../uploads/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $ext      = pathinfo($imageFile['name'], PATHINFO_EXTENSION);
    $filename = 'state_' . uniqid() . '.' . strtolower($ext);
    if (move_uploaded_file($imageFile['tmp_name'], $uploadDir . $filename)) {
        return UPLOAD_URL . $filename;
    }
    return '';
}

if ($method === 'POST') {
    requireAuth();
    $b = $_POST ?: getBody();
    if (empty($b['title'])) respond(['error' => 'Title required'], 400);
    $imageUrl = saveStateImage($_FILES['image'] ?? null) ?: ($b['image_url'] ?? '');
    $stmt = $db->prepare('INSERT INTO state_updates (title,state,update_date,content,image_url,status) VALUES (?,?,?,?,?,?)');
    $stmt->execute([
        $b['title'], $b['state'] ?? 'FCT - Abuja',
        $b['update_date'] ?: date('Y-m-d'),
        $b['content'] ?? '', $imageUrl, $b['status'] ?? 'Published'
    ]);
    respond(['success' => true, 'id' => $db->lastInsertId(), 'image_url' => $imageUrl]);
}

if ($method === 'PUT') {
    requireAuth();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) respond(['error' => 'ID required'], 400);
    $b = $_POST ?: getBody();
    $imageUrl = saveStateImage($_FILES['image'] ?? null);
    if (!$imageUrl) $imageUrl = $b['existing_image'] ?? $b['image_url'] ?? '';
    $stmt = $db->prepare('UPDATE state_updates SET title=?,state=?,update_date=?,content=?,image_url=?,status=? WHERE id=?');
    $stmt->execute([
        $b['title'], $b['state'] ?? 'FCT - Abuja',
        $b['update_date'] ?: date('Y-m-d'),
        $b['content'] ?? '', $imageUrl, $b['status'] ?? 'Published', $id
    ]);
    respond(['success' => true, 'image_url' => $imageUrl]);
}

if ($method === 'DELETE') {
    requireAuth();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) respond(['error' => 'ID required'], 400);
    $db->prepare('DELETE FROM state_updates WHERE id=?')->execute([$id]);
    respond(['success' => true]);
}

respond(['error' => 'Method not allowed'], 405);
