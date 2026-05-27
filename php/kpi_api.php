<?php
// php/kpi_api.php

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
//  GET ALL INDUSTRIES (for tabs + dropdown) — no auth needed
// ============================================================
if ($action === 'get_industries' && $method === 'GET') {
    try {
        $stmt = $pdo->query("SELECT id, name, icon FROM industries ORDER BY name ASC");
        json_out(['success' => true, 'data' => $stmt->fetchAll()]);
    } catch (Exception $e) {
        json_out(['success' => false, 'message' => 'DB error: ' . $e->getMessage(), 'data' => []]);
    }
}

// ============================================================
//  GET KPIs — no auth needed (read-only view)
// ============================================================
if ($action === 'get_kpis' && $method === 'GET') {
    try {
        $industry_id = (int)($_GET['industry_id'] ?? 0);
        $search      = '%' . trim($_GET['search'] ?? '') . '%';

        $where  = "WHERE k.kpi_name LIKE ?";
        $params = [$search];

        if ($industry_id > 0) {
            $where .= " AND k.industry_id = ?";
            $params[] = $industry_id;
        }

        // NOTE: No JOIN to users table — avoids FK issues entirely
        $stmt = $pdo->prepare("
            SELECT k.id, k.kpi_name, k.formula, k.unit, k.description,
                   k.industry_id, k.created_at, k.updated_at,
                   i.name AS industry_name,
                   i.icon AS industry_icon
            FROM kpi_details k
            LEFT JOIN industries i ON i.id = k.industry_id
            $where
            ORDER BY i.name ASC, k.kpi_name ASC
        ");
        $stmt->execute($params);
        json_out(['success' => true, 'data' => $stmt->fetchAll()]);
    } catch (Exception $e) {
        json_out(['success' => false, 'message' => 'DB error: ' . $e->getMessage(), 'data' => []]);
    }
}

// ============================================================
//  GET KPI STATS — no auth needed
// ============================================================
if ($action === 'get_kpi_stats' && $method === 'GET') {
    try {
        $total      = (int) $pdo->query("SELECT COUNT(*) FROM kpi_details")->fetchColumn();
        $industries = (int) $pdo->query("SELECT COUNT(DISTINCT industry_id) FROM kpi_details")->fetchColumn();
        $units      = (int) $pdo->query("SELECT COUNT(DISTINCT unit) FROM kpi_details WHERE unit IS NOT NULL AND unit != ''")->fetchColumn();
        json_out(['success' => true, 'total' => $total, 'industries' => $industries, 'units' => $units]);
    } catch (Exception $e) {
        json_out(['success' => true, 'total' => 0, 'industries' => 0, 'units' => 0]);
    }
}

// ============================================================
//  CREATE KPI (admin only)
// ============================================================
if ($action === 'create_kpi' && $method === 'POST') {
    require_admin();
    try {
        $industry_id = (int)($body['industry_id'] ?? 0);
        $kpi_name    = sanitize($body['kpi_name']    ?? '');
        $formula     = sanitize($body['formula']     ?? '');
        $unit        = sanitize($body['unit']        ?? '');
        $description = sanitize($body['description'] ?? '');

        if (!$industry_id) json_out(['success' => false, 'message' => 'Industry is required'], 422);
        if (empty($kpi_name)) json_out(['success' => false, 'message' => 'KPI Name is required'], 422);

        // Use NULL for created_by if user_id not in session
        $created_by = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

        $pdo->prepare("
            INSERT INTO kpi_details (industry_id, kpi_name, formula, unit, description, created_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([$industry_id, $kpi_name, $formula ?: null, $unit ?: null, $description ?: null, $created_by]);

        json_out(['success' => true, 'id' => (int) $pdo->lastInsertId()]);
    } catch (Exception $e) {
        json_out(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
    }
}

// ============================================================
//  UPDATE KPI (admin only)
// ============================================================
if ($action === 'update_kpi' && $method === 'PUT') {
    require_admin();
    try {
        $id          = (int)($body['id']          ?? 0);
        $industry_id = (int)($body['industry_id'] ?? 0);
        $kpi_name    = sanitize($body['kpi_name']    ?? '');
        $formula     = sanitize($body['formula']     ?? '');
        $unit        = sanitize($body['unit']        ?? '');
        $description = sanitize($body['description'] ?? '');

        if (!$id)          json_out(['success' => false, 'message' => 'ID is required'], 422);
        if (!$industry_id) json_out(['success' => false, 'message' => 'Industry is required'], 422);
        if (empty($kpi_name)) json_out(['success' => false, 'message' => 'KPI Name is required'], 422);

        $pdo->prepare("
            UPDATE kpi_details
            SET industry_id = ?, kpi_name = ?, formula = ?, unit = ?, description = ?
            WHERE id = ?
        ")->execute([$industry_id, $kpi_name, $formula ?: null, $unit ?: null, $description ?: null, $id]);

        json_out(['success' => true]);
    } catch (Exception $e) {
        json_out(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
    }
}

// ============================================================
//  DELETE KPI (admin only)
// ============================================================
if ($action === 'delete_kpi' && $method === 'DELETE') {
    require_admin();
    try {
        $id = (int)($body['id'] ?? 0);
        if (!$id) json_out(['success' => false, 'message' => 'ID is required'], 422);
        $pdo->prepare("DELETE FROM kpi_details WHERE id = ?")->execute([$id]);
        json_out(['success' => true]);
    } catch (Exception $e) {
        json_out(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
    }
}

// Fallback
json_out(['success' => false, 'message' => 'Unknown action: ' . $action], 404);