<?php
// api/visual_api.api
// UPDATED: add_visual_usage now accepts plain-text client_name / dashboard_name
//          (no foreign-key lookup required — text stored directly in visual_usage)

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => "api Error [$errno]: $errstr in $errfile on line $errline"]);
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
    $raw = file_get_contents('api://input');
    if ($raw) { $decoded = json_decode($raw, true); if (is_array($decoded)) $body = $decoded; }
    if (!empty($_POST)) $body = array_merge($body, $_POST);
}

// ============================================================
//  HELPER: get columns of visual_details (cached per request)
// ============================================================
function getVisualDetailsCols(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    foreach ($pdo->query("SHOW COLUMNS FROM visual_details")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $cache[] = $c['Field'];
    }
    return $cache;
}

// ============================================================
//  HELPER: ensure required columns exist in visual_details
// ============================================================
function ensureVisualDetailsCols(PDO $pdo): void {
    $cols = getVisualDetailsCols($pdo);
    $needed = [
        'function_name'   => "ALTER TABLE visual_details ADD COLUMN function_name   VARCHAR(255) DEFAULT NULL AFTER kpi_name",
        'company_size'    => "ALTER TABLE visual_details ADD COLUMN company_size     VARCHAR(255) DEFAULT NULL AFTER function_name",
        'submitted_by'    => "ALTER TABLE visual_details ADD COLUMN submitted_by     INT          DEFAULT NULL AFTER company_size",
        'approval_status' => "ALTER TABLE visual_details ADD COLUMN approval_status  VARCHAR(20)  NOT NULL DEFAULT 'approved' AFTER submitted_by",
    ];
    foreach ($needed as $col => $ddl) {
        if (!in_array($col, $cols)) {
            try { $pdo->exec($ddl); } catch (Exception $e) { /* concurrent request */ }
        }
    }
}

// ============================================================
//  HELPER: ensure visual_usage has text columns
//  Migrates from FK-based (client_id, dashboard_id) to
//  plain-text (client_name, dashboard_name).
//  Both old and new columns can coexist safely.
// ============================================================
function ensureVisualUsageTextCols(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $cols = [];
    foreach ($pdo->query("SHOW COLUMNS FROM visual_usage")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $cols[] = $c['Field'];
    }

    if (!in_array('client_name', $cols)) {
        try {
            $pdo->exec("ALTER TABLE visual_usage ADD COLUMN client_name VARCHAR(255) DEFAULT NULL AFTER visual_id");
        } catch (Exception $e) {}
    }

    if (!in_array('dashboard_name', $cols)) {
        try {
            $pdo->exec("ALTER TABLE visual_usage ADD COLUMN dashboard_name VARCHAR(255) DEFAULT NULL AFTER client_name");
        } catch (Exception $e) {}
    }

    // IMPORTANT: old FK columns may still exist in your table.
    // Make them nullable so text-based usage entries do not fail FK checks.
    if (in_array('client_id', $cols)) {
        try {
            $pdo->exec("ALTER TABLE visual_usage MODIFY client_id INT(10) UNSIGNED NULL DEFAULT NULL");
        } catch (Exception $e) {}
    }

    if (in_array('dashboard_id', $cols)) {
        try {
            $pdo->exec("ALTER TABLE visual_usage MODIFY dashboard_id INT(10) UNSIGNED NULL DEFAULT NULL");
        } catch (Exception $e) {}
    }
}

// ============================================================
//  GET INDUSTRIES (public)
// ============================================================
if ($action === 'get_industries' && $method === 'GET') {
    try {
        $stmt = $pdo->query(
            "SELECT id, name, icon FROM industries
             WHERE approval_status = 'approved'
             ORDER BY name ASC"
        );
        json_out(['success' => true, 'data' => $stmt->fetchAll()]);
    } catch (Exception $e) {
        json_out(['success' => false, 'data' => [], 'message' => $e->getMessage()]);
    }
}

// ============================================================
//  GET FUNCTION NAMES
// ============================================================
if ($action === 'get_function_names' && $method === 'GET') {
    try {
        $stmt = $pdo->query(
            "SELECT DISTINCT name FROM functions
             WHERE approval_status = 'approved'
               AND name IS NOT NULL AND TRIM(name) <> ''
             ORDER BY name ASC"
        );
        json_out(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_COLUMN, 0)]);
    } catch (Exception $e) {
        json_out(['success' => false, 'data' => [], 'message' => $e->getMessage()]);
    }
}

// ============================================================
//  GET COMPANY SIZE NAMES
// ============================================================
if ($action === 'get_company_size_names' && $method === 'GET') {
    try {
        $stmt = $pdo->query(
            "SELECT DISTINCT name FROM company_sizes
             WHERE approval_status = 'approved'
               AND name IS NOT NULL AND TRIM(name) <> ''
             ORDER BY name ASC"
        );
        json_out(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_COLUMN, 0)]);
    } catch (Exception $e) {
        json_out(['success' => false, 'data' => [], 'message' => $e->getMessage()]);
    }
}

// ============================================================
//  GET VISUAL STATS (public)
// ============================================================
if ($action === 'get_visual_stats' && $method === 'GET') {
    try {
        $cols        = getVisualDetailsCols($pdo);
        $apprFilter  = in_array('approval_status', $cols) ? " AND approval_status = 'approved'" : '';
        $total       = (int)$pdo->query("SELECT COUNT(*) FROM visual_details WHERE 1=1$apprFilter")->fetchColumn();
        $kpis        = (int)$pdo->query("SELECT COUNT(DISTINCT kpi_name) FROM visual_details WHERE kpi_name != '' AND kpi_name IS NOT NULL$apprFilter")->fetchColumn();
        $custom      = (int)$pdo->query("SELECT COUNT(*) FROM visual_details WHERE visual_type = 'custom'$apprFilter")->fetchColumn();
        $marketplace = (int)$pdo->query("SELECT COUNT(*) FROM visual_details WHERE visual_type = 'marketplace'$apprFilter")->fetchColumn();
        json_out(['success' => true, 'total' => $total, 'kpis' => $kpis, 'custom' => $custom, 'marketplace' => $marketplace]);
    } catch (Exception $e) {
        json_out(['success' => true, 'total' => 0, 'kpis' => 0, 'custom' => 0, 'marketplace' => 0]);
    }
}

// ============================================================
//  GET VISUALS (public — approved only)
// ============================================================
if ($action === 'get_visuals' && $method === 'GET') {
    try {
        ensureVisualDetailsCols($pdo);

        $industry_id = (int)($_GET['industry_id'] ?? 0);
        $visual_type = trim($_GET['visual_type']  ?? '');
        $search      = '%' . trim($_GET['search'] ?? '') . '%';

        $existingCols = [];
        foreach ($pdo->query("SHOW COLUMNS FROM visual_details")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $existingCols[] = $c['Field'];
        }

        $selectFn   = in_array('function_name',  $existingCols) ? "COALESCE(v.function_name, '') AS function_name" : "'' AS function_name";
        $selectSize = in_array('company_size',   $existingCols) ? "COALESCE(v.company_size, '')  AS company_size"  : "'' AS company_size";
        $selectSub  = in_array('submitted_by',   $existingCols) ? 'v.submitted_by'               : 'NULL AS submitted_by';
        $selectAppr = in_array('approval_status',$existingCols) ? 'v.approval_status'             : "'approved' AS approval_status";

        $approvalFilter = in_array('approval_status', $existingCols) ? " AND v.approval_status = 'approved'" : '';

        $where  = "WHERE (v.visual_name LIKE ? OR v.kpi_name LIKE ?)$approvalFilter";
        $params = [$search, $search];
        if ($industry_id > 0)                                { $where .= ' AND v.industry_id = ?'; $params[] = $industry_id; }
        if (in_array($visual_type, ['custom','marketplace'])){ $where .= ' AND v.visual_type = ?'; $params[] = $visual_type; }

        $groupByCols = 'v.id, v.visual_name, v.visual_type, v.kpi_name, v.kpi_id, v.image_path, v.description, v.industry_id, v.created_at, i.name, i.icon';
        if (in_array('function_name',   $existingCols)) $groupByCols .= ', v.function_name';
        if (in_array('company_size',    $existingCols)) $groupByCols .= ', v.company_size';
        if (in_array('submitted_by',    $existingCols)) $groupByCols .= ', v.submitted_by';
        if (in_array('approval_status', $existingCols)) $groupByCols .= ', v.approval_status';

        $stmt = $pdo->prepare("
            SELECT
                v.id, v.visual_name, v.visual_type, v.kpi_name, v.kpi_id,
                v.image_path, v.description, v.industry_id, v.created_at,
                $selectFn, $selectSize, $selectSub, $selectAppr,
                i.name AS industry_name, i.icon AS industry_icon,
                COUNT(vu.id) AS usage_count
            FROM visual_details v
            LEFT JOIN industries   i  ON i.id        = v.industry_id
            LEFT JOIN visual_usage vu ON vu.visual_id = v.id
            $where
            GROUP BY $groupByCols
            ORDER BY i.name ASC, v.kpi_name ASC, v.visual_name ASC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
              . '://' . $_SERVER['HTTP_HOST']
              . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/') . '/';

        foreach ($rows as &$row) {
            $row['image_url']     = $row['image_path'] ? $base . VISUAL_WEB_PATH . rawurlencode($row['image_path']) : null;
            $row['usage_count']   = (int)$row['usage_count'];
            $row['kpi_id']        = ($row['kpi_id'] !== null && $row['kpi_id'] !== '') ? (int)$row['kpi_id'] : null;
            $row['function_name'] = (string)($row['function_name'] ?? '');
            $row['company_size']  = (string)($row['company_size']  ?? '');
        }
        unset($row);

        json_out(['success' => true, 'data' => $rows]);
    } catch (Exception $e) {
        json_out(['success' => false, 'data' => [], 'message' => $e->getMessage()]);
    }
}

// ============================================================
//  GET USAGE FOR A VISUAL (any logged-in user)
//  Returns client_name / dashboard_name as plain text.
//  Falls back to joined table names for legacy rows that
//  still have client_id / dashboard_id FKs.
// ============================================================
if ($action === 'get_visual_usage' && $method === 'GET') {
    require_any_user();
    try {
        ensureVisualUsageTextCols($pdo);

        $visual_id = (int)($_GET['visual_id'] ?? 0);
        if (!$visual_id) json_out(['success' => false, 'message' => 'visual_id required'], 422);

        // Detect which columns exist so query adapts to both old and new schema
        $vuCols = [];
        foreach ($pdo->query("SHOW COLUMNS FROM visual_usage")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $vuCols[] = $c['Field'];
        }

        $hasClientName    = in_array('client_name',    $vuCols);
        $hasDashboardName = in_array('dashboard_name', $vuCols);
        $hasClientId      = in_array('client_id',      $vuCols);
        $hasDashboardId   = in_array('dashboard_id',   $vuCols);

        // Build SELECT expressions that work regardless of schema version
        // Priority: new text columns → fall back to FK-joined names
        if ($hasClientName && $hasClientId) {
            $clientNameExpr = "COALESCE(NULLIF(TRIM(vu.client_name),''), c.name, '') AS client_name";
        } elseif ($hasClientName) {
            $clientNameExpr = "COALESCE(vu.client_name, '') AS client_name";
        } elseif ($hasClientId) {
            $clientNameExpr = "COALESCE(c.name, '') AS client_name";
        } else {
            $clientNameExpr = "'' AS client_name";
        }

        if ($hasDashboardName && $hasDashboardId) {
            $dashNameExpr = "COALESCE(NULLIF(TRIM(vu.dashboard_name),''), d.name, '') AS dashboard_name";
        } elseif ($hasDashboardName) {
            $dashNameExpr = "COALESCE(vu.dashboard_name, '') AS dashboard_name";
        } elseif ($hasDashboardId) {
            $dashNameExpr = "COALESCE(d.name, '') AS dashboard_name";
        } else {
            $dashNameExpr = "'' AS dashboard_name";
        }

        $leftJoins = '';
        if ($hasClientId)   $leftJoins .= " LEFT JOIN clients    c ON c.id = vu.client_id";
        if ($hasDashboardId) $leftJoins .= " LEFT JOIN dashboards d ON d.id = vu.dashboard_id";

        $stmt = $pdo->prepare("
            SELECT vu.id, vu.notes, vu.created_at,
                   $clientNameExpr,
                   $dashNameExpr
            FROM visual_usage vu
            $leftJoins
            WHERE vu.visual_id = ?
            ORDER BY client_name ASC, dashboard_name ASC
        ");
        $stmt->execute([$visual_id]);
        json_out(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        json_out(['success' => false, 'data' => [], 'message' => $e->getMessage()]);
    }
}

// ============================================================
//  ADD USAGE (super_admin only)
//  Accepts plain-text client_name and dashboard_name.
//  Does NOT require FK ids — text is stored directly.
// ============================================================
if ($action === 'add_visual_usage' && $method === 'POST') {
    require_super_admin();
    try {
        ensureVisualUsageTextCols($pdo);

        $visual_id     = (int)($body['visual_id']      ?? 0);
        $client_name   = trim($body['client_name']     ?? '');
        $dashboard_name= trim($body['dashboard_name']  ?? '');
        $notes         = trim($body['notes']           ?? '');

        if (!$visual_id)   json_out(['success' => false, 'message' => 'visual_id is required'],   422);
        if (!$client_name) json_out(['success' => false, 'message' => 'Client name is required'], 422);

        $created_by = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

        // Detect available columns for INSERT
        $vuCols = [];
        foreach ($pdo->query("SHOW COLUMNS FROM visual_usage")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $vuCols[] = $c['Field'];
        }

        $insertCols   = ['visual_id'];
        $insertValues = [$visual_id];

        if (in_array('client_name', $vuCols)) {
            $insertCols[]   = 'client_name';
            $insertValues[] = $client_name;
        }
        if (in_array('dashboard_name', $vuCols)) {
            $insertCols[]   = 'dashboard_name';
            $insertValues[] = $dashboard_name ?: null;
        }

        // Keep old FK columns NULL. This prevents FK error 1452 when using plain text.
        if (in_array('client_id', $vuCols)) {
            $insertCols[]   = 'client_id';
            $insertValues[] = null;
        }
        if (in_array('dashboard_id', $vuCols)) {
            $insertCols[]   = 'dashboard_id';
            $insertValues[] = null;
        }

        if (in_array('notes', $vuCols)) {
            $insertCols[]   = 'notes';
            $insertValues[] = $notes ?: null;
        }
        if (in_array('created_by', $vuCols)) {
            $insertCols[]   = 'created_by';
            $insertValues[] = $created_by;
        }

        $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
        $colList      = implode(', ', $insertCols);

        $pdo->prepare("INSERT INTO visual_usage ($colList) VALUES ($placeholders)")
            ->execute($insertValues);

        json_out(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
    } catch (Exception $e) {
        json_out(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
    }
}

// ============================================================
//  DELETE USAGE (super_admin only)
// ============================================================
if ($action === 'delete_visual_usage' && $method === 'DELETE') {
    require_super_admin();
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
//  CREATE VISUAL
// ============================================================
if ($action === 'create_visual' && $method === 'POST') {
    require_contributor_or_above();
    try {
        ensureVisualDetailsCols($pdo);

        $industry_id   = (int)($_POST['industry_id']   ?? 0);
        $kpi_id_raw    = trim($_POST['kpi_id']         ?? '');
        $kpi_id        = ($kpi_id_raw !== '' && is_numeric($kpi_id_raw)) ? (int)$kpi_id_raw : null;
        $visual_type   = in_array($_POST['visual_type'] ?? '', ['custom','marketplace']) ? $_POST['visual_type'] : 'custom';
        $visual_name   = sanitize($_POST['visual_name']   ?? '');
        $description   = sanitize($_POST['description']   ?? '');
        $function_name = sanitize($_POST['function_name'] ?? '');
        $company_size  = sanitize($_POST['company_size']  ?? '');

        if (!$industry_id) json_out(['success' => false, 'message' => 'Industry is required'], 422);
        if (!$visual_name) json_out(['success' => false, 'message' => 'Visual name is required'], 422);

        if ($kpi_id) {
            $kStmt = $pdo->prepare("SELECT kpi_name FROM kpi_details WHERE id = ?");
            $kStmt->execute([$kpi_id]);
            $kRow = $kStmt->fetch();
            if (!$kRow) json_out(['success' => false, 'message' => 'Linked KPI not found.'], 422);
            $kpi_name = $kRow['kpi_name'];
        } else {
            $kpi_name = sanitize($_POST['kpi_name'] ?? '');
            if (empty($kpi_name)) json_out(['success' => false, 'message' => 'KPI name is required'], 422);
            $kpi_id = null;
        }

        $approval_status = submission_status();

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

        $created_by   = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $submitted_by = $created_by;

        $existCols = [];
        foreach ($pdo->query("SHOW COLUMNS FROM visual_details")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $existCols[] = $c['Field'];
        }

        $insertCols   = ['industry_id','kpi_name','kpi_id','visual_name','visual_type','image_path','description','created_by'];
        $insertValues = [$industry_id, $kpi_name, $kpi_id, $visual_name, $visual_type, $image_path, $description ?: null, $created_by];

        if (in_array('function_name',   $existCols)) { $insertCols[] = 'function_name';   $insertValues[] = $function_name ?: null; }
        if (in_array('company_size',    $existCols)) { $insertCols[] = 'company_size';    $insertValues[] = $company_size  ?: null; }
        if (in_array('submitted_by',    $existCols)) { $insertCols[] = 'submitted_by';    $insertValues[] = $submitted_by; }
        if (in_array('approval_status', $existCols)) { $insertCols[] = 'approval_status'; $insertValues[] = $approval_status; }

        $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
        $colList      = implode(', ', $insertCols);
        $pdo->prepare("INSERT INTO visual_details ($colList) VALUES ($placeholders)")->execute($insertValues);
        $newId = (int)$pdo->lastInsertId();

        if (in_array('approval_status', $existCols) && $approval_status === 'pending') {
            notify($pdo, $submitted_by, 'visual', $newId, $visual_name, 'submitted', 'Your visual submission is awaiting approval.');
            $admins = $pdo->query("SELECT id FROM users WHERE role='super_admin' AND is_active=1");
            foreach ($admins->fetchAll() as $a) {
                if ($a['id'] !== $submitted_by)
                    notify($pdo, $a['id'], 'visual', $newId, $visual_name, 'submitted', 'New visual submission awaiting your review.');
            }
        }

        json_out(['success' => true, 'id' => $newId, 'status' => $approval_status]);
    } catch (Exception $e) {
        json_out(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
    }
}

// ============================================================
//  UPDATE VISUAL (super_admin only)
// ============================================================
if ($action === 'update_visual' && $method === 'POST') {
    require_super_admin();
    try {
        ensureVisualDetailsCols($pdo);

        $id            = (int)($_POST['id']            ?? 0);
        $industry_id   = (int)($_POST['industry_id']   ?? 0);
        $kpi_id_raw    = trim($_POST['kpi_id']         ?? '');
        $kpi_id        = ($kpi_id_raw !== '' && is_numeric($kpi_id_raw)) ? (int)$kpi_id_raw : null;
        $visual_type   = in_array($_POST['visual_type'] ?? '', ['custom','marketplace']) ? $_POST['visual_type'] : 'custom';
        $visual_name   = sanitize($_POST['visual_name']   ?? '');
        $description   = sanitize($_POST['description']   ?? '');
        $function_name = sanitize($_POST['function_name'] ?? '');
        $company_size  = sanitize($_POST['company_size']  ?? '');

        if (!$id)          json_out(['success' => false, 'message' => 'ID is required'], 422);
        if (!$industry_id) json_out(['success' => false, 'message' => 'Industry is required'], 422);
        if (!$visual_name) json_out(['success' => false, 'message' => 'Visual name is required'], 422);

        if ($kpi_id) {
            $kStmt = $pdo->prepare("SELECT kpi_name FROM kpi_details WHERE id = ?");
            $kStmt->execute([$kpi_id]);
            $kRow = $kStmt->fetch();
            if (!$kRow) json_out(['success' => false, 'message' => 'Linked KPI not found.'], 422);
            $kpi_name = $kRow['kpi_name'];
        } else {
            $kpi_name = sanitize($_POST['kpi_name'] ?? '');
            if (empty($kpi_name)) json_out(['success' => false, 'message' => 'KPI name is required'], 422);
            $kpi_id = null;
        }

        $curr = $pdo->prepare("SELECT image_path FROM visual_details WHERE id = ?");
        $curr->execute([$id]);
        $current    = $curr->fetch();
        $image_path = $current ? $current['image_path'] : null;

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
            if ($image_path && file_exists(VISUAL_UPLOAD_DIR . $image_path)) unlink(VISUAL_UPLOAD_DIR . $image_path);
            $image_path = $storedName;
        }

        if (!empty($_POST['remove_image']) && $_POST['remove_image'] === '1') {
            if ($image_path && file_exists(VISUAL_UPLOAD_DIR . $image_path)) unlink(VISUAL_UPLOAD_DIR . $image_path);
            $image_path = null;
        }

        $updExistCols = [];
        foreach ($pdo->query("SHOW COLUMNS FROM visual_details")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $updExistCols[] = $c['Field'];
        }

        $setClauses = ['industry_id = ?','kpi_name = ?','kpi_id = ?','visual_name = ?','visual_type = ?','image_path = ?','description = ?'];
        $updValues  = [$industry_id, $kpi_name, $kpi_id, $visual_name, $visual_type, $image_path, $description ?: null];

        if (in_array('function_name',   $updExistCols)) { $setClauses[] = 'function_name = ?';          $updValues[] = $function_name ?: null; }
        if (in_array('company_size',    $updExistCols)) { $setClauses[] = 'company_size = ?';           $updValues[] = $company_size  ?: null; }
        if (in_array('approval_status', $updExistCols)) { $setClauses[] = "approval_status = 'approved'"; }

        $updValues[] = $id;
        $pdo->prepare("UPDATE visual_details SET " . implode(', ', $setClauses) . " WHERE id = ?")->execute($updValues);

        json_out(['success' => true]);
    } catch (Exception $e) {
        json_out(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
    }
}

// ============================================================
//  DELETE VISUAL (super_admin only)
// ============================================================
if ($action === 'delete_visual' && $method === 'DELETE') {
    require_super_admin();
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
