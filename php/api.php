<?php
// php/api.php

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => "PHP Error [$errno]: $errstr in $errfile on line $errline"]);
    exit;
});

set_exception_handler(function($e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Exception: ' . $e->getMessage()]);
    exit;
});

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? trim($_GET['action']) : '';
$pdo    = getPDO();

define('PBIX_UPLOAD_DIR', __DIR__ . '/../uploads/pbix/');
define('PBIX_MAX_SIZE', 2 * 1024 * 1024 * 1024);
define('PBIX_WEB_PATH',   'uploads/pbix/');

if (!is_dir(PBIX_UPLOAD_DIR)) {
    mkdir(PBIX_UPLOAD_DIR, 0755, true);
}

$body = [];
if (in_array($method, ['PUT', 'DELETE', 'POST'])) {
    $raw = file_get_contents('php://input');
    if ($raw) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) $body = $decoded;
    }
    if (!empty($_POST)) $body = array_merge($body, $_POST);
}

// ============================================================
//  SESSION CHECK
// ============================================================
if ($action === 'session_check') {
    json_out([
        'success'   => true,
        'logged_in' => !empty($_SESSION['user_id']),
        'name'      => $_SESSION['user_name']    ?? null,
        'email'     => $_SESSION['user_email']   ?? null,
        'role'      => $_SESSION['user_role']    ?? null,
        'picture'   => $_SESSION['user_picture'] ?? null,
        'is_admin'  => ($_SESSION['user_role']   ?? '') === 'admin',
    ]);
}

// ============================================================
//  LOGOUT
// ============================================================
if ($action === 'logout' && $method === 'POST') {
    session_unset();
    session_destroy();
    json_out(['success' => true]);
}

// ============================================================
//  STATS
// ============================================================
if ($action === 'get_stats' && $method === 'GET') {
    $industries = (int) $pdo->query("SELECT COUNT(*) FROM industries")->fetchColumn();
    $clients    = (int) $pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
    $dashboards = (int) $pdo->query("SELECT COUNT(*) FROM dashboards")->fetchColumn();
    json_out(['success' => true, 'industries' => $industries, 'clients' => $clients, 'dashboards' => $dashboards]);
}

// ============================================================
//  USER MANAGEMENT (admin only)
// ============================================================
if ($action === 'get_users' && $method === 'GET') {
    require_admin();
    $stmt = $pdo->query("SELECT id, name, email, role, is_active, created_at FROM users ORDER BY created_at DESC");
    json_out(['success' => true, 'data' => $stmt->fetchAll()]);
}

if ($action === 'create_user' && $method === 'POST') {
    require_admin();
    $name  = sanitize($body['name']  ?? '');
    $email = strtolower(trim($body['email'] ?? ''));
    $role  = in_array($body['role'] ?? '', ['admin', 'bi_developer']) ? $body['role'] : 'bi_developer';
    if (empty($name) || empty($email)) json_out(['success' => false, 'message' => 'Name and email are required'], 422);
    $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $check->execute([$email]);
    if ($check->fetch()) json_out(['success' => false, 'message' => 'Email already exists'], 422);
    $pdo->prepare("INSERT INTO users (name, email, role, is_active) VALUES (?, ?, ?, 1)")
        ->execute([$name, $email, $role]);
    json_out(['success' => true, 'id' => (int) $pdo->lastInsertId()]);
}

if ($action === 'update_user' && $method === 'PUT') {
    require_admin();
    $id        = (int)($body['id'] ?? 0);
    $name      = sanitize($body['name'] ?? '');
    $role      = in_array($body['role'] ?? '', ['admin', 'bi_developer']) ? $body['role'] : 'bi_developer';
    $is_active = isset($body['is_active']) ? (int)$body['is_active'] : 1;
    if (!$id || empty($name)) json_out(['success' => false, 'message' => 'ID and Name are required'], 422);
    if ($id === (int)($_SESSION['user_id'] ?? 0) && !$is_active)
        json_out(['success' => false, 'message' => 'You cannot deactivate your own account'], 422);
    $pdo->prepare("UPDATE users SET name=?, role=?, is_active=? WHERE id=?")->execute([$name, $role, $is_active, $id]);
    json_out(['success' => true]);
}

if ($action === 'delete_user' && $method === 'DELETE') {
    require_admin();
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_out(['success' => false, 'message' => 'ID is required'], 422);
    if ($id === (int)($_SESSION['user_id'] ?? 0))
        json_out(['success' => false, 'message' => 'You cannot delete your own account'], 422);
    $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
    json_out(['success' => true]);
}

// ============================================================
//  INDUSTRIES
// ============================================================
if ($action === 'get_industries' && $method === 'GET') {
    $search = '%' . trim($_GET['search'] ?? '') . '%';
    $stmt = $pdo->prepare("
        SELECT i.id, i.name, i.icon, i.description, i.created_at,
               u.name AS created_by_name,
               COUNT(DISTINCT c.id) AS total_clients,
               COUNT(DISTINCT d.id) AS total_dashboards
        FROM industries i
        LEFT JOIN users      u ON u.id = i.created_by
        LEFT JOIN clients    c ON c.industry_id = i.id
        LEFT JOIN dashboards d ON d.industry_id = i.id
        WHERE i.name LIKE ?
        GROUP BY i.id, i.name, i.icon, i.description, i.created_at, u.name
        ORDER BY i.created_at DESC
    ");
    $stmt->execute([$search]);
    json_out(['success' => true, 'data' => $stmt->fetchAll()]);
}

if ($action === 'create_industry' && $method === 'POST') {
    require_admin();
    $name = sanitize($body['name'] ?? '');
    $icon = sanitize($body['icon'] ?? 'fa-industry');
    $desc = sanitize($body['description'] ?? '');
    if (empty($name)) json_out(['success' => false, 'message' => 'Industry name is required'], 422);
    $pdo->prepare("INSERT INTO industries (name, icon, description, created_by) VALUES (?, ?, ?, ?)")
        ->execute([$name, $icon, $desc, $_SESSION['user_id']]);
    json_out(['success' => true, 'id' => (int) $pdo->lastInsertId()]);
}

if ($action === 'update_industry' && $method === 'PUT') {
    require_admin();
    $id   = (int)($body['id'] ?? 0);
    $name = sanitize($body['name'] ?? '');
    $icon = sanitize($body['icon'] ?? 'fa-industry');
    $desc = sanitize($body['description'] ?? '');
    if (!$id || empty($name)) json_out(['success' => false, 'message' => 'ID and Name are required'], 422);
    $pdo->prepare("UPDATE industries SET name=?, icon=?, description=? WHERE id=?")->execute([$name, $icon, $desc, $id]);
    json_out(['success' => true]);
}

if ($action === 'delete_industry' && $method === 'DELETE') {
    require_admin();
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_out(['success' => false, 'message' => 'ID is required'], 422);
    $files = $pdo->prepare("SELECT pf.file_name FROM powerbi_files pf INNER JOIN dashboards d ON d.id = pf.dashboard_id WHERE d.industry_id = ?");
    $files->execute([$id]);
    foreach ($files->fetchAll() as $f) { if (file_exists(PBIX_UPLOAD_DIR . $f['file_name'])) unlink(PBIX_UPLOAD_DIR . $f['file_name']); }
    $pdo->prepare("DELETE FROM industries WHERE id=?")->execute([$id]);
    json_out(['success' => true]);
}

// ============================================================
//  CLIENTS
// ============================================================
if ($action === 'get_clients' && $method === 'GET') {
    $industry_id = (int)($_GET['industry_id'] ?? 0);
    $search      = '%' . trim($_GET['search'] ?? '') . '%';
    $where  = "WHERE c.name LIKE ?";
    $params = [$search];
    if ($industry_id > 0) { $where .= " AND c.industry_id = ?"; $params[] = $industry_id; }
    $stmt = $pdo->prepare("
        SELECT c.id, c.name, c.email, c.phone, c.description, c.industry_id, c.created_at,
               i.name AS industry_name, COUNT(d.id) AS total_dashboards
        FROM clients c
        LEFT JOIN industries i ON i.id = c.industry_id
        LEFT JOIN dashboards d ON d.client_id = c.id
        $where
        GROUP BY c.id, c.name, c.email, c.phone, c.description, c.industry_id, c.created_at, i.name
        ORDER BY c.created_at DESC
    ");
    $stmt->execute($params);
    json_out(['success' => true, 'data' => $stmt->fetchAll()]);
}

if ($action === 'create_client' && $method === 'POST') {
    require_admin();
    $industry_id = (int)($body['industry_id'] ?? 0);
    $name  = sanitize($body['name']  ?? '');
    $email = sanitize($body['email'] ?? '');
    $phone = sanitize($body['phone'] ?? '');
    $desc  = sanitize($body['description'] ?? '');
    if (!$industry_id || empty($name)) json_out(['success' => false, 'message' => 'Industry and Client name are required'], 422);
    $pdo->prepare("INSERT INTO clients (industry_id, name, email, phone, description, created_by) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$industry_id, $name, $email, $phone, $desc, $_SESSION['user_id']]);
    json_out(['success' => true, 'id' => (int) $pdo->lastInsertId()]);
}

if ($action === 'update_client' && $method === 'PUT') {
    require_admin();
    $id    = (int)($body['id'] ?? 0);
    $name  = sanitize($body['name']  ?? '');
    $email = sanitize($body['email'] ?? '');
    $phone = sanitize($body['phone'] ?? '');
    $desc  = sanitize($body['description'] ?? '');
    if (!$id || empty($name)) json_out(['success' => false, 'message' => 'ID and Name are required'], 422);
    $pdo->prepare("UPDATE clients SET name=?, email=?, phone=?, description=? WHERE id=?")->execute([$name, $email, $phone, $desc, $id]);
    json_out(['success' => true]);
}

if ($action === 'delete_client' && $method === 'DELETE') {
    require_admin();
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_out(['success' => false, 'message' => 'ID is required'], 422);
    $files = $pdo->prepare("SELECT pf.file_name FROM powerbi_files pf INNER JOIN dashboards d ON d.id = pf.dashboard_id WHERE d.client_id = ?");
    $files->execute([$id]);
    foreach ($files->fetchAll() as $f) { if (file_exists(PBIX_UPLOAD_DIR . $f['file_name'])) unlink(PBIX_UPLOAD_DIR . $f['file_name']); }
    $pdo->prepare("DELETE FROM clients WHERE id=?")->execute([$id]);
    json_out(['success' => true]);
}

// ============================================================
//  DASHBOARDS
// ============================================================
if ($action === 'get_dashboards' && $method === 'GET') {
    $client_id   = (int)($_GET['client_id']   ?? 0);
    $industry_id = (int)($_GET['industry_id'] ?? 0);
    $search      = '%' . trim($_GET['search'] ?? '') . '%';
    $where  = "WHERE d.name LIKE ?";
    $params = [$search];
    if ($client_id   > 0) { $where .= " AND d.client_id   = ?"; $params[] = $client_id; }
    if ($industry_id > 0) { $where .= " AND d.industry_id = ?"; $params[] = $industry_id; }
    $stmt = $pdo->prepare("
        SELECT d.id, d.name, d.embed_url, d.description, d.is_active,
               d.client_id, d.industry_id, d.created_at,
               c.name AS client_name, i.name AS industry_name,
               COUNT(pf.id) AS total_pbix_files
        FROM dashboards d
        LEFT JOIN clients       c  ON c.id  = d.client_id
        LEFT JOIN industries    i  ON i.id  = d.industry_id
        LEFT JOIN powerbi_files pf ON pf.dashboard_id = d.id
        $where
        GROUP BY d.id, d.name, d.embed_url, d.description, d.is_active,
                 d.client_id, d.industry_id, d.created_at, c.name, i.name
        ORDER BY d.created_at DESC
    ");
    $stmt->execute($params);
    json_out(['success' => true, 'data' => $stmt->fetchAll()]);
}

if ($action === 'create_dashboard' && $method === 'POST') {
    require_admin();
    $client_id   = (int)($body['client_id']   ?? 0);
    $industry_id = (int)($body['industry_id'] ?? 0);
    $name        = sanitize($body['name']      ?? '');
    $embed_url   = trim($body['embed_url']     ?? '');
    $desc        = sanitize($body['description'] ?? '');
    $is_active   = isset($body['is_active']) ? (int)$body['is_active'] : 1;
    if (!$client_id || !$industry_id || empty($name) || empty($embed_url))
        json_out(['success' => false, 'message' => 'Client, Industry, Name and Embed URL are required'], 422);
    $pdo->prepare("INSERT INTO dashboards (client_id, industry_id, name, embed_url, description, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)")
        ->execute([$client_id, $industry_id, $name, $embed_url, $desc, $is_active, $_SESSION['user_id']]);
    json_out(['success' => true, 'id' => (int) $pdo->lastInsertId()]);
}

if ($action === 'update_dashboard' && $method === 'PUT') {
    require_admin();
    $id        = (int)($body['id'] ?? 0);
    $name      = sanitize($body['name']    ?? '');
    $embed_url = trim($body['embed_url']   ?? '');
    $desc      = sanitize($body['description'] ?? '');
    $is_active = isset($body['is_active']) ? (int)$body['is_active'] : 1;
    if (!$id || empty($name)) json_out(['success' => false, 'message' => 'ID and Name are required'], 422);
    $pdo->prepare("UPDATE dashboards SET name=?, embed_url=?, description=?, is_active=? WHERE id=?")->execute([$name, $embed_url, $desc, $is_active, $id]);
    json_out(['success' => true]);
}

if ($action === 'delete_dashboard' && $method === 'DELETE') {
    require_admin();
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_out(['success' => false, 'message' => 'ID is required'], 422);
    $files = $pdo->prepare("SELECT file_name FROM powerbi_files WHERE dashboard_id = ?");
    $files->execute([$id]);
    foreach ($files->fetchAll() as $f) { if (file_exists(PBIX_UPLOAD_DIR . $f['file_name'])) unlink(PBIX_UPLOAD_DIR . $f['file_name']); }
    $pdo->prepare("DELETE FROM dashboards WHERE id=?")->execute([$id]);
    json_out(['success' => true]);
}

if ($action === 'get_clients_by_industry' && $method === 'GET') {
    $industry_id = (int)($_GET['industry_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT id, name FROM clients WHERE industry_id=? ORDER BY name ASC");
    $stmt->execute([$industry_id]);
    json_out(['success' => true, 'data' => $stmt->fetchAll()]);
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
    $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
          . '://' . $_SERVER['HTTP_HOST']
          . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/') . '/';
    foreach ($files as &$file) {
        $file['download_url'] = $base . PBIX_WEB_PATH . rawurlencode($file['file_name']);
        $file['file_size_kb'] = $file['file_size'] > 0
            ? ($file['file_size'] >= 1048576
                ? round($file['file_size'] / 1048576, 1) . ' MB'
                : round($file['file_size'] / 1024, 1) . ' KB')
            : '—';
    }
    unset($file);
    json_out(['success' => true, 'data' => $files]);
}

if ($action === 'upload_pbix_file' && $method === 'POST') {
    require_admin();
    $dashboard_id = (int)($_POST['dashboard_id'] ?? 0);
    $display_name = sanitize($_POST['display_name'] ?? '');
    $description  = sanitize($_POST['description']  ?? '');
    if (!$dashboard_id) json_out(['success' => false, 'message' => 'dashboard_id is required'], 422);
    if (empty($display_name)) json_out(['success' => false, 'message' => 'Display name is required'], 422);
    if (!isset($_FILES['pbix_file']) || $_FILES['pbix_file']['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors = [1=>'File exceeds server limit.',2=>'File exceeds form limit.',3=>'Partial upload.',4=>'No file uploaded.',6=>'Missing temp folder.',7=>'Write failed.',8=>'Blocked by extension.'];
        $errCode = $_FILES['pbix_file']['error'] ?? -1;
        json_out(['success' => false, 'message' => $uploadErrors[$errCode] ?? 'Unknown upload error.'], 422);
    }
    $file     = $_FILES['pbix_file'];
    $origName = basename($file['name']);
    $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $fileSize = $file['size'];
    if (!in_array($ext, ['pbix', 'pbit'])) json_out(['success' => false, 'message' => 'Only .pbix and .pbit files are allowed.'], 422);
    if ($fileSize > PBIX_MAX_SIZE) json_out(['success' => false, 'message' => 'File too large. Maximum 200 MB.'], 422);
    $storedName = 'dash' . $dashboard_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destPath   = PBIX_UPLOAD_DIR . $storedName;
    if (!move_uploaded_file($file['tmp_name'], $destPath)) json_out(['success' => false, 'message' => 'Failed to move uploaded file.'], 500);
    $pdo->prepare("INSERT INTO powerbi_files (dashboard_id, display_name, file_name, original_name, file_size, description, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?)")
        ->execute([$dashboard_id, $display_name, $storedName, $origName, $fileSize, $description, $_SESSION['user_id']]);
    json_out(['success' => true, 'id' => (int) $pdo->lastInsertId(), 'file_name' => $storedName]);
}

if ($action === 'update_pbix_file' && $method === 'PUT') {
    require_admin();
    $id           = (int)($body['id'] ?? 0);
    $display_name = sanitize($body['display_name'] ?? '');
    $description  = sanitize($body['description']  ?? '');
    if (!$id || empty($display_name)) json_out(['success' => false, 'message' => 'ID and Display Name are required'], 422);
    $pdo->prepare("UPDATE powerbi_files SET display_name=?, description=? WHERE id=?")->execute([$display_name, $description, $id]);
    json_out(['success' => true]);
}

if ($action === 'delete_pbix_file' && $method === 'DELETE') {
    require_admin();
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_out(['success' => false, 'message' => 'ID is required'], 422);
    $stmt = $pdo->prepare("SELECT file_name FROM powerbi_files WHERE id=?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row) {
        if (file_exists(PBIX_UPLOAD_DIR . $row['file_name'])) unlink(PBIX_UPLOAD_DIR . $row['file_name']);
        $pdo->prepare("DELETE FROM powerbi_files WHERE id=?")->execute([$id]);
        json_out(['success' => true]);
    } else {
        json_out(['success' => false, 'message' => 'File record not found'], 404);
    }
}

if ($action === 'download_pbix' && $method === 'GET') {
    require_any_user();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'ID is required']); exit; }
    $stmt = $pdo->prepare("SELECT * FROM powerbi_files WHERE id=?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'File not found']); exit; }
    $filePath = PBIX_UPLOAD_DIR . $row['file_name'];
    if (!file_exists($filePath)) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'File not found on server']); exit; }
    $ext  = strtolower(pathinfo($row['file_name'], PATHINFO_EXTENSION));
    $mime = ($ext === 'pbit') ? 'application/vnd.ms-powerbi-template' : 'application/octet-stream';
    header('Content-Description: File Transfer');
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . addslashes($row['original_name']) . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Expires: 0');
    ob_end_clean();
    readfile($filePath);
    exit;
}

json_out(['success' => false, 'message' => 'Unknown action: ' . $action], 404);