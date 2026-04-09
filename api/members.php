<?php
// api/members.php — CRUD for members + dues summary
// FIX: Removed references to non-existent 'membership_status' column.
//      Frontend JS should use u.active (1/0) to determine membership state.
require_once __DIR__ . '/helpers.php';
cors();

$user   = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$db     = getDB();

// ── GET LIST ──────────────────────────────────────────────────
if ($method === 'GET' && $action === 'list') {
    requireRole($user, 'admin', 'executive');
    $search = '%' . trim($_GET['q'] ?? '') . '%';
    $stmt   = $db->prepare("
        SELECT u.id, u.member_id, u.name, u.email, u.phone, u.state, u.workplace,
               u.role, u.join_date, u.active, u.activated_at,
               COALESCE(SUM(p.amount), 0) AS total_paid
        FROM users u
        LEFT JOIN dues_payments p ON p.user_id = u.id
        WHERE u.role = 'member'
          AND (u.name LIKE ? OR u.email LIKE ? OR u.state LIKE ?)
        GROUP BY u.id
        ORDER BY u.join_date DESC
    ");
    $stmt->execute([$search, $search, $search]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['dues'] = calcDues($row, (float) $row['total_paid']);
    }
    ok($rows);
}

// ── GET SINGLE ────────────────────────────────────────────────
elseif ($method === 'GET' && $action === 'get') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) {
        fail('Member ID required');
    }
    if ($user['role'] === 'member' && $user['id'] != $id) {
        fail('Forbidden', 403);
    }

    $stmt = $db->prepare("
        SELECT u.*, COALESCE(SUM(p.amount), 0) AS total_paid
        FROM users u
        LEFT JOIN dues_payments p ON p.user_id = u.id
        WHERE u.id = ?
        GROUP BY u.id
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        fail('Member not found', 404);
    }
    unset($row['password_hash']);
    $row['dues'] = calcDues($row, (float) $row['total_paid']);
    ok($row);
}

// ── DASHBOARD STATS ───────────────────────────────────────────
elseif ($method === 'GET' && $action === 'stats') {
    requireRole($user, 'admin', 'executive');
    $stmt = $db->query("
        SELECT u.id, u.join_date, u.active, u.activated_at,
               COALESCE(SUM(p.amount), 0) AS total_paid
        FROM users u
        LEFT JOIN dues_payments p ON p.user_id = u.id
        WHERE u.role = 'member'
        GROUP BY u.id
    ");
    $members       = $stmt->fetchAll();
    $totalMembers  = count($members);
    $activeMembers = 0;
    $totalCollected = 0;
    $totalArrears  = 0;
    $overdueCount  = 0;

    foreach ($members as $m) {
        if ($m['active']) {
            $activeMembers++;
        }
        $d = calcDues($m, (float) $m['total_paid']);
        $totalCollected += $d['totalPaid'];
        $totalArrears   += $d['arrearAmount'];
        if (in_array($d['status'], ['overdue', 'partial'])) {
            $overdueCount++;
        }
    }

    ok(compact('totalMembers', 'activeMembers', 'totalCollected', 'totalArrears', 'overdueCount'));
}

// ── ADD MEMBER (admin) ────────────────────────────────────────
elseif ($method === 'POST' && $action === 'add') {
    requireRole($user, 'admin');
    $b = body();

    $name      = trim($b['name']      ?? '');
    $email     = trim($b['email']     ?? '');
    $phone     = trim($b['phone']     ?? '');
    $state     = trim($b['state']     ?? '');
    $workplace = trim($b['workplace'] ?? '');
    $joinDate  = $b['join_date']  ?? date('Y-m-d');
    $pass      = $b['password']   ?? 'ahrimpn2025';

    if (!$name || !$email || !$state) {
        fail('Name, email and state are required');
    }

    $chk = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $chk->execute([$email]);
    if ($chk->fetch()) {
        fail('Email is already registered');
    }

    $memberId = nextCode('MBR', 'users', 'member_id');
    $hash     = password_hash($pass, PASSWORD_BCRYPT);

    // Admin-added members are activated immediately
    $db->prepare("
        INSERT INTO users (member_id, name, email, password_hash, phone, state, workplace,
                           role, join_date, active, activated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'member', ?, 1, ?)
    ")->execute([$memberId, $name, $email, $hash, $phone, $state, $workplace, $joinDate, $joinDate]);

    ok(['member_id' => $memberId], "Member {$name} added successfully");
}

// ── TOGGLE ACTIVE ──────────────────────────────────────────────
elseif ($method === 'POST' && $action === 'toggle') {
    requireRole($user, 'admin');
    $id = (int) (body()['id'] ?? 0);
    if (!$id) {
        fail('Member ID required');
    }

    // When activating, set activated_at if not already set
    $db->prepare("
        UPDATE users
        SET active = NOT active,
            activated_at = CASE
                WHEN active = 0 AND activated_at IS NULL THEN NOW()
                ELSE activated_at
            END
        WHERE id = ?
    ")->execute([$id]);

    $stmt = $db->prepare('SELECT name, active FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    ok($row, $row['name'] . ' ' . ($row['active'] ? 'activated' : 'deactivated'));
}

// ── PROMOTE ROLE ──────────────────────────────────────────────
elseif ($method === 'POST' && $action === 'promote') {
    requireRole($user, 'admin');
    $b    = body();
    $id   = (int) ($b['id']   ?? 0);
    $role = $b['role'] ?? 'member';

    if (!in_array($role, ['member', 'executive', 'admin'])) {
        fail('Invalid role');
    }
    $db->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $id]);
    ok(null, 'Role updated successfully');
}

// ── PENDING APPROVALS ──────────────────────────────────────────
elseif ($method === 'GET' && $action === 'pending') {
    requireRole($user, 'admin', 'executive');
    $stmt = $db->query("
        SELECT u.*,
               fp.tx_ref, fp.amount AS flw_amount, fp.plan_type,
               fp.status AS flw_status, fp.created_at AS payment_initiated_at
        FROM users u
        LEFT JOIN flw_pending_payments fp ON fp.user_id = u.id
            AND fp.status IN ('pending', 'failed')
            AND fp.created_at = (
                SELECT MAX(fp2.created_at)
                FROM flw_pending_payments fp2
                WHERE fp2.user_id = u.id
                  AND fp2.status IN ('pending', 'failed')
            )
        WHERE u.active = 0 AND u.role = 'member'
        ORDER BY u.id DESC
    ");
    ok($stmt->fetchAll());
}

// ── ADMIN MANUAL APPROVE ───────────────────────────────────────
elseif ($method === 'POST' && $action === 'approve_payment') {
    requireRole($user, 'admin');
    $b  = body();
    $id = (int) ($b['id'] ?? 0);
    if (!$id) {
        fail('Member ID required');
    }

    // Activate
    $db->prepare('UPDATE users SET active = 1, activated_at = NOW() WHERE id = ?')->execute([$id]);

    $type   = $b['payment_type'] ?? 'monthly';
    $amount = ($type === 'annual') ? ANNUAL_DUES : MONTHLY_DUES;
    $months = ($type === 'annual') ? 12 : 1;

    $db->prepare("
        INSERT INTO dues_payments (user_id, amount, months_count, payment_type, recorded_by, notes)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([
        $id, $amount, $months, $type, $user['id'],
        $b['notes'] ?? 'Manual approval by admin: ' . $user['name'],
    ]);

    // Mark any pending Flutterwave record as admin-verified
    $db->prepare("
        UPDATE flw_pending_payments
        SET status = 'admin_verified', verified_by = ?, verified_at = NOW()
        WHERE user_id = ? AND status IN ('pending', 'failed')
    ")->execute([$user['id'], $id]);

    ok(null, 'Membership activated and payment recorded');
}

// ── REJECT MEMBER ──────────────────────────────────────────────
elseif ($method === 'POST' && $action === 'reject') {
    requireRole($user, 'admin');
    $id = (int) (body()['id'] ?? 0);
    if (!$id) {
        fail('Member ID required');
    }

    $db->prepare("DELETE FROM flw_pending_payments WHERE user_id = ?")->execute([$id]);
    $db->prepare("DELETE FROM sessions WHERE user_id = ?")->execute([$id]);
    $db->prepare("DELETE FROM users WHERE id = ? AND active = 0 AND role = 'member'")->execute([$id]);

    ok(null, 'Member rejected and removed');
}

else {
    fail('Unknown action or method');
}
