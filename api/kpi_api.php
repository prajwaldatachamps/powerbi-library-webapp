<?php
/**
 * api/kpi_api.api — KPI REST API
 * Fixed: industry_id is fully optional for create_kpi
 * Added: bulk_add_tags endpoint for bulk tag assignment
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? trim($_GET['action']) : '';
$pdo    = getPDO();

$body = [];
if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
    $raw = file_get_contents('api://input');
    if ($raw !== false && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $body = $decoded;
        }
    }
    if ($method === 'POST' && !empty($_POST)) {
        $body = array_merge($body, $_POST);
    }
}

ensureKpiSchema($pdo);

function ensureKpiSchema(PDO $pdo): void
{
    try {
        $colInfo = $pdo->query("SHOW COLUMNS FROM kpi_details LIKE 'industry_id'")->fetch(PDO::FETCH_ASSOC);
        if ($colInfo) {
            $dbName = $pdo->query("SELECT DATABASE()")->fetchColumn();
            $fkRows = $pdo->query("
                SELECT CONSTRAINT_NAME
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA    = '{$dbName}'
                  AND TABLE_NAME      = 'kpi_details'
                  AND COLUMN_NAME     = 'industry_id'
                  AND REFERENCED_TABLE_NAME IS NOT NULL
            ")->fetchAll(PDO::FETCH_ASSOC);

            foreach ($fkRows as $fk) {
                $constraintName = $fk['CONSTRAINT_NAME'];
                $pdo->exec("ALTER TABLE kpi_details DROP FOREIGN KEY `{$constraintName}`");
            }

            if (strtolower($colInfo['Null']) === 'no') {
                $pdo->exec("ALTER TABLE kpi_details MODIFY COLUMN industry_id INT DEFAULT NULL");
            }
        }
    } catch (Exception $e) {
        // silently continue
    }

    $cols = $pdo->query("SHOW COLUMNS FROM kpi_details LIKE 'custom_description'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE kpi_details ADD COLUMN custom_description TEXT DEFAULT NULL AFTER description");
    }

    $colsSub = $pdo->query("SHOW COLUMNS FROM kpi_details LIKE 'submitted_by'")->fetchAll();
    if (empty($colsSub)) {
        $pdo->exec("
            ALTER TABLE kpi_details
            ADD COLUMN submitted_by INT DEFAULT NULL AFTER custom_description,
            ADD COLUMN approval_status ENUM('approved','pending','rejected') NOT NULL DEFAULT 'approved' AFTER submitted_by,
            ADD COLUMN rejection_reason TEXT DEFAULT NULL AFTER approval_status
        ");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS kpi_tags (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            kpi_id     INT UNSIGNED NOT NULL,
            tag_type   ENUM('industry','function','company_size') NOT NULL DEFAULT 'industry',
            tag_label  VARCHAR(200) NOT NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_kpi_tag (kpi_id, tag_type, tag_label),
            INDEX idx_kpi  (kpi_id),
            INDEX idx_type (tag_type),
            INDEX idx_sort (kpi_id, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function getExistingCols(PDO $pdo): array {
    $cols = [];
    foreach ($pdo->query("SHOW COLUMNS FROM kpi_details")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $cols[] = $c['Field'];
    }
    return $cols;
}

/* ── Reference Data ─────────────────────────────────── */

if ($action === 'get_industries' && $method === 'GET') {
    require_any_user();
    $stmt = $pdo->query("SELECT id, name, icon FROM industries WHERE approval_status='approved' ORDER BY name ASC");
    json_out(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($action === 'get_functions' && $method === 'GET') {
    require_any_user();
    $stmt = $pdo->query("SELECT id, name, icon, industry_id FROM functions WHERE approval_status='approved' ORDER BY name ASC");
    json_out(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($action === 'get_company_sizes' && $method === 'GET') {
    require_any_user();
    $stmt = $pdo->query("SELECT id, name, icon, function_id, industry_id FROM company_sizes WHERE approval_status='approved' ORDER BY name ASC");
    json_out(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

/* ── KPI Stats ──────────────────────────────────────── */
if ($action === 'get_kpi_stats' && $method === 'GET') {
    require_any_user();

    $existCols   = getExistingCols($pdo);
    $apprFilter  = in_array('approval_status', $existCols) ? " WHERE approval_status = 'approved'" : '';
    $apprAnd     = in_array('approval_status', $existCols) ? " AND approval_status = 'approved'"   : '';

    $total      = (int) $pdo->query("SELECT COUNT(*) FROM kpi_details{$apprFilter}")->fetchColumn();
    $industries = (int) $pdo->query("SELECT COUNT(DISTINCT industry_id) FROM kpi_details WHERE industry_id IS NOT NULL AND industry_id > 0{$apprAnd}")->fetchColumn();
    $units      = (int) $pdo->query("SELECT COUNT(DISTINCT unit) FROM kpi_details WHERE unit IS NOT NULL AND unit <> ''{$apprAnd}")->fetchColumn();

    json_out(['success' => true, 'total' => $total, 'industries' => $industries, 'units' => $units]);
}

/* ── Get KPIs ───────────────────────────────────────── */
if ($action === 'get_kpis' && $method === 'GET') {
    require_any_user();

    $industryId = isset($_GET['industry_id']) ? (int) $_GET['industry_id'] : 0;
    $searchRaw  = trim($_GET['search'] ?? '');
    $search     = '%' . $searchRaw . '%';
    $limit      = min((int) ($_GET['limit']  ?? 500), 1000);
    $offset     = max((int) ($_GET['offset'] ?? 0),   0);

    $existCols      = getExistingCols($pdo);
    $approvalClause = in_array('approval_status', $existCols) ? " AND k.approval_status = 'approved'" : '';

    $where  = "WHERE k.kpi_name LIKE :search" . $approvalClause;
    $params = [':search' => $search];

    if ($industryId > 0) {
        $where .= " AND k.industry_id = :ind";
        $params[':ind'] = $industryId;
    }

    $customDescSelect = in_array('custom_description', $existCols) ? ', k.custom_description' : ', NULL AS custom_description';

    $stmt = $pdo->prepare("
        SELECT
            k.id, k.industry_id, k.kpi_name, k.unit, k.formula, k.description
            {$customDescSelect},
            k.created_at,
            i.name AS industry_name,
            i.icon AS industry_icon
        FROM kpi_details k
        LEFT JOIN industries i ON i.id = k.industry_id
        {$where}
        ORDER BY k.kpi_name ASC
        LIMIT :lim OFFSET :off
    ");

    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    $stmt->bindValue(':lim', $limit,  PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $kpiList = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($kpiList)) {
        json_out(['success' => true, 'data' => []]);
    }

    $kpiIds = array_map('intval', array_column($kpiList, 'id'));
    $in     = implode(',', $kpiIds);

    $tagMap = [];
    if ($in !== '') {
        $tagRows = $pdo->query("
            SELECT id, kpi_id, tag_type, tag_label, sort_order
            FROM kpi_tags
            WHERE kpi_id IN ({$in})
            ORDER BY kpi_id ASC, sort_order ASC, id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($tagRows as $tag) {
            $tagMap[(int) $tag['kpi_id']][] = $tag;
        }
    }

    foreach ($kpiList as &$kpi) {
        $kpi['tags'] = $tagMap[(int) $kpi['id']] ?? [];
    }
    unset($kpi);

    json_out(['success' => true, 'data' => $kpiList]);
}

/* ── Create KPI ───────────────────────────────────────── */
if ($action === 'create_kpi' && $method === 'POST') {
    require_contributor_or_above();

    $industry_id_raw    = $body['industry_id'] ?? null;
    $industry_id        = ($industry_id_raw !== null && (int) $industry_id_raw > 0) ? (int) $industry_id_raw : null;

    $kpi_name           = sanitize($body['kpi_name']           ?? '');
    $unit               = sanitize($body['unit']               ?? '');
    $formula            = sanitize($body['formula']            ?? '');
    $description        = sanitize($body['description']        ?? '');
    $custom_description = trim    ($body['custom_description'] ?? '');

    if ($kpi_name === '') {
        json_out(['success' => false, 'message' => 'KPI Name is required.'], 422);
    }

    $existCols = getExistingCols($pdo);

    if ($industry_id !== null) {
        $dup = $pdo->prepare("SELECT id FROM kpi_details WHERE industry_id = ? AND kpi_name = ? LIMIT 1");
        $dup->execute([$industry_id, $kpi_name]);
        if ($dup->fetch()) {
            json_out(['success' => false, 'message' => 'A KPI with this name already exists for this industry.'], 422);
        }
    } else {
        $dup = $pdo->prepare("SELECT id FROM kpi_details WHERE kpi_name = ? AND (industry_id IS NULL OR industry_id = 0) LIMIT 1");
        $dup->execute([$kpi_name]);
        if ($dup->fetch()) {
            json_out(['success' => false, 'message' => 'A KPI with this name already exists.'], 422);
        }
    }

    $approval_status = submission_status();
    $submitted_by    = !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    $created_by      = $submitted_by;

    $insertCols   = [];
    $insertValues = [];

    if ($industry_id !== null) {
        $insertCols[]   = 'industry_id';
        $insertValues[] = $industry_id;
    }

    $insertCols[]   = 'kpi_name';
    $insertValues[] = $kpi_name;

    $insertCols[]   = 'unit';
    $insertValues[] = $unit ?: null;

    $insertCols[]   = 'formula';
    $insertValues[] = $formula ?: null;

    $insertCols[]   = 'description';
    $insertValues[] = $description ?: null;

    if (in_array('created_by', $existCols)) {
        $insertCols[]   = 'created_by';
        $insertValues[] = $created_by;
    }

    if (in_array('custom_description', $existCols)) {
        $insertCols[]   = 'custom_description';
        $insertValues[] = $custom_description ?: null;
    }
    if (in_array('submitted_by', $existCols)) {
        $insertCols[]   = 'submitted_by';
        $insertValues[] = $submitted_by;
    }
    if (in_array('approval_status', $existCols)) {
        $insertCols[]   = 'approval_status';
        $insertValues[] = $approval_status;
    }

    $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
    $colList      = implode(', ', $insertCols);

    try {
        $pdo->prepare("INSERT INTO kpi_details ({$colList}) VALUES ({$placeholders})")
            ->execute($insertValues);
    } catch (PDOException $e) {
        json_out(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
    }

    $newId = (int) $pdo->lastInsertId();

    if (in_array('approval_status', $existCols) && $approval_status === 'pending' && $submitted_by) {
        notify($pdo, $submitted_by, 'kpi', $newId, $kpi_name, 'submitted', 'Your KPI submission is awaiting approval.');

        $admins = $pdo->query("SELECT id FROM users WHERE role = 'super_admin' AND is_active = 1");
        foreach ($admins->fetchAll(PDO::FETCH_ASSOC) as $admin) {
            if ((int) $admin['id'] !== $submitted_by) {
                notify($pdo, (int) $admin['id'], 'kpi', $newId, $kpi_name, 'submitted', 'New KPI submission awaiting your review.');
            }
        }
    }

    json_out(['success' => true, 'id' => $newId, 'status' => $approval_status]);
}

/* ── Update KPI (super_admin only) ─────────────────── */
if ($action === 'update_kpi' && $method === 'PUT') {
    require_super_admin();

    $industry_id_raw    = $body['industry_id'] ?? null;
    $industry_id        = ($industry_id_raw !== null && (int) $industry_id_raw > 0) ? (int) $industry_id_raw : null;

    $id                 = (int)    ($body['id']                 ?? 0);
    $kpi_name           = sanitize ($body['kpi_name']           ?? '');
    $unit               = sanitize ($body['unit']               ?? '');
    $formula            = sanitize ($body['formula']            ?? '');
    $description        = sanitize ($body['description']        ?? '');
    $custom_description = trim     ($body['custom_description'] ?? '');

    if (!$id)             json_out(['success' => false, 'message' => 'ID is required.'],       422);
    if ($kpi_name === '') json_out(['success' => false, 'message' => 'KPI Name is required.'], 422);

    if ($industry_id !== null) {
        $dup = $pdo->prepare("SELECT id FROM kpi_details WHERE industry_id = ? AND kpi_name = ? AND id <> ? LIMIT 1");
        $dup->execute([$industry_id, $kpi_name, $id]);
        if ($dup->fetch()) {
            json_out(['success' => false, 'message' => 'A KPI with this name already exists for this industry.'], 422);
        }
    }

    $existCols = getExistingCols($pdo);

    $setClauses   = ['kpi_name = ?', 'unit = ?', 'formula = ?', 'description = ?'];
    $updateValues = [$kpi_name, $unit ?: null, $formula ?: null, $description ?: null];

    $setClauses[]   = 'industry_id = ?';
    $updateValues[] = $industry_id;

    if (in_array('custom_description', $existCols)) {
        $setClauses[]   = 'custom_description = ?';
        $updateValues[] = $custom_description ?: null;
    }
    if (in_array('approval_status', $existCols)) {
        $setClauses[] = "approval_status = 'approved'";
    }

    $updateValues[] = $id;
    $setStr = implode(', ', $setClauses);

    try {
        $pdo->prepare("UPDATE kpi_details SET {$setStr} WHERE id = ?")
            ->execute($updateValues);
    } catch (PDOException $e) {
        json_out(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
    }

    json_out(['success' => true]);
}

/* ── Delete KPI (super_admin only) ─────────────────── */
if ($action === 'delete_kpi' && $method === 'DELETE') {
    require_super_admin();

    $id = (int) ($body['id'] ?? 0);
    if (!$id) json_out(['success' => false, 'message' => 'ID is required.'], 422);

    $check = $pdo->prepare("SELECT id FROM kpi_details WHERE id = ? LIMIT 1");
    $check->execute([$id]);
    if (!$check->fetch()) {
        json_out(['success' => false, 'message' => 'KPI not found.'], 404);
    }

    $pdo->prepare("DELETE FROM kpi_tags    WHERE kpi_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM kpi_details WHERE id = ?")->execute([$id]);

    json_out(['success' => true]);
}

/* ── Save Custom Description ─────────────────────────── */
if ($action === 'save_custom_description' && $method === 'POST') {
    require_any_user();

    $kpi_id      = (int)  ($body['kpi_id']     ?? 0);
    $description = trim   ($body['description'] ?? '');

    if (!$kpi_id) json_out(['success' => false, 'message' => 'kpi_id is required.'], 422);

    $check = $pdo->prepare("SELECT id FROM kpi_details WHERE id = ? LIMIT 1");
    $check->execute([$kpi_id]);
    if (!$check->fetch()) {
        json_out(['success' => false, 'message' => 'KPI not found.'], 404);
    }

    $cols = $pdo->query("SHOW COLUMNS FROM kpi_details LIKE 'custom_description'")->fetchAll();
    if (!empty($cols)) {
        $pdo->prepare("UPDATE kpi_details SET custom_description = ? WHERE id = ?")
            ->execute([$description !== '' ? $description : null, $kpi_id]);
    }

    json_out(['success' => true]);
}

/* ── Add Tag (super_admin only) ─────────────────────── */
if ($action === 'add_tag' && $method === 'POST') {
    require_super_admin();

    $validTypes = ['industry', 'function', 'company_size'];

    $kpi_id    = (int)    ($body['kpi_id']    ?? 0);
    $tag_type  = trim     ($body['tag_type']  ?? '');
    $tag_label = sanitize ($body['tag_label'] ?? '');

    if (!$kpi_id)    json_out(['success' => false, 'message' => 'kpi_id is required.'],    422);
    if (!$tag_label) json_out(['success' => false, 'message' => 'Tag label is required.'], 422);

    if (!in_array($tag_type, $validTypes, true)) {
        json_out(['success' => false, 'message' => 'Invalid tag_type. Must be one of: ' . implode(', ', $validTypes)], 422);
    }

    $check = $pdo->prepare("SELECT id FROM kpi_details WHERE id = ? LIMIT 1");
    $check->execute([$kpi_id]);
    if (!$check->fetch()) {
        json_out(['success' => false, 'message' => 'KPI not found.'], 404);
    }

    $dup = $pdo->prepare("SELECT id FROM kpi_tags WHERE kpi_id = ? AND tag_type = ? AND tag_label = ? LIMIT 1");
    $dup->execute([$kpi_id, $tag_type, $tag_label]);
    if ($dup->fetch()) {
        json_out(['success' => false, 'message' => 'This tag already exists for this KPI.'], 422);
    }

    $maxStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) FROM kpi_tags WHERE kpi_id = ?");
    $maxStmt->execute([$kpi_id]);
    $nextOrder = (int) $maxStmt->fetchColumn() + 1;

    $pdo->prepare("INSERT INTO kpi_tags (kpi_id, tag_type, tag_label, sort_order) VALUES (?, ?, ?, ?)")
        ->execute([$kpi_id, $tag_type, $tag_label, $nextOrder]);

    json_out(['success' => true, 'id' => (int) $pdo->lastInsertId(), 'sort_order' => $nextOrder]);
}

/* ── Delete Tag (super_admin only) ─────────────────── */
if ($action === 'delete_tag' && $method === 'DELETE') {
    require_super_admin();

    $id = (int) ($body['id'] ?? 0);
    if (!$id) json_out(['success' => false, 'message' => 'Tag ID is required.'], 422);

    $check = $pdo->prepare("SELECT id FROM kpi_tags WHERE id = ? LIMIT 1");
    $check->execute([$id]);
    if (!$check->fetch()) {
        json_out(['success' => false, 'message' => 'Tag not found.'], 404);
    }

    $pdo->prepare("DELETE FROM kpi_tags WHERE id = ?")->execute([$id]);

    json_out(['success' => true]);
}

/* ── Reorder Tags (super_admin only) ────────────────── */
if ($action === 'reorder_tags' && $method === 'POST') {
    require_super_admin();

    $kpi_id = (int)   ($body['kpi_id'] ?? 0);
    $order  = $body['order'] ?? null;

    if (!$kpi_id)          json_out(['success' => false, 'message' => 'kpi_id is required.'],              422);
    if (!is_array($order)) json_out(['success' => false, 'message' => 'order must be an array of tag IDs.'], 422);

    if (!empty($order)) {
        $cleanIds = array_values(array_unique(array_map('intval', $order)));
        $in       = implode(',', $cleanIds);

        $ownedRows = $pdo->prepare("SELECT id FROM kpi_tags WHERE kpi_id = ? AND id IN ({$in})");
        $ownedRows->execute([$kpi_id]);
        $ownedIds = array_map('intval', array_column($ownedRows->fetchAll(PDO::FETCH_ASSOC), 'id'));

        if (!empty($ownedIds)) {
            $updateStmt = $pdo->prepare("UPDATE kpi_tags SET sort_order = ? WHERE id = ? AND kpi_id = ?");
            foreach ($cleanIds as $sortIndex => $tagId) {
                if (in_array($tagId, $ownedIds, true)) {
                    $updateStmt->execute([$sortIndex, $tagId, $kpi_id]);
                }
            }
        }
    }

    json_out(['success' => true]);
}

/* ── Get Tags for a single KPI ──────────────────────── */
if ($action === 'get_tags' && $method === 'GET') {
    require_any_user();

    $kpi_id = (int) ($_GET['kpi_id'] ?? 0);
    if (!$kpi_id) json_out(['success' => false, 'message' => 'kpi_id is required.'], 422);

    $stmt = $pdo->prepare("
        SELECT id, kpi_id, tag_type, tag_label, sort_order, created_at
        FROM kpi_tags WHERE kpi_id = ?
        ORDER BY sort_order ASC, id ASC
    ");
    $stmt->execute([$kpi_id]);

    json_out(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

/* ══════════════════════════════════════════════════════
   ── Bulk Add Tags (super_admin only) ─────────────────
   Assigns one tag (type + label) to multiple KPI IDs.
   Skips KPIs that already have the same tag (no error).
   Returns counts of assigned vs skipped.
   ══════════════════════════════════════════════════════ */
if ($action === 'bulk_add_tags' && $method === 'POST') {
    require_super_admin();

    $validTypes = ['industry', 'function', 'company_size'];

    $kpi_ids   = $body['kpi_ids']   ?? [];
    $tag_type  = trim($body['tag_type']  ?? '');
    $tag_label = sanitize($body['tag_label'] ?? '');

    if (!is_array($kpi_ids) || empty($kpi_ids)) {
        json_out(['success' => false, 'message' => 'kpi_ids must be a non-empty array.'], 422);
    }
    if (!$tag_label) {
        json_out(['success' => false, 'message' => 'Tag label is required.'], 422);
    }
    if (!in_array($tag_type, $validTypes, true)) {
        json_out(['success' => false, 'message' => 'Invalid tag_type.'], 422);
    }

    // Sanitise and validate IDs
    $cleanIds = array_values(array_unique(array_map('intval', $kpi_ids)));
    $cleanIds = array_filter($cleanIds, fn($id) => $id > 0);
    if (empty($cleanIds)) {
        json_out(['success' => false, 'message' => 'No valid KPI IDs provided.'], 422);
    }

    // Verify KPIs actually exist
    $in          = implode(',', $cleanIds);
    $existingKpis = $pdo->query("SELECT id FROM kpi_details WHERE id IN ({$in})")
                        ->fetchAll(PDO::FETCH_COLUMN);
    $existingKpis = array_map('intval', $existingKpis);

    if (empty($existingKpis)) {
        json_out(['success' => false, 'message' => 'None of the provided KPI IDs exist.'], 422);
    }

    // Find which ones already have this tag
    $inExisting = implode(',', $existingKpis);
    $alreadyTagged = $pdo->prepare("
        SELECT kpi_id FROM kpi_tags
        WHERE kpi_id IN ({$inExisting})
          AND tag_type  = ?
          AND tag_label = ?
    ");
    $alreadyTagged->execute([$tag_type, $tag_label]);
    $alreadyIds = array_map('intval', array_column($alreadyTagged->fetchAll(PDO::FETCH_ASSOC), 'kpi_id'));

    // Insert for the ones that don't have the tag yet
    $toInsert = array_diff($existingKpis, $alreadyIds);

    $inserted = 0;
    if (!empty($toInsert)) {
        // Get the next sort_order for each kpi
        $insertStmt = $pdo->prepare("
            INSERT INTO kpi_tags (kpi_id, tag_type, tag_label, sort_order)
            VALUES (?, ?, ?, (SELECT COALESCE(MAX(sort_order), -1) + 1 FROM kpi_tags t2 WHERE t2.kpi_id = ?))
        ");

        foreach ($toInsert as $kpiId) {
            try {
                $insertStmt->execute([$kpiId, $tag_type, $tag_label, $kpiId]);
                $inserted++;
            } catch (PDOException $e) {
                // Duplicate — skip silently
            }
        }
    }

    $skipped = count($alreadyIds);

    json_out([
        'success'  => true,
        'assigned' => $inserted,
        'skipped'  => $skipped,
        'message'  => $inserted > 0
            ? "Tag assigned to {$inserted} KPI" . ($inserted > 1 ? 's' : '') . ($skipped > 0 ? ", {$skipped} already had it" : '')
            : "All selected KPIs already have this tag"
    ]);
}

/* ── Fallback ────────────────────────────────────────── */
json_out(['success' => false, 'message' => 'Unknown action: ' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8')], 404);
