<?php
// api/content.php — events + CMS site content
require_once __DIR__ . '/helpers.php';
cors();

$user   = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$db     = getDB();

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

else fail('Unknown action');
