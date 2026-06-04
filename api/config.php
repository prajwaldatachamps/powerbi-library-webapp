<?php
// php/config.php

define('DB_HOST', getenv('DB_HOST'));
define('DB_NAME', getenv('DB_NAME'));
define('DB_USER', getenv('DB_USER'));
define('DB_PASS', getenv('DB_PASS'));
define('DB_PORT', getenv('DB_PORT') ?: 3306);

define('GOOGLE_CLIENT_ID', getenv('GOOGLE_CLIENT_ID'));
define('GOOGLE_CLIENT_SECRET', getenv('GOOGLE_CLIENT_SECRET'));
define('GOOGLE_REDIRECT_URI', getenv('GOOGLE_REDIRECT_URI'));

function getPDO(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT .
                   ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'DB Connection failed: ' . $e->getMessage()]);
            exit;
        }
    }
    return $pdo;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function json_out(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// ── Role helpers ──────────────────────────────────────────────────────────────
function get_role(): string {
    return $_SESSION['user_role'] ?? '';
}

function is_super_admin(): bool {
    return !empty($_SESSION['user_id']) && $_SESSION['user_role'] === 'super_admin';
}

function is_contributor(): bool {
    return !empty($_SESSION['user_id']) && $_SESSION['user_role'] === 'contributor';
}

function is_logged_in(): bool {
    return !empty($_SESSION['user_id']);
}

// Require super_admin for destructive / management actions
function require_super_admin(): void {
    if (!is_super_admin()) {
        json_out(['success' => false, 'message' => 'Unauthorized — Super-admin access required'], 401);
    }
}

// Require contributor OR super_admin for content creation
function require_contributor_or_above(): void {
    if (empty($_SESSION['user_id'])) {
        json_out(['success' => false, 'message' => 'Unauthorized — Please login'], 401);
    }
    if (!in_array($_SESSION['user_role'], ['super_admin', 'contributor'])) {
        json_out(['success' => false, 'message' => 'Unauthorized — Contributor access required'], 401);
    }
}

// Require any authenticated user
function require_any_user(): void {
    if (empty($_SESSION['user_id'])) {
        json_out(['success' => false, 'message' => 'Unauthorized — Please login'], 401);
    }
}

// Determine approval_status for new submissions
// Super-admins bypass approval; contributors go to pending
function submission_status(): string {
    return is_super_admin() ? 'approved' : 'pending';
}

function sanitize(string $val): string {
    return htmlspecialchars(trim($val), ENT_QUOTES, 'UTF-8');
}

// Create an approval notification
function notify(PDO $pdo, int $userId, string $itemType, int $itemId, string $itemName, string $action, string $message = ''): void {
    try {
        $pdo->prepare("
            INSERT INTO approval_notifications (user_id, item_type, item_id, item_name, action, message)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([$userId, $itemType, $itemId, $itemName, $action, $message]);
    } catch (Exception $e) { /* non-fatal */ }
}