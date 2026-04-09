<?php
// api/payment.php — Flutterwave payment initiation & verification
// FIX: Webhook now uses FLW_WEBHOOK_HASH constant (not FLW_SECRET_KEY).
//      Keys moved to config.php. Minor structural cleanup.
require_once __DIR__ . '/helpers.php';
cors();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$db     = getDB();

// ── INITIATE PAYMENT ──────────────────────────────────────────
// Called by frontend right after registration to get a payment link.
// No auth token required so new members can call this immediately.
if ($method === 'POST' && $action === 'initiate') {
    $b        = body();
    $userId   = (int) ($b['user_id'] ?? 0);
    $planType = in_array($b['plan'] ?? '', ['monthly', 'annual']) ? $b['plan'] : 'monthly';

    if (!$userId) {
        fail('user_id is required');
    }

    $stmt = $db->prepare('SELECT id, member_id, name, email, phone FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $member = $stmt->fetch();
    if (!$member) {
        fail('Member not found', 404);
    }

    $amount = ($planType === 'annual') ? ANNUAL_DUES : MONTHLY_DUES;
    $txRef  = 'AHRIMPN-' . strtoupper(bin2hex(random_bytes(6)));

    $db->prepare("
        INSERT INTO flw_pending_payments (tx_ref, user_id, amount, plan_type, status, created_at)
        VALUES (?, ?, ?, ?, 'pending', NOW())
        ON DUPLICATE KEY UPDATE amount = ?, plan_type = ?, status = 'pending', created_at = NOW()
    ")->execute([$txRef, $userId, $amount, $planType, $amount, $planType]);

    $redirectUrl = APP_URL . '/api/payment.php?action=callback';

    $payload = [
        'tx_ref'       => $txRef,
        'amount'       => $amount,
        'currency'     => 'NGN',
        'redirect_url' => $redirectUrl,
        'meta'         => ['user_id' => $userId, 'plan_type' => $planType],
        'customer'     => [
            'email'       => $member['email'],
            'phonenumber' => $member['phone'] ?? '',
            'name'        => $member['name'],
        ],
        'customizations' => [
            'title'       => 'AHRIMPN Membership Dues',
            'description' => ($planType === 'annual' ? '12-month' : '1-month') . ' membership dues for ' . $member['name'],
            'logo'        => '',
        ],
        'payment_options' => 'card,banktransfer,ussd',
    ];

    $ch = curl_init(FLW_BASE_URL . '/payments');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . FLW_SECRET_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = json_decode($resp, true);
    if ($status !== 200 || ($result['status'] ?? '') !== 'success') {
        fail('Payment gateway error: ' . ($result['message'] ?? 'Unknown error'));
    }

    ok(['link' => $result['data']['link'], 'tx_ref' => $txRef], 'Payment link generated');
}

// ── VERIFY PAYMENT (frontend polling / redirect) ───────────────
elseif ($method === 'GET' && $action === 'verify') {
    $txRef         = trim($_GET['tx_ref']         ?? '');
    $transactionId = trim($_GET['transaction_id'] ?? '');

    if (!$txRef && !$transactionId) {
        fail('tx_ref or transaction_id is required');
    }

    $verifyResult = $transactionId ? flwVerifyById($transactionId) : flwVerifyByRef($txRef);
    if (!$verifyResult) {
        fail('Could not verify payment with gateway');
    }

    $flwStatus = $verifyResult['status'] ?? '';
    $flwAmount = (float) ($verifyResult['amount'] ?? 0);
    $flwRef    = $verifyResult['tx_ref'] ?? $txRef;

    $stmt = $db->prepare('SELECT * FROM flw_pending_payments WHERE tx_ref = ? LIMIT 1');
    $stmt->execute([$flwRef]);
    $pending = $stmt->fetch();
    if (!$pending) {
        fail('Transaction reference not found');
    }

    if ($pending['status'] === 'verified') {
        ok(['status' => 'already_verified', 'user_id' => $pending['user_id']], 'Already processed');
    }

    if ($flwStatus === 'successful' && $flwAmount >= (float) $pending['amount']) {
        activateFromPayment($db, $pending, $verifyResult);
        ok(['status' => 'success', 'user_id' => $pending['user_id']], 'Payment verified! Membership activated.');
    } else {
        $db->prepare("UPDATE flw_pending_payments SET status = 'failed', flw_response = ? WHERE tx_ref = ?")
           ->execute([json_encode($verifyResult), $flwRef]);
        fail('Payment not successful. Status: ' . $flwStatus);
    }
}

// ── REDIRECT CALLBACK (Flutterwave redirects browser here) ─────
elseif ($method === 'GET' && $action === 'callback') {
    $txRef         = $_GET['tx_ref']         ?? '';
    $transactionId = $_GET['transaction_id'] ?? '';
    $flwStatus     = $_GET['status']         ?? '';

    $portalUrl = APP_URL . '/mem.html';

    if ($flwStatus !== 'successful') {
        header("Location: {$portalUrl}?payment=failed&ref={$txRef}");
        exit;
    }

    $verifyResult = $transactionId ? flwVerifyById($transactionId) : flwVerifyByRef($txRef);
    if (!$verifyResult || $verifyResult['status'] !== 'successful') {
        header("Location: {$portalUrl}?payment=failed&ref={$txRef}");
        exit;
    }

    $flwRef = $verifyResult['tx_ref'] ?? $txRef;
    $stmt   = $db->prepare('SELECT * FROM flw_pending_payments WHERE tx_ref = ? LIMIT 1');
    $stmt->execute([$flwRef]);
    $pending = $stmt->fetch();

    if ($pending && $pending['status'] !== 'verified') {
        if ((float) $verifyResult['amount'] >= (float) $pending['amount']) {
            activateFromPayment($db, $pending, $verifyResult);
        }
    }

    header("Location: {$portalUrl}?payment=success&ref={$flwRef}&uid=" . ($pending['user_id'] ?? ''));
    exit;
}

// ── WEBHOOK (Flutterwave server-to-server POST) ────────────────
elseif ($method === 'POST' && $action === 'webhook') {
    // FIX: Use FLW_WEBHOOK_HASH (set in Flutterwave dashboard), NOT the secret key.
    $hash = $_SERVER['HTTP_VERIF_HASH'] ?? '';
    if ($hash !== FLW_WEBHOOK_HASH) {
        http_response_code(401);
        exit;
    }

    $payload = body();
    if (($payload['event'] ?? '') === 'charge.completed') {
        $data   = $payload['data'];
        $txRef  = $data['tx_ref'] ?? '';
        $stmt   = $db->prepare("SELECT * FROM flw_pending_payments WHERE tx_ref = ? AND status = 'pending' LIMIT 1");
        $stmt->execute([$txRef]);
        $pending = $stmt->fetch();
        if ($pending && $data['status'] === 'successful' && (float) $data['amount'] >= (float) $pending['amount']) {
            activateFromPayment($db, $pending, $data);
        }
    }

    http_response_code(200);
    echo 'OK';
    exit;
}

// ── ADMIN: PAYMENT HISTORY FOR A MEMBER ───────────────────────
elseif ($method === 'GET' && $action === 'history') {
    $authUser = requireAuth();
    requireRole($authUser, 'admin', 'executive');
    $userId = (int) ($_GET['user_id'] ?? 0);
    if (!$userId) {
        fail('user_id is required');
    }

    $stmt = $db->prepare("
        SELECT fp.*, u.name AS recorder_name
        FROM flw_pending_payments fp
        LEFT JOIN users u ON u.id = fp.verified_by
        WHERE fp.user_id = ?
        ORDER BY fp.created_at DESC
    ");
    $stmt->execute([$userId]);
    ok($stmt->fetchAll());
}

else {
    fail('Unknown action');
}


// ── INTERNAL HELPERS ──────────────────────────────────────────

function flwVerifyById(string $id): ?array {
    $ch = curl_init(FLW_BASE_URL . '/transactions/' . $id . '/verify');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . FLW_SECRET_KEY],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp   = curl_exec($ch);
    curl_close($ch);
    $result = json_decode($resp, true);
    return ($result['status'] ?? '') === 'success' ? $result['data'] : null;
}

function flwVerifyByRef(string $ref): ?array {
    $ch = curl_init(FLW_BASE_URL . '/transactions?tx_ref=' . urlencode($ref));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . FLW_SECRET_KEY],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp   = curl_exec($ch);
    curl_close($ch);
    $result = json_decode($resp, true);
    if (($result['status'] ?? '') === 'success' && !empty($result['data'])) {
        return $result['data'][0];
    }
    return null;
}

function activateFromPayment(PDO $db, array $pending, array $flwData): void {
    $userId   = (int) $pending['user_id'];
    $planType = $pending['plan_type'];
    $amount   = (float) $pending['amount'];
    $months   = ($planType === 'annual') ? 12 : 1;
    $flwTxId  = $flwData['id'] ?? null;

    $db->prepare('UPDATE users SET active = 1, activated_at = NOW() WHERE id = ? AND active = 0')
       ->execute([$userId]);

    $db->prepare("
        INSERT INTO dues_payments (user_id, amount, months_count, payment_type, recorded_by, notes)
        VALUES (?, ?, ?, ?, NULL, ?)
    ")->execute([
        $userId, $amount, $months, $planType,
        'Auto-verified via Flutterwave. Tx: ' . ($flwTxId ?? $pending['tx_ref']),
    ]);

    $db->prepare("
        UPDATE flw_pending_payments
        SET status = 'verified', flw_transaction_id = ?, flw_response = ?, verified_at = NOW()
        WHERE tx_ref = ?
    ")->execute([$flwTxId, json_encode($flwData), $pending['tx_ref']]);
}
