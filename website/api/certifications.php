<?php
// api/certifications.php
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();

if ($method === 'GET') {
    $status = $_GET['status'] ?? '';
    $sql = 'SELECT * FROM certifications WHERE 1=1';
    $params = [];
    if ($status) { $sql .= ' AND status = ?'; $params[] = $status; }
    $sql .= ' ORDER BY created_at DESC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    respond($stmt->fetchAll());
}

if ($method === 'POST') {
    requireAuth();
    $b = getBody();
    if (empty($b['name'])) respond(['error' => 'Name required'], 400);
    $stmt = $db->prepare('INSERT INTO certifications (name,category,duration,description,requirements,status) VALUES (?,?,?,?,?,?)');
    $stmt->execute([
        $b['name'], $b['category'] ?? 'Professional',
        $b['duration'] ?? '', $b['description'] ?? '',
        $b['requirements'] ?? '', $b['status'] ?? 'Active'
    ]);
    respond(['success' => true, 'id' => $db->lastInsertId()]);
}

if ($method === 'PUT') {
    requireAuth();
    $b  = getBody();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) respond(['error' => 'ID required'], 400);
    $stmt = $db->prepare('UPDATE certifications SET name=?,category=?,duration=?,description=?,requirements=?,status=? WHERE id=?');
    $stmt->execute([
        $b['name'], $b['category'] ?? 'Professional',
        $b['duration'] ?? '', $b['description'] ?? '',
        $b['requirements'] ?? '', $b['status'] ?? 'Active', $id
    ]);
    respond(['success' => true]);
}

if ($method === 'DELETE') {
    requireAuth();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) respond(['error' => 'ID required'], 400);
    $db->prepare('DELETE FROM certifications WHERE id=?')->execute([$id]);
    respond(['success' => true]);
}

respond(['error' => 'Method not allowed'], 405);
