<?php
// api/helpers.php — Shared utilities
// FIX: Complete rewrite to resolve parse error (unclosed brace) and clean up all functions.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// ── CORS ─────────────────────────────────────────────────────
function cors(): void {
    header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGIN);
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

// ── JSON RESPONSES ───────────────────────────────────────────
function respond(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ok($data = null, string $message = 'OK'): void {
    respond(['success' => true, 'message' => $message, 'data' => $data]);
}

function fail(string $message, int $code = 400): void {
    respond(['success' => false, 'message' => $message], $code);
}

// ── INPUT ─────────────────────────────────────────────────────
function body(): array {
    static $parsed = null;
    if ($parsed === null) {
        $raw    = file_get_contents('php://input');
        $parsed = json_decode($raw, true) ?? [];
    }
    return $parsed;
}

// ── TOKEN AUTH ───────────────────────────────────────────────
function generateToken(): string {
    return bin2hex(random_bytes(32));
}

function requireAuth(): array {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token  = str_replace('Bearer ', '', $header);
    if (!$token) {
        fail('Unauthorized — no token provided', 401);
    }

    $db  = getDB();
    $sql = "SELECT s.user_id, s.expires_at,
                   u.id, u.member_id, u.name, u.email,
                   u.role, u.state, u.workplace, u.join_date,
                   u.active, u.phone, u.activated_at,
                   u.membership_category, u.professional_cadre,
                   u.present_qualification, u.payment_type
            FROM sessions s
            JOIN users u ON u.id = s.user_id
            WHERE s.token = ? AND s.expires_at > NOW()
            LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user) {
        fail('Session expired — please log in again', 401);
    }

    return $user;
}

function requireRole(array $user, string ...$roles): void {
    if (!in_array($user['role'], $roles, true)) {
        fail('Forbidden — insufficient permissions', 403);
    }
}

// ── DUES CALCULATOR ──────────────────────────────────────────
// Only calculates dues from activation date (not join_date).
// Members who haven't been activated yet return 'pending' status.
function calcDues(array $member, float $totalPaid): array {
    // Member not yet activated — no dues owed yet
    if (!$member['active']) {
        return [
            'monthsElapsed' => 0,
            'totalOwed'     => 0,
            'totalPaid'     => $totalPaid,
            'arrearMonths'  => 0,
            'arrearAmount'  => 0,
            'pct'           => 0,
            'status'        => 'pending',
            'monthsPaid'    => 0,
        ];
    }

    // Use activated_at if set, fall back to join_date
    $startStr  = !empty($member['activated_at']) ? $member['activated_at'] : $member['join_date'];
    $startDate = new DateTime($startStr);
    $now       = new DateTime();

    $monthsElapsed = (int)(
        ($now->format('Y') - $startDate->format('Y')) * 12
        + ($now->format('n') - $startDate->format('n'))
    );
    $monthsElapsed = max(0, $monthsElapsed);

    $totalOwed    = $monthsElapsed * MONTHLY_DUES;
    $monthsPaid   = (int) floor($totalPaid / MONTHLY_DUES);
    $arrearMonths = max(0, $monthsElapsed - $monthsPaid);
    $arrearAmount = $arrearMonths * MONTHLY_DUES;
    $pct          = $totalOwed > 0 ? min(100, (int) round($totalPaid / $totalOwed * 100)) : 100;

    if ($totalPaid == 0 && $monthsElapsed > 0) {
        $status = 'overdue';
    } elseif ($arrearMonths > 0) {
        $status = 'partial';
    } else {
        $status = 'paid';
    }

    return compact(
        'monthsElapsed', 'totalOwed', 'totalPaid',
        'arrearMonths', 'arrearAmount', 'pct', 'status', 'monthsPaid'
    );
}

// ── AUTO-INCREMENT CODES ──────────────────────────────────────
// Generates codes like MBR001, CRS042, etc.
function nextCode(string $prefix, string $table, string $col): string {
    $db     = getDB();
    $offset = strlen($prefix) + 1; // SUBSTRING is 1-indexed
    $sql    = "SELECT MAX(CAST(SUBSTRING({$col}, {$offset}) AS UNSIGNED)) AS n FROM {$table}";
    $stmt   = $db->query($sql);
    $n      = (int) ($stmt->fetch()['n'] ?? 0);
    return $prefix . str_pad($n + 1, 3, '0', STR_PAD_LEFT);
}
