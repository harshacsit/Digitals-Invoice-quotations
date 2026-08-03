<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/auth.php';

// Auth guard (non-blocking for development per auth.php)
requireAuth();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? null;

try {
    if ($method === 'GET' && in_array($action, ['availability', 'calendar', 'customer_summary'], true)) {
        switch ($action) {
            case 'availability':
                handleAvailability($pdo);
                break;
            case 'calendar':
                handleCalendar($pdo);
                break;
            case 'customer_summary':
                handleCustomerSummary($pdo);
                break;
        }
    } elseif ($method === 'POST' && in_array($action, ['schedule', 'activate', 'complete', 'cancel'], true)) {
        handleActionStatus($pdo, $action);
    } else {
        switch ($method) {
            case 'GET':
                if (isset($_GET['id'])) {
                    handleGetSingle($pdo);
                } else {
                    handleGetList($pdo);
                }
                break;
            case 'POST':
                handleCreate($pdo);
                break;
            case 'PUT':
                handleUpdate($pdo);
                break;
            case 'DELETE':
                handleDelete($pdo);
                break;
            default:
                sendErrorResponse('Method Not Allowed', 405);
                break;
        }
    }
} catch (Throwable $e) {
    sendErrorResponse('Internal Server Error', 500);
}

/**
 * GET Paginated List of Campaigns
 */
function handleGetList(PDO $pdo): void
{
    $page = isset($_GET['page']) ? filter_var($_GET['page'], FILTER_VALIDATE_INT) : 1;
    $limit = isset($_GET['limit']) ? filter_var($_GET['limit'], FILTER_VALIDATE_INT) : 20;

    if ($page === false || $page < 1) {
        $page = 1;
    }
    if ($limit === false || $limit < 1) {
        $limit = 20;
    } elseif ($limit > 100) {
        $limit = 100;
    }

    $search = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
    $customerId = isset($_GET['customer_id']) ? filter_var($_GET['customer_id'], FILTER_VALIDATE_INT) : null;
    $status = isset($_GET['status']) ? trim((string) $_GET['status']) : '';
    $fromDate = isset($_GET['from_date']) ? trim((string) $_GET['from_date']) : '';
    $toDate = isset($_GET['to_date']) ? trim((string) $_GET['to_date']) : '';

    $whereClauses = [];
    $params = [];

    if ($search !== '') {
        $whereClauses[] = '(cmp.campaign_name LIKE :s_cn OR c.company_name LIKE :s_comp OR cmp.notes LIKE :s_notes)';
        $params[':s_cn'] = '%' . $search . '%';
        $params[':s_comp'] = '%' . $search . '%';
        $params[':s_notes'] = '%' . $search . '%';
    }

    if ($customerId !== false && $customerId !== null && $customerId > 0) {
        $whereClauses[] = 'cmp.customer_id = :customer_id';
        $params[':customer_id'] = $customerId;
    }

    if ($status !== '') {
        $whereClauses[] = 'cmp.status = :status';
        $params[':status'] = $status;
    }

    if ($fromDate !== '') {
        $whereClauses[] = 'cmp.start_date >= :from_date';
        $params[':from_date'] = $fromDate;
    }

    if ($toDate !== '') {
        $whereClauses[] = 'cmp.end_date <= :to_date';
        $params[':to_date'] = $toDate;
    }

    $whereSql = $whereClauses !== [] ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

    $countSql = "SELECT COUNT(*) FROM campaigns cmp JOIN customers c ON cmp.customer_id = c.id {$whereSql}";
    $countStmt = $pdo->prepare($countSql);
    foreach ($params as $key => $val) {
        $countStmt->bindValue($key, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $countStmt->execute();
    $total = (int) $countStmt->fetchColumn();

    $totalPages = $total > 0 ? (int) ceil($total / $limit) : 0;
    $offset = ($page - 1) * $limit;

    $sql = "SELECT 
                cmp.id,
                cmp.campaign_number,
                cmp.customer_id,
                cmp.quotation_id,
                cmp.invoice_id,
                cmp.campaign_name,
                cmp.campaign_type,
                cmp.start_date,
                cmp.end_date,
                cmp.status,
                cmp.progress,
                cmp.notes,
                cmp.created_by,
                cmp.created_at,
                cmp.updated_at,
                c.company_name,
                c.contact_person,
                c.email AS customer_email,
                c.phone AS customer_phone
            FROM campaigns cmp
            JOIN customers c ON cmp.customer_id = c.id
            {$whereSql}
            ORDER BY cmp.id DESC
            LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $campaigns = array_map(static function (array $row): array {
        return formatCampaignRow($row);
    }, $rows);

    $pagination = [
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'total_pages' => $totalPages,
    ];

    sendSuccessResponse('Campaigns fetched successfully.', $campaigns, 200, $pagination);
}

/**
 * GET Single Campaign Details with Booked Screens
 */
function handleGetSingle(PDO $pdo): void
{
    $id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
    if ($id === false || $id <= 0) {
        sendErrorResponse('Invalid campaign ID.', 400);
    }

    $sql = "SELECT 
                cmp.id,
                cmp.campaign_number,
                cmp.customer_id,
                cmp.quotation_id,
                cmp.invoice_id,
                cmp.campaign_name,
                cmp.campaign_type,
                cmp.start_date,
                cmp.end_date,
                cmp.status,
                cmp.progress,
                cmp.notes,
                cmp.created_by,
                cmp.created_at,
                cmp.updated_at,
                c.company_name,
                c.contact_person,
                c.email AS customer_email,
                c.phone AS customer_phone
            FROM campaigns cmp
            JOIN customers c ON cmp.customer_id = c.id
            WHERE cmp.id = :id";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch();

    if (!$row) {
        sendErrorResponse('Campaign not found.', 404);
    }

    $campaign = formatCampaignRow($row);

    // Fetch linked screens
    $csSql = "SELECT 
                cs.id AS booking_id,
                cs.screen_id,
                cs.start_date,
                cs.end_date,
                cs.agreed_monthly_rate,
                cs.status AS booking_status,
                s.name AS screen_name,
                s.screen_type,
                s.location AS screen_location,
                s.city AS screen_city,
                s.dimensions AS screen_dimensions,
                s.monthly_rate AS current_monthly_rate,
                s.image_path
            FROM campaign_screens cs
            JOIN screens s ON cs.screen_id = s.id
            WHERE cs.campaign_id = :id
            ORDER BY cs.id ASC";

    $csStmt = $pdo->prepare($csSql);
    $csStmt->bindValue(':id', $id, PDO::PARAM_INT);
    $csStmt->execute();
    $screenRows = $csStmt->fetchAll();

    $campaign['screens'] = array_map(static function (array $s): array {
        return [
            'booking_id' => (int) $s['booking_id'],
            'screen_id' => (int) $s['screen_id'],
            'id' => (int) $s['screen_id'],
            'name' => $s['screen_name'],
            'screen_type' => $s['screen_type'],
            'location' => $s['screen_location'],
            'city' => $s['screen_city'],
            'dimensions' => $s['screen_dimensions'],
            'agreed_monthly_rate' => (float) $s['agreed_monthly_rate'],
            'current_monthly_rate' => (float) $s['current_monthly_rate'],
            'start_date' => $s['start_date'],
            'end_date' => $s['end_date'],
            'status' => $s['booking_status'],
            'image_path' => $s['image_path'],
        ];
    }, $screenRows);

    sendSuccessResponse('Campaign fetched successfully.', $campaign);
}

/**
 * POST Create Campaign (with multi-screen atomicity & availability checks)
 */
function handleCreate(PDO $pdo): void
{
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    if (!is_array($input)) {
        sendErrorResponse('Invalid JSON payload.', 400);
    }

    $validated = validateCampaignInput($pdo, $input);

    $pdo->beginTransaction();

    try {
        // Lock and verify availability for all requested screens
        foreach ($validated['screen_ids'] as $screenId) {
            $availStmt = $pdo->prepare("SELECT cs.id, s.name FROM campaign_screens cs
                                        JOIN screens s ON cs.screen_id = s.id
                                        WHERE cs.screen_id = :screen_id 
                                          AND cs.status != 'cancelled'
                                          AND cs.start_date <= :end_date 
                                          AND cs.end_date >= :start_date
                                        FOR UPDATE");
            $availStmt->bindValue(':screen_id', $screenId, PDO::PARAM_INT);
            $availStmt->bindValue(':end_date', $validated['end_date'], PDO::PARAM_STR);
            $availStmt->bindValue(':start_date', $validated['start_date'], PDO::PARAM_STR);
            $availStmt->execute();
            $conflict = $availStmt->fetch();

            if ($conflict) {
                $pdo->rollBack();
                sendErrorResponse("Screen ID {$screenId} ({$conflict['name']}) is already booked for the selected dates.", 409);
            }
        }

        $campaignNumber = generateCampaignNumber($pdo);

        $sql = "INSERT INTO campaigns (
                    campaign_number, customer_id, quotation_id, invoice_id,
                    campaign_name, campaign_type, start_date, end_date, status, progress, notes, created_by
                ) VALUES (
                    :campaign_number, :customer_id, :quotation_id, :invoice_id,
                    :campaign_name, :campaign_type, :start_date, :end_date, 'planned', 0.00, :notes, :created_by
                )";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':campaign_number', $campaignNumber, PDO::PARAM_STR);
        $stmt->bindValue(':customer_id', $validated['customer_id'], PDO::PARAM_INT);
        $stmt->bindValue(':quotation_id', $validated['quotation_id'], $validated['quotation_id'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':invoice_id', $validated['invoice_id'], $validated['invoice_id'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':campaign_name', $validated['campaign_name'], PDO::PARAM_STR);
        $stmt->bindValue(':campaign_type', $validated['campaign_type'], PDO::PARAM_STR);
        $stmt->bindValue(':start_date', $validated['start_date'], PDO::PARAM_STR);
        $stmt->bindValue(':end_date', $validated['end_date'], PDO::PARAM_STR);
        $stmt->bindValue(':notes', $validated['notes'], $validated['notes'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':created_by', getCurrentUserId(), getCurrentUserId() === null ? PDO::PARAM_NULL : PDO::PARAM_INT);

        $stmt->execute();
        $newId = (int) $pdo->lastInsertId();

        // Insert into campaign_screens
        $csSql = "INSERT INTO campaign_screens (
                    campaign_id, screen_id, start_date, end_date, agreed_monthly_rate, status
                ) VALUES (
                    :campaign_id, :screen_id, :start_date, :end_date, :agreed_monthly_rate, 'reserved'
                )";
        $csStmt = $pdo->prepare($csSql);

        foreach ($validated['screens_data'] as $sData) {
            $csStmt->bindValue(':campaign_id', $newId, PDO::PARAM_INT);
            $csStmt->bindValue(':screen_id', $sData['id'], PDO::PARAM_INT);
            $csStmt->bindValue(':start_date', $validated['start_date'], PDO::PARAM_STR);
            $csStmt->bindValue(':end_date', $validated['end_date'], PDO::PARAM_STR);
            $csStmt->bindValue(':agreed_monthly_rate', $sData['monthly_rate'], PDO::PARAM_STR);
            $csStmt->execute();
        }

        $pdo->commit();

        $_GET['id'] = $newId;
        handleGetSingle($pdo);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * PUT Update Campaign (with availability check excluding self)
 */
function handleUpdate(PDO $pdo): void
{
    $id = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : null;
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    if (!is_array($input)) {
        sendErrorResponse('Invalid JSON payload.', 400);
    }

    if (!$id && isset($input['id'])) {
        $id = filter_var($input['id'], FILTER_VALIDATE_INT);
    }

    if ($id === false || $id === null || $id <= 0) {
        sendErrorResponse('Campaign ID is required.', 400);
    }

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare('SELECT * FROM campaigns WHERE id = :id FOR UPDATE');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $existing = $stmt->fetch();

        if (!$existing) {
            $pdo->rollBack();
            sendErrorResponse('Campaign not found.', 404);
        }

        if (in_array($existing['status'], ['completed', 'cancelled'], true)) {
            $pdo->rollBack();
            sendErrorResponse('Completed or cancelled campaigns cannot be updated.', 409);
        }

        // Merge input with existing values if fields omitted
        $mergedInput = array_merge([
            'customer_id' => $existing['customer_id'],
            'campaign_name' => $existing['campaign_name'],
            'campaign_type' => $existing['campaign_type'],
            'start_date' => $existing['start_date'],
            'end_date' => $existing['end_date'],
            'notes' => $existing['notes'],
        ], $input);

        if (!isset($input['screen_ids'])) {
            // Fetch existing screen_ids
            $exCs = $pdo->prepare('SELECT screen_id FROM campaign_screens WHERE campaign_id = :id');
            $exCs->bindValue(':id', $id, PDO::PARAM_INT);
            $exCs->execute();
            $mergedInput['screen_ids'] = array_column($exCs->fetchAll(PDO::FETCH_ASSOC), 'screen_id');
        }

        $validated = validateCampaignInput($pdo, $mergedInput);

        // Check screen availability EXCLUDING current campaign
        foreach ($validated['screen_ids'] as $screenId) {
            $availStmt = $pdo->prepare("SELECT cs.id, s.name FROM campaign_screens cs
                                        JOIN screens s ON cs.screen_id = s.id
                                        WHERE cs.screen_id = :screen_id 
                                          AND cs.campaign_id != :current_id
                                          AND cs.status != 'cancelled'
                                          AND cs.start_date <= :end_date 
                                          AND cs.end_date >= :start_date
                                        FOR UPDATE");
            $availStmt->bindValue(':screen_id', $screenId, PDO::PARAM_INT);
            $availStmt->bindValue(':current_id', $id, PDO::PARAM_INT);
            $availStmt->bindValue(':end_date', $validated['end_date'], PDO::PARAM_STR);
            $availStmt->bindValue(':start_date', $validated['start_date'], PDO::PARAM_STR);
            $availStmt->execute();
            $conflict = $availStmt->fetch();

            if ($conflict) {
                $pdo->rollBack();
                sendErrorResponse("Screen ID {$screenId} ({$conflict['name']}) is already booked for the selected dates.", 409);
            }
        }

        $sql = "UPDATE campaigns SET
                    customer_id = :customer_id,
                    campaign_name = :campaign_name,
                    campaign_type = :campaign_type,
                    start_date = :start_date,
                    end_date = :end_date,
                    notes = :notes,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id";

        $updStmt = $pdo->prepare($sql);
        $updStmt->bindValue(':customer_id', $validated['customer_id'], PDO::PARAM_INT);
        $updStmt->bindValue(':campaign_name', $validated['campaign_name'], PDO::PARAM_STR);
        $updStmt->bindValue(':campaign_type', $validated['campaign_type'], PDO::PARAM_STR);
        $updStmt->bindValue(':start_date', $validated['start_date'], PDO::PARAM_STR);
        $updStmt->bindValue(':end_date', $validated['end_date'], PDO::PARAM_STR);
        $updStmt->bindValue(':notes', $validated['notes'], $validated['notes'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $updStmt->bindValue(':id', $id, PDO::PARAM_INT);
        $updStmt->execute();

        // Delete old campaign_screens and re-insert
        $delCs = $pdo->prepare('DELETE FROM campaign_screens WHERE campaign_id = :id');
        $delCs->bindValue(':id', $id, PDO::PARAM_INT);
        $delCs->execute();

        $bookingStatus = $existing['status'] === 'active' ? 'active' : ($existing['status'] === 'completed' ? 'completed' : 'reserved');

        $csSql = "INSERT INTO campaign_screens (
                    campaign_id, screen_id, start_date, end_date, agreed_monthly_rate, status
                ) VALUES (
                    :campaign_id, :screen_id, :start_date, :end_date, :agreed_monthly_rate, :status
                )";
        $csStmt = $pdo->prepare($csSql);

        foreach ($validated['screens_data'] as $sData) {
            $csStmt->bindValue(':campaign_id', $id, PDO::PARAM_INT);
            $csStmt->bindValue(':screen_id', $sData['id'], PDO::PARAM_INT);
            $csStmt->bindValue(':start_date', $validated['start_date'], PDO::PARAM_STR);
            $csStmt->bindValue(':end_date', $validated['end_date'], PDO::PARAM_STR);
            $csStmt->bindValue(':agreed_monthly_rate', $sData['monthly_rate'], PDO::PARAM_STR);
            $csStmt->bindValue(':status', $bookingStatus, PDO::PARAM_STR);
            $csStmt->execute();
        }

        $pdo->commit();

        $_GET['id'] = $id;
        handleGetSingle($pdo);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Handle status transitions: schedule, activate, complete, cancel
 */
function handleActionStatus(PDO $pdo, string $action): void
{
    $id = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : null;
    if ($id === false || $id === null || $id <= 0) {
        sendErrorResponse('Campaign ID is required.', 400);
    }

    $stmt = $pdo->prepare('SELECT id, status FROM campaigns WHERE id = :id');
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $campaign = $stmt->fetch();

    if (!$campaign) {
        sendErrorResponse('Campaign not found.', 404);
    }

    $currentStatus = $campaign['status'];
    $targetCampaignStatus = '';
    $targetScreenStatus = '';
    $progress = null;

    switch ($action) {
        case 'schedule':
            if ($currentStatus === 'completed') {
                sendErrorResponse('Completed campaigns cannot be re-scheduled.', 409);
            }
            $targetCampaignStatus = 'planned';
            $targetScreenStatus = 'reserved';
            $progress = 0.00;
            break;

        case 'activate':
            if (in_array($currentStatus, ['completed', 'cancelled'], true)) {
                sendErrorResponse('Completed or cancelled campaigns cannot be activated.', 409);
            }
            $targetCampaignStatus = 'active';
            $targetScreenStatus = 'active';
            break;

        case 'complete':
            if ($currentStatus === 'cancelled') {
                sendErrorResponse('Cancelled campaigns cannot be completed.', 409);
            }
            $targetCampaignStatus = 'completed';
            $targetScreenStatus = 'completed';
            $progress = 100.00;
            break;

        case 'cancel':
            if ($currentStatus === 'completed') {
                sendErrorResponse('Completed campaigns cannot be cancelled.', 409);
            }
            $targetCampaignStatus = 'cancelled';
            $targetScreenStatus = 'cancelled';
            break;
    }

    $pdo->beginTransaction();

    try {
        if ($progress !== null) {
            $updCmp = $pdo->prepare("UPDATE campaigns SET status = :status, progress = :progress, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
            $updCmp->bindValue(':progress', number_format($progress, 2, '.', ''), PDO::PARAM_STR);
        } else {
            $updCmp = $pdo->prepare("UPDATE campaigns SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        }

        $updCmp->bindValue(':status', $targetCampaignStatus, PDO::PARAM_STR);
        $updCmp->bindValue(':id', $id, PDO::PARAM_INT);
        $updCmp->execute();

        // Update campaign_screens status
        $updCs = $pdo->prepare("UPDATE campaign_screens SET status = :status WHERE campaign_id = :id");
        $updCs->bindValue(':status', $targetScreenStatus, PDO::PARAM_STR);
        $updCs->bindValue(':id', $id, PDO::PARAM_INT);
        $updCs->execute();

        $pdo->commit();

        // Server-Side Automated Email Trigger (Failure Isolated & Duplicate Protected)
        if (in_array($targetCampaignStatus, ['active', 'completed', 'cancelled'], true)) {
            try {
                require_once __DIR__ . '/../includes/email/EmailDispatcher.php';
                $dispatcher = new EmailDispatcher($pdo);
                $dispatcher->sendAutomatedCampaignUpdate($id, $targetCampaignStatus);
            } catch (Throwable $e) {
                error_log('[Campaign Status Auto-Email Exception] ' . $e->getMessage());
            }
        }

        sendSuccessResponse("Campaign status updated to {$targetCampaignStatus}.", [
            'campaign_id' => $id,
            'status' => $targetCampaignStatus
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * DELETE Campaign (only draft/planned/cancelled allowed, active/completed return HTTP 409)
 */
function handleDelete(PDO $pdo): void
{
    $id = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : null;
    if (!$id) {
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);
        if (is_array($input) && isset($input['id'])) {
            $id = filter_var($input['id'], FILTER_VALIDATE_INT);
        }
    }

    if ($id === false || $id === null || $id <= 0) {
        sendErrorResponse('Campaign ID is required.', 400);
    }

    $stmt = $pdo->prepare('SELECT id, status FROM campaigns WHERE id = :id');
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $campaign = $stmt->fetch();

    if (!$campaign) {
        sendErrorResponse('Campaign not found.', 404);
    }

    if (in_array($campaign['status'], ['active', 'completed'], true)) {
        sendErrorResponse('Active or completed campaigns cannot be deleted. Please cancel the campaign instead.', 409);
    }

    $pdo->beginTransaction();

    try {
        $delCs = $pdo->prepare('DELETE FROM campaign_screens WHERE campaign_id = :id');
        $delCs->bindValue(':id', $id, PDO::PARAM_INT);
        $delCs->execute();

        $delCmp = $pdo->prepare('DELETE FROM campaigns WHERE id = :id');
        $delCmp->bindValue(':id', $id, PDO::PARAM_INT);
        $delCmp->execute();

        $pdo->commit();

        sendSuccessResponse('Campaign deleted successfully.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * GET Screen Availability Endpoint
 * GET /api/campaigns.php?action=availability&screen_id=1&start_date=2026-08-10&end_date=2026-08-30
 */
function handleAvailability(PDO $pdo): void
{
    $screenId = isset($_GET['screen_id']) ? filter_var($_GET['screen_id'], FILTER_VALIDATE_INT) : null;
    if (!$screenId && isset($_GET['id'])) {
        $screenId = filter_var($_GET['id'], FILTER_VALIDATE_INT);
    }

    if ($screenId === false || $screenId === null || $screenId <= 0) {
        sendErrorResponse('Valid screen_id is required.', 400);
    }

    $checkStmt = $pdo->prepare('SELECT id FROM screens WHERE id = :id');
    $checkStmt->bindValue(':id', $screenId, PDO::PARAM_INT);
    $checkStmt->execute();
    if (!$checkStmt->fetch()) {
        sendErrorResponse('Screen not found.', 404);
    }

    $startDateStr = isset($_GET['start_date']) ? trim((string) $_GET['start_date']) : '';
    $endDateStr = isset($_GET['end_date']) ? trim((string) $_GET['end_date']) : '';

    if ($startDateStr === '' || $endDateStr === '') {
        sendErrorResponse('Start date and end date are required.', 400);
    }

    $startDateObj = DateTime::createFromFormat('Y-m-d', $startDateStr);
    $endDateObj = DateTime::createFromFormat('Y-m-d', $endDateStr);

    $isStartValid = $startDateObj && $startDateObj->format('Y-m-d') === $startDateStr;
    $isEndValid = $endDateObj && $endDateObj->format('Y-m-d') === $endDateStr;

    if (!$isStartValid || !$isEndValid) {
        sendErrorResponse('Invalid start or end date format. Use YYYY-MM-DD.', 400);
    }

    if ($startDateObj > $endDateObj) {
        sendErrorResponse('Start date must be before or equal to end date.', 400);
    }

    $sql = "SELECT COUNT(*) FROM campaign_screens 
            WHERE screen_id = :screen_id 
              AND status != 'cancelled'
              AND start_date <= :end_date 
              AND end_date >= :start_date";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':screen_id', $screenId, PDO::PARAM_INT);
    $stmt->bindValue(':end_date', $endDateStr, PDO::PARAM_STR);
    $stmt->bindValue(':start_date', $startDateStr, PDO::PARAM_STR);
    $stmt->execute();

    $bookedCount = (int) $stmt->fetchColumn();

    if ($bookedCount > 0) {
        sendSuccessResponse('Screen availability status fetched.', [
            'screen_id' => $screenId,
            'available' => false,
            'message' => 'Screen is already booked for the selected dates.'
        ]);
    } else {
        sendSuccessResponse('Screen availability status fetched.', [
            'screen_id' => $screenId,
            'available' => true
        ]);
    }
}

/**
 * GET Campaign Calendar Endpoint
 * GET /api/campaigns.php?action=calendar&from_date=2026-08-01&to_date=2026-08-31
 */
function handleCalendar(PDO $pdo): void
{
    $fromDate = isset($_GET['from_date']) ? trim((string) $_GET['from_date']) : date('Y-m-01');
    $toDate = isset($_GET['to_date']) ? trim((string) $_GET['to_date']) : date('Y-m-t');

    $sql = "SELECT 
                cmp.id AS campaign_id,
                cmp.campaign_number,
                cmp.campaign_name,
                cmp.start_date,
                cmp.end_date,
                cmp.status
            FROM campaigns cmp
            WHERE cmp.status != 'cancelled'
              AND cmp.start_date <= :to_date
              AND cmp.end_date >= :from_date
            ORDER BY cmp.start_date ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':from_date', $fromDate, PDO::PARAM_STR);
    $stmt->bindValue(':to_date', $toDate, PDO::PARAM_STR);
    $stmt->execute();
    $campaignRows = $stmt->fetchAll();

    $calendar = [];
    foreach ($campaignRows as $cRow) {
        $cId = (int) $cRow['campaign_id'];

        $sSql = "SELECT s.id, s.name, s.screen_type, s.location, s.city 
                 FROM campaign_screens cs 
                 JOIN screens s ON cs.screen_id = s.id 
                 WHERE cs.campaign_id = :cid AND cs.status != 'cancelled'";
        $sStmt = $pdo->prepare($sSql);
        $sStmt->bindValue(':cid', $cId, PDO::PARAM_INT);
        $sStmt->execute();
        $sRows = $sStmt->fetchAll();

        $screens = array_map(static function (array $s): array {
            return [
                'id' => (int) $s['id'],
                'name' => $s['name'],
                'screen_type' => $s['screen_type'],
                'location' => $s['location'],
                'city' => $s['city'],
            ];
        }, $sRows);

        $calendar[] = [
            'campaign_id' => $cId,
            'campaign_number' => $cRow['campaign_number'],
            'campaign_name' => $cRow['campaign_name'],
            'start_date' => $cRow['start_date'],
            'end_date' => $cRow['end_date'],
            'status' => $cRow['status'],
            'screens' => $screens,
        ];
    }

    sendSuccessResponse('Campaign calendar fetched successfully.', $calendar);
}

/**
 * GET Customer Campaign Summary
 * GET /api/campaigns.php?action=customer_summary&customer_id=5
 */
function handleCustomerSummary(PDO $pdo): void
{
    $customerId = isset($_GET['customer_id']) ? filter_var($_GET['customer_id'], FILTER_VALIDATE_INT) : null;
    if ($customerId === false || $customerId === null || $customerId <= 0) {
        sendErrorResponse('Valid customer_id is required.', 400);
    }

    $custStmt = $pdo->prepare('SELECT id FROM customers WHERE id = :id');
    $custStmt->bindValue(':id', $customerId, PDO::PARAM_INT);
    $custStmt->execute();
    if (!$custStmt->fetch()) {
        sendErrorResponse('Customer not found.', 404);
    }

    $sql = "SELECT 
                COUNT(id) AS total_campaigns,
                COALESCE(SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END), 0) AS active_campaigns,
                COALESCE(SUM(CASE WHEN status = 'planned' THEN 1 ELSE 0 END), 0) AS scheduled_campaigns,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END), 0) AS completed_campaigns,
                COALESCE(SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END), 0) AS cancelled_campaigns
            FROM campaigns
            WHERE customer_id = :cid";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':cid', $customerId, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch();

    sendSuccessResponse('Customer campaign summary fetched successfully.', [
        'customer_id' => $customerId,
        'total_campaigns' => (int) $row['total_campaigns'],
        'active_campaigns' => (int) $row['active_campaigns'],
        'scheduled_campaigns' => (int) $row['scheduled_campaigns'],
        'completed_campaigns' => (int) $row['completed_campaigns'],
        'cancelled_campaigns' => (int) $row['cancelled_campaigns'],
    ]);
}

/**
 * Validate input payload for creating/updating a campaign
 */
function validateCampaignInput(PDO $pdo, array $input): array
{
    $customerId = isset($input['customer_id']) ? filter_var($input['customer_id'], FILTER_VALIDATE_INT) : null;
    if ($customerId === false || $customerId === null || $customerId <= 0) {
        sendErrorResponse('Valid customer_id is required.', 400);
    }

    $custStmt = $pdo->prepare('SELECT id, status FROM customers WHERE id = :id');
    $custStmt->bindValue(':id', $customerId, PDO::PARAM_INT);
    $custStmt->execute();
    $customer = $custStmt->fetch();

    if (!$customer) {
        sendErrorResponse('Customer not found.', 404);
    }
    if ($customer['status'] !== 'active') {
        sendErrorResponse('Customer is inactive.', 400);
    }

    $campaignName = isset($input['campaign_name']) ? trim((string) $input['campaign_name']) : '';
    if ($campaignName === '') {
        sendErrorResponse('Campaign name is required.', 400);
    }

    $startDateStr = isset($input['start_date']) ? trim((string) $input['start_date']) : '';
    $endDateStr = isset($input['end_date']) ? trim((string) $input['end_date']) : '';

    if ($startDateStr === '' || $endDateStr === '') {
        sendErrorResponse('Start date and end date are required.', 400);
    }

    $sObj = DateTime::createFromFormat('Y-m-d', $startDateStr);
    $eObj = DateTime::createFromFormat('Y-m-d', $endDateStr);

    if (!$sObj || $sObj->format('Y-m-d') !== $startDateStr || !$eObj || $eObj->format('Y-m-d') !== $endDateStr) {
        sendErrorResponse('Invalid start or end date format. Use YYYY-MM-DD.', 400);
    }

    if ($sObj > $eObj) {
        sendErrorResponse('Start date must be before or equal to end date.', 400);
    }

    $screenIds = $input['screen_ids'] ?? [];
    if (!is_array($screenIds) || count($screenIds) === 0) {
        sendErrorResponse('At least one screen_id is required.', 400);
    }

    // Reject duplicate screen IDs
    $cleanScreenIds = array_map(static function ($id) {
        return (int) $id;
    }, $screenIds);

    if (count($cleanScreenIds) !== count(array_unique($cleanScreenIds))) {
        sendErrorResponse('Duplicate screen IDs are not allowed.', 400);
    }

    $screensData = [];
    foreach ($cleanScreenIds as $sId) {
        if ($sId <= 0) {
            sendErrorResponse('Invalid screen_id provided.', 400);
        }

        $screenStmt = $pdo->prepare('SELECT id, name, monthly_rate, status FROM screens WHERE id = :id');
        $screenStmt->bindValue(':id', $sId, PDO::PARAM_INT);
        $screenStmt->execute();
        $screen = $screenStmt->fetch();

        if (!$screen) {
            sendErrorResponse("Screen ID {$sId} not found.", 400);
        }
        if ($screen['status'] === 'inactive') {
            sendErrorResponse("Screen ID {$sId} is inactive and cannot be booked.", 400);
        }

        $screensData[] = [
            'id' => (int) $screen['id'],
            'name' => $screen['name'],
            'monthly_rate' => (float) $screen['monthly_rate'],
        ];
    }

    $campaignType = isset($input['campaign_type']) ? trim((string) $input['campaign_type']) : 'tv_display';
    $allowedTypes = ['tv_display', 'billboard', 'digital_board', 'other'];
    if (!in_array($campaignType, $allowedTypes, true)) {
        $campaignType = 'tv_display';
    }

    $notes = isset($input['notes']) && trim((string) $input['notes']) !== ''
        ? trim((string) $input['notes'])
        : (isset($input['description']) && trim((string) $input['description']) !== '' ? trim((string) $input['description']) : null);

    $quotationId = isset($input['quotation_id']) ? filter_var($input['quotation_id'], FILTER_VALIDATE_INT) : null;
    $invoiceId = isset($input['invoice_id']) ? filter_var($input['invoice_id'], FILTER_VALIDATE_INT) : null;

    return [
        'customer_id' => $customerId,
        'quotation_id' => $quotationId !== false && $quotationId > 0 ? $quotationId : null,
        'invoice_id' => $invoiceId !== false && $invoiceId > 0 ? $invoiceId : null,
        'campaign_name' => $campaignName,
        'campaign_type' => $campaignType,
        'start_date' => $startDateStr,
        'end_date' => $endDateStr,
        'screen_ids' => $cleanScreenIds,
        'screens_data' => $screensData,
        'notes' => $notes,
    ];
}

/**
 * Generate next campaign number (CMP-1001 format)
 */
function generateCampaignNumber(PDO $pdo): string
{
    $prefix = 'CMP-';
    $lastStmt = $pdo->prepare("SELECT campaign_number FROM campaigns ORDER BY id DESC LIMIT 1 FOR UPDATE");
    $lastStmt->execute();
    $lastRow = $lastStmt->fetch();

    $nextNum = 1001;
    if ($lastRow && !empty($lastRow['campaign_number'])) {
        $lastNumStr = $lastRow['campaign_number'];
        if (preg_match('/(\d+)$/', $lastNumStr, $matches)) {
            $nextNum = ((int) $matches[1]) + 1;
        }
    }

    return $prefix . $nextNum;
}

/**
 * Format campaign DB row to response structure
 */
function formatCampaignRow(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'campaign_number' => $row['campaign_number'],
        'customer_id' => (int) $row['customer_id'],
        'customer' => [
            'id' => (int) $row['customer_id'],
            'company_name' => $row['company_name'],
            'contact_person' => $row['contact_person'],
            'email' => $row['customer_email'],
            'phone' => $row['customer_phone'],
        ],
        'quotation_id' => $row['quotation_id'] !== null ? (int) $row['quotation_id'] : null,
        'invoice_id' => $row['invoice_id'] !== null ? (int) $row['invoice_id'] : null,
        'campaign_name' => $row['campaign_name'],
        'campaign_type' => $row['campaign_type'],
        'start_date' => $row['start_date'],
        'end_date' => $row['end_date'],
        'status' => $row['status'],
        'progress' => (float) $row['progress'],
        'notes' => $row['notes'],
        'created_by' => $row['created_by'] !== null ? (int) $row['created_by'] : null,
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
    ];
}
