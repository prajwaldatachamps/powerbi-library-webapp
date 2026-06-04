<?php
// api/wireframe_api.api

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
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'get_wireframes':     get_wireframes();     break;
    case 'create_wireframe':   create_wireframe();   break;
    case 'update_wireframe':   update_wireframe();   break;
    case 'update_status':      update_status();      break;
    case 'delete_wireframe':   delete_wireframe();   break;
    case 'download_wireframe': download_wireframe(); break;
    default: json_out(['success' => false, 'message' => 'Unknown action'], 400);
}

function upload_dir(): string {
    $dir = __DIR__ . '/../uploads/wireframes/';
    if (!is_dir($dir)) { mkdir($dir, 0755, true); }
    return $dir;
}

function allowed_status(string $status): string {
    $allowed = ['pending', 'in_review', 'approved', 'rejected'];
    return in_array($status, $allowed, true) ? $status : 'pending';
}

function current_user_id(): ?int {
    return !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

function is_owner(array $row): bool {
    return current_user_id() && !empty($row['uploaded_by']) && (int)$row['uploaded_by'] === current_user_id();
}

function notify_super_admins(PDO $pdo, int $itemId, string $itemName): void {
    try {
        $stmt = $pdo->query("SELECT id FROM users WHERE role = 'super_admin'");
        $admins = $stmt->fetchAll();
        foreach ($admins as $admin) {
            notify($pdo, (int)$admin['id'], 'wireframe', $itemId, $itemName, 'submitted', 'New wireframe submitted for review.');
        }
    } catch (Exception $e) { /* notification is non-fatal */ }
}

function get_wireframes(): void {
    require_any_user();
    $pdo = getPDO();

    $where = '';
    $params = [];

    if (!is_super_admin()) {
        if (is_contributor()) {
            $where = "WHERE w.status = 'approved' OR w.uploaded_by = :uid";
            $params[':uid'] = current_user_id();
        } else {
            $where = "WHERE w.status = 'approved'";
        }
    }

    $sql = "
        SELECT
            w.id, w.name, w.dashboard_name, w.industry_id,
            i.name AS industry_name,
            w.function_id, f.name AS function_name,
            w.status, w.description, w.tags, w.file_type, w.file_name,
            w.original_name, w.file_ext, w.file_size, w.figma_link,
            w.remarks, w.uploaded_by,
            u.name AS uploaded_by_name,
            w.created_at, w.updated_at
        FROM wireframes w
        LEFT JOIN industries i ON i.id = w.industry_id
        LEFT JOIN functions  f ON f.id = w.function_id
        LEFT JOIN users      u ON u.id = w.uploaded_by
        $where
        ORDER BY w.created_at DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        if (!empty($row['file_size'])) {
            $sz = (int)$row['file_size'];
            $row['file_size_display'] = $sz >= 1048576
                ? round($sz / 1048576, 1) . ' MB'
                : round($sz / 1024) . ' KB';
        } else {
            $row['file_size_display'] = '';
        }
        $row['id']          = (int)$row['id'];
        $row['industry_id'] = (int)$row['industry_id'];
        $row['function_id'] = $row['function_id'] ? (int)$row['function_id'] : null;
        $row['uploaded_by'] = $row['uploaded_by'] ? (int)$row['uploaded_by'] : null;
    }
    unset($row);

    json_out(['success' => true, 'data' => $rows]);
}

function create_wireframe(): void {
    require_contributor_or_above();
    $pdo = getPDO();

    $name          = trim($_POST['name'] ?? '');
    $dashboardName = trim($_POST['dashboard_name'] ?? '');
    $industryId    = (int)($_POST['industry_id'] ?? 0);
    $functionId    = (int)($_POST['function_id'] ?? 0) ?: null;
    $status        = is_super_admin() ? allowed_status(trim($_POST['status'] ?? 'approved')) : 'pending';
    $description   = trim($_POST['description'] ?? '');
    $tags          = trim($_POST['tags'] ?? '');
    $fileType      = trim($_POST['file_type'] ?? '');
    $figmaLink     = trim($_POST['figma_link'] ?? '');
    $uploadedBy    = current_user_id();

    if (!$name)          json_out(['success' => false, 'message' => 'Name is required'], 422);
    if (!$dashboardName) json_out(['success' => false, 'message' => 'Dashboard name is required'], 422);
    if (!$industryId)    json_out(['success' => false, 'message' => 'Industry is required'], 422);
    if (!$fileType)      json_out(['success' => false, 'message' => 'File type is required'], 422);

    $fileName = $originalName = $fileExt = null;
    $fileSize = null;

    if ($fileType === 'fig') {
        if (!$figmaLink) json_out(['success' => false, 'message' => 'Figma link is required'], 422);
        if (!filter_var($figmaLink, FILTER_VALIDATE_URL)) json_out(['success' => false, 'message' => 'Invalid Figma URL'], 422);
    } elseif (!empty($_FILES['wireframe_file']['name'])) {
        $uploadResult = handle_file_upload();
        if (!$uploadResult['success']) json_out(['success' => false, 'message' => $uploadResult['message']], 422);
        $fileName     = $uploadResult['file_name'];
        $originalName = $uploadResult['original_name'];
        $fileExt      = $uploadResult['file_ext'];
        $fileSize     = $uploadResult['file_size'];
    } else {
        json_out(['success' => false, 'message' => 'A file or Figma link is required'], 422);
    }

    $stmt = $pdo->prepare("
        INSERT INTO wireframes
            (name, dashboard_name, industry_id, function_id, status, description, tags,
             file_type, file_name, original_name, file_ext, file_size, figma_link,
             uploaded_by, created_at, updated_at)
        VALUES
            (:name, :dashboard_name, :industry_id, :function_id, :status, :description, :tags,
             :file_type, :file_name, :original_name, :file_ext, :file_size, :figma_link,
             :uploaded_by, NOW(), NOW())
    ");
    $stmt->execute([
        ':name'           => sanitize($name),
        ':dashboard_name' => sanitize($dashboardName),
        ':industry_id'    => $industryId,
        ':function_id'    => $functionId,
        ':status'         => $status,
        ':description'    => sanitize($description),
        ':tags'           => sanitize($tags),
        ':file_type'      => sanitize($fileType),
        ':file_name'      => $fileName,
        ':original_name'  => $originalName ? sanitize($originalName) : null,
        ':file_ext'       => $fileExt,
        ':file_size'      => $fileSize,
        ':figma_link'     => $figmaLink ?: null,
        ':uploaded_by'    => $uploadedBy,
    ]);

    $newId = (int)$pdo->lastInsertId();
    if (!is_super_admin()) notify_super_admins($pdo, $newId, $dashboardName ?: $name);

    json_out(['success' => true, 'message' => 'Wireframe created', 'id' => $newId]);
}

function update_wireframe(): void {
    require_any_user();
    $pdo = getPDO();

    $id = (int)($_POST['id'] ?? 0);
    if (!$id) json_out(['success' => false, 'message' => 'Invalid ID'], 422);

    $existing = $pdo->prepare("SELECT * FROM wireframes WHERE id = :id");
    $existing->execute([':id' => $id]);
    $row = $existing->fetch();
    if (!$row) json_out(['success' => false, 'message' => 'Wireframe not found'], 404);

    if (!is_super_admin() && !(is_contributor() && is_owner($row) && $row['status'] === 'pending')) {
        json_out(['success' => false, 'message' => 'Unauthorized — Super-admin access required'], 401);
    }

    $name          = trim($_POST['name'] ?? '');
    $dashboardName = trim($_POST['dashboard_name'] ?? '');
    $industryId    = (int)($_POST['industry_id'] ?? 0);
    $functionId    = (int)($_POST['function_id'] ?? 0) ?: null;
    $status        = is_super_admin() ? allowed_status(trim($_POST['status'] ?? $row['status'])) : 'pending';
    $description   = trim($_POST['description'] ?? '');
    $tags          = trim($_POST['tags'] ?? '');
    $fileType      = trim($_POST['file_type'] ?? $row['file_type']);
    $figmaLink     = trim($_POST['figma_link'] ?? '');

    if (!$name)       json_out(['success' => false, 'message' => 'Name is required'], 422);
    if (!$industryId) json_out(['success' => false, 'message' => 'Industry is required'], 422);

    $fileName     = $row['file_name'];
    $originalName = $row['original_name'];
    $fileExt      = $row['file_ext'];
    $fileSize     = $row['file_size'];
    $storedFigma  = $row['figma_link'];

    if ($fileType === 'fig') {
        if ($figmaLink) {
            if (!filter_var($figmaLink, FILTER_VALIDATE_URL)) json_out(['success' => false, 'message' => 'Invalid Figma URL'], 422);
            $storedFigma = $figmaLink;
            if ($fileName && file_exists(upload_dir() . $fileName)) unlink(upload_dir() . $fileName);
            $fileName = $originalName = $fileExt = null;
            $fileSize = null;
        } elseif (!$storedFigma) {
            json_out(['success' => false, 'message' => 'Figma link is required'], 422);
        }
    } elseif (!empty($_FILES['wireframe_file']['name'])) {
        $uploadResult = handle_file_upload();
        if (!$uploadResult['success']) json_out(['success' => false, 'message' => $uploadResult['message']], 422);
        if ($fileName && file_exists(upload_dir() . $fileName)) unlink(upload_dir() . $fileName);
        $fileName     = $uploadResult['file_name'];
        $originalName = $uploadResult['original_name'];
        $fileExt      = $uploadResult['file_ext'];
        $fileSize     = $uploadResult['file_size'];
        $storedFigma  = null;
    }

    $stmt = $pdo->prepare("
        UPDATE wireframes SET
            name = :name,
            dashboard_name = :dashboard_name,
            industry_id = :industry_id,
            function_id = :function_id,
            status = :status,
            description = :description,
            tags = :tags,
            file_type = :file_type,
            file_name = :file_name,
            original_name = :original_name,
            file_ext = :file_ext,
            file_size = :file_size,
            figma_link = :figma_link,
            updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        ':name'           => sanitize($name),
        ':dashboard_name' => sanitize($dashboardName),
        ':industry_id'    => $industryId,
        ':function_id'    => $functionId,
        ':status'         => $status,
        ':description'    => sanitize($description),
        ':tags'           => sanitize($tags),
        ':file_type'      => sanitize($fileType),
        ':file_name'      => $fileName,
        ':original_name'  => $originalName ? sanitize($originalName) : null,
        ':file_ext'       => $fileExt,
        ':file_size'      => $fileSize,
        ':figma_link'     => $storedFigma ?: null,
        ':id'             => $id,
    ]);

    json_out(['success' => true, 'message' => 'Wireframe updated']);
}

function update_status(): void {
    require_super_admin();
    $pdo  = getPDO();
    $body = json_decode(file_get_contents('api://input'), true) ?? [];

    $id      = (int)($body['id'] ?? 0);
    $status  = allowed_status(trim($body['status'] ?? ''));
    $remarks = trim($body['remarks'] ?? '');

    if (!$id) json_out(['success' => false, 'message' => 'Invalid ID'], 422);

    $old = $pdo->prepare("SELECT dashboard_name, name, uploaded_by FROM wireframes WHERE id = :id");
    $old->execute([':id' => $id]);
    $row = $old->fetch();
    if (!$row) json_out(['success' => false, 'message' => 'Wireframe not found'], 404);

    $stmt = $pdo->prepare("UPDATE wireframes SET status = :status, remarks = :remarks, updated_at = NOW() WHERE id = :id");
    $stmt->execute([':status' => $status, ':remarks' => sanitize($remarks), ':id' => $id]);

    if (!empty($row['uploaded_by'])) {
        $message = $remarks ?: ('Wireframe status changed to ' . $status . '.');
        notify($pdo, (int)$row['uploaded_by'], 'wireframe', $id, $row['dashboard_name'] ?: $row['name'], $status === 'approved' ? 'approved' : ($status === 'rejected' ? 'rejected' : 'submitted'), $message);
    }

    json_out(['success' => true, 'message' => 'Status updated']);
}

function delete_wireframe(): void {
    require_super_admin();
    $pdo  = getPDO();
    $body = json_decode(file_get_contents('api://input'), true) ?? [];
    $id   = (int)($body['id'] ?? 0);

    if (!$id) json_out(['success' => false, 'message' => 'Invalid ID'], 422);

    $stmt = $pdo->prepare("SELECT file_name FROM wireframes WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) json_out(['success' => false, 'message' => 'Wireframe not found'], 404);

    if (!empty($row['file_name'])) {
        $path = upload_dir() . $row['file_name'];
        if (file_exists($path)) unlink($path);
    }

    $pdo->prepare("DELETE FROM wireframes WHERE id = :id")->execute([':id' => $id]);
    json_out(['success' => true, 'message' => 'Wireframe deleted']);
}

function download_wireframe(): void {
    require_any_user();
    $pdo = getPDO();
    $id  = (int)($_GET['id'] ?? 0);

    if (!$id) { http_response_code(400); exit('Invalid ID'); }

    $stmt = $pdo->prepare("SELECT file_name, original_name, file_type, status, uploaded_by FROM wireframes WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    if (!$row) { http_response_code(404); exit('Not found'); }
    if (!is_super_admin() && $row['status'] !== 'approved' && !is_owner($row)) { http_response_code(401); exit('Unauthorized'); }
    if ($row['file_type'] === 'fig' || empty($row['file_name'])) { http_response_code(400); exit('No downloadable file for this wireframe'); }

    $path = upload_dir() . $row['file_name'];
    if (!file_exists($path)) { http_response_code(404); exit('File not found on disk'); }

    $mime = mime_content_type($path) ?: 'application/octet-stream';
    $downloadName = $row['original_name'] ?: basename($path);

    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . addslashes($downloadName) . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, no-cache');
    readfile($path);
    exit;
}

function handle_file_upload(): array {
    $file = $_FILES['wireframe_file'] ?? null;
    $maxBytes = 100 * 1024 * 1024;

    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        $errMap = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds form size limit',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION  => 'Upload blocked by server extension',
        ];
        return ['success' => false, 'message' => $errMap[$file['error'] ?? -1] ?? 'Upload error'];
    }

    if ($file['size'] > $maxBytes) return ['success' => false, 'message' => 'File too large (max 100 MB)'];

    $originalName = basename($file['name']);
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    $allowed = ['xlsx','xls','xltx','xltm','pptx','ppt','pptm','potx','pdf','png','jpg','jpeg','gif','svg','docx','doc','zip','rar','7z'];
    if (!in_array($ext, $allowed, true)) return ['success' => false, 'message' => 'File type not allowed: .' . $ext];

    $newName = uniqid('wf_', true) . '.' . $ext;
    $destPath = upload_dir() . $newName;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) return ['success' => false, 'message' => 'Failed to save file'];

    return [
        'success'       => true,
        'file_name'     => $newName,
        'original_name' => $originalName,
        'file_ext'      => '.' . $ext,
        'file_size'     => (int)$file['size'],
    ];
}
