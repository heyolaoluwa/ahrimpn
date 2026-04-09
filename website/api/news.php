<?php
// ═══════════════════════════════════════════════════════════
//  api/news.php — Trending News & Featured Story CRUD
//
//  GET  ?action=list              → public, returns all stories
//  POST ?action=save              → admin only, create or update (JSON)
//  POST ?action=upload            → admin only, upload image/video file
//  POST ?action=delete            → admin only, delete by id
// ═══════════════════════════════════════════════════════════
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

// ── Upload config ─────────────────────────────────────────
define('NEWS_UPLOAD_DIR', dirname(__DIR__) . '/uploads/news/');
define('NEWS_UPLOAD_URL', '/uploads/news/');
define('MAX_IMAGE_BYTES', 10  * 1024 * 1024);   // 10 MB
define('MAX_VIDEO_BYTES', 100 * 1024 * 1024);   // 100 MB
define('ALLOWED_IMAGE_TYPES', ['image/jpeg','image/png','image/gif','image/webp']);
define('ALLOWED_VIDEO_TYPES', ['video/mp4','video/webm','video/ogg','video/quicktime']);

// ── Auto-create table if it doesn't exist ────────────────
function ensureTable(): void {
    getDB()->exec("
        CREATE TABLE IF NOT EXISTS news_stories (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            category    VARCHAR(60)  NOT NULL DEFAULT 'Announcement',
            title       TEXT         NOT NULL,
            date_label  VARCHAR(60)  DEFAULT '',
            url         VARCHAR(600) DEFAULT '',
            featured    TINYINT(1)   NOT NULL DEFAULT 0,
            excerpt     TEXT,
            image       VARCHAR(255) DEFAULT '1.jpg',
            media_type  VARCHAR(10)  DEFAULT 'image',
            sort_order  INT          NOT NULL DEFAULT 0,
            created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            updated_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    // Add media_type column if upgrading from old schema
    try {
        getDB()->exec("ALTER TABLE news_stories ADD COLUMN media_type VARCHAR(10) DEFAULT 'image' AFTER image");
    } catch (Exception $e) { /* column already exists */ }
}

ensureTable();

// Ensure upload directory exists
if (!is_dir(NEWS_UPLOAD_DIR)) {
    mkdir(NEWS_UPLOAD_DIR, 0755, true);
}

$action = $_GET['action'] ?? '';

// ── LIST (public) ─────────────────────────────────────────
if ($action === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = getDB()
        ->query('SELECT * FROM news_stories ORDER BY featured DESC, sort_order ASC, id DESC')
        ->fetchAll();

    foreach ($rows as &$r) {
        $r['id']         = (int)$r['id'];
        $r['featured']   = (bool)$r['featured'];
        $r['sort_order'] = (int)$r['sort_order'];
        $r['media_type'] = $r['media_type'] ?? 'image';
    }
    ok($rows);
}

// ── UPLOAD FILE (admin only) ──────────────────────────────
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin();

    if (empty($_FILES['file'])) fail('No file received.');

    $file     = $_FILES['file'];
    $mime     = mime_content_type($file['tmp_name']);
    $isImage  = in_array($mime, ALLOWED_IMAGE_TYPES);
    $isVideo  = in_array($mime, ALLOWED_VIDEO_TYPES);

    if (!$isImage && !$isVideo) {
        fail('File type not allowed. Use JPEG, PNG, GIF, WEBP, MP4, WEBM, OGG, or MOV.');
    }

    $maxBytes = $isVideo ? MAX_VIDEO_BYTES : MAX_IMAGE_BYTES;
    if ($file['size'] > $maxBytes) {
        $limit = $isVideo ? '100MB' : '10MB';
        fail("File too large. Maximum size for " . ($isVideo ? 'videos' : 'images') . " is $limit.");
    }

    if ($file['error'] !== UPLOAD_ERR_OK) fail('Upload error code: ' . $file['error']);

    // Generate unique filename preserving extension
    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = 'news_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dest     = NEWS_UPLOAD_DIR . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        fail('Could not save file. Check server permissions.');
    }

    // Clean up old news uploads if total exceeds 500MB
    cleanupOldUploads(500 * 1024 * 1024);

    ok([
        'filename'   => $filename,
        'url'        => NEWS_UPLOAD_URL . $filename,
        'media_type' => $isVideo ? 'video' : 'image',
        'size'       => $file['size'],
    ]);
}

// ── SAVE (admin only) ─────────────────────────────────────
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin();

    $body       = json_decode(file_get_contents('php://input'), true) ?? [];
    $id         = isset($body['id'])         ? (int)$body['id']         : null;
    $category   = trim($body['category']    ?? 'Announcement');
    $title      = trim($body['title']       ?? '');
    $date       = trim($body['date']        ?? '');
    $url        = trim($body['url']         ?? '');
    $featured   = !empty($body['featured']);
    $excerpt    = trim($body['excerpt']     ?? '');
    $image      = trim($body['image']       ?? '1.jpg');
    $media_type = trim($body['media_type']  ?? 'image');

    if ($title === '') fail('Title is required.');

    $db = getDB();

    if ($featured) {
        $db->prepare('UPDATE news_stories SET featured = 0 WHERE id != ?')
           ->execute([$id ?? 0]);
    }

    if ($id) {
        $stmt = $db->prepare('
            UPDATE news_stories
            SET category=?, title=?, date_label=?, url=?, featured=?, excerpt=?, image=?, media_type=?
            WHERE id=?
        ');
        $stmt->execute([$category, $title, $date, $url, $featured ? 1 : 0, $excerpt, $image, $media_type, $id]);
        ok(['id' => $id]);
    } else {
        $stmt = $db->prepare('
            INSERT INTO news_stories (category, title, date_label, url, featured, excerpt, image, media_type)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$category, $title, $date, $url, $featured ? 1 : 0, $excerpt, $image, $media_type]);
        ok(['id' => (int)$db->lastInsertId()]);
    }
}

// ── DELETE (admin only) ───────────────────────────────────
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin();

    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $id   = (int)($body['id'] ?? 0);
    if (!$id) fail('ID is required.');

    // Optionally delete uploaded file too
    $row = getDB()->prepare('SELECT image FROM news_stories WHERE id=?');
    $row->execute([$id]);
    $story = $row->fetch();
    if ($story && str_starts_with($story['image'], '/uploads/news/')) {
        $path = dirname(__DIR__) . $story['image'];
        if (file_exists($path)) unlink($path);
    }

    getDB()->prepare('DELETE FROM news_stories WHERE id=?')->execute([$id]);
    ok(null);
}

// ── CLEANUP helper ────────────────────────────────────────
function cleanupOldUploads(int $maxTotalBytes): void {
    $files = glob(NEWS_UPLOAD_DIR . 'news_*');
    if (!$files) return;

    // Sort by modification time oldest first
    usort($files, fn($a, $b) => filemtime($a) - filemtime($b));

    $total = array_sum(array_map('filesize', $files));
    foreach ($files as $f) {
        if ($total <= $maxTotalBytes) break;
        $total -= filesize($f);
        unlink($f);
    }
}

fail('Unknown action.', 404);
