<?php
// ============================================================
//  generate_hash.api
//  Run this ONCE in your browser to get the bcrypt hash,
//  then paste it into the SQL INSERT or run the UPDATE below.
//  DELETE this file from the server afterwards!
// ============================================================

$password = 'Admin@1234';                        // ← change before running
$hash     = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

// Auto-update the admin row in the database
require_once __DIR__ . '/config.php';
$pdo = getPDO();
$pdo->prepare("UPDATE admins SET password = ? WHERE email = 'admin@datachamps.com'")
    ->execute([$hash]);

echo "<pre style='font-family:monospace;font-size:15px;'>";
echo "Password : $password\n";
echo "Hash     : $hash\n";
echo "\nAdmin password updated in database ✓\n";
echo "DELETE this file now for security!\n";
echo "</pre>";
