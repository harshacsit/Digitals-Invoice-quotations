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
    if ($method === 'POST' && in_array($action, ['submit', 'approve', 'reject'], true)) {
        switch ($action) {
            case 'submit':
                handleActionSubmit($pdo);
                break;
            case 'approve':
                handleActionApprove($pdo);
                break;
            case 'reject':
                handleActionReject($pdo);
                break;
        }
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
 * GET List of Quotations (paginated, with search & filter)
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
        $whereClauses[] = '(q.quotation_number LIKE :s_qn OR c.company_name LIKE :s_cn OR c.contact_person LIKE :s_cp)';
        $params[':s_qn'] = '%' . $search . '%';
        $params[':s_cn'] = '%' . $search . '%';
        $params[':s_cp'] = '%' . $search . '%';
    }

    if ($customerId !== false && $customerId !== null && $customerId > 0) {
        $whereClauses[] = 'q.customer_id = :customer_id';
        $params[':customer_id'] = $customerId;
    }

    if ($status !== '') {
        $whereClauses[] = 'q.status = :status';
        $params[':status'] = $status;
    }

    if ($fromDate !== '') {
        $whereClauses[] = 'q.quotation_date >= :from_date';
        $params[':from_date'] = $fromDate;
    }

    if ($toDate !== '') {
        $whereClauses[] = 'q.quotation_date <= :to_date';
        $params[':to_date'] = $toDate;
    }

    $whereSql = $whereClauses !== [] ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

    // Count query
    $countSql = "SELECT COUNT(*) FROM quotations q JOIN customers c ON q.customer_id = c.id {$whereSql}";
    $countStmt = $pdo->prepare($countSql);
    foreach ($params as $key => $val) {
        $countStmt->bindValue($key, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $countStmt->execute();
    $total = (int) $countStmt->fetchColumn();

    $totalPages = $total > 0 ? (int) ceil($total / $limit) : 0;
    $offset = ($page - 1) * $limit;

    // Fetch records
    $sql = "SELECT 
                q.id,
                q.quotation_number,
                q.customer_id,
                q.quotation_date,
                q.valid_until,
                q.subtotal,
                q.discount_type,
                q.discount_value,
                q.discount_amount,
                q.taxable_amount,
                q.cgst_rate,
                q.cgst_amount,
                q.sgst_rate,
                q.sgst_amount,
                q.igst_rate,
                q.igst_amount,
                q.total_amount,
                q.currency,
                q.status,
                q.notes,
                q.terms_conditions,
                q.created_by,
                q.approved_by,
                q.approved_at,
                q.rejected_reason,
                q.created_at,
                q.updated_at,
                c.company_name,
                c.contact_person,
                c.email AS customer_email,
                c.phone AS customer_phone
            FROM quotations q
            JOIN customers c ON q.customer_id = c.id
            {$whereSql}
            ORDER BY q.id DESC
            LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $quotations = array_map(static function (array $row): array {
        return formatQuotationRow($row);
    }, $rows);

    $pagination = [
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'total_pages' => $totalPages,
    ];

    sendSuccessResponse('Quotations fetched successfully.', $quotations, 200, $pagination);
}

/**
 * GET Single Quotation with Customer and Items details
 */
function handleGetSingle(PDO $pdo): void
{
    $id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
    if ($id === false || $id <= 0) {
        sendErrorResponse('Invalid quotation ID.', 400);
    }

    $sql = "SELECT 
                q.id,
                q.quotation_number,
                q.customer_id,
                q.quotation_date,
                q.valid_until,
                q.subtotal,
                q.discount_type,
                q.discount_value,
                q.discount_amount,
                q.taxable_amount,
                q.cgst_rate,
                q.cgst_amount,
                q.sgst_rate,
                q.sgst_amount,
                q.igst_rate,
                q.igst_amount,
                q.total_amount,
                q.currency,
                q.status,
                q.notes,
                q.terms_conditions,
                q.created_by,
                q.approved_by,
                q.approved_at,
                q.rejected_reason,
                q.created_at,
                q.updated_at,
                c.company_name,
                c.contact_person,
                c.email AS customer_email,
                c.phone AS customer_phone
            FROM quotations q
            JOIN customers c ON q.customer_id = c.id
            WHERE q.id = :id";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch();

    if (!$row) {
        sendErrorResponse('Quotation not found.', 404);
    }

    $quotation = formatQuotationRow($row);

    // Fetch items
    $itemSql = "SELECT 
                    qi.id,
                    qi.quotation_id,
                    qi.screen_id,
                    qi.description,
                    qi.quantity,
                    qi.duration_months,
                    qi.unit_price,
                    qi.discount_amount,
                    qi.tax_rate,
                    qi.tax_amount,
                    qi.line_total,
                    s.name AS screen_name,
                    s.screen_type,
                    s.location AS screen_location,
                    s.city AS screen_city
                FROM quotation_items qi
                LEFT JOIN screens s ON qi.screen_id = s.id
                WHERE qi.quotation_id = :id
                ORDER BY qi.id ASC";

    $itemStmt = $pdo->prepare($itemSql);
    $itemStmt->bindValue(':id', $id, PDO::PARAM_INT);
    $itemStmt->execute();
    $itemRows = $itemStmt->fetchAll();

    $quotation['items'] = array_map(static function (array $item): array {
        return [
            'id' => (int) $item['id'],
            'quotation_id' => (int) $item['quotation_id'],
            'screen_id' => $item['screen_id'] !== null ? (int) $item['screen_id'] : null,
            'screen_name' => $item['screen_name'],
            'screen_type' => $item['screen_type'],
            'screen_location' => $item['screen_location'],
            'screen_city' => $item['screen_city'],
            'description' => $item['description'],
            'quantity' => (float) $item['quantity'],
            'duration_months' => (float) $item['duration_months'],
            'unit_price' => (float) $item['unit_price'],
            'discount_amount' => (float) $item['discount_amount'],
            'tax_rate' => (float) $item['tax_rate'],
            'tax_amount' => (float) $item['tax_amount'],
            'line_total' => (float) $item['line_total'],
        ];
    }, $itemRows);

    sendSuccessResponse('Quotation fetched successfully.', $quotation);
}

/**
 * POST Create Quotation (with transaction and pricing calculation)
 */
function handleCreate(PDO $pdo): void
{
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    if (!is_array($input)) {
        sendErrorResponse('Invalid JSON payload.', 400);
    }

    $validated = validateQuotationInput($pdo, $input);

    $pdo->beginTransaction();

    try {
        $quotationNumber = generateQuotationNumber($pdo);

        $sql = "INSERT INTO quotations (
                    quotation_number, customer_id, quotation_date, valid_until,
                    subtotal, discount_type, discount_value, discount_amount, taxable_amount,
                    cgst_rate, cgst_amount, sgst_rate, sgst_amount, igst_rate, igst_amount,
                    total_amount, currency, status, notes, terms_conditions, created_by
                ) VALUES (
                    :quotation_number, :customer_id, :quotation_date, :valid_until,
                    :subtotal, :discount_type, :discount_value, :discount_amount, :taxable_amount,
                    :cgst_rate, :cgst_amount, :sgst_rate, :sgst_amount, :igst_rate, :igst_amount,
                    :total_amount, :currency, 'draft', :notes, :terms_conditions, :created_by
                )";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':quotation_number', $quotationNumber, PDO::PARAM_STR);
        $stmt->bindValue(':customer_id', $validated['customer_id'], PDO::PARAM_INT);
        $stmt->bindValue(':quotation_date', $validated['quotation_date'], PDO::PARAM_STR);
        $stmt->bindValue(':valid_until', $validated['valid_until'], PDO::PARAM_STR);
        $stmt->bindValue(':subtotal', $validated['subtotal'], PDO::PARAM_STR);
        $stmt->bindValue(':discount_type', $validated['discount_type'], PDO::PARAM_STR);
        $stmt->bindValue(':discount_value', $validated['discount_value'], PDO::PARAM_STR);
        $stmt->bindValue(':discount_amount', $validated['discount_amount'], PDO::PARAM_STR);
        $stmt->bindValue(':taxable_amount', $validated['taxable_amount'], PDO::PARAM_STR);
        $stmt->bindValue(':cgst_rate', $validated['cgst_rate'], PDO::PARAM_STR);
        $stmt->bindValue(':cgst_amount', $validated['cgst_amount'], PDO::PARAM_STR);
        $stmt->bindValue(':sgst_rate', $validated['sgst_rate'], PDO::PARAM_STR);
        $stmt->bindValue(':sgst_amount', $validated['sgst_amount'], PDO::PARAM_STR);
        $stmt->bindValue(':igst_rate', $validated['igst_rate'], PDO::PARAM_STR);
        $stmt->bindValue(':igst_amount', $validated['igst_amount'], PDO::PARAM_STR);
        $stmt->bindValue(':total_amount', $validated['total_amount'], PDO::PARAM_STR);
        $stmt->bindValue(':currency', 'INR', PDO::PARAM_STR);
        $stmt->bindValue(':notes', $validated['notes'], $validated['notes'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':terms_conditions', $validated['terms_conditions'], $validated['terms_conditions'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':created_by', getCurrentUserId(), getCurrentUserId() === null ? PDO::PARAM_NULL : PDO::PARAM_INT);

        $stmt->execute();
        $newId = (int) $pdo->lastInsertId();

        // Insert items
        $itemSql = "INSERT INTO quotation_items (
                        quotation_id, screen_id, description, quantity, duration_months, unit_price, discount_amount, tax_rate, tax_amount, line_total
                    ) VALUES (
                        :quotation_id, :screen_id, :description, :quantity, :duration_months, :unit_price, 0.00, 0.00, 0.00, :line_total
                    )";
        $itemStmt = $pdo->prepare($itemSql);

        foreach ($validated['items'] as $item) {
            $itemStmt->bindValue(':quotation_id', $newId, PDO::PARAM_INT);
            $itemStmt->bindValue(':screen_id', $item['screen_id'], $item['screen_id'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $itemStmt->bindValue(':description', $item['description'], PDO::PARAM_STR);
            $itemStmt->bindValue(':quantity', $item['quantity'], PDO::PARAM_STR);
            $itemStmt->bindValue(':duration_months', $item['duration_months'], PDO::PARAM_STR);
            $itemStmt->bindValue(':unit_price', $item['unit_price'], PDO::PARAM_STR);
            $itemStmt->bindValue(':line_total', $item['line_total'], PDO::PARAM_STR);
            $itemStmt->execute();
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
 * PUT Update Quotation
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
        sendErrorResponse('Quotation ID is required.', 400);
    }

    $checkStmt = $pdo->prepare('SELECT * FROM quotations WHERE id = :id');
    $checkStmt->bindValue(':id', $id, PDO::PARAM_INT);
    $checkStmt->execute();
    $existing = $checkStmt->fetch();

    if (!$existing) {
        sendErrorResponse('Quotation not found.', 404);
    }

    if (in_array($existing['status'], ['approved', 'converted'], true)) {
        sendErrorResponse('Approved quotations cannot be edited.', 409);
    }

    if ($existing['status'] === 'pending_approval') {
        sendErrorResponse('Quotation submitted for approval cannot be edited until reviewed.', 409);
    }

    $validated = validateQuotationInput($pdo, $input);

    $pdo->beginTransaction();

    try {
        $sql = "UPDATE quotations SET
                    customer_id = :customer_id,
                    quotation_date = :quotation_date,
                    valid_until = :valid_until,
                    subtotal = :subtotal,
                    discount_type = :discount_type,
                    discount_value = :discount_value,
                    discount_amount = :discount_amount,
                    taxable_amount = :taxable_amount,
                    cgst_rate = :cgst_rate,
                    cgst_amount = :cgst_amount,
                    sgst_rate = :sgst_rate,
                    sgst_amount = :sgst_amount,
                    igst_rate = :igst_rate,
                    igst_amount = :igst_amount,
                    total_amount = :total_amount,
                    notes = :notes,
                    terms_conditions = :terms_conditions,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':customer_id', $validated['customer_id'], PDO::PARAM_INT);
        $stmt->bindValue(':quotation_date', $validated['quotation_date'], PDO::PARAM_STR);
        $stmt->bindValue(':valid_until', $validated['valid_until'], PDO::PARAM_STR);
        $stmt->bindValue(':subtotal', $validated['subtotal'], PDO::PARAM_STR);
        $stmt->bindValue(':discount_type', $validated['discount_type'], PDO::PARAM_STR);
        $stmt->bindValue(':discount_value', $validated['discount_value'], PDO::PARAM_STR);
        $stmt->bindValue(':discount_amount', $validated['discount_amount'], PDO::PARAM_STR);
        $stmt->bindValue(':taxable_amount', $validated['taxable_amount'], PDO::PARAM_STR);
        $stmt->bindValue(':cgst_rate', $validated['cgst_rate'], PDO::PARAM_STR);
        $stmt->bindValue(':cgst_amount', $validated['cgst_amount'], PDO::PARAM_STR);
        $stmt->bindValue(':sgst_rate', $validated['sgst_rate'], PDO::PARAM_STR);
        $stmt->bindValue(':sgst_amount', $validated['sgst_amount'], PDO::PARAM_STR);
        $stmt->bindValue(':igst_rate', $validated['igst_rate'], PDO::PARAM_STR);
        $stmt->bindValue(':igst_amount', $validated['igst_amount'], PDO::PARAM_STR);
        $stmt->bindValue(':total_amount', $validated['total_amount'], PDO::PARAM_STR);
        $stmt->bindValue(':notes', $validated['notes'], $validated['notes'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':terms_conditions', $validated['terms_conditions'], $validated['terms_conditions'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        $stmt->execute();

        // Remove old items
        $delStmt = $pdo->prepare('DELETE FROM quotation_items WHERE quotation_id = :id');
        $delStmt->bindValue(':id', $id, PDO::PARAM_INT);
        $delStmt->execute();

        // Insert new items
        $itemSql = "INSERT INTO quotation_items (
                        quotation_id, screen_id, description, quantity, duration_months, unit_price, discount_amount, tax_rate, tax_amount, line_total
                    ) VALUES (
                        :quotation_id, :screen_id, :description, :quantity, :duration_months, :unit_price, 0.00, 0.00, 0.00, :line_total
                    )";
        $itemStmt = $pdo->prepare($itemSql);

        foreach ($validated['items'] as $item) {
            $itemStmt->bindValue(':quotation_id', $id, PDO::PARAM_INT);
            $itemStmt->bindValue(':screen_id', $item['screen_id'], $item['screen_id'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $itemStmt->bindValue(':description', $item['description'], PDO::PARAM_STR);
            $itemStmt->bindValue(':quantity', $item['quantity'], PDO::PARAM_STR);
            $itemStmt->bindValue(':duration_months', $item['duration_months'], PDO::PARAM_STR);
            $itemStmt->bindValue(':unit_price', $item['unit_price'], PDO::PARAM_STR);
            $itemStmt->bindValue(':line_total', $item['line_total'], PDO::PARAM_STR);
            $itemStmt->execute();
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
 * Action: Submit quotation for approval
 */
function handleActionSubmit(PDO $pdo): void
{
    $id = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : null;
    if ($id === false || $id === null || $id <= 0) {
        sendErrorResponse('Quotation ID is required.', 400);
    }

    $stmt = $pdo->prepare('SELECT id, status FROM quotations WHERE id = :id');
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $quotation = $stmt->fetch();

    if (!$quotation) {
        sendErrorResponse('Quotation not found.', 404);
    }

    if (in_array($quotation['status'], ['approved', 'converted'], true)) {
        sendErrorResponse('Approved or converted quotations cannot be submitted again.', 409);
    }

    if ($quotation['status'] === 'pending_approval') {
        sendErrorResponse('Quotation is already pending approval.', 400);
    }

    $updateStmt = $pdo->prepare("UPDATE quotations SET status = 'pending_approval', updated_at = CURRENT_TIMESTAMP WHERE id = :id");
    $updateStmt->bindValue(':id', $id, PDO::PARAM_INT);
    $updateStmt->execute();

    sendSuccessResponse('Quotation submitted for approval.');
}

/**
 * Action: Approve quotation
 */
function handleActionApprove(PDO $pdo): void
{
    requireRole(['owner', 'manager']);

    $id = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : null;
    if ($id === false || $id === null || $id <= 0) {
        sendErrorResponse('Quotation ID is required.', 400);
    }

    $stmt = $pdo->prepare('SELECT id, status FROM quotations WHERE id = :id');
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $quotation = $stmt->fetch();

    if (!$quotation) {
        sendErrorResponse('Quotation not found.', 404);
    }

    if ($quotation['status'] !== 'pending_approval') {
        sendErrorResponse('Only quotations with pending_approval status can be approved.', 400);
    }

    $userId = getCurrentUserId();
    $updateSql = "UPDATE quotations SET 
                    status = 'approved', 
                    approved_by = :approved_by, 
                    approved_at = CURRENT_TIMESTAMP, 
                    updated_at = CURRENT_TIMESTAMP 
                  WHERE id = :id";

    $updateStmt = $pdo->prepare($updateSql);
    $updateStmt->bindValue(':approved_by', $userId, $userId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $updateStmt->bindValue(':id', $id, PDO::PARAM_INT);
    $updateStmt->execute();

    // Server-Side Automated Email Trigger (Failure Isolated & Duplicate Protected)
    try {
        require_once __DIR__ . '/../includes/email/EmailDispatcher.php';
        $dispatcher = new EmailDispatcher($pdo);
        $dispatcher->sendAutomatedQuotation($id);
    } catch (Throwable $e) {
        error_log('[Quotation Approval Auto-Email Exception] ' . $e->getMessage());
    }

    sendSuccessResponse('Quotation approved successfully.', [
        'quotation_id' => $id,
        'status' => 'approved'
    ]);
}

/**
 * Action: Reject quotation
 */
function handleActionReject(PDO $pdo): void
{
    requireRole(['owner', 'manager']);

    $id = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : null;
    if ($id === false || $id === null || $id <= 0) {
        sendErrorResponse('Quotation ID is required.', 400);
    }

    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    $reason = is_array($input) && isset($input['reason']) ? trim((string) $input['reason']) : '';

    $stmt = $pdo->prepare('SELECT id, status FROM quotations WHERE id = :id');
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $quotation = $stmt->fetch();

    if (!$quotation) {
        sendErrorResponse('Quotation not found.', 404);
    }

    if ($quotation['status'] !== 'pending_approval') {
        sendErrorResponse('Only quotations with pending_approval status can be rejected.', 400);
    }

    $updateSql = "UPDATE quotations SET 
                    status = 'rejected', 
                    rejected_reason = :reason, 
                    updated_at = CURRENT_TIMESTAMP 
                  WHERE id = :id";

    $updateStmt = $pdo->prepare($updateSql);
    $updateStmt->bindValue(':reason', $reason === '' ? null : $reason, $reason === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $updateStmt->bindValue(':id', $id, PDO::PARAM_INT);
    $updateStmt->execute();

    sendSuccessResponse('Quotation rejected successfully.');
}

/**
 * DELETE Quotation
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
        sendErrorResponse('Quotation ID is required.', 400);
    }

    $stmt = $pdo->prepare('SELECT id, status FROM quotations WHERE id = :id');
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $quotation = $stmt->fetch();

    if (!$quotation) {
        sendErrorResponse('Quotation not found.', 404);
    }

    if (in_array($quotation['status'], ['approved', 'converted'], true)) {
        sendErrorResponse('Approved or converted quotations cannot be deleted.', 409);
    }

    $pdo->beginTransaction();

    try {
        $delItems = $pdo->prepare('DELETE FROM quotation_items WHERE quotation_id = :id');
        $delItems->bindValue(':id', $id, PDO::PARAM_INT);
        $delItems->execute();

        $delQuote = $pdo->prepare('DELETE FROM quotations WHERE id = :id');
        $delQuote->bindValue(':id', $id, PDO::PARAM_INT);
        $delQuote->execute();

        $pdo->commit();

        sendSuccessResponse('Quotation deleted successfully.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Validate input and calculate pricing, discounts, and GST rates for quotation
 */
function validateQuotationInput(PDO $pdo, array $input): array
{
    $customerId = isset($input['customer_id']) ? filter_var($input['customer_id'], FILTER_VALIDATE_INT) : null;
    if ($customerId === false || $customerId === null || $customerId <= 0) {
        sendErrorResponse('Valid customer_id is required.', 400);
    }

    // Verify customer exists and is active
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

    $quotationDate = isset($input['quotation_date']) ? trim((string) $input['quotation_date']) : date('Y-m-d');
    $qDateObj = DateTime::createFromFormat('Y-m-d', $quotationDate);
    if (!$qDateObj || $qDateObj->format('Y-m-d') !== $quotationDate) {
        sendErrorResponse('Invalid quotation_date format. Use YYYY-MM-DD.', 400);
    }

    if (isset($input['valid_until']) && trim((string) $input['valid_until']) !== '') {
        $validUntil = trim((string) $input['valid_until']);
        $vDateObj = DateTime::createFromFormat('Y-m-d', $validUntil);
        if (!$vDateObj || $vDateObj->format('Y-m-d') !== $validUntil) {
            sendErrorResponse('Invalid valid_until date format. Use YYYY-MM-DD.', 400);
        }
        if ($qDateObj > $vDateObj) {
            sendErrorResponse('valid_until date must be equal to or after quotation_date.', 400);
        }
    } else {
        // Fetch validity days setting (default 15)
        $valDays = 15;
        $setStmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'quotation_validity_days'");
        $setStmt->execute();
        $setRow = $setStmt->fetch();
        if ($setRow && is_numeric($setRow['setting_value'])) {
            $valDays = (int) $setRow['setting_value'];
        }
        $validUntil = (clone $qDateObj)->modify("+{$valDays} days")->format('Y-m-d');
    }

    $itemsRaw = $input['items'] ?? null;
    if (!is_array($itemsRaw) || count($itemsRaw) === 0) {
        sendErrorResponse('At least one item is required in the quotation.', 400);
    }

    $processedItems = [];
    $subtotal = 0.0;

    foreach ($itemsRaw as $index => $item) {
        if (!is_array($item)) {
            sendErrorResponse("Item at index {$index} must be an object.", 400);
        }

        $desc = isset($item['description']) ? trim((string) $item['description']) : '';
        if ($desc === '') {
            sendErrorResponse("Item description is required at index {$index}.", 400);
        }

        $qtyRaw = $item['quantity'] ?? null;
        if ($qtyRaw === null || !is_numeric($qtyRaw) || (float) $qtyRaw <= 0) {
            sendErrorResponse("Quantity must be a positive number at index {$index}.", 400);
        }
        $quantity = (float) $qtyRaw;

        $durationRaw = $item['duration_months'] ?? null;
        if ($durationRaw === null || !is_numeric($durationRaw) || (float) $durationRaw <= 0) {
            sendErrorResponse("Duration in months must be a positive number at index {$index}.", 400);
        }
        $durationMonths = (float) $durationRaw;

        $unitPriceRaw = $item['unit_price'] ?? null;
        if ($unitPriceRaw === null || !is_numeric($unitPriceRaw) || (float) $unitPriceRaw < 0) {
            sendErrorResponse("Unit price must be a non-negative number at index {$index}.", 400);
        }
        $unitPrice = (float) $unitPriceRaw;

        $screenId = isset($item['screen_id']) ? filter_var($item['screen_id'], FILTER_VALIDATE_INT) : null;
        if ($screenId !== false && $screenId !== null && $screenId > 0) {
            $screenStmt = $pdo->prepare('SELECT id, status FROM screens WHERE id = :id');
            $screenStmt->bindValue(':id', $screenId, PDO::PARAM_INT);
            $screenStmt->execute();
            $screen = $screenStmt->fetch();

            if (!$screen) {
                sendErrorResponse("Screen ID {$screenId} not found at index {$index}.", 400);
            }
            if ($screen['status'] === 'inactive') {
                sendErrorResponse("Screen ID {$screenId} is inactive at index {$index}.", 400);
            }
        } else {
            $screenId = null;
        }

        $lineTotal = round($quantity * $durationMonths * $unitPrice, 2);
        $subtotal += $lineTotal;

        $processedItems[] = [
            'screen_id' => $screenId,
            'description' => $desc,
            'quantity' => number_format($quantity, 2, '.', ''),
            'duration_months' => number_format($durationMonths, 2, '.', ''),
            'unit_price' => number_format($unitPrice, 2, '.', ''),
            'line_total' => number_format($lineTotal, 2, '.', ''),
        ];
    }

    $subtotal = round($subtotal, 2);

    $discountType = isset($input['discount_type']) ? trim((string) $input['discount_type']) : 'fixed';
    if (!in_array($discountType, ['fixed', 'percentage'], true)) {
        sendErrorResponse('Invalid discount_type. Must be fixed or percentage.', 400);
    }

    $discountValRaw = $input['discount_value'] ?? 0;
    if (!is_numeric($discountValRaw) || (float) $discountValRaw < 0) {
        sendErrorResponse('Discount value must be a non-negative number.', 400);
    }
    $discountValue = (float) $discountValRaw;

    if ($discountType === 'percentage') {
        if ($discountValue > 100) {
            sendErrorResponse('Discount percentage cannot exceed 100%.', 400);
        }
        $discountAmount = round(($subtotal * $discountValue) / 100, 2);
    } else {
        $discountAmount = round($discountValue, 2);
    }

    if ($discountAmount > $subtotal) {
        sendErrorResponse('Discount amount cannot exceed subtotal.', 400);
    }

    $taxableAmount = round($subtotal - $discountAmount, 2);

    // GST Rates validation
    $cgstRateRaw = $input['cgst_rate'] ?? 0;
    $sgstRateRaw = $input['sgst_rate'] ?? 0;
    $igstRateRaw = $input['igst_rate'] ?? 0;

    if (!is_numeric($cgstRateRaw) || (float) $cgstRateRaw < 0 ||
        !is_numeric($sgstRateRaw) || (float) $sgstRateRaw < 0 ||
        !is_numeric($igstRateRaw) || (float) $igstRateRaw < 0) {
        sendErrorResponse('GST rates must be non-negative numbers.', 400);
    }

    $cgstRate = (float) $cgstRateRaw;
    $sgstRate = (float) $sgstRateRaw;
    $igstRate = (float) $igstRateRaw;

    // Validate GST Combination: CGST+SGST OR IGST
    if ($igstRate > 0 && ($cgstRate > 0 || $sgstRate > 0)) {
        sendErrorResponse('Invalid GST combination. Use either CGST+SGST or IGST, not both.', 400);
    }

    $cgstAmount = round(($taxableAmount * $cgstRate) / 100, 2);
    $sgstAmount = round(($taxableAmount * $sgstRate) / 100, 2);
    $igstAmount = round(($taxableAmount * $igstRate) / 100, 2);

    $totalAmount = round($taxableAmount + $cgstAmount + $sgstAmount + $igstAmount, 2);

    $notes = isset($input['notes']) && trim((string) $input['notes']) !== '' ? trim((string) $input['notes']) : null;

    $termsConditions = isset($input['terms_conditions']) && trim((string) $input['terms_conditions']) !== ''
        ? trim((string) $input['terms_conditions'])
        : null;

    if ($termsConditions === null) {
        $termStmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'terms_conditions'");
        $termStmt->execute();
        $termRow = $termStmt->fetch();
        if ($termRow && !empty($termRow['setting_value'])) {
            $termsConditions = trim($termRow['setting_value']);
        }
    }

    return [
        'customer_id' => $customerId,
        'quotation_date' => $quotationDate,
        'valid_until' => $validUntil,
        'items' => $processedItems,
        'subtotal' => number_format($subtotal, 2, '.', ''),
        'discount_type' => $discountType,
        'discount_value' => number_format($discountValue, 2, '.', ''),
        'discount_amount' => number_format($discountAmount, 2, '.', ''),
        'taxable_amount' => number_format($taxableAmount, 2, '.', ''),
        'cgst_rate' => number_format($cgstRate, 2, '.', ''),
        'cgst_amount' => number_format($cgstAmount, 2, '.', ''),
        'sgst_rate' => number_format($sgstRate, 2, '.', ''),
        'sgst_amount' => number_format($sgstAmount, 2, '.', ''),
        'igst_rate' => number_format($igstRate, 2, '.', ''),
        'igst_amount' => number_format($igstAmount, 2, '.', ''),
        'total_amount' => number_format($totalAmount, 2, '.', ''),
        'notes' => $notes,
        'terms_conditions' => $termsConditions,
    ];
}

/**
 * Generate next quotation number (QT-1001 format)
 */
function generateQuotationNumber(PDO $pdo): string
{
    $prefix = 'QT-';
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'quotation_prefix'");
    $stmt->execute();
    $row = $stmt->fetch();
    if ($row && !empty($row['setting_value'])) {
        $prefix = trim($row['setting_value']);
    }

    $lastStmt = $pdo->prepare("SELECT quotation_number FROM quotations ORDER BY id DESC LIMIT 1 FOR UPDATE");
    $lastStmt->execute();
    $lastRow = $lastStmt->fetch();

    $nextNum = 1001;
    if ($lastRow && !empty($lastRow['quotation_number'])) {
        $lastNumStr = $lastRow['quotation_number'];
        if (preg_match('/(\d+)$/', $lastNumStr, $matches)) {
            $nextNum = ((int) $matches[1]) + 1;
        }
    }

    return $prefix . $nextNum;
}

/**
 * Format quotation DB row to response structure
 */
function formatQuotationRow(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'quotation_number' => $row['quotation_number'],
        'customer_id' => (int) $row['customer_id'],
        'customer' => [
            'id' => (int) $row['customer_id'],
            'company_name' => $row['company_name'],
            'contact_person' => $row['contact_person'],
            'email' => $row['customer_email'],
            'phone' => $row['customer_phone'],
        ],
        'quotation_date' => $row['quotation_date'],
        'valid_until' => $row['valid_until'],
        'subtotal' => (float) $row['subtotal'],
        'discount_type' => $row['discount_type'],
        'discount_value' => (float) $row['discount_value'],
        'discount_amount' => (float) $row['discount_amount'],
        'taxable_amount' => (float) $row['taxable_amount'],
        'cgst_rate' => (float) $row['cgst_rate'],
        'cgst_amount' => (float) $row['cgst_amount'],
        'sgst_rate' => (float) $row['sgst_rate'],
        'sgst_amount' => (float) $row['sgst_amount'],
        'igst_rate' => (float) $row['igst_rate'],
        'igst_amount' => (float) $row['igst_amount'],
        'total_amount' => (float) $row['total_amount'],
        'currency' => $row['currency'],
        'status' => $row['status'],
        'notes' => $row['notes'],
        'terms_conditions' => $row['terms_conditions'],
        'created_by' => $row['created_by'] !== null ? (int) $row['created_by'] : null,
        'approved_by' => $row['approved_by'] !== null ? (int) $row['approved_by'] : null,
        'approved_at' => $row['approved_at'],
        'rejected_reason' => $row['rejected_reason'],
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
    ];
}
