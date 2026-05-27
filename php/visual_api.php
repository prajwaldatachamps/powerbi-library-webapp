<?php
// php/visual_api.php

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

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? trim($_GET['action']) : '';
$pdo    = getPDO();

define('VISUAL_UPLOAD_DIR', __DIR__ . '/../uploads/visuals/');
define('VISUAL_MAX_SIZE',   10 * 1024 * 1024);
define('VISUAL_WEB_PATH',   'uploads/visuals/');

if (!is_dir(VISUAL_UPLOAD_DIR)) mkdir(VISUAL_UPLOAD_DIR, 0755, true);

$body = [];
if (in_array($method, ['PUT','DELETE','POST'])) {
    $raw = file_get_contents('php://input');
    if ($raw) { $decoded = json_decode($raw, true); if (is_array($decoded)) $body = $decoded; }
    if (!empty($_POST)) $body = array_merge($body, $_POST);
}

// ============================================================
//  GET INDUSTRIES
// ============================================================
if ($action === 'get_industries' && $method === 'GET') {
    try {
        $stmt = $pdo->query("SELECT id, name, icon FROM industries ORDER BY name ASC");
        json_out(['success' => true, 'data' => $stmt->fetchAll()]);
    } catch (Exception $e) {
        json_out(['success' => false, 'data' => [], 'message' => $e->getMessage()]);
    }
}

// ============================================================
//  GET CLIENTS (for usage dropdown)
// ============================================================
if ($action === 'get_clients' && $method === 'GET') {
    try {
        $stmt = $pdo->query("SELECT id, name FROM clients ORDER BY name ASC");
        json_out(['success' => true, 'data' => $stmt->fetchAll()]);
    } catch (Exception $e) {
        json_out(['success' => false, 'data' => [], 'message' => $e->getMessage()]);
    }
}

// ============================================================
//  GET DASHBOARDS BY CLIENT (for usage dropdown)
// ============================================================
if ($action === 'get_dashboards_by_client' && $method === 'GET') {
    try {
        $client_id = (int)($_GET['client_id'] ?? 0);
        if (!$client_id) json_out(['success' => true, 'data' => []]);
        $stmt = $pdo->prepare("SELECT id, name FROM dashboards WHERE client_id = ? ORDER BY name ASC");
        $stmt->execute([$client_id]);
        json_out(['success' => true, 'data' => $stmt->fetchAll()]);
    } catch (Exception $e) {
        json_out(['success' => false, 'data' => [], 'message' => $e->getMessage()]);
    }
}

// ============================================================
//  GET VISUAL STATS
// ============================================================
if ($action === 'get_visual_stats' && $method === 'GET') {
    try {
        $total       = (int) $pdo->query("SELECT COUNT(*) FROM visual_details")->fetchColumn();
        $kpis        = (int) $pdo->query("SELECT COUNT(DISTINCT kpi_name) FROM visual_details WHERE kpi_name != '' AND kpi_name IS NOT NULL")->fetchColumn();
        $custom      = (int) $pdo->query("SELECT COUNT(*) FROM visual_details WHERE visual_type='custom'")->fetchColumn();
        $marketplace = (int) $pdo->query("SELECT COUNT(*) FROM visual_details WHERE visual_type='marketplace'")->fetchColumn();
        json_out(['success' => true, 'total' => $total, 'kpis' => $kpis, 'custom' => $custom, 'marketplace' => $marketplace]);
    } catch (Exception $e) {
        json_out(['success' => true, 'total' => 0, 'kpis' => 0, 'custom' => 0, 'marketplace' => 0]);
    }
}

// ============================================================
//  GET VISUALS (flat single grid)
//  NOTE: kpi_id column — NULL means custom KPI name, integer means linked to kpi_details.id
// ============================================================
if ($action === 'get_visuals' && $method === 'GET') {
    try {
        $industry_id = (int)($_GET['industry_id'] ?? 0);
        $visual_type = trim($_GET['visual_type']  ?? '');
        $search      = '%' . trim($_GET['search'] ?? '') . '%';

        $where  = "WHERE (v.visual_name LIKE ? OR v.kpi_name LIKE ?)";
        $params = [$search, $search];
        if ($industry_id > 0) { $where .= " AND v.industry_id = ?"; $params[] = $industry_id; }
        if (in_array($visual_type, ['custom','marketplace'])) { $where .= " AND v.visual_type = ?"; $params[] = $visual_type; }

        $stmt = $pdo->prepare("
            SELECT v.id, v.visual_name, v.visual_type, v.kpi_name, v.kpi_id,
                   v.image_path, v.description, v.industry_id, v.created_at,
                   i.name AS industry_name, i.icon AS industry_icon,
                   COUNT(vu.id) AS usage_count
            FROM visual_details v
            LEFT JOIN industries i ON i.id = v.industry_id
            LEFT JOIN visual_usage vu ON vu.visual_id = v.id
            $where
            GROUP BY v.id, v.visual_name, v.visual_type, v.kpi_name, v.kpi_id,
                     v.image_path, v.description, v.industry_id, v.created_at,
                     i.name, i.icon
            ORDER BY i.name ASC, v.kpi_name ASC, v.visual_name ASC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
              . '://' . $_SERVER['HTTP_HOST']
              . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/') . '/';

        foreach ($rows as &$row) {
            $row['image_url']   = $row['image_path'] ? $base . VISUAL_WEB_PATH . rawurlencode($row['image_path']) : null;
            $row['usage_count'] = (int) $row['usage_count'];
            $row['kpi_id']      = $row['kpi_id'] ? (int) $row['kpi_id'] : null;
        }
        unset($row);

        json_out(['success' => true, 'data' => $rows]);
    } catch (Exception $e) {
        json_out(['success' => false, 'data' => [], 'message' => $e->getMessage()]);
    }
}

// ============================================================
//  GET USAGE FOR A VISUAL
// ============================================================
if ($action === 'get_visual_usage' && $method === 'GET') {
    try {
        $visual_id = (int)($_GET['visual_id'] ?? 0);
        if (!$visual_id) json_out(['success' => false, 'message' => 'visual_id required'], 422);
        $stmt = $pdo->prepare("
            SELECT vu.id, vu.notes, vu.created_at,
                   c.id AS client_id, c.name AS client_name,
                   d.id AS dashboard_id, d.name AS dashboard_name
            FROM visual_usage vu
            LEFT JOIN clients    c ON c.id = vu.client_id
            LEFT JOIN dashboards d ON d.id = vu.dashboard_id
            WHERE vu.visual_id = ?
            ORDER BY c.name ASC, d.name ASC
        ");
        $stmt->execute([$visual_id]);
        json_out(['success' => true, 'data' => $stmt->fetchAll()]);
    } catch (Exception $e) {
        json_out(['success' => false, 'data' => [], 'message' => $e->getMessage()]);
    }
}

// ============================================================
//  ADD USAGE (admin only)
// ============================================================
if ($action === 'add_visual_usage' && $method === 'POST') {
    require_admin();
    try {
        $visual_id    = (int)($body['visual_id']    ?? 0);
        $client_id    = (int)($body['client_id']    ?? 0);
        $dashboard_id = (int)($body['dashboard_id'] ?? 0);
        $notes        = sanitize($body['notes']     ?? '');
        if (!$visual_id) json_out(['success' => false, 'message' => 'visual_id required'], 422);
        if (!$client_id) json_out(['success' => false, 'message' => 'Client is required'], 422);
        $created_by = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $pdo->prepare("
            INSERT INTO visual_usage (visual_id, client_id, dashboard_id, notes, created_by)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$visual_id, $client_id, $dashboard_id ?: null, $notes ?: null, $created_by]);
        json_out(['success' => true, 'id' => (int) $pdo->lastInsertId()]);
    } catch (Exception $e) {
        json_out(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
    }
}

// ============================================================
//  DELETE USAGE (admin only)
// ============================================================
if ($action === 'delete_visual_usage' && $method === 'DELETE') {
    require_admin();
    try {
        $id = (int)($body['id'] ?? 0);
        if (!$id) json_out(['success' => false, 'message' => 'ID required'], 422);
        $pdo->prepare("DELETE FROM visual_usage WHERE id = ?")->execute([$id]);
        json_out(['success' => true]);
    } catch (Exception $e) {
        json_out(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
    }
}

// ============================================================
//  CREATE VISUAL (admin only)
//  Accepts kpi_id (int|null) and kpi_name (string).
//  If kpi_id is provided, kpi_name is derived from the linked KPI row.
// ============================================================
if ($action === 'create_visual' && $method === 'POST') {
    require_admin();
    try {
        $industry_id = (int)($_POST['industry_id'] ?? 0);
        $kpi_id_raw  = trim($_POST['kpi_id']       ?? '');
        $kpi_id      = ($kpi_id_raw !== '' && is_numeric($kpi_id_raw)) ? (int)$kpi_id_raw : null;
        $visual_type = in_array($_POST['visual_type'] ?? '', ['custom','marketplace']) ? $_POST['visual_type'] : 'custom';
        $visual_name = sanitize($_POST['visual_name'] ?? '');
        $description = sanitize($_POST['description'] ?? '');

        if (!$industry_id) json_out(['success' => false, 'message' => 'Industry is required'], 422);
        if (!$visual_name) json_out(['success' => false, 'message' => 'Visual name is required'], 422);

        // Resolve kpi_name
        if ($kpi_id) {
            // Linked mode — get kpi_name from kpi_details
            $kStmt = $pdo->prepare("SELECT kpi_name FROM kpi_details WHERE id = ?");
            $kStmt->execute([$kpi_id]);
            $kRow = $kStmt->fetch();
            if (!$kRow) json_out(['success' => false, 'message' => 'Linked KPI not found. It may have been deleted.'], 422);
            $kpi_name = $kRow['kpi_name'];
        } else {
            // Custom mode — take from POST
            $kpi_name = sanitize($_POST['kpi_name'] ?? '');
            if (empty($kpi_name)) json_out(['success' => false, 'message' => 'KPI name is required'], 422);
            $kpi_id = null;
        }

        // Handle image upload
        $image_path = null;
        if (isset($_FILES['visual_image']) && $_FILES['visual_image']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['visual_image'];
            $ext  = strtolower(pathinfo(basename($file['name']), PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','gif','webp','svg']))
                json_out(['success' => false, 'message' => 'Only JPG, PNG, GIF, WEBP, SVG allowed.'], 422);
            if ($file['size'] > VISUAL_MAX_SIZE)
                json_out(['success' => false, 'message' => 'Image too large. Max 10 MB.'], 422);
            $storedName = 'vis_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (!move_uploaded_file($file['tmp_name'], VISUAL_UPLOAD_DIR . $storedName))
                json_out(['success' => false, 'message' => 'Failed to upload image.'], 500);
            $image_path = $storedName;
        }

        $created_by = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $pdo->prepare("
            INSERT INTO visual_details (industry_id, kpi_name, kpi_id, visual_name, visual_type, image_path, description, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([$industry_id, $kpi_name, $kpi_id, $visual_name, $visual_type, $image_path, $description ?: null, $created_by]);

        json_out(['success' => true, 'id' => (int) $pdo->lastInsertId()]);
    } catch (Exception $e) {
        json_out(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
    }
}

// ============================================================
//  UPDATE VISUAL (admin only)
// ============================================================
if ($action === 'update_visual' && $method === 'POST') {
    require_admin();
    try {
        $id          = (int)($_POST['id']          ?? 0);
        $industry_id = (int)($_POST['industry_id'] ?? 0);
        $kpi_id_raw  = trim($_POST['kpi_id']       ?? '');
        $kpi_id      = ($kpi_id_raw !== '' && is_numeric($kpi_id_raw)) ? (int)$kpi_id_raw : null;
        $visual_type = in_array($_POST['visual_type'] ?? '', ['custom','marketplace']) ? $_POST['visual_type'] : 'custom';
        $visual_name = sanitize($_POST['visual_name'] ?? '');
        $description = sanitize($_POST['description'] ?? '');

        if (!$id)          json_out(['success' => false, 'message' => 'ID is required'], 422);
        if (!$industry_id) json_out(['success' => false, 'message' => 'Industry is required'], 422);
        if (!$visual_name) json_out(['success' => false, 'message' => 'Visual name is required'], 422);

        // Resolve kpi_name
        if ($kpi_id) {
            $kStmt = $pdo->prepare("SELECT kpi_name FROM kpi_details WHERE id = ?");
            $kStmt->execute([$kpi_id]);
            $kRow = $kStmt->fetch();
            if (!$kRow) json_out(['success' => false, 'message' => 'Linked KPI not found. It may have been deleted.'], 422);
            $kpi_name = $kRow['kpi_name'];
        } else {
            $kpi_name = sanitize($_POST['kpi_name'] ?? '');
            if (empty($kpi_name)) json_out(['success' => false, 'message' => 'KPI name is required'], 422);
            $kpi_id = null;
        }

        // Retrieve current image path
        $curr = $pdo->prepare("SELECT image_path FROM visual_details WHERE id = ?");
        $curr->execute([$id]);
        $current    = $curr->fetch();
        $image_path = $current ? $current['image_path'] : null;

        // Handle new image upload
        if (isset($_FILES['visual_image']) && $_FILES['visual_image']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['visual_image'];
            $ext  = strtolower(pathinfo(basename($file['name']), PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','gif','webp','svg']))
                json_out(['success' => false, 'message' => 'Only JPG, PNG, GIF, WEBP, SVG allowed.'], 422);
            if ($file['size'] > VISUAL_MAX_SIZE)
                json_out(['success' => false, 'message' => 'Image too large. Max 10 MB.'], 422);
            $storedName = 'vis_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (!move_uploaded_file($file['tmp_name'], VISUAL_UPLOAD_DIR . $storedName))
                json_out(['success' => false, 'message' => 'Failed to upload image.'], 500);
            // Delete old image file
            if ($image_path && file_exists(VISUAL_UPLOAD_DIR . $image_path)) unlink(VISUAL_UPLOAD_DIR . $image_path);
            $image_path = $storedName;
        }

        // Handle image removal request
        if (!empty($_POST['remove_image']) && $_POST['remove_image'] === '1') {
            if ($image_path && file_exists(VISUAL_UPLOAD_DIR . $image_path)) unlink(VISUAL_UPLOAD_DIR . $image_path);
            $image_path = null;
        }

        $pdo->prepare("
            UPDATE visual_details
            SET industry_id = ?, kpi_name = ?, kpi_id = ?, visual_name = ?, visual_type = ?, image_path = ?, description = ?
            WHERE id = ?
        ")->execute([$industry_id, $kpi_name, $kpi_id, $visual_name, $visual_type, $image_path, $description ?: null, $id]);

        json_out(['success' => true]);
    } catch (Exception $e) {
        json_out(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
    }
}

// ============================================================
//  DELETE VISUAL (admin only)
// ============================================================
if ($action === 'delete_visual' && $method === 'DELETE') {
    require_admin();
    try {
        $id = (int)($body['id'] ?? 0);
        if (!$id) json_out(['success' => false, 'message' => 'ID is required'], 422);
        $stmt = $pdo->prepare("SELECT image_path FROM visual_details WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row && $row['image_path'] && file_exists(VISUAL_UPLOAD_DIR . $row['image_path']))
            unlink(VISUAL_UPLOAD_DIR . $row['image_path']);
        $pdo->prepare("DELETE FROM visual_details WHERE id = ?")->execute([$id]);
        json_out(['success' => true]);
    } catch (Exception $e) {
        json_out(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
    }
}

json_out(['success' => false, 'message' => 'Unknown action: ' . $action], 404);