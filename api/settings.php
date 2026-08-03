<?php

declare(strict_types=1);

/**
 * AdsDash — api/settings.php
 *
 * Handles:
 *   GET  /api/settings.php                          — fetch all settings groups
 *   GET  /api/settings.php?group=company            — fetch one group
 *   POST /api/settings.php                          — save settings key-value pairs (JSON body)
 *   POST /api/settings.php?action=upload_template   — upload quotation/invoice PDF template (multipart)
 *   GET  /api/settings.php?action=get_template&type=quotation|invoice — get active template info
 *   POST /api/settings.php?action=reset_template&type=quotation|invoice — reset to built-in default
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/auth.php';

requireAuth();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? null;

// ── CORS helper (all settings endpoints return JSON except binary downloads) ──
header('Content-Type: application/json; charset=utf-8');

try {
    // ── Template-specific actions ─────────────────────────────────────────────
    if ($action === 'upload_template') {
        if ($method !== 'POST') {
            sendErrorResponse('Method Not Allowed', 405);
        }
        requireRole(['owner', 'manager']);
        handleUploadTemplate($pdo);
        exit;
    }

    if ($action === 'get_template') {
        if ($method !== 'GET') {
            sendErrorResponse('Method Not Allowed', 405);
        }
        handleGetTemplate($pdo);
        exit;
    }

    if ($action === 'reset_template') {
        if ($method !== 'POST') {
            sendErrorResponse('Method Not Allowed', 405);
        }
        requireRole(['owner', 'manager']);
        handleResetTemplate($pdo);
        exit;
    }

    // ── General settings CRUD ─────────────────────────────────────────────────
    switch ($method) {
        case 'GET':
            handleGetSettings($pdo);
            break;

        case 'POST':
        case 'PUT':
            requireRole(['owner', 'manager']);
            handleSaveSettings($pdo);
            break;

        default:
            sendErrorResponse('Method Not Allowed', 405);
    }

} catch (Throwable $e) {
    sendErrorResponse('Internal Server Error: ' . $e->getMessage(), 500);
}

/* ─────────────────────────────────────────────────────────────────────────────
   SETTINGS TABLE HELPERS
   Expects a `settings` table:
     CREATE TABLE IF NOT EXISTS settings (
       id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
       `group`    VARCHAR(50)   NOT NULL,
       `key`      VARCHAR(100)  NOT NULL,
       `value`    TEXT          NULL,
       created_at TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
       updated_at TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
       UNIQUE KEY uq_group_key (`group`, `key`)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
   ───────────────────────────────────────────────────────────────────────────── */

/**
 * GET /api/settings.php              — all groups
 * GET /api/settings.php?group=company — one group
 */
function handleGetSettings(PDO $pdo): void
{
    // Ensure settings table exists (auto-bootstrap)
    ensureSettingsTable($pdo);

    $group = isset($_GET['group']) ? trim((string) $_GET['group']) : null;

    if ($group !== null && $group !== '') {
        $stmt = $pdo->prepare('SELECT `key`, `value` FROM settings WHERE `group` = :g ORDER BY `key`');
        $stmt->bindValue(':g', $group, PDO::PARAM_STR);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        sendSuccessResponse('Settings fetched.', ['group' => $group, 'settings' => $rows]);
    }

    $stmt = $pdo->query('SELECT `group`, `key`, `value` FROM settings ORDER BY `group`, `key`');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $grouped = [];
    foreach ($rows as $row) {
        $grouped[$row['group']][$row['key']] = $row['value'];
    }

    sendSuccessResponse('All settings fetched.', $grouped);
}

/**
 * POST /api/settings.php
 * Body: { "group": "company", "settings": { "company_name": "...", "gstin": "..." } }
 * Or flat: { "company_name": "...", "gstin": "..." } with group passed as ?group=company
 */
function handleSaveSettings(PDO $pdo): void
{
    ensureSettingsTable($pdo);

    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (!is_array($input)) {
        sendErrorResponse('Invalid JSON payload.', 400);
    }

    $group    = isset($input['group']) ? trim((string) $input['group']) : (isset($_GET['group']) ? trim((string) $_GET['group']) : '');
    $settings = isset($input['settings']) && is_array($input['settings']) ? $input['settings'] : null;

    // Allow flat format: { "group": "company", "key1": "val1", ... }
    if ($settings === null) {
        $reserved = ['group'];
        $settings = [];
        foreach ($input as $k => $v) {
            if (!in_array($k, $reserved, true)) {
                $settings[$k] = $v;
            }
        }
    }

    if ($group === '') {
        sendErrorResponse('Settings group is required.', 400);
    }

    if (empty($settings)) {
        sendErrorResponse('No settings provided.', 400);
    }

    // Allowed groups
    $allowedGroups = ['company', 'quotation', 'invoice', 'tax', 'notifications', 'templates'];
    if (!in_array($group, $allowedGroups, true)) {
        sendErrorResponse('Invalid settings group. Allowed: ' . implode(', ', $allowedGroups), 400);
    }

    $upsertSql = 'INSERT INTO settings (`group`, `key`, `value`) VALUES (:g, :k, :v)
                  ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = CURRENT_TIMESTAMP';
    $stmt = $pdo->prepare($upsertSql);

    $pdo->beginTransaction();
    try {
        foreach ($settings as $key => $value) {
            $key   = trim((string) $key);
            $value = $value !== null ? (string) $value : null;

            if ($key === '') continue;

            $stmt->bindValue(':g', $group, PDO::PARAM_STR);
            $stmt->bindValue(':k', $key, PDO::PARAM_STR);
            $stmt->bindValue(':v', $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->execute();
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    sendSuccessResponse('Settings saved successfully.', ['group' => $group, 'saved' => array_keys($settings)]);
}

/* ─────────────────────────────────────────────────────────────────────────────
   TEMPLATE UPLOAD / GET / RESET
   ───────────────────────────────────────────────────────────────────────────── */

/**
 * POST /api/settings.php?action=upload_template
 * Multipart form: type=quotation|invoice, template=<file>
 */
function handleUploadTemplate(PDO $pdo): void
{
    ensureSettingsTable($pdo);

    $type = isset($_POST['type']) ? trim((string) $_POST['type']) : '';
    if (!in_array($type, ['quotation', 'invoice'], true)) {
        sendErrorResponse('Invalid template type. Must be "quotation" or "invoice".', 400);
    }

    if (!isset($_FILES['template']) || $_FILES['template']['error'] !== UPLOAD_ERR_OK) {
        $uploadErr = $_FILES['template']['error'] ?? UPLOAD_ERR_NO_FILE;
        $errMsg = match ($uploadErr) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds maximum allowed upload size.',
            UPLOAD_ERR_PARTIAL                         => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE                         => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR                      => 'Missing server temporary directory.',
            UPLOAD_ERR_CANT_WRITE                      => 'Failed to write file to disk.',
            default                                    => 'File upload failed (error code: ' . $uploadErr . ').',
        };
        sendErrorResponse($errMsg, 400);
    }

    $file       = $_FILES['template'];
    $origName   = basename($file['name']);
    $tmpPath    = $file['tmp_name'];
    $fileSize   = $file['size'];
    $mimeType   = mime_content_type($tmpPath) ?: 'application/octet-stream';

    // ── Validate file type & size ─────────────────────────────────────────────
    $maxBytes = 5 * 1024 * 1024; // 5 MB
    if ($fileSize > $maxBytes) {
        sendErrorResponse('File exceeds maximum allowed size of 5 MB.', 400);
    }

    $allowedMimes = [
        'text/html',
        'application/xhtml+xml',
        'application/pdf',
    ];
    $allowedExts = ['html', 'htm', 'pdf'];
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

    if (!in_array($mimeType, $allowedMimes, true) && !in_array($ext, $allowedExts, true)) {
        sendErrorResponse('Invalid file type. Only HTML and PDF templates are accepted.', 400);
    }

    // ── Determine storage path ────────────────────────────────────────────────
    $storageDir = realpath(__DIR__ . '/../storage/templates');
    if ($storageDir === false || !is_dir($storageDir)) {
        // Try to create it
        if (!mkdir(__DIR__ . '/../storage/templates', 0755, true)) {
            sendErrorResponse('Server storage directory could not be created. Check server permissions.', 500);
        }
        $storageDir = realpath(__DIR__ . '/../storage/templates');
    }

    // ── Build safe filename ───────────────────────────────────────────────────
    // Pattern: quotation_template.html or invoice_template.pdf (overwrites previous)
    $safeExt      = in_array($ext, ['html', 'htm']) ? 'html' : 'pdf';
    $safeFilename = $type . '_template.' . $safeExt;
    $destPath     = $storageDir . DIRECTORY_SEPARATOR . $safeFilename;

    // ── Move uploaded file ────────────────────────────────────────────────────
    if (!move_uploaded_file($tmpPath, $destPath)) {
        sendErrorResponse('Failed to move uploaded file to storage. Check server write permissions.', 500);
    }

    // ── Persist metadata in settings table ────────────────────────────────────
    $upsertSql = 'INSERT INTO settings (`group`, `key`, `value`) VALUES (:g, :k, :v)
                  ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = CURRENT_TIMESTAMP';
    $stmt = $pdo->prepare($upsertSql);

    $metaGroup = 'templates';
    $now       = date('Y-m-d H:i:s');

    foreach ([
        $type . '_template_filename'  => $safeFilename,
        $type . '_template_orig_name' => $origName,
        $type . '_template_mime'      => $mimeType,
        $type . '_template_size'      => (string) $fileSize,
        $type . '_template_updated'   => $now,
        $type . '_template_active'    => 'custom',
    ] as $key => $value) {
        $stmt->bindValue(':g', $metaGroup, PDO::PARAM_STR);
        $stmt->bindValue(':k', $key, PDO::PARAM_STR);
        $stmt->bindValue(':v', $value, PDO::PARAM_STR);
        $stmt->execute();
    }

    sendSuccessResponse('Template uploaded successfully.', [
        'type'          => $type,
        'filename'      => $safeFilename,
        'original_name' => $origName,
        'size_bytes'    => $fileSize,
        'mime'          => $mimeType,
        'updated_at'    => $now,
    ]);
}

/**
 * GET /api/settings.php?action=get_template&type=quotation|invoice
 */
function handleGetTemplate(PDO $pdo): void
{
    ensureSettingsTable($pdo);

    $type = isset($_GET['type']) ? trim((string) $_GET['type']) : '';
    if (!in_array($type, ['quotation', 'invoice'], true)) {
        sendErrorResponse('Invalid template type. Must be "quotation" or "invoice".', 400);
    }

    $stmt = $pdo->prepare(
        'SELECT `key`, `value` FROM settings WHERE `group` = "templates" AND `key` LIKE :prefix ORDER BY `key`'
    );
    $stmt->bindValue(':prefix', $type . '_template_%', PDO::PARAM_STR);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $prefix        = $type . '_template_';
    $activeMode    = $rows[$prefix . 'active'] ?? 'default';
    $filename      = $rows[$prefix . 'filename'] ?? null;
    $origName      = $rows[$prefix . 'orig_name'] ?? null;
    $updatedAt     = $rows[$prefix . 'updated'] ?? null;
    $sizeBytes     = $rows[$prefix . 'size'] ?? null;

    // Check if custom file actually exists on disk
    $fileExists = false;
    if ($filename !== null) {
        $filePath   = realpath(__DIR__ . '/../storage/templates/' . $filename);
        $fileExists = $filePath !== false && file_exists($filePath);
    }

    if (!$fileExists) {
        $activeMode = 'default';
    }

    sendSuccessResponse('Template info fetched.', [
        'type'          => $type,
        'active'        => $activeMode,         // 'default' | 'custom'
        'filename'      => $fileExists ? $filename : null,
        'original_name' => $fileExists ? $origName : null,
        'size_bytes'    => $fileExists ? (int) $sizeBytes : null,
        'updated_at'    => $fileExists ? $updatedAt : null,
    ]);
}

/**
 * POST /api/settings.php?action=reset_template&type=quotation|invoice
 */
function handleResetTemplate(PDO $pdo): void
{
    ensureSettingsTable($pdo);

    $type = isset($_GET['type']) ? trim((string) $_GET['type']) : '';
    if (!in_array($type, ['quotation', 'invoice'], true)) {
        sendErrorResponse('Invalid template type.', 400);
    }

    // Remove physical file if exists
    $storageDir = __DIR__ . '/../storage/templates';
    foreach (['html', 'pdf'] as $ext) {
        $filePath = $storageDir . DIRECTORY_SEPARATOR . $type . '_template.' . $ext;
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
    }

    // Remove metadata from settings table
    $prefix = $type . '_template_%';
    $stmt = $pdo->prepare('DELETE FROM settings WHERE `group` = "templates" AND `key` LIKE :prefix');
    $stmt->bindValue(':prefix', $prefix, PDO::PARAM_STR);
    $stmt->execute();

    sendSuccessResponse('Template reset to default successfully.', ['type' => $type, 'active' => 'default']);
}

/* ─────────────────────────────────────────────────────────────────────────────
   BOOTSTRAP: Ensure settings table exists
   ───────────────────────────────────────────────────────────────────────────── */
function ensureSettingsTable(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `group`    VARCHAR(50)  NOT NULL,
        `key`      VARCHAR(100) NOT NULL,
        `value`    TEXT         NULL,
        created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_group_key (`group`, `key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
