<?php
// api/events.php — CRUD for events (with image upload)
// FIX: Added require helpers.php (was missing — caused requireAuth/respond/body to be undefined).
// FIX: Replaced getBody() calls with body() which is the correct helper function name.
require_once __DIR__ . '/helpers.php';
cors();

$method = $_SERVER['REQUEST_METHOD'];
$db     = getDB();

// ── LIST EVENTS (public) ─────────────────────────────────────
if ($method === 'GET' && empty($_GET['id'])) {
    $status = $_GET['status'] ?? '';
    $limit  = min((int) ($_GET['limit'] ?? 100), 200);
    $sql    = 'SELECT * FROM events WHERE 1=1';
    $params = [];
    if ($status) {
        $sql     .= ' AND status = ?';
        $params[] = $status;
    }
    $sql     .= ' ORDER BY start_date ASC LIMIT ?';
    $params[] = $limit;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    ok($stmt->fetchAll());
}

// ── GET SINGLE EVENT ─────────────────────────────────────────
elseif ($method === 'GET' && !empty($_GET['id'])) {
    $id   = (int) $_GET['id'];
    $stmt = $db->prepare('SELECT * FROM events WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row  = $stmt->fetch();
    if (!$row) {
        fail('Event not found', 404);
    }
    ok($row);
}

// ── CREATE EVENT ─────────────────────────────────────────────
elseif ($method === 'POST' && empty($_GET['id'])) {
    requireAuth();

    // Support both JSON body and multipart/form-data (for image uploads)
    $b        = !empty($_POST) ? $_POST : body();
    $imageUrl = handleImageUpload($_FILES['image'] ?? null, $b['image_url'] ?? '');

    if (empty($b['title'])) {
        fail('Title is required');
    }

    $stmt = $db->prepare('
        INSERT INTO events (title, start_date, end_date, venue, event_type, description, reg_link, image_url, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        trim($b['title']),
        $b['start_date'] ?: null,
        $b['end_date']   ?: null,
        trim($b['venue']       ?? ''),
        trim($b['event_type']  ?? 'Conference'),
        trim($b['description'] ?? ''),
        trim($b['reg_link']    ?? ''),
        $imageUrl,
        $b['status'] ?? 'Upcoming',
    ]);

    ok(['id' => $db->lastInsertId(), 'image_url' => $imageUrl], 'Event created successfully');
}

// ── UPDATE EVENT (PUT or POST with ?id=N) ───────────────────
elseif (in_array($method, ['PUT', 'POST']) && !empty($_GET['id'])) {
    requireAuth();

    $id = (int) $_GET['id'];
    if (!$id) {
        fail('Event ID required');
    }

    $b        = !empty($_POST) ? $_POST : body();
    $imageUrl = handleImageUpload($_FILES['image'] ?? null, $b['existing_image'] ?? ($b['image_url'] ?? ''));

    if (empty($b['title'])) {
        fail('Title is required');
    }

    $stmt = $db->prepare('
        UPDATE events
        SET title=?, start_date=?, end_date=?, venue=?, event_type=?,
            description=?, reg_link=?, image_url=?, status=?
        WHERE id=?
    ');
    $stmt->execute([
        trim($b['title']),
        $b['start_date'] ?: null,
        $b['end_date']   ?: null,
        trim($b['venue']       ?? ''),
        trim($b['event_type']  ?? 'Conference'),
        trim($b['description'] ?? ''),
        trim($b['reg_link']    ?? ''),
        $imageUrl,
        $b['status'] ?? 'Upcoming',
        $id,
    ]);

    ok(['image_url' => $imageUrl], 'Event updated successfully');
}

// ── DELETE EVENT ─────────────────────────────────────────────
elseif ($method === 'DELETE') {
    requireAuth();

    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) {
        fail('Event ID required');
    }

    $db->prepare('DELETE FROM events WHERE id = ?')->execute([$id]);
    ok(null, 'Event deleted');
}

else {
    fail('Method not allowed', 405);
}


// ── IMAGE UPLOAD HELPER ──────────────────────────────────────
function handleImageUpload(?array $file, string $fallback = ''): string {
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        return $fallback;
    }

    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    if (!in_array($file['type'], $allowed)) {
        fail('Only JPG, PNG, WEBP, GIF images are allowed');
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        fail('Image too large — maximum size is 5 MB');
    }

    $uploadDir = __DIR__ . '/../uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = 'event_' . uniqid() . '.' . $ext;

    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
        fail('Image upload failed — check server write permissions', 500);
    }

    return UPLOAD_URL . $filename;
}
