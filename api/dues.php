<?php
// api/dues.php — dues tracker and payment recording
require_once __DIR__ . '/helpers.php';
cors();

$user   = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$db     = getDB();

// ── LIST ALL DUES ────────────────────────────────────────────
if ($method === 'GET' && $action === 'list') {
    requireRole($user, 'admin', 'executive');
    $statusFilter = $_GET['status'] ?? '';
    $stmt = $db->query("
        SELECT u.id, u.member_id, u.name, u.state, u.join_date, u.active,
               COALESCE(SUM(p.amount),0) AS total_paid
        FROM users u
        LEFT JOIN dues_payments p ON p.user_id = u.id
        WHERE u.role = 'member'
        GROUP BY u.id
        ORDER BY u.name");
    $rows = $stmt->fetchAll();
    $result = [];
    foreach ($rows as $row) {
        $d = calcDues($row, (float)$row['total_paid']);
        $row['dues'] = $d;
        if (!$statusFilter || $d['status'] === $statusFilter) {
            $result[] = $row;
        }
    }
    ok($result);
}

// ── MY DUES (member self) ────────────────────────────────────
elseif ($method === 'GET' && $action === 'mine') {
    $stmt = $db->prepare("
        SELECT u.*, COALESCE(SUM(p.amount),0) AS total_paid
        FROM users u
        LEFT JOIN dues_payments p ON p.user_id = u.id
        WHERE u.id = ? GROUP BY u.id");
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch();
    $row['dues'] = calcDues($row, (float)$row['total_paid']);

    // payment history
    $hist = $db->prepare("SELECT * FROM dues_payments WHERE user_id=? ORDER BY paid_at DESC");
    $hist->execute([$user['id']]);
    $row['payment_history'] = $hist->fetchAll();
    ok($row);
}

// ── AGGREGATE STATS ──────────────────────────────────────────
elseif ($method === 'GET' && $action === 'stats') {
    requireRole($user, 'admin', 'executive');
    $stmt = $db->query("
        SELECT u.id, u.join_date,
               COALESCE(SUM(p.amount),0) AS total_paid
        FROM users u
        LEFT JOIN dues_payments p ON p.user_id = u.id
        WHERE u.role='member' GROUP BY u.id");
    $rows = $stmt->fetchAll();
    $totalOwed = $totalPaid = $totalArrears = 0;
    $counts = ['paid'=>0,'partial'=>0,'overdue'=>0];
    foreach ($rows as $r) {
        $d = calcDues($r, (float)$r['total_paid']);
        $totalOwed    += $d['totalOwed'];
        $totalPaid    += $d['totalPaid'];
        $totalArrears += $d['arrearAmount'];
        $counts[$d['status']] = ($counts[$d['status']]??0) + 1;
    }
    $rate = $totalOwed > 0 ? round($totalPaid/$totalOwed*100) : 100;
    ok(compact('totalOwed','totalPaid','totalArrears','rate','counts'));
}

// ── RECORD PAYMENT ───────────────────────────────────────────
elseif ($method === 'POST' && $action === 'record') {
    requireRole($user, 'admin', 'executive');
    $b       = body();
    $userId  = (int)($b['user_id']  ?? 0);
    $amount  = (float)($b['amount'] ?? 0);
    $type    = $b['type']  ?? 'monthly';
    $notes   = $b['notes'] ?? '';
    if (!$userId || $amount < MONTHLY_DUES) fail('Invalid payment details');
    // derive months covered
    $months = $type === 'annual' ? 12 : max(1, (int)round($amount / MONTHLY_DUES));
    $db->prepare("INSERT INTO dues_payments (user_id,amount,months_count,payment_type,recorded_by,notes)
                  VALUES (?,?,?,?,?,?)")
       ->execute([$userId,$amount,$months,$type,$user['id'],$notes]);
    ok(null, "₦".number_format($amount)." recorded successfully");
}

else fail('Unknown action');
