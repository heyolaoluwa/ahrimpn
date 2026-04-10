<?php
// api/content.php — events + CMS site content
require_once __DIR__ . '/helpers.php';
cors();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$db     = getDB();

// Public read-only actions — no auth needed
$publicActions = ['site', 'events'];
$user = in_array($action, $publicActions) ? null : requireAuth();

// ── LIST EVENTS ──────────────────────────────────────────────
if ($method === 'GET' && $action === 'events') {
    $stmt = $db->query("SELECT * FROM events ORDER BY event_date ASC");
    ok($stmt->fetchAll());
}

// ── ADD EVENT ────────────────────────────────────────────────
elseif ($method === 'POST' && $action === 'add_event') {
    requireRole($user, 'admin');
    $b = body();
    $title = trim($b['title'] ?? '');
    if (!$title) fail('Title required');
    $code = nextCode('EVT','events','event_code');
    $db->prepare("INSERT INTO events (event_code,title,event_date,location,state,event_type,description,active)
                  VALUES (?,?,?,?,?,?,?,1)")
       ->execute([$code,$title,$b['event_date']??null,$b['location']??'',$b['state']??'All States',
                  $b['event_type']??'seminar',$b['description']??'']);
    ok(['event_code'=>$code], "Event '$title' added");
}

// ── TOGGLE EVENT ─────────────────────────────────────────────
elseif ($method === 'POST' && $action === 'toggle_event') {
    requireRole($user, 'admin');
    $id = (int)(body()['id'] ?? 0);
    $db->prepare("UPDATE events SET active = NOT active WHERE id=?")->execute([$id]);
    ok(null, 'Event status updated');
}

// ── DELETE EVENT ─────────────────────────────────────────────
elseif ($method === 'POST' && $action === 'delete_event') {
    requireRole($user, 'admin');
    $id = (int)(body()['id'] ?? 0);
    $db->prepare("DELETE FROM events WHERE id=?")->execute([$id]);
    ok(null, 'Event deleted');
}

// ── GET SITE CONTENT ─────────────────────────────────────────
elseif ($method === 'GET' && $action === 'site') {
    $stmt = $db->query("SELECT content_key, content_value FROM site_content");
    $rows = $stmt->fetchAll();
    $out  = [];
    foreach ($rows as $row) {
        $v = $row['content_value'];
        $decoded = json_decode($v, true);
        $out[$row['content_key']] = ($decoded !== null) ? $decoded : $v;
    }
    ok($out);
}

// ── UPDATE SITE CONTENT ──────────────────────────────────────
elseif ($method === 'POST' && $action === 'update_site') {
    requireRole($user, 'admin');
    $b   = body();
    $key = $b['key']   ?? '';
    $val = $b['value'] ?? '';
    if (!$key) fail('Content key required');
    $encoded = is_array($val) ? json_encode($val, JSON_UNESCAPED_UNICODE) : $val;
    $db->prepare("INSERT INTO site_content (content_key,content_value) VALUES (?,?)
                  ON DUPLICATE KEY UPDATE content_value=?")
       ->execute([$key,$encoded,$encoded]);
    ok(null, 'Content updated');
}

// ── UPLOAD HERO MEDIA ────────────────────────────────────────
elseif ($method === 'POST' && $action === 'upload_hero_media') {
    requireRole($user, 'admin');
    if (empty($_FILES['file'])) fail('No file received');
    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) fail('Upload error code: ' . $file['error']);

    $mime    = mime_content_type($file['tmp_name']);
    $images  = ['image/jpeg','image/png','image/gif','image/webp'];
    $videos  = ['video/mp4','video/webm','video/ogg','video/quicktime'];
    $isImage = in_array($mime, $images);
    $isVideo = in_array($mime, $videos);
    if (!$isImage && !$isVideo) fail('Allowed: JPEG, PNG, WEBP, GIF, MP4, WEBM');

    $maxBytes = $isVideo ? 100 * 1024 * 1024 : 10 * 1024 * 1024;
    if ($file['size'] > $maxBytes) fail($isVideo ? 'Video max 100 MB' : 'Image max 10 MB');

    $uploadDir = __DIR__ . '/../uploads/hero/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = 'hero_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename))
        fail('Could not save file — check server write permissions');

    ok([
        'url'        => '/uploads/hero/' . $filename,
        'media_type' => $isVideo ? 'video' : 'image',
    ], 'Uploaded successfully');
}

else fail('Unknown action');
