<?php
// api/jobs.php — Member Job Board with admin moderation
require_once __DIR__ . '/helpers.php';
cors();

$user   = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$db     = getDB();

// ── LIST APPROVED JOBS (all authenticated members) ──────────────────────────
if ($method === 'GET' && $action === 'list') {
    $stmt = $db->query("
        SELECT j.id, j.title, j.company, j.location, j.job_type,
               j.description, j.created_at,
               u.name AS poster_name, u.member_id AS poster_member_id
        FROM jobs j
        JOIN users u ON u.id = j.user_id
        WHERE j.status = 'approved'
        ORDER BY j.created_at DESC
    ");
    ok($stmt->fetchAll());
}

// ── LIST PENDING JOBS (admin/executive only) ─────────────────────────────────
elseif ($method === 'GET' && $action === 'pending') {
    requireRole($user, 'admin', 'executive');
    $stmt = $db->query("
        SELECT j.id, j.title, j.company, j.location, j.job_type,
               j.description, j.status, j.created_at,
               u.name AS poster_name, u.member_id AS poster_member_id, u.email AS poster_email
        FROM jobs j
        JOIN users u ON u.id = j.user_id
        WHERE j.status = 'pending'
        ORDER BY j.created_at DESC
    ");
    ok($stmt->fetchAll());
}

// ── LIST MY JOBS (member's own submissions) ──────────────────────────────────
elseif ($method === 'GET' && $action === 'mine') {
    $stmt = $db->prepare("
        SELECT id, title, company, location, job_type, description, status, created_at
        FROM jobs
        WHERE user_id = ?
        ORDER BY created_at DESC
    ");
    $stmt->execute([$user['id']]);
    ok($stmt->fetchAll());
}

// ── CREATE JOB LISTING ───────────────────────────────────────────────────────
elseif ($method === 'POST' && $action === 'create') {
    // Only active members can post jobs
    if (!$user['active'] && !in_array($user['role'], ['admin', 'executive'])) {
        fail('Your membership must be active to post job listings', 403);
    }

    $b           = body();
    $title       = trim($b['title']       ?? '');
    $company     = trim($b['company']     ?? '');
    $location    = trim($b['location']    ?? '');
    $job_type    = trim($b['job_type']    ?? 'Full-time');
    $description = trim($b['description'] ?? '');

    if (!$title || !$company || !$description) {
        fail('Title, company, and description are required');
    }

    $allowed_types = ['Full-time', 'Part-time', 'Contract', 'Internship', 'Volunteer'];
    if (!in_array($job_type, $allowed_types)) $job_type = 'Full-time';

    $db->prepare("
        INSERT INTO jobs (user_id, title, company, location, job_type, description, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
    ")->execute([$user['id'], $title, $company, $location, $job_type, $description]);

    ok(['id' => (int) $db->lastInsertId()], 'Job listing submitted for review');
}

// ── APPROVE JOB (admin) ──────────────────────────────────────────────────────
elseif ($method === 'POST' && $action === 'approve') {
    requireRole($user, 'admin', 'executive');
    $id = (int) (body()['id'] ?? 0);
    if (!$id) fail('Job ID required');

    $db->prepare("
        UPDATE jobs SET status = 'approved', reviewed_by = ?, reviewed_at = NOW()
        WHERE id = ?
    ")->execute([$user['id'], $id]);

    ok(null, 'Job listing approved and published');
}

// ── REJECT JOB (admin) ───────────────────────────────────────────────────────
elseif ($method === 'POST' && $action === 'reject') {
    requireRole($user, 'admin', 'executive');
    $id = (int) (body()['id'] ?? 0);
    if (!$id) fail('Job ID required');

    $db->prepare("
        UPDATE jobs SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW()
        WHERE id = ?
    ")->execute([$user['id'], $id]);

    ok(null, 'Job listing rejected');
}

// ── DELETE JOB (owner or admin) ──────────────────────────────────────────────
elseif ($method === 'POST' && $action === 'delete') {
    $b  = body();
    $id = (int) ($b['id'] ?? 0);
    if (!$id) fail('Job ID required');

    // Verify ownership unless admin
    $stmt = $db->prepare("SELECT user_id FROM jobs WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $job = $stmt->fetch();
    if (!$job) fail('Job not found', 404);

    if ($job['user_id'] !== $user['id'] && !in_array($user['role'], ['admin', 'executive'])) {
        fail('Forbidden', 403);
    }

    $db->prepare("DELETE FROM jobs WHERE id = ?")->execute([$id]);
    ok(null, 'Job listing deleted');
}

else {
    fail('Unknown action', 400);
}
