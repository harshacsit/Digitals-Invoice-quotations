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
    if ($method === 'POST' && $action === 'from_quotation') {
        handleCreateFromQuotation($pdo);
    } else {
        switch ($method) {
            case 'GET':
                if (isset($_GET['id'])) {
                    handleGetSingle($pdo);
                } else {
                    handleGetList($pdo);
                }
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
 * GET List of Invoices (paginated, with search & filters)
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
    $quotationId = isset($_GET['quotation_id']) ? filter_var($_GET['quotation_id'], FILTER_VALIDATE_INT) : null;
    $status = isset($_GET['status']) ? trim((string) $_GET['status']) : '';
    $fromDate = isset($_GET['from_date']) ? trim((string) $_GET['from_date']) : '';
    $toDate = isset($_GET['to_date']) ? trim((string) $_GET['to_date']) : '';

    $whereClauses = [];
    $params = [];

    if ($search !== '') {
        $whereClauses[] = '(i.invoice_number LIKE :s_inv OR c.company_name LIKE :s_cn OR c.contact_person LIKE :s_cp)';
        $params[':s_inv'] = '%' . $search . '%';
        $params[':s_cn'] = '%' . $search . '%';
        $params[':s_cp'] = '%' . $search . '%';
    }

    if ($customerId !== false && $customerId !== null && $customerId > 0) {
        $whereClauses[] = 'i.customer_id = :customer_id';
        $params[':customer_id'] = $customerId;
    }

    if ($quotationId !== false && $quotationId !== null && $quotationId > 0) {
        $whereClauses[] = 'i.quotation_id = :quotation_id';
        $params[':quotation_id'] = $quotationId;
    }

    if ($status !== '') {
        if ($status === 'overdue') {
            $whereClauses[] = "i.status != 'cancelled' AND i.balance_amount > 0 AND CURRENT_DATE() > i.due_date";
        } else {
            $whereClauses[] = 'i.status = :status';
            $params[':status'] = $status;
        }
    }

    if ($fromDate !== '') {
        $whereClauses[] = 'i.invoice_date >= :from_date';
        $params[':from_date'] = $fromDate;
    }

    if ($toDate !== '') {
        $whereClauses[] = 'i.invoice_date <= :to_date';
        $params[':to_date'] = $toDate;
    }

    $whereSql = $whereClauses !== [] ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

    // Count query
    $countSql = "SELECT COUNT(*) FROM invoices i JOIN customers c ON i.customer_id = c.id {$whereSql}";
    $countStmt = $pdo->prepare($countSql);
    foreach ($params as $key => $val) {
        $countStmt->bindValue($key, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $countStmt->execute();
    $total = (int) $countStmt->fetchColumn();

    $totalPages = $total > 0 ? (int) ceil($total / $limit) : 0;
    $offset = ($page - 1) * $limit;

    // Fetch records with computed dynamic status rule
    $sql = "SELECT 
                i.id,
                i.invoice_number,
                i.quotation_id,
                i.customer_id,
                i.invoice_date,
                i.due_date,
                i.subtotal,
                i.discount_amount,
                i.taxable_amount,
                i.cgst_rate,
                i.cgst_amount,
                i.sgst_rate,
                i.sgst_amount,
                i.igst_rate,
                i.igst_amount,
                i.total_amount,
                i.paid_amount,
                i.balance_amount,
                i.currency,
                CASE
                    WHEN i.status = 'cancelled' THEN 'cancelled'
                    WHEN i.paid_amount >= i.total_amount THEN 'paid'
                    WHEN i.paid_amount > 0 THEN 'partial'
                    WHEN i.balance_amount > 0 AND CURRENT_DATE() > i.due_date THEN 'overdue'
                    ELSE 'unpaid'
                END AS computed_status,
                i.notes,
                i.terms_conditions,
                i.created_by,
                i.created_at,
                i.updated_at,
                c.company_name,
                c.contact_person,
                c.email AS customer_email,
                c.phone AS customer_phone
            FROM invoices i
            JOIN customers c ON i.customer_id = c.id
            {$whereSql}
            ORDER BY i.id DESC
            LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $invoices = array_map(static function (array $row): array {
        return formatInvoiceRow($row);
    }, $rows);

    $pagination = [
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'total_pages' => $totalPages,
    ];

    sendSuccessResponse('Invoices fetched successfully.', $invoices, 200, $pagination);
}

/**
 * GET Single Invoice with Customer, Quotation and Items details
 */
function handleGetSingle(PDO $pdo): void
{
    $id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
    if ($id === false || $id <= 0) {
        sendErrorResponse('Invalid invoice ID.', 400);
    }

    $sql = "SELECT 
                i.id,
                i.invoice_number,
                i.quotation_id,
                i.customer_id,
                i.invoice_date,
                i.due_date,
                i.subtotal,
                i.discount_amount,
                i.taxable_amount,
                i.cgst_rate,
                i.cgst_amount,
                i.sgst_rate,
                i.sgst_amount,
                i.igst_rate,
                i.igst_amount,
                i.total_amount,
                i.paid_amount,
                i.balance_amount,
                i.currency,
                CASE
                    WHEN i.status = 'cancelled' THEN 'cancelled'
                    WHEN i.paid_amount >= i.total_amount THEN 'paid'
                    WHEN i.paid_amount > 0 THEN 'partial'
                    WHEN i.balance_amount > 0 AND CURRENT_DATE() > i.due_date THEN 'overdue'
                    ELSE 'unpaid'
                END AS computed_status,
                i.notes,
                i.terms_conditions,
                i.created_by,
                i.created_at,
                i.updated_at,
                c.company_name,
                c.contact_person,
                c.email AS customer_email,
                c.phone AS customer_phone
            FROM invoices i
            JOIN customers c ON i.customer_id = c.id
            WHERE i.id = :id";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch();

    if (!$row) {
        sendErrorResponse('Invoice not found.', 404);
    }

    $invoice = formatInvoiceRow($row);

    // Linked quotation details if available
    if ($invoice['quotation_id'] !== null) {
        $qStmt = $pdo->prepare('SELECT id, quotation_number, status FROM quotations WHERE id = :qid');
        $qStmt->bindValue(':qid', $invoice['quotation_id'], PDO::PARAM_INT);
        $qStmt->execute();
        $qRow = $qStmt->fetch();
        if ($qRow) {
            $invoice['quotation'] = [
                'id' => (int) $qRow['id'],
                'quotation_number' => $qRow['quotation_number'],
                'status' => $qRow['status'],
            ];
        } else {
            $invoice['quotation'] = null;
        }
    } else {
        $invoice['quotation'] = null;
    }

    // Fetch items
    $itemSql = "SELECT 
                    ii.id,
                    ii.invoice_id,
                    ii.screen_id,
                    ii.description,
                    ii.quantity,
                    ii.duration_months,
                    ii.unit_price,
                    ii.discount_amount,
                    ii.tax_rate,
                    ii.tax_amount,
                    ii.line_total,
                    s.name AS screen_name,
                    s.screen_type,
                    s.location AS screen_location,
                    s.city AS screen_city
                FROM invoice_items ii
                LEFT JOIN screens s ON ii.screen_id = s.id
                WHERE ii.invoice_id = :id
                ORDER BY ii.id ASC";

    $itemStmt = $pdo->prepare($itemSql);
    $itemStmt->bindValue(':id', $id, PDO::PARAM_INT);
    $itemStmt->execute();
    $itemRows = $itemStmt->fetchAll();

    $invoice['items'] = array_map(static function (array $item): array {
        return [
            'id' => (int) $item['id'],
            'invoice_id' => (int) $item['invoice_id'],
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

    sendSuccessResponse('Invoice fetched successfully.', $invoice);
}

/**
 * POST Convert Approved Quotation -> Invoice
 * Endpoint: POST /api/invoices.php?action=from_quotation&quotation_id=1
 */
function handleCreateFromQuotation(PDO $pdo): void
{
    $quotationId = isset($_GET['quotation_id']) ? filter_var($_GET['quotation_id'], FILTER_VALIDATE_INT) : null;
    if (!$quotationId) {
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);
        if (is_array($input) && isset($input['quotation_id'])) {
            $quotationId = filter_var($input['quotation_id'], FILTER_VALIDATE_INT);
        }
    }

    if ($quotationId === false || $quotationId === null || $quotationId <= 0) {
        sendErrorResponse('Valid quotation_id is required.', 400);
    }

    $pdo->beginTransaction();

    try {
        // 1. Lock and check quotation
        $qStmt = $pdo->prepare('SELECT * FROM quotations WHERE id = :id FOR UPDATE');
        $qStmt->bindValue(':id', $quotationId, PDO::PARAM_INT);
        $qStmt->execute();
        $quotation = $qStmt->fetch();

        if (!$quotation) {
            $pdo->rollBack();
            sendErrorResponse('Quotation not found.', 404);
        }

        if ($quotation['status'] !== 'approved') {
            $pdo->rollBack();
            sendErrorResponse('Only approved quotations can be converted to invoices.', 409);
        }

        // 2. Check duplicate invoice for this quotation
        $invCheck = $pdo->prepare('SELECT id FROM invoices WHERE quotation_id = :qid FOR UPDATE');
        $invCheck->bindValue(':qid', $quotationId, PDO::PARAM_INT);
        $invCheck->execute();
        if ($invCheck->fetch()) {
            $pdo->rollBack();
            sendErrorResponse('An invoice already exists for this quotation.', 409);
        }

        // 3. Verify customer is active
        $custStmt = $pdo->prepare('SELECT id, status FROM customers WHERE id = :cid FOR UPDATE');
        $custStmt->bindValue(':cid', $quotation['customer_id'], PDO::PARAM_INT);
        $custStmt->execute();
        $customer = $custStmt->fetch();

        if (!$customer) {
            $pdo->rollBack();
            sendErrorResponse('Customer not found.', 404);
        }
        if ($customer['status'] !== 'active') {
            $pdo->rollBack();
            sendErrorResponse('Customer is inactive.', 400);
        }

        // 4. Fetch quotation items
        $qiStmt = $pdo->prepare('SELECT * FROM quotation_items WHERE quotation_id = :qid ORDER BY id ASC');
        $qiStmt->bindValue(':qid', $quotationId, PDO::PARAM_INT);
        $qiStmt->execute();
        $quotationItems = $qiStmt->fetchAll();

        if (count($quotationItems) === 0) {
            $pdo->rollBack();
            sendErrorResponse('Quotation has no items.', 400);
        }

        // 5. Generate Invoice Number & Dates
        $invoiceNumber = generateInvoiceNumber($pdo);
        $invoiceDate = date('Y-m-d');

        $dueDays = 15;
        $setStmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'payment_due_days'");
        $setStmt->execute();
        $setRow = $setStmt->fetch();
        if ($setRow && is_numeric($setRow['setting_value'])) {
            $dueDays = (int) $setRow['setting_value'];
        }
        $dueDate = date('Y-m-d', strtotime("+{$dueDays} days"));

        // 6. Insert into invoices table
        $sql = "INSERT INTO invoices (
                    invoice_number, quotation_id, customer_id, invoice_date, due_date,
                    subtotal, discount_amount, taxable_amount, cgst_rate, cgst_amount,
                    sgst_rate, sgst_amount, igst_rate, igst_amount, total_amount,
                    paid_amount, balance_amount, currency, status, notes, terms_conditions, created_by
                ) VALUES (
                    :invoice_number, :quotation_id, :customer_id, :invoice_date, :due_date,
                    :subtotal, :discount_amount, :taxable_amount, :cgst_rate, :cgst_amount,
                    :sgst_rate, :sgst_amount, :igst_rate, :igst_amount, :total_amount,
                    0.00, :balance_amount, :currency, 'unpaid', :notes, :terms_conditions, :created_by
                )";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':invoice_number', $invoiceNumber, PDO::PARAM_STR);
        $stmt->bindValue(':quotation_id', $quotationId, PDO::PARAM_INT);
        $stmt->bindValue(':customer_id', $quotation['customer_id'], PDO::PARAM_INT);
        $stmt->bindValue(':invoice_date', $invoiceDate, PDO::PARAM_STR);
        $stmt->bindValue(':due_date', $dueDate, PDO::PARAM_STR);
        $stmt->bindValue(':subtotal', $quotation['subtotal'], PDO::PARAM_STR);
        $stmt->bindValue(':discount_amount', $quotation['discount_amount'], PDO::PARAM_STR);
        $stmt->bindValue(':taxable_amount', $quotation['taxable_amount'], PDO::PARAM_STR);
        $stmt->bindValue(':cgst_rate', $quotation['cgst_rate'], PDO::PARAM_STR);
        $stmt->bindValue(':cgst_amount', $quotation['cgst_amount'], PDO::PARAM_STR);
        $stmt->bindValue(':sgst_rate', $quotation['sgst_rate'], PDO::PARAM_STR);
        $stmt->bindValue(':sgst_amount', $quotation['sgst_amount'], PDO::PARAM_STR);
        $stmt->bindValue(':igst_rate', $quotation['igst_rate'], PDO::PARAM_STR);
        $stmt->bindValue(':igst_amount', $quotation['igst_amount'], PDO::PARAM_STR);
        $stmt->bindValue(':total_amount', $quotation['total_amount'], PDO::PARAM_STR);
        $stmt->bindValue(':balance_amount', $quotation['total_amount'], PDO::PARAM_STR);
        $stmt->bindValue(':currency', $quotation['currency'] ?? 'INR', PDO::PARAM_STR);
        $stmt->bindValue(':notes', $quotation['notes'], $quotation['notes'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':terms_conditions', $quotation['terms_conditions'], $quotation['terms_conditions'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':created_by', getCurrentUserId(), getCurrentUserId() === null ? PDO::PARAM_NULL : PDO::PARAM_INT);

        $stmt->execute();
        $newInvoiceId = (int) $pdo->lastInsertId();

        // 7. Copy items to invoice_items table
        $itemSql = "INSERT INTO invoice_items (
                        invoice_id, screen_id, description, quantity, duration_months, unit_price, discount_amount, tax_rate, tax_amount, line_total
                    ) VALUES (
                        :invoice_id, :screen_id, :description, :quantity, :duration_months, :unit_price, :discount_amount, :tax_rate, :tax_amount, :line_total
                    )";
        $itemStmt = $pdo->prepare($itemSql);

        foreach ($quotationItems as $item) {
            $itemStmt->bindValue(':invoice_id', $newInvoiceId, PDO::PARAM_INT);
            $itemStmt->bindValue(':screen_id', $item['screen_id'], $item['screen_id'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $itemStmt->bindValue(':description', $item['description'], PDO::PARAM_STR);
            $itemStmt->bindValue(':quantity', $item['quantity'], PDO::PARAM_STR);
            $itemStmt->bindValue(':duration_months', $item['duration_months'], PDO::PARAM_STR);
            $itemStmt->bindValue(':unit_price', $item['unit_price'], PDO::PARAM_STR);
            $itemStmt->bindValue(':discount_amount', $item['discount_amount'], PDO::PARAM_STR);
            $itemStmt->bindValue(':tax_rate', $item['tax_rate'], PDO::PARAM_STR);
            $itemStmt->bindValue(':tax_amount', $item['tax_amount'], PDO::PARAM_STR);
            $itemStmt->bindValue(':line_total', $item['line_total'], PDO::PARAM_STR);
            $itemStmt->execute();
        }

        // 8. Update quotation status to converted
        $updateQ = $pdo->prepare("UPDATE quotations SET status = 'converted', updated_at = CURRENT_TIMESTAMP WHERE id = :qid");
        $updateQ->bindValue(':qid', $quotationId, PDO::PARAM_INT);
        $updateQ->execute();

        $pdo->commit();

        // Server-Side Automated Email Trigger (Failure Isolated & Duplicate Protected)
        try {
            require_once __DIR__ . '/../includes/email/EmailDispatcher.php';
            $dispatcher = new EmailDispatcher($pdo);
            $dispatcher->sendAutomatedInvoice($newInvoiceId);
        } catch (Throwable $e) {
            error_log('[Invoice Creation Auto-Email Exception] ' . $e->getMessage());
        }

        $_GET['id'] = $newInvoiceId;
        handleGetSingle($pdo);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * PUT Update Invoice Metadata (restricted to metadata only: due_date, notes, terms_conditions)
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
        sendErrorResponse('Invoice ID is required.', 400);
    }

    $stmt = $pdo->prepare('SELECT * FROM invoices WHERE id = :id');
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $existing = $stmt->fetch();

    if (!$existing) {
        sendErrorResponse('Invoice not found.', 404);
    }

    if ($existing['status'] === 'cancelled') {
        sendErrorResponse('Cancelled invoices cannot be edited.', 409);
    }

    $dueDate = $existing['due_date'];
    if (array_key_exists('due_date', $input) && trim((string) $input['due_date']) !== '') {
        $dueDateCandidate = trim((string) $input['due_date']);
        $dObj = DateTime::createFromFormat('Y-m-d', $dueDateCandidate);
        if (!$dObj || $dObj->format('Y-m-d') !== $dueDateCandidate) {
            sendErrorResponse('Invalid due_date format. Use YYYY-MM-DD.', 400);
        }
        if ($dueDateCandidate < $existing['invoice_date']) {
            sendErrorResponse('due_date cannot be earlier than invoice_date.', 400);
        }
        $dueDate = $dueDateCandidate;
    }

    $notes = array_key_exists('notes', $input) ? (trim((string) $input['notes']) !== '' ? trim((string) $input['notes']) : null) : $existing['notes'];
    $terms = array_key_exists('terms_conditions', $input) ? (trim((string) $input['terms_conditions']) !== '' ? trim((string) $input['terms_conditions']) : null) : $existing['terms_conditions'];

    $updateSql = "UPDATE invoices SET 
                    due_date = :due_date,
                    notes = :notes,
                    terms_conditions = :terms_conditions,
                    updated_at = CURRENT_TIMESTAMP
                  WHERE id = :id";

    $updateStmt = $pdo->prepare($updateSql);
    $updateStmt->bindValue(':due_date', $dueDate, PDO::PARAM_STR);
    $updateStmt->bindValue(':notes', $notes, $notes === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $updateStmt->bindValue(':terms_conditions', $terms, $terms === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $updateStmt->bindValue(':id', $id, PDO::PARAM_INT);

    $updateStmt->execute();

    $_GET['id'] = $id;
    handleGetSingle($pdo);
}

/**
 * DELETE Soft-cancel Invoice (sets status = 'cancelled')
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
        sendErrorResponse('Invoice ID is required.', 400);
    }

    $stmt = $pdo->prepare('SELECT id, paid_amount, status FROM invoices WHERE id = :id');
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $invoice = $stmt->fetch();

    if (!$invoice) {
        sendErrorResponse('Invoice not found.', 404);
    }

    if ((float) $invoice['paid_amount'] > 0) {
        sendErrorResponse('Invoice with payments cannot be cancelled.', 409);
    }

    $updateStmt = $pdo->prepare("UPDATE invoices SET status = 'cancelled', updated_at = CURRENT_TIMESTAMP WHERE id = :id");
    $updateStmt->bindValue(':id', $id, PDO::PARAM_INT);
    $updateStmt->execute();

    sendSuccessResponse('Invoice cancelled successfully.');
}

/**
 * Generate next invoice number (INV-1001 format)
 */
function generateInvoiceNumber(PDO $pdo): string
{
    $prefix = 'INV-';
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'invoice_prefix'");
    $stmt->execute();
    $row = $stmt->fetch();
    if ($row && !empty($row['setting_value'])) {
        $prefix = trim($row['setting_value']);
    }

    $lastStmt = $pdo->prepare("SELECT invoice_number FROM invoices ORDER BY id DESC LIMIT 1 FOR UPDATE");
    $lastStmt->execute();
    $lastRow = $lastStmt->fetch();

    $nextNum = 1001;
    if ($lastRow && !empty($lastRow['invoice_number'])) {
        $lastNumStr = $lastRow['invoice_number'];
        if (preg_match('/(\d+)$/', $lastNumStr, $matches)) {
            $nextNum = ((int) $matches[1]) + 1;
        }
    }

    return $prefix . $nextNum;
}

/**
 * Format invoice DB row to response structure
 */
function formatInvoiceRow(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'invoice_number' => $row['invoice_number'],
        'quotation_id' => $row['quotation_id'] !== null ? (int) $row['quotation_id'] : null,
        'customer_id' => (int) $row['customer_id'],
        'customer' => [
            'id' => (int) $row['customer_id'],
            'company_name' => $row['company_name'],
            'contact_person' => $row['contact_person'],
            'email' => $row['customer_email'],
            'phone' => $row['customer_phone'],
        ],
        'invoice_date' => $row['invoice_date'],
        'due_date' => $row['due_date'],
        'subtotal' => (float) $row['subtotal'],
        'discount_amount' => (float) $row['discount_amount'],
        'taxable_amount' => (float) $row['taxable_amount'],
        'cgst_rate' => (float) $row['cgst_rate'],
        'cgst_amount' => (float) $row['cgst_amount'],
        'sgst_rate' => (float) $row['sgst_rate'],
        'sgst_amount' => (float) $row['sgst_amount'],
        'igst_rate' => (float) $row['igst_rate'],
        'igst_amount' => (float) $row['igst_amount'],
        'total_amount' => (float) $row['total_amount'],
        'paid_amount' => (float) $row['paid_amount'],
        'balance_amount' => (float) $row['balance_amount'],
        'currency' => $row['currency'],
        'status' => $row['computed_status'] ?? $row['status'],
        'notes' => $row['notes'],
        'terms_conditions' => $row['terms_conditions'],
        'created_by' => $row['created_by'] !== null ? (int) $row['created_by'] : null,
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
    ];
}
