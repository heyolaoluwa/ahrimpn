<?php
// api/auth.php — Login, register, logout
require_once __DIR__ . '/helpers.php';
cors();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$db     = getDB();

// ── LOGIN ─────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'login') {
    $b     = body();
    $email = trim($b['email']    ?? '');
    $pass  =      $b['password'] ?? '';

    if (!$email || !$pass) fail('Email and password are required');

    $stmt = $db->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($pass, $user['password_hash'])) {
        fail('Invalid email or password', 401);
    }

    // Create session token
    $token     = generateToken();
    $expiresAt = date('Y-m-d H:i:s', time() + SESSION_TTL);

    $db->prepare("INSERT INTO sessions (user_id, token, expires_at) VALUES (?, ?, ?)")
       ->execute([$user['id'], $token, $expiresAt]);

    // Remove sensitive fields before sending to client
    unset($user['password_hash']);

    ok([
        'token' => $token,
        'user'  => $user,
    ], 'Login successful');
}

// ── REGISTER ─────────────────────────────────────────────────
elseif ($method === 'POST' && $action === 'register') {
    $b         = body();
    $name      = trim($b['name']      ?? '');
    $email     = trim($b['email']     ?? '');
    $phone     = trim($b['phone']     ?? '');
    $state     = trim($b['state']     ?? '');
    $workplace = trim($b['workplace'] ?? '');
    $pass      =      $b['password']  ?? '';

    $category      = trim($b['membership_category']   ?? '');
    $cadre         = trim($b['professional_cadre']    ?? '');
    $qualification = trim($b['present_qualification'] ?? '');
    $paymentType   = trim($b['payment_type']          ?? 'individual');

    // Validate required fields
    if (!$name)  fail('Full name is required');
    if (!$email) fail('Email address is required');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Invalid email address');
    if (!$state) fail('State is required');
    if (!$category) fail('Membership category is required');
    if (!$cadre)    fail('Professional cadre is required');
    if (!$qualification) fail('Present qualification is required');
    if (strlen($pass) < 8) fail('Password must be at least 8 characters');

    $allowedCategories = ['Affiliate', 'Full', 'Associate', 'Fellow', 'Honorary'];
    $allowedCadres     = ['Technician', 'Technologist', 'Officer'];
    $allowedQuals      = ['PD/ND', 'HND', 'BHIM', 'MSC/MHIM', 'PHD'];
    if (!in_array($category, $allowedCategories))      fail('Invalid membership category');
    if (!in_array($cadre, $allowedCadres))             fail('Invalid professional cadre');
    if (!in_array($qualification, $allowedQuals))      fail('Invalid qualification');
    if (!in_array($paymentType, ['chapter','individual'])) $paymentType = 'individual';

    // Check email not already taken
    $chk = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $chk->execute([$email]);
    if ($chk->fetch()) fail('An account with that email already exists');

    // Create member
    $memberId = nextCode('MBR', 'users', 'member_id');
    $hash     = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);

    // New self-registered members start inactive (active=0) until payment.
    // Try INSERT with new columns first; fall back if migration not yet run.
    try {
        $db->prepare("
            INSERT INTO users
                (member_id, name, email, password_hash, phone, state, workplace,
                 membership_category, professional_cadre, present_qualification, payment_type,
                 role, join_date, active, activated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'member', CURDATE(), 0, NULL)
        ")->execute([$memberId, $name, $email, $hash, $phone, $state, $workplace,
                     $category, $cadre, $qualification, $paymentType]);
    } catch (\PDOException $e) {
        // Columns don't exist yet (migration pending) — use the base schema
        if (stripos($e->getMessage(), 'Unknown column') !== false) {
            $db->prepare("
                INSERT INTO users
                    (member_id, name, email, password_hash, phone, state, workplace,
                     role, join_date, active, activated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'member', CURDATE(), 0, NULL)
            ")->execute([$memberId, $name, $email, $hash, $phone, $state, $workplace]);
        } else {
            fail('Registration failed: ' . $e->getMessage());
        }
    }

    $userId = (int) $db->lastInsertId();

    // Create session so they can immediately proceed to payment
    $token     = generateToken();
    $expiresAt = date('Y-m-d H:i:s', time() + SESSION_TTL);

    $db->prepare("INSERT INTO sessions (user_id, token, expires_at) VALUES (?, ?, ?)")
       ->execute([$userId, $token, $expiresAt]);

    // Fetch the newly created user row to return (minus password)
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    unset($user['password_hash']);

    ok([
        'token'     => $token,
        'user'      => $user,
        'member_id' => $memberId,
    ], 'Account created successfully. Please complete your payment to activate membership.');
}

// ── LOGOUT ────────────────────────────────────────────────────
elseif ($method === 'POST' && $action === 'logout') {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token  = str_replace('Bearer ', '', $header);
    if ($token) {
        $db->prepare("DELETE FROM sessions WHERE token = ?")->execute([$token]);
    }
    ok(null, 'Logged out successfully');
}

// ── CHANGE PASSWORD ──────────────────────────────────────────
elseif ($method === 'POST' && $action === 'change_password') {
    $user    = requireAuth();
    $b       = body();
    $current = $b['current_password'] ?? '';
    $new     = $b['new_password']     ?? '';

    if (strlen($new) < 8) fail('New password must be at least 8 characters');

    // Re-fetch full row to get hash
    $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch();

    if (!password_verify($current, $row['password_hash'])) {
        fail('Current password is incorrect', 401);
    }

    $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
    $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
       ->execute([$hash, $user['id']]);

    // Invalidate all other sessions so old password can't be reused
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token  = str_replace('Bearer ', '', $header);
    $db->prepare("DELETE FROM sessions WHERE user_id = ? AND token != ?")
       ->execute([$user['id'], $token]);

    ok(null, 'Password changed successfully');
}

// ── ME (refresh current user) ─────────────────────────────────
elseif ($method === 'GET' && $action === 'me') {
    $user = requireAuth();
    unset($user['password_hash']);
    ok($user);
}

else {
    fail('Unknown action', 400);
}
