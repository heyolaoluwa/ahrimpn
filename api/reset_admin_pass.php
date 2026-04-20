<?php
// ONE-TIME admin password reset — DELETE THIS FILE after use
require_once __DIR__ . '/db.php';

$email    = 'support@ahrimpn.org'; // ← change to your admin email
$newPass  = 'Admin@2025!';       // ← set a strong new password

$hash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
$db   = getDB();
$stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
$stmt->execute([$hash, $email]);

if ($stmt->rowCount() === 0) {
    echo "No user found with email: $email\n";
} else {
    echo "Password reset successfully for $email\n";
    echo "New password: $newPass\n";
    echo "DELETE THIS FILE NOW!\n";
}
