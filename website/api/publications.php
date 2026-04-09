<?php
// api/publications.php
require_once 'config.php';

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();

if ($method === 'GET') {
    $status = $_GET['status'] ?? 'Published';
    $type   = $_GET['type']   ?? '';
    $sql    = 'SELECT * FROM publications WHERE 1=1';
    $params = [];
    if ($status !== 'all') { $sql .= ' AND status = ?'; $params[] = $status; }
    if ($type)             { $sql .= ' AND pub_type = ?'; $params[] = $type; }
    $sql .= ' ORDER BY pub_year DESC, created_at DESC LIMIT 200';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    respond($stmt->fetchAll());
}

if ($method === 'POST') {
    requireAuth();
    $b = getBody();
    if (empty($b['title'])) respond(['error' => 'Title required'], 400);
    $stmt = $db->prepare('INSERT INTO publications (title,pub_type,pub_year,author,description,file_url,status) VALUES (?,?,?,?,?,?,?)');
    $stmt->execute([
        $b['title'], $b['pub_type'] ?? 'Journal',
        (int)($b['pub_year'] ?? date('Y')),
        $b['author'] ?? '', $b['description'] ?? '',
        $b['file_url'] ?? '', $b['status'] ?? 'Published'
    ]);
    respond(['success' => true, 'id' => $db->lastInsertId()]);
}

if ($method === 'PUT') {
    requireAuth();
    $b  = getBody();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) respond(['error' => 'ID required'], 400);
    $stmt = $db->prepare('UPDATE publications SET title=?,pub_type=?,pub_year=?,author=?,description=?,file_url=?,status=? WHERE id=?');
    $stmt->execute([
        $b['title'], $b['pub_type'] ?? 'Journal',
        (int)($b['pub_year'] ?? date('Y')),
        $b['author'] ?? '', $b['description'] ?? '',
        $b['file_url'] ?? '', $b['status'] ?? 'Published', $id
    ]);
    respond(['success' => true]);
}

if ($method === 'DELETE') {
    requireAuth();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) respond(['error' => 'ID required'], 400);
    $db->prepare('DELETE FROM publications WHERE id=?')->execute([$id]);
    respond(['success' => true]);
}

respond(['error' => 'Method not allowed'], 405);
