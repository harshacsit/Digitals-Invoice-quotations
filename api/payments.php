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
    if ($method === 'GET' && in_array($action, ['summary', 'customer_summary'], true)) {
        if ($action === 'summary') {
            handleInvoiceSummary($pdo);
        } else {
            handleCustomerSummary($pdo);
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
                if ($action === 'cancel') {
                    sendErrorResponse('Payments cannot be deleted because financial records must be retained.', 409);
                } else {
                    handleCreate($pdo);
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
 * GET Paginated List of Payments
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

    $invoiceId = isset($_GET['invoice_id']) ? filter_var($_GET['invoice_id'], FILTER_VALIDATE_INT) : null;
    $customerId = isset($_GET['customer_id']) ? filter_var($_GET['customer_id'], FILTER_VALIDATE_INT) : null;
    $paymentMethod = isset($_GET['payment_method']) ? trim((string) $_GET['payment_method']) : '';
    $fromDate = isset($_GET['from_date']) ? trim((string) $_GET['from_date']) : '';
    $toDate = isset($_GET['to_date']) ? trim((string) $_GET['to_date']) : '';

    $whereClauses = [];
    $params = [];

    if ($invoiceId !== false && $invoiceId !== null && $invoiceId > 0) {
        $whereClauses[] = 'p.invoice_id = :invoice_id';
        $params[':invoice_id'] = $invoiceId;
    }

    if ($customerId !== false && $customerId !== null && $customerId > 0) {
        $whereClauses[] = 'p.customer_id = :customer_id';
        $params[':customer_id'] = $customerId;
    }

    if ($paymentMethod !== '') {
        $whereClauses[] = 'p.payment_method = :payment_method';
        $params[':payment_method'] = $paymentMethod;
    }

    if ($fromDate !== '') {
        $whereClauses[] = 'p.payment_date >= :from_date';
        $params[':from_date'] = $fromDate;
    }

    if ($toDate !== '') {
        $whereClauses[] = 'p.payment_date <= :to_date';
        $params[':to_date'] = $toDate;
    }

    $whereSql = $whereClauses !== [] ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

    // Count query
    $countSql = "SELECT COUNT(*) FROM payments p JOIN invoices i ON p.invoice_id = i.id JOIN customers c ON p.customer_id = c.id {$whereSql}";
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
                p.id,
                p.invoice_id,
                i.invoice_number,
                p.customer_id,
                c.company_name AS customer_company_name,
                c.contact_person AS customer_contact_person,
                c.email AS customer_email,
                c.phone AS customer_phone,
                p.amount,
                p.payment_date,
                p.payment_method,
                p.payment_reference AS reference_number,
                p.notes,
                'completed' AS status,
                p.recorded_by,
                p.created_at
            FROM payments p
            JOIN invoices i ON p.invoice_id = i.id
            JOIN customers c ON p.customer_id = c.id
            {$whereSql}
            ORDER BY p.id DESC
            LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $payments = array_map(static function (array $row): array {
        return formatPaymentRow($row);
    }, $rows);

    $pagination = [
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'total_pages' => $totalPages,
    ];

    sendSuccessResponse('Payments fetched successfully.', $payments, 200, $pagination);
}

/**
 * GET Single Payment Details
 */
function handleGetSingle(PDO $pdo): void
{
    $id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
    if ($id === false || $id <= 0) {
        sendErrorResponse('Invalid payment ID.', 400);
    }

    $sql = "SELECT 
                p.id,
                p.invoice_id,
                i.invoice_number,
                i.total_amount AS invoice_total_amount,
                i.paid_amount AS invoice_paid_amount,
                i.balance_amount AS invoice_balance_amount,
                i.status AS invoice_status,
                p.customer_id,
                c.company_name AS customer_company_name,
                c.contact_person AS customer_contact_person,
                c.email AS customer_email,
                c.phone AS customer_phone,
                p.amount,
                p.payment_date,
                p.payment_method,
                p.payment_reference AS reference_number,
                p.notes,
                'completed' AS status,
                p.recorded_by,
                p.created_at
            FROM payments p
            JOIN invoices i ON p.invoice_id = i.id
            JOIN customers c ON p.customer_id = c.id
            WHERE p.id = :id";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch();

    if (!$row) {
        sendErrorResponse('Payment not found.', 404);
    }

    $payment = formatPaymentRow($row);

    sendSuccessResponse('Payment fetched successfully.', $payment);
}

/**
 * POST Create Payment (with transaction & row locking)
 */
function handleCreate(PDO $pdo): void
{
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    if (!is_array($input)) {
        sendErrorResponse('Invalid JSON payload.', 400);
    }

    $invoiceId = isset($input['invoice_id']) ? filter_var($input['invoice_id'], FILTER_VALIDATE_INT) : null;
    if ($invoiceId === false || $invoiceId === null || $invoiceId <= 0) {
        sendErrorResponse('Valid invoice_id is required.', 400);
    }

    $amountRaw = $input['amount'] ?? null;
    if ($amountRaw === null || !is_numeric($amountRaw)) {
        sendErrorResponse('Valid payment amount is required.', 400);
    }
    $requestedAmount = round((float) $amountRaw, 2);
    if ($requestedAmount <= 0) {
        sendErrorResponse('Payment amount must be greater than zero.', 400);
    }

    $paymentDate = isset($input['payment_date']) ? trim((string) $input['payment_date']) : date('Y-m-d');
    $dObj = DateTime::createFromFormat('Y-m-d', $paymentDate);
    if (!$dObj || $dObj->format('Y-m-d') !== $paymentDate) {
        sendErrorResponse('Invalid payment_date format. Use YYYY-MM-DD.', 400);
    }

    $paymentMethod = isset($input['payment_method']) ? trim((string) $input['payment_method']) : '';
    $allowedMethods = ['cash', 'bank_transfer', 'upi', 'card', 'cheque', 'other'];
    if (!in_array($paymentMethod, $allowedMethods, true)) {
        sendErrorResponse('Invalid payment method. Allowed values: cash, bank_transfer, upi, card, cheque, other.', 400);
    }

    $referenceNumber = isset($input['reference_number']) ? trim((string) $input['reference_number']) : null;
    if ($referenceNumber === null && isset($input['payment_reference'])) {
        $referenceNumber = trim((string) $input['payment_reference']);
    }
    if ($referenceNumber === '') {
        $referenceNumber = null;
    }

    $notes = isset($input['notes']) && trim((string) $input['notes']) !== '' ? trim((string) $input['notes']) : null;

    $pdo->beginTransaction();

    try {
        // 1. Lock invoice row
        $invStmt = $pdo->prepare('SELECT id, customer_id, total_amount, paid_amount, balance_amount, due_date, status FROM invoices WHERE id = :id FOR UPDATE');
        $invStmt->bindValue(':id', $invoiceId, PDO::PARAM_INT);
        $invStmt->execute();
        $invoice = $invStmt->fetch();

        if (!$invoice) {
            $pdo->rollBack();
            sendErrorResponse('Invoice not found.', 404);
        }

        if ($invoice['status'] === 'cancelled') {
            $pdo->rollBack();
            sendErrorResponse('Payments cannot be recorded for a cancelled invoice.', 409);
        }

        // 2. Calculate current paid amount & balance from DB payments
        $sumStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = :id FOR UPDATE');
        $sumStmt->bindValue(':id', $invoiceId, PDO::PARAM_INT);
        $sumStmt->execute();
        $sumPaid = round((float) $sumStmt->fetchColumn(), 2);

        $totalAmount = round((float) $invoice['total_amount'], 2);
        $currentBalance = round($totalAmount - $sumPaid, 2);
        if ($currentBalance < 0) {
            $currentBalance = 0.0;
        }

        if ($currentBalance <= 0 || $invoice['status'] === 'paid') {
            $pdo->rollBack();
            sendErrorResponse('Invoice is already fully paid.', 409);
        }

        if ($requestedAmount > $currentBalance) {
            $pdo->rollBack();
            sendErrorResponse('Payment amount cannot exceed the invoice balance.', 400);
        }

        // 3. Check reference number uniqueness if provided
        if ($referenceNumber !== null) {
            $refStmt = $pdo->prepare('SELECT id FROM payments WHERE payment_reference = :ref FOR UPDATE');
            $refStmt->bindValue(':ref', $referenceNumber, PDO::PARAM_STR);
            $refStmt->execute();
            if ($refStmt->fetch()) {
                $pdo->rollBack();
                sendErrorResponse('Payment reference number already exists.', 400);
            }
        }

        // 4. Insert payment
        $sql = 'INSERT INTO payments (
                    invoice_id, customer_id, payment_reference, amount, payment_method, payment_date, notes, recorded_by
                ) VALUES (
                    :invoice_id, :customer_id, :payment_reference, :amount, :payment_method, :payment_date, :notes, :recorded_by
                )';

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':invoice_id', $invoiceId, PDO::PARAM_INT);
        $stmt->bindValue(':customer_id', $invoice['customer_id'], PDO::PARAM_INT);
        $stmt->bindValue(':payment_reference', $referenceNumber, $referenceNumber === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':amount', number_format($requestedAmount, 2, '.', ''), PDO::PARAM_STR);
        $stmt->bindValue(':payment_method', $paymentMethod, PDO::PARAM_STR);
        $stmt->bindValue(':payment_date', $paymentDate, PDO::PARAM_STR);
        $stmt->bindValue(':notes', $notes, $notes === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':recorded_by', getCurrentUserId(), getCurrentUserId() === null ? PDO::PARAM_NULL : PDO::PARAM_INT);

        $stmt->execute();
        $newPaymentId = (int) $pdo->lastInsertId();

        // 5. Recalculate invoice totals and status
        $sumStmt->execute();
        $newPaidAmount = round((float) $sumStmt->fetchColumn(), 2);
        $newBalanceAmount = round($totalAmount - $newPaidAmount, 2);
        if ($newBalanceAmount < 0) {
            $newBalanceAmount = 0.00;
        }

        $newStatus = 'unpaid';
        if ($invoice['status'] === 'cancelled') {
            $newStatus = 'cancelled';
        } elseif ($newPaidAmount >= $totalAmount && $newBalanceAmount == 0) {
            $newStatus = 'paid';
        } elseif ($newPaidAmount > 0) {
            $today = date('Y-m-d');
            if (!empty($invoice['due_date']) && $today > $invoice['due_date']) {
                $newStatus = 'overdue';
            } else {
                $newStatus = 'partial';
            }
        } else {
            $today = date('Y-m-d');
            if (!empty($invoice['due_date']) && $today > $invoice['due_date']) {
                $newStatus = 'overdue';
            } else {
                $newStatus = 'unpaid';
            }
        }

        $updInv = $pdo->prepare('UPDATE invoices SET paid_amount = :paid_amount, balance_amount = :balance_amount, status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $updInv->bindValue(':paid_amount', number_format($newPaidAmount, 2, '.', ''), PDO::PARAM_STR);
        $updInv->bindValue(':balance_amount', number_format($newBalanceAmount, 2, '.', ''), PDO::PARAM_STR);
        $updInv->bindValue(':status', $newStatus, PDO::PARAM_STR);
        $updInv->bindValue(':id', $invoiceId, PDO::PARAM_INT);
        $updInv->execute();

        $pdo->commit();

        // Server-Side Automated Email Trigger (Failure Isolated & Duplicate Protected)
        try {
            require_once __DIR__ . '/../includes/email/EmailDispatcher.php';
            $dispatcher = new EmailDispatcher($pdo);
            $dispatcher->sendAutomatedPaymentReceipt($newPaymentId);
        } catch (Throwable $e) {
            error_log('[Payment Creation Auto-Email Exception] ' . $e->getMessage());
        }

        $_GET['id'] = $newPaymentId;
        handleGetSingle($pdo);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * PUT Update Payment Metadata (amount and invoice_id CANNOT be changed)
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
        sendErrorResponse('Payment ID is required.', 400);
    }

    $stmt = $pdo->prepare('SELECT * FROM payments WHERE id = :id');
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $existing = $stmt->fetch();

    if (!$existing) {
        sendErrorResponse('Payment not found.', 404);
    }

    if (isset($input['amount']) && round((float) $input['amount'], 2) !== round((float) $existing['amount'], 2)) {
        sendErrorResponse('Financial payment amount cannot be modified.', 409);
    }
    if (isset($input['invoice_id']) && (int) $input['invoice_id'] !== (int) $existing['invoice_id']) {
        sendErrorResponse('Financial payment invoice cannot be modified.', 409);
    }

    $paymentDate = $existing['payment_date'];
    if (isset($input['payment_date']) && trim((string) $input['payment_date']) !== '') {
        $dStr = trim((string) $input['payment_date']);
        $dObj = DateTime::createFromFormat('Y-m-d', $dStr);
        if (!$dObj || $dObj->format('Y-m-d') !== $dStr) {
            sendErrorResponse('Invalid payment_date format. Use YYYY-MM-DD.', 400);
        }
        $paymentDate = $dStr;
    }

    $paymentMethod = $existing['payment_method'];
    if (isset($input['payment_method']) && trim((string) $input['payment_method']) !== '') {
        $pm = trim((string) $input['payment_method']);
        $allowedMethods = ['cash', 'bank_transfer', 'upi', 'card', 'cheque', 'other'];
        if (!in_array($pm, $allowedMethods, true)) {
            sendErrorResponse('Invalid payment method. Allowed values: cash, bank_transfer, upi, card, cheque, other.', 400);
        }
        $paymentMethod = $pm;
    }

    $refNum = $existing['payment_reference'];
    if (array_key_exists('reference_number', $input) || array_key_exists('payment_reference', $input)) {
        $refCandidate = isset($input['reference_number']) ? trim((string) $input['reference_number']) : (isset($input['payment_reference']) ? trim((string) $input['payment_reference']) : '');
        $refCandidate = $refCandidate === '' ? null : $refCandidate;
        if ($refCandidate !== null && $refCandidate !== $existing['payment_reference']) {
            $refCheck = $pdo->prepare('SELECT id FROM payments WHERE payment_reference = :ref AND id != :id');
            $refCheck->bindValue(':ref', $refCandidate, PDO::PARAM_STR);
            $refCheck->bindValue(':id', $id, PDO::PARAM_INT);
            $refCheck->execute();
            if ($refCheck->fetch()) {
                sendErrorResponse('Payment reference number already exists.', 400);
            }
        }
        $refNum = $refCandidate;
    }

    $notes = array_key_exists('notes', $input) ? (trim((string) $input['notes']) !== '' ? trim((string) $input['notes']) : null) : $existing['notes'];

    $updSql = 'UPDATE payments SET 
                payment_date = :payment_date,
                payment_method = :payment_method,
                payment_reference = :payment_reference,
                notes = :notes
               WHERE id = :id';

    $updStmt = $pdo->prepare($updSql);
    $updStmt->bindValue(':payment_date', $paymentDate, PDO::PARAM_STR);
    $updStmt->bindValue(':payment_method', $paymentMethod, PDO::PARAM_STR);
    $updStmt->bindValue(':payment_reference', $refNum, $refNum === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $updStmt->bindValue(':notes', $notes, $notes === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $updStmt->bindValue(':id', $id, PDO::PARAM_INT);

    $updStmt->execute();

    $_GET['id'] = $id;
    handleGetSingle($pdo);
}

/**
 * DELETE Payment -> Returns 409 Conflict (Financial audit records cannot be deleted)
 */
function handleDelete(PDO $pdo): void
{
    sendErrorResponse('Payments cannot be deleted because financial records must be retained.', 409);
}

/**
 * GET Invoice Payment Summary
 * GET /api/payments.php?action=summary&invoice_id=1
 */
function handleInvoiceSummary(PDO $pdo): void
{
    $invoiceId = isset($_GET['invoice_id']) ? filter_var($_GET['invoice_id'], FILTER_VALIDATE_INT) : null;
    if ($invoiceId === false || $invoiceId === null || $invoiceId <= 0) {
        sendErrorResponse('Valid invoice_id is required.', 400);
    }

    $invStmt = $pdo->prepare('SELECT id, total_amount, paid_amount, balance_amount, status FROM invoices WHERE id = :id');
    $invStmt->bindValue(':id', $invoiceId, PDO::PARAM_INT);
    $invStmt->execute();
    $invoice = $invStmt->fetch();

    if (!$invoice) {
        sendErrorResponse('Invoice not found.', 404);
    }

    $cntStmt = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE invoice_id = :id');
    $cntStmt->bindValue(':id', $invoiceId, PDO::PARAM_INT);
    $cntStmt->execute();
    $paymentCount = (int) $cntStmt->fetchColumn();

    sendSuccessResponse('Payment summary fetched successfully.', [
        'invoice_id' => (int) $invoice['id'],
        'total_amount' => (float) $invoice['total_amount'],
        'paid_amount' => (float) $invoice['paid_amount'],
        'balance_amount' => (float) $invoice['balance_amount'],
        'payment_count' => $paymentCount,
        'status' => $invoice['status'],
    ]);
}

/**
 * GET Customer Payment Summary
 * GET /api/payments.php?action=customer_summary&customer_id=5
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
                COUNT(id) AS invoice_count,
                COALESCE(SUM(total_amount), 0) AS total_invoiced,
                COALESCE(SUM(paid_amount), 0) AS total_paid,
                COALESCE(SUM(balance_amount), 0) AS total_outstanding
            FROM invoices
            WHERE customer_id = :cid AND status != 'cancelled'";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':cid', $customerId, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch();

    sendSuccessResponse('Customer payment summary fetched successfully.', [
        'customer_id' => $customerId,
        'total_invoiced' => (float) $row['total_invoiced'],
        'total_paid' => (float) $row['total_paid'],
        'total_outstanding' => (float) $row['total_outstanding'],
        'invoice_count' => (int) $row['invoice_count'],
    ]);
}

/**
 * Format payment DB row to response structure
 */
function formatPaymentRow(array $row): array
{
    $res = [
        'id' => (int) $row['id'],
        'invoice_id' => (int) $row['invoice_id'],
        'invoice_number' => $row['invoice_number'],
        'customer_id' => (int) $row['customer_id'],
        'customer' => [
            'id' => (int) $row['customer_id'],
            'company_name' => $row['customer_company_name'],
            'contact_person' => $row['customer_contact_person'],
            'email' => $row['customer_email'],
            'phone' => $row['customer_phone'],
        ],
        'amount' => (float) $row['amount'],
        'payment_date' => $row['payment_date'],
        'payment_method' => $row['payment_method'],
        'reference_number' => $row['reference_number'],
        'notes' => $row['notes'],
        'status' => 'completed',
        'recorded_by' => $row['recorded_by'] !== null ? (int) $row['recorded_by'] : null,
        'created_at' => $row['created_at'],
    ];

    if (isset($row['invoice_total_amount'])) {
        $res['invoice'] = [
            'id' => (int) $row['invoice_id'],
            'invoice_number' => $row['invoice_number'],
            'total_amount' => (float) $row['invoice_total_amount'],
            'paid_amount' => (float) $row['invoice_paid_amount'],
            'balance_amount' => (float) $row['invoice_balance_amount'],
            'status' => $row['invoice_status'],
        ];
    }

    return $res;
}
