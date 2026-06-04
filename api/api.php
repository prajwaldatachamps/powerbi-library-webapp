<?php
// api/api.php

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => "PHP Error [$errno]: $errstr in $errfile on line $errline"
    ]);
    exit;
});

set_exception_handler(function($e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Exception: ' . $e->getMessage()
    ]);
    exit;
});

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://powerbi-library-webapp.vercel.app');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? trim($_GET['action']) : '';

restore_auth_from_cookie();

$pdo = getPDO();

// define('PBIX_UPLOAD_DIR', __DIR__ . '/../uploads/pbix/');
define('PBIX_MAX_SIZE', 2 * 1024 * 1024 * 1024);
define('PBIX_WEB_PATH', 'uploads/pbix/');

if (!is_dir(PBIX_UPLOAD_DIR)) {
    mkdir(PBIX_UPLOAD_DIR, 0755, true);
}

$body = [];

if (in_array($method, ['PUT', 'DELETE', 'POST'])) {
    $raw = file_get_contents('php://input');

    if ($raw) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $body = $decoded;
        }
    }

    if (!empty($_POST)) {
        $body = array_merge($body, $_POST);
    }
}

// ============================================================
//  SESSION CHECK
// ============================================================
if ($action === 'session_check') {
    restore_auth_from_cookie();

    $role = $_SESSION['user_role'] ?? '';

    json_out([
        'success'         => true,
        'logged_in'       => !empty($_SESSION['user_id']),
        'user_id'         => $_SESSION['user_id'] ?? null,
        'name'            => $_SESSION['user_name'] ?? null,
        'email'           => $_SESSION['user_email'] ?? null,
        'role'            => $role,
        'picture'         => $_SESSION['user_picture'] ?? null,
        'is_super_admin'  => $role === 'super_admin',
        'is_contributor'  => $role === 'contributor',
        'is_bi_developer' => $role === 'bi_developer',
        'can_add_content' => in_array($role, ['super_admin', 'contributor']),
        'pending_count'   => $role === 'super_admin' ? get_pending_count($pdo) : 0
    ]);
}

// ============================================================
//  LOGOUT
// ============================================================
if ($action === 'logout' && $method === 'POST') {
    session_unset();
    session_destroy();
    clear_auth_cookie();

    json_out(['success' => true]);
}

// ============================================================
//  STATS
// ============================================================
if ($action === 'get_stats' && $method === 'GET') {
    json_out([
        'success'       => true,
        'industries'    => (int) $pdo->query("SELECT COUNT(*) FROM industries    WHERE approval_status='approved'")->fetchColumn(),
        'functions'     => (int) $pdo->query("SELECT COUNT(*) FROM functions     WHERE approval_status='approved'")->fetchColumn(),
        'company_sizes' => (int) $pdo->query("SELECT COUNT(*) FROM company_sizes WHERE approval_status='approved'")->fetchColumn(),
        'dashboards'    => (int) $pdo->query("SELECT COUNT(*) FROM dashboards    WHERE approval_status='approved'")->fetchColumn(),
    ]);
}

// ============================================================
//  PENDING COUNT (super-admin badge)
// ============================================================
function get_pending_count(PDO $pdo): int {
    $tables = ['industries','functions','company_sizes','dashboards'];
    $total  = 0;
    foreach ($tables as $t) {
        $total += (int) $pdo->query("SELECT COUNT(*) FROM $t WHERE approval_status='pending'")->fetchColumn();
    }
    return $total;
}

if ($action === 'get_pending_count' && $method === 'GET') {
    require_super_admin();
    json_out(['success' => true, 'count' => get_pending_count($pdo)]);
}

// ============================================================
//  APPROVAL QUEUE (super-admin only)
// ============================================================
if ($action === 'get_pending_items' && $method === 'GET') {
    require_super_admin();
    $items = [];
    $tables = [
        'industries'    => ['label' => 'Industry',     'type' => 'industry'],
        'functions'     => ['label' => 'Function',     'type' => 'function'],
        'company_sizes' => ['label' => 'Company Size', 'type' => 'company_size'],
        'dashboards'    => ['label' => 'Dashboard',    'type' => 'dashboard'],
    ];
    foreach ($tables as $tbl => $meta) {
        $extraCol = ($tbl === 'dashboards') ? ', t.embed_url' : '';
        $stmt = $pdo->prepare("
            SELECT t.id, t.name, t.description, t.created_at$extraCol,
                   u.name AS submitted_by_name, u.email AS submitted_by_email,
                   '$meta[type]' AS item_type, '$meta[label]' AS item_label
            FROM $tbl t
            LEFT JOIN users u ON u.id = t.submitted_by
            WHERE t.approval_status = 'pending'
            ORDER BY t.created_at ASC
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) { $r['table'] = $tbl; }
        $items = array_merge($items, $rows);
    }
    usort($items, fn($a, $b) => strtotime($a['created_at']) - strtotime($b['created_at']));
    json_out(['success' => true, 'data' => $items]);
}

if ($action === 'approve_item' && $method === 'POST') {
    require_super_admin();
    $item_type = sanitize($body['item_type'] ?? '');
    $id        = (int)($body['id'] ?? 0);
    $tableMap  = ['industry' => 'industries', 'function' => 'functions', 'company_size' => 'company_sizes', 'dashboard' => 'dashboards'];
    $tbl       = $tableMap[$item_type] ?? '';
    if (!$tbl || !$id) json_out(['success' => false, 'message' => 'Invalid item'], 422);

    $stmt = $pdo->prepare("SELECT name, submitted_by FROM $tbl WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) json_out(['success' => false, 'message' => 'Item not found'], 404);

    $pdo->prepare("UPDATE $tbl SET approval_status='approved', reviewed_by=?, reviewed_at=NOW() WHERE id=?")
        ->execute([$_SESSION['user_id'], $id]);

    if ($row['submitted_by']) {
        notify($pdo, $row['submitted_by'], $item_type, $id, $row['name'], 'approved', 'Your submission has been approved and is now live.');
    }
    json_out(['success' => true]);
}

if ($action === 'reject_item' && $method === 'POST') {
    require_super_admin();
    $item_type = sanitize($body['item_type'] ?? '');
    $id        = (int)($body['id'] ?? 0);
    $reason    = sanitize($body['reason'] ?? '');
    $tableMap  = ['industry' => 'industries', 'function' => 'functions', 'company_size' => 'company_sizes', 'dashboard' => 'dashboards'];
    $tbl       = $tableMap[$item_type] ?? '';
    if (!$tbl || !$id) json_out(['success' => false, 'message' => 'Invalid item'], 422);

    $stmt = $pdo->prepare("SELECT name, submitted_by FROM $tbl WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) json_out(['success' => false, 'message' => 'Item not found'], 404);

    $pdo->prepare("UPDATE $tbl SET approval_status='rejected', reviewed_by=?, reviewed_at=NOW(), rejection_reason=? WHERE id=?")
        ->execute([$_SESSION['user_id'], $reason, $id]);

    if ($row['submitted_by']) {
        notify($pdo, $row['submitted_by'], $item_type, $id, $row['name'], 'rejected', $reason ?: 'Your submission was not approved.');
    }
    json_out(['success' => true]);
}

// ============================================================
//  NOTIFICATIONS
// ============================================================
if ($action === 'get_notifications' && $method === 'GET') {
    require_any_user();
    $stmt = $pdo->prepare("
        SELECT id, item_type, item_name, action, message, is_read, created_at
        FROM approval_notifications
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$_SESSION['user_id']]);
    json_out(['success' => true, 'data' => $stmt->fetchAll()]);
}

if ($action === 'mark_notifications_read' && $method === 'POST') {
    require_any_user();
    $pdo->prepare("UPDATE approval_notifications SET is_read=1 WHERE user_id=?")->execute([$_SESSION['user_id']]);
    json_out(['success' => true]);
}

// ============================================================
//  MY SUBMISSIONS
// ============================================================
if ($action === 'get_my_submissions' && $method === 'GET') {
    require_any_user();
    $uid = (int)$_SESSION['user_id'];
    $items = [];
    $tables = [
        'industries'    => 'industry',
        'functions'     => 'function',
        'company_sizes' => 'company_size',
        'dashboards'    => 'dashboard',
    ];
    foreach ($tables as $tbl => $type) {
        $stmt = $pdo->prepare("
            SELECT id, name, description, approval_status, rejection_reason, created_at,
                   '$type' AS item_type
            FROM $tbl WHERE submitted_by = ? AND approval_status IN ('pending','rejected')
            ORDER BY created_at DESC
        ");
        $stmt->execute([$uid]);
        $items = array_merge($items, $stmt->fetchAll());
    }
    usort($items, fn($a, $b) => strtotime($b['created_at']) - strtotime($a['created_at']));
    json_out(['success' => true, 'data' => $items]);
}

// ============================================================
//  USER MANAGEMENT (super_admin only)
// ============================================================
if ($action === 'get_users' && $method === 'GET') {
    require_super_admin();
    $stmt = $pdo->query("SELECT id, name, email, role, is_active, created_at FROM users ORDER BY created_at DESC");
    json_out(['success' => true, 'data' => $stmt->fetchAll()]);
}

if ($action === 'create_user' && $method === 'POST') {
    require_super_admin();
    $name  = sanitize($body['name']  ?? '');
    $email = strtolower(trim($body['email'] ?? ''));
    $role  = in_array($body['role'] ?? '', ['super_admin','contributor','bi_developer']) ? $body['role'] : 'bi_developer';
    if (empty($name) || empty($email)) json_out(['success' => false, 'message' => 'Name and email are required'], 422);
    $check = $pdo->prepare("SELECT id FROM users WHERE email = ?"); $check->execute([$email]);
    if ($check->fetch()) json_out(['success' => false, 'message' => 'Email already exists'], 422);
    $pdo->prepare("INSERT INTO users (name, email, role, is_active) VALUES (?, ?, ?, 1)")->execute([$name, $email, $role]);
    json_out(['success' => true, 'id' => (int) $pdo->lastInsertId()]);
}

if ($action === 'update_user' && $method === 'PUT') {
    require_super_admin();
    $id        = (int)($body['id'] ?? 0);
    $name      = sanitize($body['name'] ?? '');
    $role      = in_array($body['role'] ?? '', ['super_admin','contributor','bi_developer']) ? $body['role'] : 'bi_developer';
    $is_active = isset($body['is_active']) ? (int)$body['is_active'] : 1;
    if (!$id || empty($name)) json_out(['success' => false, 'message' => 'ID and Name are required'], 422);
    if ($id === (int)($_SESSION['user_id'] ?? 0) && !$is_active)
        json_out(['success' => false, 'message' => 'You cannot deactivate your own account'], 422);
    $pdo->prepare("UPDATE users SET name=?, role=?, is_active=? WHERE id=?")->execute([$name, $role, $is_active, $id]);
    json_out(['success' => true]);
}

if ($action === 'delete_user' && $method === 'DELETE') {
    require_super_admin();
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_out(['success' => false, 'message' => 'ID is required'], 422);
    if ($id === (int)($_SESSION['user_id'] ?? 0))
        json_out(['success' => false, 'message' => 'You cannot delete your own account'], 422);
    $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
    json_out(['success' => true]);
}

// ============================================================
//  INDUSTRIES
//  NOTE: ALL roles (including super-admin) see only 'approved'
//        on the main grid. Pending items are shown ONLY in the
//        Approval Queue modal via get_pending_items.
// ============================================================
if ($action === 'get_industries' && $method === 'GET') {
    $search = '%' . trim($_GET['search'] ?? '') . '%';
    $stmt = $pdo->prepare("
        SELECT i.id, i.name, i.icon, i.description, i.created_at,
               i.approval_status,
               COUNT(DISTINCT f.id) AS total_functions,
               COUNT(DISTINCT d.id) AS total_dashboards
        FROM industries i
        LEFT JOIN functions  f ON f.industry_id = i.id AND f.approval_status = 'approved'
        LEFT JOIN dashboards d ON d.industry_id = i.id AND d.approval_status = 'approved'
        WHERE i.name LIKE ? AND i.approval_status = 'approved'
        GROUP BY i.id, i.name, i.icon, i.description, i.created_at, i.approval_status
        ORDER BY i.created_at DESC
    ");
    $stmt->execute([$search]);
    json_out(['success' => true, 'data' => $stmt->fetchAll()]);
}

if ($action === 'create_industry' && $method === 'POST') {
    require_contributor_or_above();
    $name   = sanitize($body['name']        ?? '');
    $icon   = sanitize($body['icon']        ?? 'fa-industry');
    $desc   = sanitize($body['description'] ?? '');
    $status = submission_status();
    if (empty($name)) json_out(['success' => false, 'message' => 'Industry name is required'], 422);
    $pdo->prepare("INSERT INTO industries (name, icon, description, created_by, submitted_by, approval_status) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$name, $icon, $desc, $_SESSION['user_id'], $_SESSION['user_id'], $status]);
    $newId = (int)$pdo->lastInsertId();
    if ($status === 'pending') {
        notify($pdo, $_SESSION['user_id'], 'industry', $newId, $name, 'submitted', 'Your industry submission is awaiting approval.');
        $admins = $pdo->query("SELECT id FROM users WHERE role='super_admin' AND is_active=1");
        foreach ($admins->fetchAll() as $a) {
            if ($a['id'] !== (int)$_SESSION['user_id'])
                notify($pdo, $a['id'], 'industry', $newId, $name, 'submitted', 'New industry submission awaiting your review.');
        }
    }
    json_out(['success' => true, 'id' => $newId, 'status' => $status]);
}

if ($action === 'update_industry' && $method === 'PUT') {
    require_super_admin();
    $id   = (int)($body['id']          ?? 0);
    $name = sanitize($body['name']        ?? '');
    $icon = sanitize($body['icon']        ?? 'fa-industry');
    $desc = sanitize($body['description'] ?? '');
    if (!$id || empty($name)) json_out(['success' => false, 'message' => 'ID and Name are required'], 422);
    $pdo->prepare("UPDATE industries SET name=?, icon=?, description=? WHERE id=?")->execute([$name, $icon, $desc, $id]);
    json_out(['success' => true]);
}

if ($action === 'delete_industry' && $method === 'DELETE') {
    require_super_admin();
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_out(['success' => false, 'message' => 'ID is required'], 422);
    $files = $pdo->prepare("SELECT pf.file_name FROM powerbi_files pf INNER JOIN dashboards d ON d.id=pf.dashboard_id WHERE d.industry_id=?");
    $files->execute([$id]);
    foreach ($files->fetchAll() as $f) { if (file_exists(PBIX_UPLOAD_DIR.$f['file_name'])) unlink(PBIX_UPLOAD_DIR.$f['file_name']); }
    $pdo->prepare("DELETE FROM industries WHERE id=?")->execute([$id]);
    json_out(['success' => true]);
}

// ============================================================
//  FUNCTIONS
//  NOTE: ALL roles see only 'approved' on the main grid.
// ============================================================
if ($action === 'get_functions' && $method === 'GET') {
    $industry_id = (int)($_GET['industry_id'] ?? 0);
    $search      = '%' . trim($_GET['search'] ?? '') . '%';
    $where  = "WHERE f.name LIKE ? AND f.approval_status = 'approved'";
    $params = [$search];
    if ($industry_id > 0) { $where .= " AND f.industry_id=?"; $params[] = $industry_id; }
    $stmt = $pdo->prepare("
        SELECT f.id, f.name, f.icon, f.description, f.industry_id, f.created_at,
               f.approval_status,
               i.name AS industry_name,
               COUNT(DISTINCT cs.id) AS total_company_sizes,
               COUNT(DISTINCT d.id)  AS total_dashboards
        FROM functions f
        LEFT JOIN industries    i  ON i.id  = f.industry_id
        LEFT JOIN company_sizes cs ON cs.function_id = f.id AND cs.approval_status='approved'
        LEFT JOIN dashboards    d  ON d.function_id  = f.id AND d.approval_status='approved'
        $where
        GROUP BY f.id, f.name, f.icon, f.description, f.industry_id, f.created_at, f.approval_status, i.name
        ORDER BY f.created_at DESC
    ");
    $stmt->execute($params);
    json_out(['success' => true, 'data' => $stmt->fetchAll()]);
}

if ($action === 'create_function' && $method === 'POST') {
    require_contributor_or_above();
    $industry_id = (int)($body['industry_id'] ?? 0);
    $name   = sanitize($body['name']        ?? '');
    $icon   = sanitize($body['icon']        ?? 'fa-sitemap');
    $desc   = sanitize($body['description'] ?? '');
    $status = submission_status();
    if (!$industry_id || empty($name)) json_out(['success' => false, 'message' => 'Industry and Function name are required'], 422);
    $pdo->prepare("INSERT INTO functions (industry_id, name, icon, description, created_by, submitted_by, approval_status) VALUES (?, ?, ?, ?, ?, ?, ?)")
        ->execute([$industry_id, $name, $icon, $desc, $_SESSION['user_id'], $_SESSION['user_id'], $status]);
    $newId = (int)$pdo->lastInsertId();
    if ($status === 'pending') {
        notify($pdo, $_SESSION['user_id'], 'function', $newId, $name, 'submitted', 'Your function submission is awaiting approval.');
        $admins = $pdo->query("SELECT id FROM users WHERE role='super_admin' AND is_active=1");
        foreach ($admins->fetchAll() as $a) {
            if ($a['id'] !== (int)$_SESSION['user_id'])
                notify($pdo, $a['id'], 'function', $newId, $name, 'submitted', 'New function submission awaiting your review.');
        }
    }
    json_out(['success' => true, 'id' => $newId, 'status' => $status]);
}

if ($action === 'update_function' && $method === 'PUT') {
    require_super_admin();
    $id   = (int)($body['id']          ?? 0);
    $name = sanitize($body['name']        ?? '');
    $icon = sanitize($body['icon']        ?? 'fa-sitemap');
    $desc = sanitize($body['description'] ?? '');
    if (!$id || empty($name)) json_out(['success' => false, 'message' => 'ID and Name are required'], 422);
    $pdo->prepare("UPDATE functions SET name=?, icon=?, description=? WHERE id=?")->execute([$name, $icon, $desc, $id]);
    json_out(['success' => true]);
}

if ($action === 'delete_function' && $method === 'DELETE') {
    require_super_admin();
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_out(['success' => false, 'message' => 'ID is required'], 422);
    $files = $pdo->prepare("SELECT pf.file_name FROM powerbi_files pf INNER JOIN dashboards d ON d.id=pf.dashboard_id WHERE d.function_id=?");
    $files->execute([$id]);
    foreach ($files->fetchAll() as $f) { if (file_exists(PBIX_UPLOAD_DIR.$f['file_name'])) unlink(PBIX_UPLOAD_DIR.$f['file_name']); }
    $pdo->prepare("DELETE FROM functions WHERE id=?")->execute([$id]);
    json_out(['success' => true]);
}

// ============================================================
//  COMPANY SIZES
//  NOTE: ALL roles see only 'approved' on the main grid.
// ============================================================
if ($action === 'get_company_sizes' && $method === 'GET') {
    $function_id = (int)($_GET['function_id'] ?? 0);
    $industry_id = (int)($_GET['industry_id'] ?? 0);
    $search      = '%' . trim($_GET['search'] ?? '') . '%';
    $where  = "WHERE cs.name LIKE ? AND cs.approval_status = 'approved'";
    $params = [$search];
    if ($function_id > 0) { $where .= " AND cs.function_id=?"; $params[] = $function_id; }
    if ($industry_id > 0) { $where .= " AND cs.industry_id=?"; $params[] = $industry_id; }
    $stmt = $pdo->prepare("
        SELECT cs.id, cs.name, cs.icon, cs.description,
               cs.function_id, cs.industry_id, cs.created_at,
               cs.approval_status,
               f.name AS function_name, i.name AS industry_name,
               COUNT(d.id) AS total_dashboards
        FROM company_sizes cs
        LEFT JOIN functions  f ON f.id = cs.function_id
        LEFT JOIN industries i ON i.id = cs.industry_id
        LEFT JOIN dashboards d ON d.company_size_id = cs.id AND d.approval_status='approved'
        $where
        GROUP BY cs.id, cs.name, cs.icon, cs.description,
                 cs.function_id, cs.industry_id, cs.created_at, cs.approval_status,
                 f.name, i.name
        ORDER BY cs.created_at DESC
    ");
    $stmt->execute($params);
    json_out(['success' => true, 'data' => $stmt->fetchAll()]);
}

if ($action === 'create_company_size' && $method === 'POST') {
    require_contributor_or_above();
    $function_id = (int)($body['function_id'] ?? 0);
    $industry_id = (int)($body['industry_id'] ?? 0);
    $name   = sanitize($body['name']        ?? '');
    $icon   = sanitize($body['icon']        ?? 'fa-building');
    $desc   = sanitize($body['description'] ?? '');
    $status = submission_status();
    if (!$function_id || !$industry_id || empty($name)) json_out(['success' => false, 'message' => 'Function, Industry and Name are required'], 422);
    $pdo->prepare("INSERT INTO company_sizes (function_id, industry_id, name, icon, description, created_by, submitted_by, approval_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$function_id, $industry_id, $name, $icon, $desc, $_SESSION['user_id'], $_SESSION['user_id'], $status]);
    $newId = (int)$pdo->lastInsertId();
    if ($status === 'pending') {
        notify($pdo, $_SESSION['user_id'], 'company_size', $newId, $name, 'submitted', 'Your company size submission is awaiting approval.');
        $admins = $pdo->query("SELECT id FROM users WHERE role='super_admin' AND is_active=1");
        foreach ($admins->fetchAll() as $a) {
            if ($a['id'] !== (int)$_SESSION['user_id'])
                notify($pdo, $a['id'], 'company_size', $newId, $name, 'submitted', 'New company size submission awaiting your review.');
        }
    }
    json_out(['success' => true, 'id' => $newId, 'status' => $status]);
}

if ($action === 'update_company_size' && $method === 'PUT') {
    require_super_admin();
    $id   = (int)($body['id']          ?? 0);
    $name = sanitize($body['name']        ?? '');
    $icon = sanitize($body['icon']        ?? 'fa-building');
    $desc = sanitize($body['description'] ?? '');
    if (!$id || empty($name)) json_out(['success' => false, 'message' => 'ID and Name are required'], 422);
    $pdo->prepare("UPDATE company_sizes SET name=?, icon=?, description=? WHERE id=?")->execute([$name, $icon, $desc, $id]);
    json_out(['success' => true]);
}

if ($action === 'delete_company_size' && $method === 'DELETE') {
    require_super_admin();
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_out(['success' => false, 'message' => 'ID is required'], 422);
    $files = $pdo->prepare("SELECT pf.file_name FROM powerbi_files pf INNER JOIN dashboards d ON d.id=pf.dashboard_id WHERE d.company_size_id=?");
    $files->execute([$id]);
    foreach ($files->fetchAll() as $f) { if (file_exists(PBIX_UPLOAD_DIR.$f['file_name'])) unlink(PBIX_UPLOAD_DIR.$f['file_name']); }
    $pdo->prepare("DELETE FROM company_sizes WHERE id=?")->execute([$id]);
    json_out(['success' => true]);
}

// ============================================================
//  DASHBOARDS
//  NOTE: ALL roles see only 'approved' on the main grid.
// ============================================================
if ($action === 'get_dashboards' && $method === 'GET') {
    $company_size_id = (int)($_GET['company_size_id'] ?? 0);
    $function_id     = (int)($_GET['function_id']     ?? 0);
    $industry_id     = (int)($_GET['industry_id']     ?? 0);
    $search          = '%' . trim($_GET['search']      ?? '') . '%';
    $where  = "WHERE d.name LIKE ? AND d.approval_status = 'approved'";
    $params = [$search];
    if ($company_size_id > 0) { $where .= " AND d.company_size_id=?"; $params[] = $company_size_id; }
    if ($function_id     > 0) { $where .= " AND d.function_id=?";     $params[] = $function_id; }
    if ($industry_id     > 0) { $where .= " AND d.industry_id=?";     $params[] = $industry_id; }
    $stmt = $pdo->prepare("
        SELECT d.id, d.name, d.embed_url, d.description, d.is_active,
               d.company_size_id, d.function_id, d.industry_id, d.created_at,
               d.approval_status,
               cs.name AS company_size_name, f.name AS function_name, i.name AS industry_name,
               COUNT(pf.id) AS total_pbix_files
        FROM dashboards d
        LEFT JOIN company_sizes cs ON cs.id = d.company_size_id
        LEFT JOIN functions      f  ON f.id  = d.function_id
        LEFT JOIN industries     i  ON i.id  = d.industry_id
        LEFT JOIN powerbi_files  pf ON pf.dashboard_id = d.id
        $where
        GROUP BY d.id, d.name, d.embed_url, d.description, d.is_active,
                 d.company_size_id, d.function_id, d.industry_id, d.created_at, d.approval_status,
                 cs.name, f.name, i.name
        ORDER BY d.created_at DESC
    ");
    $stmt->execute($params);
    json_out(['success' => true, 'data' => $stmt->fetchAll()]);
}

if ($action === 'create_dashboard' && $method === 'POST') {
    require_contributor_or_above();
    $company_size_id = (int)($body['company_size_id'] ?? 0);
    $function_id     = (int)($body['function_id']     ?? 0);
    $industry_id     = (int)($body['industry_id']     ?? 0);
    $name      = sanitize($body['name']        ?? '');
    $embed_url = trim($body['embed_url']       ?? '');
    $desc      = sanitize($body['description'] ?? '');
    $is_active = isset($body['is_active'])     ? (int)$body['is_active'] : 1;
    $status    = submission_status();
    if (!$company_size_id || !$function_id || !$industry_id || empty($name) || empty($embed_url))
        json_out(['success' => false, 'message' => 'Company Size, Function, Industry, Name and Embed URL are required'], 422);
    $pdo->prepare("INSERT INTO dashboards (company_size_id, function_id, industry_id, name, embed_url, description, is_active, created_by, submitted_by, approval_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$company_size_id, $function_id, $industry_id, $name, $embed_url, $desc, $is_active, $_SESSION['user_id'], $_SESSION['user_id'], $status]);
    $newId = (int)$pdo->lastInsertId();
    if ($status === 'pending') {
        notify($pdo, $_SESSION['user_id'], 'dashboard', $newId, $name, 'submitted', 'Your dashboard submission is awaiting approval.');
        $admins = $pdo->query("SELECT id FROM users WHERE role='super_admin' AND is_active=1");
        foreach ($admins->fetchAll() as $a) {
            if ($a['id'] !== (int)$_SESSION['user_id'])
                notify($pdo, $a['id'], 'dashboard', $newId, $name, 'submitted', 'New dashboard submission awaiting your review.');
        }
    }
    json_out(['success' => true, 'id' => $newId, 'status' => $status]);
}

if ($action === 'update_dashboard' && $method === 'PUT') {
    require_super_admin();
    $id        = (int)($body['id']             ?? 0);
    $name      = sanitize($body['name']        ?? '');
    $embed_url = trim($body['embed_url']       ?? '');
    $desc      = sanitize($body['description'] ?? '');
    $is_active = isset($body['is_active'])     ? (int)$body['is_active'] : 1;
    if (!$id || empty($name)) json_out(['success' => false, 'message' => 'ID and Name are required'], 422);
    $pdo->prepare("UPDATE dashboards SET name=?, embed_url=?, description=?, is_active=? WHERE id=?")->execute([$name, $embed_url, $desc, $is_active, $id]);
    json_out(['success' => true]);
}

if ($action === 'delete_dashboard' && $method === 'DELETE') {
    require_super_admin();
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_out(['success' => false, 'message' => 'ID is required'], 422);
    $files = $pdo->prepare("SELECT file_name FROM powerbi_files WHERE dashboard_id=?"); $files->execute([$id]);
    foreach ($files->fetchAll() as $f) { if (file_exists(PBIX_UPLOAD_DIR.$f['file_name'])) unlink(PBIX_UPLOAD_DIR.$f['file_name']); }
    $pdo->prepare("DELETE FROM dashboards WHERE id=?")->execute([$id]);
    json_out(['success' => true]);
}

// ============================================================
//  POWER BI FILES
// ============================================================
if ($action === 'get_pbix_files' && $method === 'GET') {
    require_any_user();
    $dashboard_id = (int)($_GET['dashboard_id'] ?? 0);
    if (!$dashboard_id) json_out(['success' => false, 'message' => 'dashboard_id is required'], 422);
    $stmt = $pdo->prepare("
        SELECT pf.id, pf.display_name, pf.original_name, pf.file_name,
               pf.file_size, pf.description, pf.created_at,
               u.name AS uploaded_by_name
        FROM powerbi_files pf
        LEFT JOIN users u ON u.id = pf.uploaded_by
        WHERE pf.dashboard_id = ?
        ORDER BY pf.created_at DESC
    ");
    $stmt->execute([$dashboard_id]);
    $files = $stmt->fetchAll();
    $base  = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']==='on' ? 'https' : 'http')
           . '://' . $_SERVER['HTTP_HOST']
           . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/') . '/';
    foreach ($files as &$file) {
        $file['download_url'] = $base . PBIX_WEB_PATH . rawurlencode($file['file_name']);
        $file['file_size_kb'] = $file['file_size'] > 0
            ? ($file['file_size'] >= 1048576 ? round($file['file_size']/1048576,1).' MB' : round($file['file_size']/1024,1).' KB')
            : '—';
    }
    unset($file);
    json_out(['success' => true, 'data' => $files]);
}

if ($action === 'upload_pbix_file' && $method === 'POST') {
    require_super_admin();
    $dashboard_id = (int)($_POST['dashboard_id'] ?? 0);
    $display_name = sanitize($_POST['display_name'] ?? '');
    $description  = sanitize($_POST['description']  ?? '');
    if (!$dashboard_id) json_out(['success' => false, 'message' => 'dashboard_id is required'], 422);
    if (empty($display_name)) json_out(['success' => false, 'message' => 'Display name is required'], 422);
    if (!isset($_FILES['pbix_file']) || $_FILES['pbix_file']['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors=[1=>'File exceeds server limit.',2=>'File exceeds form limit.',3=>'Partial upload.',4=>'No file.',6=>'Missing temp folder.',7=>'Write failed.',8=>'Blocked.'];
        json_out(['success'=>false,'message'=>$uploadErrors[$_FILES['pbix_file']['error']??-1]??'Upload error.'],422);
    }
    $file=$_FILES['pbix_file']; $origName=basename($file['name']); $ext=strtolower(pathinfo($origName,PATHINFO_EXTENSION));
    if (!in_array($ext,['pbix','pbit'])) json_out(['success'=>false,'message'=>'Only .pbix and .pbit files allowed.'],422);
    if ($file['size']>PBIX_MAX_SIZE) json_out(['success'=>false,'message'=>'File too large. Max 2 GB.'],422);
    $storedName='dash'.$dashboard_id.'_'.time().'_'.bin2hex(random_bytes(4)).'.'.$ext;
    if (!move_uploaded_file($file['tmp_name'],PBIX_UPLOAD_DIR.$storedName)) json_out(['success'=>false,'message'=>'Failed to move file.'],500);
    $pdo->prepare("INSERT INTO powerbi_files (dashboard_id,display_name,file_name,original_name,file_size,description,uploaded_by) VALUES (?,?,?,?,?,?,?)")
        ->execute([$dashboard_id,$display_name,$storedName,$origName,$file['size'],$description,$_SESSION['user_id']]);
    json_out(['success'=>true,'id'=>(int)$pdo->lastInsertId(),'file_name'=>$storedName]);
}

if ($action === 'update_pbix_file' && $method === 'PUT') {
    require_super_admin();
    $id=$body['id']??0; $display_name=sanitize($body['display_name']??''); $description=sanitize($body['description']??'');
    if (!$id||empty($display_name)) json_out(['success'=>false,'message'=>'ID and Display Name required'],422);
    $pdo->prepare("UPDATE powerbi_files SET display_name=?,description=? WHERE id=?")->execute([$display_name,$description,$id]);
    json_out(['success'=>true]);
}

if ($action === 'delete_pbix_file' && $method === 'DELETE') {
    require_super_admin();
    $id=(int)($body['id']??0); if(!$id) json_out(['success'=>false,'message'=>'ID required'],422);
    $stmt=$pdo->prepare("SELECT file_name FROM powerbi_files WHERE id=?"); $stmt->execute([$id]); $row=$stmt->fetch();
    if($row){ if(file_exists(PBIX_UPLOAD_DIR.$row['file_name'])) unlink(PBIX_UPLOAD_DIR.$row['file_name']); $pdo->prepare("DELETE FROM powerbi_files WHERE id=?")->execute([$id]); json_out(['success'=>true]); }
    else json_out(['success'=>false,'message'=>'File not found'],404);
}

if ($action === 'download_pbix' && $method === 'GET') {
    require_any_user();
    $id=(int)($_GET['id']??0); if(!$id){header('Content-Type: application/json');echo json_encode(['success'=>false,'message'=>'ID required']);exit;}
    $stmt=$pdo->prepare("SELECT * FROM powerbi_files WHERE id=?"); $stmt->execute([$id]); $row=$stmt->fetch();
    if(!$row){header('Content-Type: application/json');echo json_encode(['success'=>false,'message'=>'File not found']);exit;}
    $filePath=PBIX_UPLOAD_DIR.$row['file_name'];
    if(!file_exists($filePath)){header('Content-Type: application/json');echo json_encode(['success'=>false,'message'=>'File missing on server']);exit;}
    $ext=strtolower(pathinfo($row['file_name'],PATHINFO_EXTENSION));
    $mime=($ext==='pbit')?'application/vnd.ms-powerbi-template':'application/octet-stream';
    header('Content-Description: File Transfer'); header('Content-Type: '.$mime);
    header('Content-Disposition: attachment; filename="'.addslashes($row['original_name']).'"');
    header('Content-Length: '.filesize($filePath)); header('Cache-Control: must-revalidate'); header('Pragma: public'); header('Expires: 0');
    ob_end_clean(); readfile($filePath); exit;
}

json_out(['success'=>false,'message'=>'Unknown action: '.$action],404);
