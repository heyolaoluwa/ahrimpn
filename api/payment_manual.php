<?php
// api/payment_manual.php — Manual bank-transfer proof uploads & certificate payments
require_once __DIR__ . '/helpers.php';

// File uploads use multipart/form-data, so we skip the JSON Content-Type header for those.
// Non-upload actions still accept JSON bodies.
cors();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$db     = getDB();

// ── UPLOAD PAYMENT PROOF (multipart POST) ──────────────────────────────────
if ($method === 'POST' && $action === 'upload_proof') {
    // Auth optional on registration upload (user just registered, token freshly issued)
    $user = requireAuth();

    $userId  = (int) ($_POST['user_id'] ?? $user['id']);
    $purpose = trim($_POST['purpose'] ?? 'registration'); // registration | dues | certificate

    if (!in_array($purpose, ['registration', 'dues', 'certificate'])) {
        fail('Invalid purpose');
    }

    // Only the member themselves or an admin can upload for a given user_id
    if ($user['id'] !== $userId && !in_array($user['role'], ['admin', 'executive'])) {
        fail('Forbidden', 403);
    }

    if (empty($_FILES['proof']) || $_FILES['proof']['error'] !== UPLOAD_ERR_OK) {
        fail('No file uploaded or upload error');
    }

    $file     = $_FILES['proof'];
    $maxBytes = 5 * 1024 * 1024; // 5 MB
    if ($file['size'] > $maxBytes) fail('File is too large (max 5 MB)');

    $allowed  = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png'];
    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($mimeType, $allowed)) fail('Invalid file type. Allowed: PDF, JPG, PNG');

    $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'pay_' . $userId . '_' . $purpose . '_' . time() . '.' . strtolower($ext);
    $dir      = __DIR__ . '/../uploads/payments/';
    if (!is_dir($dir)) mkdir($dir, 0775, true);

    if (!move_uploaded_file($file['tmp_name'], $dir . $filename)) {
        fail('Failed to save uploaded file');
    }

    // Determine amount
    $amount = 0;
    if ($purpose === 'certificate') {
        $amount = 2000;
    } elseif ($purpose === 'registration' || $purpose === 'dues') {
        $amount = MONTHLY_DUES; // default; admin adjusts on approval
    }

    $db->prepare("
        INSERT INTO manual_payments (user_id, amount, purpose, proof_file, status, created_at)
        VALUES (?, ?, ?, ?, 'pending', NOW())
    ")->execute([$userId, $amount, $purpose, $filename]);

    ok(['filename' => $filename], 'Payment proof uploaded successfully. Awaiting admin review.');
}

// ── RECORD CHAPTER PAYMENT (JSON POST) ─────────────────────────────────────
elseif ($method === 'POST' && $action === 'record_chapter') {
    $user   = requireAuth();
    $b      = body();
    $userId = (int) ($b['user_id'] ?? $user['id']);

    if ($user['id'] !== $userId && !in_array($user['role'], ['admin', 'executive'])) {
        fail('Forbidden', 403);
    }

    // Mark user as chapter-payment-type (stays inactive until admin activates)
    $db->prepare("UPDATE users SET payment_type = 'chapter' WHERE id = ?")
       ->execute([$userId]);

    ok(null, 'Chapter payment recorded. Account will be activated by chapter admin.');
}

// ── RECORD STATE PAYMENT (JSON POST) ───────────────────────────────────────
elseif ($method === 'POST' && $action === 'record_state') {
    $user   = requireAuth();
    $b      = body();
    $userId = (int) ($b['user_id'] ?? $user['id']);

    if ($user['id'] !== $userId && !in_array($user['role'], ['admin', 'executive'])) {
        fail('Forbidden', 403);
    }

    // Mark user as state-payment-type (stays inactive/pending until admin approves)
    $db->prepare("UPDATE users SET payment_type = 'state' WHERE id = ?")
       ->execute([$userId]);

    ok(null, 'State payment recorded. Account is pending admin approval.');
}

// ── GET CERTIFICATE PAYMENT STATUS ─────────────────────────────────────────
elseif ($method === 'GET' && $action === 'cert_status') {
    $user   = requireAuth();
    $userId = (int) ($_GET['user_id'] ?? $user['id']);

    if ($user['id'] !== $userId && !in_array($user['role'], ['admin', 'executive'])) {
        fail('Forbidden', 403);
    }

    $stmt = $db->prepare("
        SELECT id, status, created_at, reviewed_at, notes,
               CONCAT('AHRIMPN/CERT/',
                 YEAR(COALESCE(reviewed_at, created_at)),
                 '/', LPAD(id, 5, '0')) AS cert_number
        FROM manual_payments
        WHERE user_id = ? AND purpose = 'certificate'
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();

    ok($row ?: ['status' => 'none'], 'Certificate payment status');
}

// ── LIST CERT PAYMENTS (admin) — all statuses, cert purpose only ────────────
elseif ($method === 'GET' && $action === 'list_cert_payments') {
    $user = requireAuth();
    requireRole($user, 'admin', 'executive');

    $status = $_GET['status'] ?? '';
    $sql = "
        SELECT mp.id, mp.user_id, mp.amount, mp.status, mp.proof_file,
               mp.created_at, mp.reviewed_at,
               u.name      AS member_name,
               u.member_id AS member_id,
               u.email,
               CONCAT(
                 'AHRIMPN/CERT/',
                 YEAR(COALESCE(mp.reviewed_at, mp.created_at)),
                 '/',
                 LPAD(mp.id, 5, '0')
               ) AS cert_number
        FROM manual_payments mp
        JOIN users u ON u.id = mp.user_id
        WHERE mp.purpose = 'certificate'
    ";
    $params = [];
    if ($status) { $sql .= " AND mp.status = ?"; $params[] = $status; }
    $sql .= " ORDER BY mp.created_at DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    ok($stmt->fetchAll());
}

// ── LIST ALL PENDING PROOFS (admin) ─────────────────────────────────────────
elseif ($method === 'GET' && $action === 'list_pending') {
    $user = requireAuth();
    requireRole($user, 'admin', 'executive');

    $purpose = $_GET['purpose'] ?? ''; // optional filter
    $sql = "
        SELECT mp.id, mp.user_id, mp.amount, mp.purpose, mp.proof_file,
               mp.status, mp.created_at,
               u.name AS member_name, u.member_id, u.email
        FROM manual_payments mp
        JOIN users u ON u.id = mp.user_id
        WHERE mp.status = 'pending'
    ";
    $params = [];
    if ($purpose) { $sql .= " AND mp.purpose = ?"; $params[] = $purpose; }
    $sql .= " ORDER BY mp.created_at DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    ok($stmt->fetchAll());
}

// ── ADMIN APPROVE PAYMENT PROOF ─────────────────────────────────────────────
elseif ($method === 'POST' && $action === 'approve') {
    $user = requireAuth();
    requireRole($user, 'admin', 'executive');
    $b  = body();
    $id = (int) ($b['id'] ?? 0);
    if (!$id) fail('Payment record ID required');

    // Fetch the record
    $stmt = $db->prepare("SELECT * FROM manual_payments WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $pay = $stmt->fetch();
    if (!$pay) fail('Payment record not found');

    // Mark as approved
    $db->prepare("
        UPDATE manual_payments
        SET status = 'approved', reviewed_by = ?, reviewed_at = NOW()
        WHERE id = ?
    ")->execute([$user['id'], $id]);

    if ($pay['purpose'] === 'registration' || $pay['purpose'] === 'dues') {
        // Activate the member
        $db->prepare("UPDATE users SET active = 1, activated_at = NOW() WHERE id = ? AND active = 0")
           ->execute([$pay['user_id']]);

        // Record dues payment
        $type   = $b['plan_type'] ?? 'monthly';
        $amount = ($type === 'annual') ? ANNUAL_DUES : MONTHLY_DUES;
        $months = ($type === 'annual') ? 12 : 1;
        $db->prepare("
            INSERT INTO dues_payments (user_id, amount, months_count, payment_type, recorded_by, notes)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([
            $pay['user_id'], $amount, $months, $type, $user['id'],
            'Approved from bank proof upload by ' . $user['name'],
        ]);
    }
    // For 'certificate' purpose: the approval alone unlocks the cert (no dues recording needed)

    ok(null, 'Payment approved successfully');
}

// ── ADMIN REJECT PAYMENT PROOF ──────────────────────────────────────────────
elseif ($method === 'POST' && $action === 'reject') {
    $user = requireAuth();
    requireRole($user, 'admin', 'executive');
    $b  = body();
    $id = (int) ($b['id'] ?? 0);
    if (!$id) fail('Payment record ID required');

    $db->prepare("
        UPDATE manual_payments
        SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(),
            notes = ?
        WHERE id = ?
    ")->execute([$user['id'], $b['notes'] ?? 'Rejected by admin', $id]);

    ok(null, 'Payment proof rejected');
}

else {
    fail('Unknown action', 400);
}
