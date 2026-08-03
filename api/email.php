<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/email/EmailDispatcher.php';
require_once __DIR__ . '/../includes/email/EmailRetryService.php';

// Auth Guard - All email operations require authentication
requireAuth();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? null;

$dispatcher = new EmailDispatcher($pdo);

$postActions = ['quotation', 'invoice', 'payment', 'campaign', 'system', 'retry'];
$getActions = ['logs', 'log', 'analytics', 'analytics_export'];

if (in_array($action, $postActions, true) && $method !== 'POST') {
    sendErrorResponse('Method Not Allowed. Use POST for this email action.', 405);
}

if (in_array($action, $getActions, true) && $method !== 'GET') {
    sendErrorResponse('Method Not Allowed. Use GET for this email action.', 405);
}

try {
    switch ($method) {
        case 'POST':
            handlePostActions($pdo, $dispatcher, $action);
            break;
        case 'GET':
            handleGetActions($pdo, $action);
            break;
        default:
            sendErrorResponse('Method Not Allowed', 405);
            break;
    }
} catch (Throwable $e) {
    error_log('[api/email.php] Exception: ' . $e->getMessage());
    sendErrorResponse('Internal Server Error', 500);
}

/**
 * Router for POST actions (Email Sending & Retries)
 */
function handlePostActions(PDO $pdo, EmailDispatcher $dispatcher, ?string $action): void
{
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    if (!is_array($input)) {
        $input = [];
    }

    switch ($action) {
        case 'quotation':
            requireRole(['owner', 'manager', 'staff']);
            handleSendQuotation($dispatcher, $input);
            break;
        case 'invoice':
            requireRole(['owner', 'manager', 'staff']);
            handleSendInvoice($dispatcher, $input);
            break;
        case 'payment':
            requireRole(['owner', 'manager', 'staff']);
            handleSendPayment($dispatcher, $input);
            break;
        case 'campaign':
            requireRole(['owner', 'manager', 'staff']);
            handleSendCampaign($dispatcher, $input);
            break;
        case 'system':
            requireRole(['owner', 'manager']);
            handleSendSystem($dispatcher, $input);
            break;
        case 'retry':
            requireRole(['owner', 'manager']);
            handleRetryEmail($pdo, $input);
            break;
        default:
            sendErrorResponse('Invalid or missing email post action.', 400);
            break;
    }
}

/**
 * Router for GET actions (Email Logs & Analytics Viewing)
 */
function handleGetActions(PDO $pdo, ?string $action): void
{
    requireRole(['owner', 'manager', 'staff']);

    switch ($action) {
        case 'logs':
            handleGetEmailLogs($pdo);
            break;
        case 'log':
            handleGetSingleEmailLog($pdo);
            break;
        case 'analytics':
            handleGetAnalytics($pdo);
            break;
        case 'analytics_export':
            handleGetAnalyticsExport($pdo);
            break;
        default:
            sendErrorResponse('Invalid or missing email get action.', 400);
            break;
    }
}

function handleSendQuotation(EmailDispatcher $dispatcher, array $input): void
{
    $quotationId = isset($input['quotation_id']) ? filter_var($input['quotation_id'], FILTER_VALIDATE_INT) : null;
    if (!$quotationId && isset($input['id'])) {
        $quotationId = filter_var($input['id'], FILTER_VALIDATE_INT);
    }
    if ($quotationId === false || $quotationId === null || $quotationId <= 0) {
        sendErrorResponse('Valid quotation_id is required.', 400);
    }

    $recipientEmail = isset($input['recipient_email']) ? trim((string) $input['recipient_email']) : null;
    if ($recipientEmail !== null && $recipientEmail !== '' && filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) === false) {
        sendErrorResponse('Invalid recipient email address.', 400);
    }

    $recipientName = isset($input['recipient_name']) ? trim((string) $input['recipient_name']) : null;
    $sentBy = getCurrentUserId();

    $result = $dispatcher->sendQuotation($quotationId, $recipientEmail, $recipientName, $sentBy);

    if ($result['success']) {
        sendSuccessResponse('Quotation email dispatched successfully.', $result);
    } else {
        $statusCode = (isset($result['message']) && str_contains(strtolower($result['message']), 'not found')) ? 404 : 500;
        sendErrorResponse($result['message'] ?? 'Unable to send email.', $statusCode);
    }
}

function handleSendInvoice(EmailDispatcher $dispatcher, array $input): void
{
    $invoiceId = isset($input['invoice_id']) ? filter_var($input['invoice_id'], FILTER_VALIDATE_INT) : null;
    if (!$invoiceId && isset($input['id'])) {
        $invoiceId = filter_var($input['id'], FILTER_VALIDATE_INT);
    }
    if ($invoiceId === false || $invoiceId === null || $invoiceId <= 0) {
        sendErrorResponse('Valid invoice_id is required.', 400);
    }

    $recipientEmail = isset($input['recipient_email']) ? trim((string) $input['recipient_email']) : null;
    if ($recipientEmail !== null && $recipientEmail !== '' && filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) === false) {
        sendErrorResponse('Invalid recipient email address.', 400);
    }

    $recipientName = isset($input['recipient_name']) ? trim((string) $input['recipient_name']) : null;
    $sentBy = getCurrentUserId();

    $result = $dispatcher->sendInvoice($invoiceId, $recipientEmail, $recipientName, $sentBy);

    if ($result['success']) {
        sendSuccessResponse('Invoice email dispatched successfully.', $result);
    } else {
        $statusCode = (isset($result['message']) && str_contains(strtolower($result['message']), 'not found')) ? 404 : 500;
        sendErrorResponse($result['message'] ?? 'Unable to send email.', $statusCode);
    }
}

function handleSendPayment(EmailDispatcher $dispatcher, array $input): void
{
    $paymentId = isset($input['payment_id']) ? filter_var($input['payment_id'], FILTER_VALIDATE_INT) : null;
    if (!$paymentId && isset($input['id'])) {
        $paymentId = filter_var($input['id'], FILTER_VALIDATE_INT);
    }
    if ($paymentId === false || $paymentId === null || $paymentId <= 0) {
        sendErrorResponse('Valid payment_id is required.', 400);
    }

    $recipientEmail = isset($input['recipient_email']) ? trim((string) $input['recipient_email']) : null;
    if ($recipientEmail !== null && $recipientEmail !== '' && filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) === false) {
        sendErrorResponse('Invalid recipient email address.', 400);
    }

    $recipientName = isset($input['recipient_name']) ? trim((string) $input['recipient_name']) : null;
    $sentBy = getCurrentUserId();

    $result = $dispatcher->sendPaymentReceipt($paymentId, $recipientEmail, $recipientName, $sentBy);

    if ($result['success']) {
        sendSuccessResponse('Payment receipt email dispatched successfully.', $result);
    } else {
        $statusCode = (isset($result['message']) && str_contains(strtolower($result['message']), 'not found')) ? 404 : 500;
        sendErrorResponse($result['message'] ?? 'Unable to send email.', $statusCode);
    }
}

function handleSendCampaign(EmailDispatcher $dispatcher, array $input): void
{
    $campaignId = isset($input['campaign_id']) ? filter_var($input['campaign_id'], FILTER_VALIDATE_INT) : null;
    if (!$campaignId && isset($input['id'])) {
        $campaignId = filter_var($input['id'], FILTER_VALIDATE_INT);
    }
    if ($campaignId === false || $campaignId === null || $campaignId <= 0) {
        sendErrorResponse('Valid campaign_id is required.', 400);
    }

    $recipientEmail = isset($input['recipient_email']) ? trim((string) $input['recipient_email']) : null;
    if ($recipientEmail !== null && $recipientEmail !== '' && filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) === false) {
        sendErrorResponse('Invalid recipient email address.', 400);
    }

    $recipientName = isset($input['recipient_name']) ? trim((string) $input['recipient_name']) : null;
    $sentBy = getCurrentUserId();

    $result = $dispatcher->sendCampaignUpdate($campaignId, $recipientEmail, $recipientName, $sentBy);

    if ($result['success']) {
        sendSuccessResponse('Campaign update email dispatched successfully.', $result);
    } else {
        $statusCode = (isset($result['message']) && str_contains(strtolower($result['message']), 'not found')) ? 404 : 500;
        sendErrorResponse($result['message'] ?? 'Unable to send email.', $statusCode);
    }
}

function handleSendSystem(EmailDispatcher $dispatcher, array $input): void
{
    $recipientEmail = isset($input['recipient_email']) ? trim((string) $input['recipient_email']) : null;
    if ($recipientEmail === null || filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) === false) {
        sendErrorResponse('Valid recipient_email is required.', 400);
    }

    $recipientName = isset($input['recipient_name']) ? trim((string) $input['recipient_name']) : null;
    $title = isset($input['title']) ? trim((string) $input['title']) : '';
    if ($title === '') {
        sendErrorResponse('Notification title is required.', 400);
    }

    $message = isset($input['message']) ? trim((string) $input['message']) : '';
    if ($message === '') {
        sendErrorResponse('Notification message is required.', 400);
    }

    $actionUrl = isset($input['action_url']) ? trim((string) $input['action_url']) : null;
    $actionText = isset($input['action_text']) ? trim((string) $input['action_text']) : null;
    $sentBy = getCurrentUserId();

    $result = $dispatcher->sendSystemNotification($recipientEmail, $recipientName, $title, $message, $actionUrl, $actionText, $sentBy);

    if ($result['success']) {
        sendSuccessResponse('System notification email dispatched successfully.', $result);
    } else {
        sendErrorResponse($result['message'] ?? 'Unable to send email.', 500);
    }
}

function handleRetryEmail(PDO $pdo, array $input): void
{
    $id = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : null;
    if (!$id && isset($input['id'])) {
        $id = filter_var($input['id'], FILTER_VALIDATE_INT);
    }

    if ($id === false || $id === null || $id <= 0) {
        sendErrorResponse('Valid email log ID is required.', 400);
    }

    $stmt = $pdo->prepare("SELECT id, status, attempt_count FROM email_logs WHERE id = :id");
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $log = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$log) {
        sendErrorResponse('Email log record not found.', 404);
    }

    if ($log['status'] === 'sent') {
        sendErrorResponse('Email has already been sent successfully.', 400);
    }

    if ($log['status'] !== 'failed') {
        sendErrorResponse('Only failed email records can be retried.', 400);
    }

    if ((int) $log['attempt_count'] >= 3) {
        sendErrorResponse('Maximum retry attempts (3) exceeded for this email log record.', 400);
    }

    $retryService = new EmailRetryService($pdo);
    $result = $retryService->retryEmail($id, true);

    if (!empty($result['success'])) {
        sendSuccessResponse('Email retried successfully.', $result);
    } else {
        sendErrorResponse($result['message'] ?? 'Email retry failed.', 400);
    }
}

/**
 * Action: GET /api/email.php?action=logs
 */
function handleGetEmailLogs(PDO $pdo): void
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
    $emailType = isset($_GET['email_type']) ? trim((string) $_GET['email_type']) : '';
    $status = isset($_GET['status']) ? trim((string) $_GET['status']) : '';
    $refType = isset($_GET['reference_type']) ? trim((string) $_GET['reference_type']) : '';
    $refId = isset($_GET['reference_id']) ? filter_var($_GET['reference_id'], FILTER_VALIDATE_INT) : null;

    $whereClauses = [];
    $params = [];

    if ($search !== '') {
        $whereClauses[] = '(recipient_email LIKE :s_email OR recipient_name LIKE :s_name OR subject LIKE :s_subj)';
        $params[':s_email'] = '%' . $search . '%';
        $params[':s_name'] = '%' . $search . '%';
        $params[':s_subj'] = '%' . $search . '%';
    }

    if ($emailType !== '') {
        $whereClauses[] = 'email_type = :email_type';
        $params[':email_type'] = $emailType;
    }

    if ($status !== '') {
        $whereClauses[] = 'status = :status';
        $params[':status'] = $status;
    }

    if ($refType !== '') {
        $whereClauses[] = 'reference_type = :ref_type';
        $params[':ref_type'] = $refType;
    }

    if ($refId !== false && $refId !== null && $refId > 0) {
        $whereClauses[] = 'reference_id = :ref_id';
        $params[':ref_id'] = $refId;
    }

    $from = isset($_GET['from']) ? trim((string) $_GET['from']) : '';
    $to = isset($_GET['to']) ? trim((string) $_GET['to']) : '';

    if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $whereClauses[] = 'created_at >= :from_ts';
        $params[':from_ts'] = $from . ' 00:00:00';
    }

    if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $whereClauses[] = 'created_at < :to_next_day_ts';
        $params[':to_next_day_ts'] = date('Y-m-d 00:00:00', strtotime($to . ' +1 day'));
    }

    $whereSql = $whereClauses !== [] ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

    // Overall Summary Metrics
    $statsSql = "SELECT 
                    COUNT(*) AS total_emails,
                    SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent_emails,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_emails,
                    SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) AS queued_emails,
                    SUM(CASE WHEN status = 'failed' AND attempt_count < 3 AND (next_retry_at IS NULL OR next_retry_at <= CURRENT_TIMESTAMP) THEN 1 ELSE 0 END) AS retry_pending_emails
                 FROM email_logs";
    $statsStmt = $pdo->query($statsSql);
    $summaryRaw = $statsStmt ? $statsStmt->fetch(PDO::FETCH_ASSOC) : [];
    $summary = [
        'total_emails' => (int) ($summaryRaw['total_emails'] ?? 0),
        'sent_emails' => (int) ($summaryRaw['sent_emails'] ?? 0),
        'failed_emails' => (int) ($summaryRaw['failed_emails'] ?? 0),
        'queued_emails' => (int) ($summaryRaw['queued_emails'] ?? 0),
        'retry_pending_emails' => (int) ($summaryRaw['retry_pending_emails'] ?? 0),
    ];

    // Count Total Matching Records
    $countSql = "SELECT COUNT(*) FROM email_logs {$whereSql}";
    $countStmt = $pdo->prepare($countSql);
    foreach ($params as $key => $val) {
        $countStmt->bindValue($key, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $countStmt->execute();
    $total = (int) $countStmt->fetchColumn();

    $totalPages = $total > 0 ? (int) ceil($total / $limit) : 0;
    $offset = ($page - 1) * $limit;

    // Fetch Log Records
    $sql = "SELECT 
                id,
                message_id,
                recipient_email,
                recipient_name,
                subject,
                email_type,
                reference_type,
                reference_id,
                attachment_name,
                status,
                attempt_count,
                last_attempt_at,
                next_retry_at,
                error_message,
                sent_by,
                sent_at,
                created_at
            FROM email_logs
            {$whereSql}
            ORDER BY id DESC
            LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    sendSuccessResponse('Email logs fetched successfully.', $logs, 200, [
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'total_pages' => $totalPages,
        'summary' => $summary,
    ]);
}

/**
 * Action: GET /api/email.php?action=log&id=X
 */
function handleGetSingleEmailLog(PDO $pdo): void
{
    $id = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : null;
    if ($id === false || $id === null || $id <= 0) {
        sendErrorResponse('Valid email log ID is required.', 400);
    }

    $sql = "SELECT 
                id,
                message_id,
                recipient_email,
                recipient_name,
                subject,
                email_type,
                reference_type,
                reference_id,
                attachment_name,
                status,
                attempt_count,
                last_attempt_at,
                next_retry_at,
                error_message,
                sent_by,
                sent_at,
                created_at
            FROM email_logs
            WHERE id = :id";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $log = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$log) {
        sendErrorResponse('Email log record not found.', 404);
    }

    sendSuccessResponse('Email log details fetched successfully.', $log);
}

/**
 * Action: GET /api/email.php?action=analytics&from=YYYY-MM-DD&to=YYYY-MM-DD
 */
function handleGetAnalytics(PDO $pdo): void
{
    requireRole(['owner', 'manager', 'staff']);

    $from = $_GET['from'] ?? null;
    $to = $_GET['to'] ?? null;

    require_once __DIR__ . '/../includes/email/EmailAnalyticsService.php';
    $analyticsService = new EmailAnalyticsService($pdo);
    $data = $analyticsService->getAnalyticsData($from ? (string) $from : null, $to ? (string) $to : null);

    sendSuccessResponse('Email analytics metrics fetched successfully.', $data);
}

/**
 * Action: GET /api/email.php?action=analytics_export&from=YYYY-MM-DD&to=YYYY-MM-DD
 */
function handleGetAnalyticsExport(PDO $pdo): void
{
    requireRole(['owner', 'manager', 'staff']);

    $from = $_GET['from'] ?? null;
    $to = $_GET['to'] ?? null;

    require_once __DIR__ . '/../includes/email/EmailAnalyticsService.php';
    $analyticsService = new EmailAnalyticsService($pdo);
    $csvContent = $analyticsService->exportAnalyticsCsv($from ? (string) $from : null, $to ? (string) $to : null);

    $filename = 'email_analytics_export_' . date('Y-m-d') . '.csv';

    if (!headers_sent()) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    echo $csvContent;
    exit(0);
}
