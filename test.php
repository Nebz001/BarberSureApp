<?php
require_once __DIR__ . '/config/db.php'; // adjust path if needed

// Emails you want to reset
$emails = [
    'admin@example.com',
    'owner1@example.com',
    'owner2@example.com',
    'owner3@example.com',
    'cust1@example.com',
    'cust2@example.com',
    'cust3@example.com',
    'cust4@example.com',
    'cust5@example.com',
    'cust6@example.com'
];

$newPassword = 'pass1234';
$newHash = password_hash($newPassword, PASSWORD_DEFAULT);

foreach ($emails as $email) {
    $stmt = $pdo->prepare("UPDATE Users SET password_hash = ? WHERE email = ?");
    $stmt->execute([$newHash, $email]);
    echo "Password reset for {$email}<br>";
}

echo "✅ All done. Try logging in with pass1234 now.";
