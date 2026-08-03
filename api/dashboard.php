<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/auth.php';

// Auth guard (non-blocking for development per auth.php)
requireAuth();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? null;

if ($method !== 'GET') {
    sendErrorResponse('Method Not Allowed', 405);
}

try {
    switch ($action) {
        case 'top_customers':
            handleTopCustomers($pdo);
            break;
        case 'screen_performance':
            handleScreenPerformance($pdo);
            break;
        case 'revenue_trend':
            handleRevenueTrend($pdo);
            break;
        case 'campaign_trend':
            handleCampaignTrend($pdo);
            break;
        case 'alerts':
            handleAlerts($pdo);
            break;
        case null:
            handleMainDashboard($pdo);
            break;
        default:
            sendErrorResponse('Invalid dashboard action requested.', 400);
            break;
    }
} catch (Throwable $e) {
    sendErrorResponse('Internal Server Error', 500);
}

/**
 * Main Consolidated Dashboard Endpoint: GET /api/dashboard.php
 */
function handleMainDashboard(PDO $pdo): void
{
    $fromDate = isset($_GET['from_date']) ? trim((string) $_GET['from_date']) : null;
    $toDate = isset($_GET['to_date']) ? trim((string) $_GET['to_date']) : null;

    if ($fromDate !== null || $toDate !== null) {
        if ($fromDate === null || $toDate === null) {
            sendErrorResponse('Both from_date and to_date must be provided for date filtering.', 400);
        }
        $fObj = DateTime::createFromFormat('Y-m-d', $fromDate);
        $tObj = DateTime::createFromFormat('Y-m-d', $toDate);
        if (!$fObj || $fObj->format('Y-m-d') !== $fromDate || !$tObj || $tObj->format('Y-m-d') !== $toDate) {
            sendErrorResponse('Invalid from_date or to_date format. Use YYYY-MM-DD.', 400);
        }
        if ($fObj > $tObj) {
            sendErrorResponse('from_date must be before or equal to to_date.', 400);
        }
    }

    $dashboardData = [
        'customers' => getCustomerStats($pdo),
        'screens' => getScreenStats($pdo),
        'quotations' => getQuotationStats($pdo, $fromDate, $toDate),
        'invoices' => getInvoiceStats($pdo, $fromDate, $toDate),
        'revenue' => getRevenueStats($pdo, $fromDate, $toDate),
        'payments' => getPaymentStats($pdo, $fromDate, $toDate),
        'campaigns' => getCampaignStats($pdo, $fromDate, $toDate),
        'campaign_progress' => getCampaignProgressStats($pdo),
        'recent_activity' => [
            'upcoming_campaigns' => getUpcomingCampaigns($pdo, 5),
            'recent_payments' => getRecentPayments($pdo, 5),
            'recent_quotations' => getRecentQuotations($pdo, 5),
            'recent_invoices' => getRecentInvoices($pdo, 5),
        ],
    ];

    sendSuccessResponse('Dashboard data fetched successfully.', $dashboardData);
}

/**
 * Customer Statistics
 */
function getCustomerStats(PDO $pdo): array
{
    $sql = "SELECT 
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active,
                SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) AS inactive
            FROM customers";
    $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);

    return [
        'total' => (int) ($row['total'] ?? 0),
        'active' => (int) ($row['active'] ?? 0),
        'inactive' => (int) ($row['inactive'] ?? 0),
    ];
}

/**
 * Screen / Inventory Statistics
 */
function getScreenStats(PDO $pdo): array
{
    $sql = "SELECT 
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) AS available,
                SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) AS maintenance,
                SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) AS inactive
            FROM screens";
    $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);

    // Calculate currently booked screens for today
    $bookedSql = "SELECT COUNT(DISTINCT screen_id) FROM campaign_screens 
                  WHERE status IN ('reserved', 'active') 
                    AND start_date <= CURRENT_DATE() 
                    AND end_date >= CURRENT_DATE()";
    $currentlyBooked = (int) $pdo->query($bookedSql)->fetchColumn();

    return [
        'total' => (int) ($row['total'] ?? 0),
        'available' => (int) ($row['available'] ?? 0),
        'maintenance' => (int) ($row['maintenance'] ?? 0),
        'inactive' => (int) ($row['inactive'] ?? 0),
        'currently_booked' => $currentlyBooked,
    ];
}

/**
 * Quotation Statistics
 */
function getQuotationStats(PDO $pdo, ?string $fromDate, ?string $toDate): array
{
    $where = '';
    $params = [];
    if ($fromDate !== null && $toDate !== null) {
        $where = 'WHERE quotation_date BETWEEN :from_date AND :to_date';
        $params = [':from_date' => $fromDate, ':to_date' => $toDate];
    }

    $sql = "SELECT 
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) AS draft,
                SUM(CASE WHEN status = 'generated' THEN 1 ELSE 0 END) AS generated_count,
                SUM(CASE WHEN status = 'pending_approval' THEN 1 ELSE 0 END) AS pending_approval,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected,
                SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) AS expired,
                SUM(CASE WHEN status = 'converted' THEN 1 ELSE 0 END) AS converted,
                COALESCE(SUM(total_amount), 0) AS total_value
            FROM quotations {$where}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
        'total' => (int) ($row['total'] ?? 0),
        'draft' => (int) ($row['draft'] ?? 0),
        'generated' => (int) ($row['generated_count'] ?? 0),
        'pending_approval' => (int) ($row['pending_approval'] ?? 0),
        'approved' => (int) ($row['approved'] ?? 0),
        'rejected' => (int) ($row['rejected'] ?? 0),
        'expired' => (int) ($row['expired'] ?? 0),
        'converted' => (int) ($row['converted'] ?? 0),
        'total_quotation_value' => (float) ($row['total_value'] ?? 0.0),
    ];
}

/**
 * Invoice Statistics
 */
function getInvoiceStats(PDO $pdo, ?string $fromDate, ?string $toDate): array
{
    $where = '';
    $params = [];
    if ($fromDate !== null && $toDate !== null) {
        $where = 'WHERE invoice_date BETWEEN :from_date AND :to_date';
        $params = [':from_date' => $fromDate, ':to_date' => $toDate];
    }

    $sql = "SELECT 
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'unpaid' THEN 1 ELSE 0 END) AS unpaid,
                SUM(CASE WHEN status = 'partial' THEN 1 ELSE 0 END) AS partial,
                SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS paid,
                SUM(CASE WHEN status != 'cancelled' AND balance_amount > 0 AND CURRENT_DATE() > due_date THEN 1 ELSE 0 END) AS overdue,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
                COALESCE(SUM(CASE WHEN status != 'cancelled' THEN total_amount ELSE 0 END), 0) AS total_invoiced,
                COALESCE(SUM(CASE WHEN status != 'cancelled' THEN paid_amount ELSE 0 END), 0) AS total_paid,
                COALESCE(SUM(CASE WHEN status != 'cancelled' THEN balance_amount ELSE 0 END), 0) AS total_outstanding
            FROM invoices {$where}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
        'total' => (int) ($row['total'] ?? 0),
        'unpaid' => (int) ($row['unpaid'] ?? 0),
        'partial' => (int) ($row['partial'] ?? 0),
        'paid' => (int) ($row['paid'] ?? 0),
        'overdue' => (int) ($row['overdue'] ?? 0),
        'cancelled' => (int) ($row['cancelled'] ?? 0),
        'total_invoiced' => (float) ($row['total_invoiced'] ?? 0.0),
        'total_paid' => (float) ($row['total_paid'] ?? 0.0),
        'total_outstanding' => (float) ($row['total_outstanding'] ?? 0.0),
    ];
}

/**
 * Revenue Statistics
 */
function getRevenueStats(PDO $pdo, ?string $fromDate, ?string $toDate): array
{
    $invWhere = "WHERE status != 'cancelled'";
    $payWhere = '';
    $paramsInv = [];
    $paramsPay = [];

    if ($fromDate !== null && $toDate !== null) {
        $invWhere .= ' AND invoice_date BETWEEN :from_date AND :to_date';
        $payWhere = 'WHERE payment_date BETWEEN :from_date AND :to_date';
        $paramsInv = [':from_date' => $fromDate, ':to_date' => $toDate];
        $paramsPay = [':from_date' => $fromDate, ':to_date' => $toDate];
    }

    // Total Invoiced and Overdue
    $invSql = "SELECT 
                COALESCE(SUM(total_amount), 0) AS total_invoiced,
                COALESCE(SUM(CASE WHEN balance_amount > 0 AND CURRENT_DATE() > due_date THEN balance_amount ELSE 0 END), 0) AS total_overdue
               FROM invoices {$invWhere}";
    $stmtInv = $pdo->prepare($invSql);
    $stmtInv->execute($paramsInv);
    $invRow = $stmtInv->fetch(PDO::FETCH_ASSOC);

    // Total Paid from Payments table
    $paySql = "SELECT COALESCE(SUM(amount), 0) FROM payments {$payWhere}";
    $stmtPay = $pdo->prepare($paySql);
    $stmtPay->execute($paramsPay);
    $totalPaid = (float) $stmtPay->fetchColumn();

    $totalInvoiced = (float) ($invRow['total_invoiced'] ?? 0.0);
    $totalOverdue = (float) ($invRow['total_overdue'] ?? 0.0);
    $totalOutstanding = round($totalInvoiced - $totalPaid, 2);
    if ($totalOutstanding < 0) {
        $totalOutstanding = 0.00;
    }

    return [
        'total_invoiced' => $totalInvoiced,
        'total_paid' => $totalPaid,
        'total_outstanding' => $totalOutstanding,
        'total_overdue' => $totalOverdue,
    ];
}

/**
 * Payment Statistics & Method Breakdown
 */
function getPaymentStats(PDO $pdo, ?string $fromDate, ?string $toDate): array
{
    $where = '';
    $params = [];
    if ($fromDate !== null && $toDate !== null) {
        $where = 'WHERE payment_date BETWEEN :from_date AND :to_date';
        $params = [':from_date' => $fromDate, ':to_date' => $toDate];
    }

    $sql = "SELECT 
                COUNT(*) AS payment_count,
                COALESCE(SUM(amount), 0) AS total_payments,
                COALESCE(SUM(CASE WHEN payment_date = CURRENT_DATE() THEN amount ELSE 0 END), 0) AS today_payments,
                COALESCE(SUM(CASE WHEN MONTH(payment_date) = MONTH(CURRENT_DATE()) AND YEAR(payment_date) = YEAR(CURRENT_DATE()) THEN amount ELSE 0 END), 0) AS this_month_payments,
                COALESCE(SUM(CASE WHEN payment_method = 'cash' THEN amount ELSE 0 END), 0) AS pm_cash,
                COALESCE(SUM(CASE WHEN payment_method = 'bank_transfer' THEN amount ELSE 0 END), 0) AS pm_bank_transfer,
                COALESCE(SUM(CASE WHEN payment_method = 'upi' THEN amount ELSE 0 END), 0) AS pm_upi,
                COALESCE(SUM(CASE WHEN payment_method = 'card' THEN amount ELSE 0 END), 0) AS pm_card,
                COALESCE(SUM(CASE WHEN payment_method = 'cheque' THEN amount ELSE 0 END), 0) AS pm_cheque,
                COALESCE(SUM(CASE WHEN payment_method = 'other' THEN amount ELSE 0 END), 0) AS pm_other
            FROM payments {$where}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
        'payment_count' => (int) ($row['payment_count'] ?? 0),
        'total_payments' => (float) ($row['total_payments'] ?? 0.0),
        'today_payments' => (float) ($row['today_payments'] ?? 0.0),
        'this_month_payments' => (float) ($row['this_month_payments'] ?? 0.0),
        'payment_methods' => [
            'cash' => (float) ($row['pm_cash'] ?? 0.0),
            'bank_transfer' => (float) ($row['pm_bank_transfer'] ?? 0.0),
            'upi' => (float) ($row['pm_upi'] ?? 0.0),
            'card' => (float) ($row['pm_card'] ?? 0.0),
            'cheque' => (float) ($row['pm_cheque'] ?? 0.0),
            'other' => (float) ($row['pm_other'] ?? 0.0),
        ],
    ];
}

/**
 * Campaign Statistics
 */
function getCampaignStats(PDO $pdo, ?string $fromDate, ?string $toDate): array
{
    $where = '';
    $params = [];
    if ($fromDate !== null && $toDate !== null) {
        $where = 'WHERE start_date BETWEEN :from_date AND :to_date';
        $params = [':from_date' => $fromDate, ':to_date' => $toDate];
    }

    $sql = "SELECT 
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'planned' THEN 1 ELSE 0 END) AS planned,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active,
                SUM(CASE WHEN status = 'paused' THEN 1 ELSE 0 END) AS paused,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
                SUM(CASE WHEN status = 'active' AND start_date <= CURRENT_DATE() AND end_date >= CURRENT_DATE() THEN 1 ELSE 0 END) AS active_today,
                SUM(CASE WHEN start_date > CURRENT_DATE() AND status NOT IN ('cancelled', 'completed') THEN 1 ELSE 0 END) AS upcoming
            FROM campaigns {$where}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
        'total_campaigns' => (int) ($row['total'] ?? 0),
        'planned_campaigns' => (int) ($row['planned'] ?? 0),
        'active_campaigns' => (int) ($row['active'] ?? 0),
        'paused_campaigns' => (int) ($row['paused'] ?? 0),
        'completed_campaigns' => (int) ($row['completed'] ?? 0),
        'cancelled_campaigns' => (int) ($row['cancelled'] ?? 0),
        'active_campaigns_today' => (int) ($row['active_today'] ?? 0),
        'upcoming_campaigns' => (int) ($row['upcoming'] ?? 0),
    ];
}

/**
 * Campaign Progress Statistics
 */
function getCampaignProgressStats(PDO $pdo): array
{
    $sql = "SELECT 
                COALESCE(AVG(progress), 0) AS avg_progress,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_cnt,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_cnt
            FROM campaigns
            WHERE status IN ('planned', 'active', 'paused')";

    $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);

    return [
        'average_progress' => round((float) ($row['avg_progress'] ?? 0.0), 2),
        'active_campaigns' => (int) ($row['active_cnt'] ?? 0),
        'completed_campaigns' => (int) ($row['completed_cnt'] ?? 0),
    ];
}

/**
 * Upcoming Campaigns List (Limit N)
 */
function getUpcomingCampaigns(PDO $pdo, int $limit = 5): array
{
    $sql = "SELECT 
                cmp.id,
                cmp.campaign_number,
                cmp.campaign_name,
                c.company_name AS customer_name,
                cmp.start_date,
                cmp.end_date,
                cmp.status,
                cmp.progress
            FROM campaigns cmp
            JOIN customers c ON cmp.customer_id = c.id
            WHERE cmp.start_date >= CURRENT_DATE() AND cmp.status NOT IN ('cancelled', 'completed')
            ORDER BY cmp.start_date ASC
            LIMIT :limit";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return array_map(static function (array $r): array {
        return [
            'id' => (int) $r['id'],
            'campaign_number' => $r['campaign_number'],
            'campaign_name' => $r['campaign_name'],
            'customer_name' => $r['customer_name'],
            'start_date' => $r['start_date'],
            'end_date' => $r['end_date'],
            'status' => $r['status'],
            'progress' => (float) $r['progress'],
        ];
    }, $rows);
}

/**
 * Recent Payments List (Limit N)
 */
function getRecentPayments(PDO $pdo, int $limit = 5): array
{
    $sql = "SELECT 
                p.id AS payment_id,
                i.invoice_number,
                c.company_name AS customer_name,
                p.amount,
                p.payment_date,
                p.payment_method,
                p.payment_reference AS reference_number
            FROM payments p
            JOIN invoices i ON p.invoice_id = i.id
            JOIN customers c ON p.customer_id = c.id
            ORDER BY p.payment_date DESC, p.created_at DESC
            LIMIT :limit";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return array_map(static function (array $r): array {
        return [
            'payment_id' => (int) $r['payment_id'],
            'invoice_number' => $r['invoice_number'],
            'customer_name' => $r['customer_name'],
            'amount' => (float) $r['amount'],
            'payment_date' => $r['payment_date'],
            'payment_method' => $r['payment_method'],
            'reference_number' => $r['reference_number'],
        ];
    }, $rows);
}

/**
 * Recent Quotations List (Limit N)
 */
function getRecentQuotations(PDO $pdo, int $limit = 5): array
{
    $sql = "SELECT 
                q.id,
                q.quotation_number,
                c.company_name AS customer_name,
                q.total_amount,
                q.status,
                q.quotation_date,
                q.valid_until
            FROM quotations q
            JOIN customers c ON q.customer_id = c.id
            ORDER BY q.id DESC
            LIMIT :limit";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return array_map(static function (array $r): array {
        return [
            'id' => (int) $r['id'],
            'quotation_number' => $r['quotation_number'],
            'customer_name' => $r['customer_name'],
            'total_amount' => (float) $r['total_amount'],
            'status' => $r['status'],
            'quotation_date' => $r['quotation_date'],
            'valid_until' => $r['valid_until'],
        ];
    }, $rows);
}

/**
 * Recent Invoices List (Limit N)
 */
function getRecentInvoices(PDO $pdo, int $limit = 5): array
{
    $sql = "SELECT 
                i.id,
                i.invoice_number,
                c.company_name AS customer_name,
                i.total_amount,
                i.paid_amount,
                i.balance_amount,
                CASE
                    WHEN i.status = 'cancelled' THEN 'cancelled'
                    WHEN i.paid_amount >= i.total_amount THEN 'paid'
                    WHEN i.paid_amount > 0 THEN 'partial'
                    WHEN i.balance_amount > 0 AND CURRENT_DATE() > i.due_date THEN 'overdue'
                    ELSE 'unpaid'
                END AS computed_status,
                i.invoice_date,
                i.due_date
            FROM invoices i
            JOIN customers c ON i.customer_id = c.id
            ORDER BY i.id DESC
            LIMIT :limit";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return array_map(static function (array $r): array {
        return [
            'id' => (int) $r['id'],
            'invoice_number' => $r['invoice_number'],
            'customer_name' => $r['customer_name'],
            'total_amount' => (float) $r['total_amount'],
            'paid_amount' => (float) $r['paid_amount'],
            'balance_amount' => (float) $r['balance_amount'],
            'status' => $r['computed_status'],
            'invoice_date' => $r['invoice_date'],
            'due_date' => $r['due_date'],
        ];
    }, $rows);
}

/**
 * Top Customers Endpoint: GET /api/dashboard.php?action=top_customers
 */
function handleTopCustomers(PDO $pdo): void
{
    $sql = "SELECT 
                c.id AS customer_id,
                c.company_name,
                c.contact_person,
                COUNT(i.id) AS invoice_count,
                COALESCE(SUM(i.total_amount), 0) AS total_invoiced,
                COALESCE(SUM(i.paid_amount), 0) AS total_paid,
                COALESCE(SUM(i.balance_amount), 0) AS outstanding
            FROM customers c
            JOIN invoices i ON c.id = i.customer_id AND i.status != 'cancelled'
            GROUP BY c.id
            ORDER BY total_invoiced DESC
            LIMIT 5";

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $topCustomers = array_map(static function (array $r): array {
        return [
            'customer_id' => (int) $r['customer_id'],
            'company_name' => $r['company_name'],
            'contact_person' => $r['contact_person'],
            'invoice_count' => (int) $r['invoice_count'],
            'total_invoiced' => (float) $r['total_invoiced'],
            'total_paid' => (float) $r['total_paid'],
            'outstanding' => (float) $r['outstanding'],
        ];
    }, $rows);

    sendSuccessResponse('Top customers fetched successfully.', $topCustomers);
}

/**
 * Screen Performance Endpoint: GET /api/dashboard.php?action=screen_performance
 */
function handleScreenPerformance(PDO $pdo): void
{
    $sql = "SELECT 
                s.id AS screen_id,
                s.name AS screen_name,
                s.screen_type,
                s.city,
                COUNT(cs.id) AS booking_count,
                COALESCE(SUM(CASE WHEN cs.status IN ('reserved', 'active') THEN 1 ELSE 0 END), 0) AS active_booking_count,
                COALESCE(SUM(cs.agreed_monthly_rate), 0) AS total_booking_value
            FROM screens s
            LEFT JOIN campaign_screens cs ON s.id = cs.screen_id AND cs.status != 'cancelled'
            GROUP BY s.id
            ORDER BY booking_count DESC, total_booking_value DESC
            LIMIT 10";

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $performance = array_map(static function (array $r): array {
        return [
            'screen_id' => (int) $r['screen_id'],
            'screen_name' => $r['screen_name'],
            'screen_type' => $r['screen_type'],
            'city' => $r['city'],
            'booking_count' => (int) $r['booking_count'],
            'active_booking_count' => (int) $r['active_booking_count'],
            'total_booking_value' => (float) $r['total_booking_value'],
        ];
    }, $rows);

    sendSuccessResponse('Screen performance data fetched successfully.', $performance);
}

/**
 * Revenue Trend Endpoint: GET /api/dashboard.php?action=revenue_trend&months=6
 */
function handleRevenueTrend(PDO $pdo): void
{
    $months = isset($_GET['months']) ? filter_var($_GET['months'], FILTER_VALIDATE_INT) : 6;
    if ($months === false || $months < 1) {
        $months = 6;
    } elseif ($months > 12) {
        $months = 12;
    }

    $trend = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $ym = date('Y-m', strtotime("-{$i} month"));

        // Invoiced amount in month
        $invSql = "SELECT COALESCE(SUM(total_amount), 0) FROM invoices 
                   WHERE status != 'cancelled' AND DATE_FORMAT(invoice_date, '%Y-%m') = :ym";
        $invStmt = $pdo->prepare($invSql);
        $invStmt->execute([':ym' => $ym]);
        $invoiced = (float) $invStmt->fetchColumn();

        // Paid amount in month
        $paySql = "SELECT COALESCE(SUM(amount), 0) FROM payments 
                   WHERE DATE_FORMAT(payment_date, '%Y-%m') = :ym";
        $payStmt = $pdo->prepare($paySql);
        $payStmt->execute([':ym' => $ym]);
        $paid = (float) $payStmt->fetchColumn();

        $trend[] = [
            'month' => $ym,
            'invoiced' => $invoiced,
            'paid' => $paid,
        ];
    }

    sendSuccessResponse('Revenue trend fetched successfully.', $trend);
}

/**
 * Campaign Trend Endpoint: GET /api/dashboard.php?action=campaign_trend
 */
function handleCampaignTrend(PDO $pdo): void
{
    $months = 6;
    $trend = [];

    for ($i = $months - 1; $i >= 0; $i--) {
        $ym = date('Y-m', strtotime("-{$i} month"));

        $sql = "SELECT 
                    SUM(CASE WHEN status = 'planned' THEN 1 ELSE 0 END) AS planned,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
                    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled
                FROM campaigns
                WHERE DATE_FORMAT(start_date, '%Y-%m') = :ym";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':ym' => $ym]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $trend[] = [
            'month' => $ym,
            'planned' => (int) ($row['planned'] ?? 0),
            'active' => (int) ($row['active'] ?? 0),
            'completed' => (int) ($row['completed'] ?? 0),
            'cancelled' => (int) ($row['cancelled'] ?? 0),
        ];
    }

    sendSuccessResponse('Campaign trend fetched successfully.', $trend);
}

/**
 * Dashboard Alerts Endpoint: GET /api/dashboard.php?action=alerts
 */
function handleAlerts(PDO $pdo): void
{
    // 1. Expiring Quotations (valid_until between today and next 7 days)
    $expQuoteSql = "SELECT 
                        q.id,
                        q.quotation_number,
                        c.company_name AS customer_name,
                        q.total_amount,
                        q.valid_until,
                        q.status
                    FROM quotations q
                    JOIN customers c ON q.customer_id = c.id
                    WHERE q.status IN ('draft', 'generated', 'pending_approval')
                      AND q.valid_until >= CURRENT_DATE()
                      AND q.valid_until <= DATE_ADD(CURRENT_DATE(), INTERVAL 7 DAY)
                    ORDER BY q.valid_until ASC
                    LIMIT 5";
    $expQuotations = $pdo->query($expQuoteSql)->fetchAll(PDO::FETCH_ASSOC);

    // 2. Overdue Invoices
    $overdueInvSql = "SELECT 
                        i.id,
                        i.invoice_number,
                        c.company_name AS customer_name,
                        i.balance_amount,
                        i.due_date,
                        'overdue' AS status
                      FROM invoices i
                      JOIN customers c ON i.customer_id = c.id
                      WHERE i.status != 'cancelled'
                        AND i.balance_amount > 0
                        AND i.due_date < CURRENT_DATE()
                      ORDER BY i.due_date ASC
                      LIMIT 5";
    $overdueInvoices = $pdo->query($overdueInvSql)->fetchAll(PDO::FETCH_ASSOC);

    // 3. Upcoming Campaigns (starting within 7 days)
    $upCmpSql = "SELECT 
                    cmp.id,
                    cmp.campaign_number,
                    cmp.campaign_name,
                    c.company_name AS customer_name,
                    cmp.start_date,
                    cmp.end_date,
                    cmp.status
                 FROM campaigns cmp
                 JOIN customers c ON cmp.customer_id = c.id
                 WHERE cmp.status NOT IN ('cancelled', 'completed')
                   AND cmp.start_date >= CURRENT_DATE()
                   AND cmp.start_date <= DATE_ADD(CURRENT_DATE(), INTERVAL 7 DAY)
                 ORDER BY cmp.start_date ASC
                 LIMIT 5";
    $upcomingCampaigns = $pdo->query($upCmpSql)->fetchAll(PDO::FETCH_ASSOC);

    // 4. Unpaid / Outstanding Invoices
    $unpaidInvSql = "SELECT 
                        i.id,
                        i.invoice_number,
                        c.company_name AS customer_name,
                        i.total_amount,
                        i.balance_amount,
                        i.due_date,
                        i.status
                     FROM invoices i
                     JOIN customers c ON i.customer_id = c.id
                     WHERE i.status IN ('unpaid', 'partial')
                       AND i.balance_amount > 0
                     ORDER BY i.id DESC
                     LIMIT 5";
    $unpaidInvoices = $pdo->query($unpaidInvSql)->fetchAll(PDO::FETCH_ASSOC);

    $alerts = [
        'expiring_quotations' => array_map(static function (array $r): array {
            return [
                'id' => (int) $r['id'],
                'quotation_number' => $r['quotation_number'],
                'customer_name' => $r['customer_name'],
                'total_amount' => (float) $r['total_amount'],
                'valid_until' => $r['valid_until'],
                'status' => $r['status'],
            ];
        }, $expQuotations),
        'overdue_invoices' => array_map(static function (array $r): array {
            return [
                'id' => (int) $r['id'],
                'invoice_number' => $r['invoice_number'],
                'customer_name' => $r['customer_name'],
                'balance_amount' => (float) $r['balance_amount'],
                'due_date' => $r['due_date'],
                'status' => $r['status'],
            ];
        }, $overdueInvoices),
        'upcoming_campaigns' => array_map(static function (array $r): array {
            return [
                'id' => (int) $r['id'],
                'campaign_number' => $r['campaign_number'],
                'campaign_name' => $r['campaign_name'],
                'customer_name' => $r['customer_name'],
                'start_date' => $r['start_date'],
                'end_date' => $r['end_date'],
                'status' => $r['status'],
            ];
        }, $upcomingCampaigns),
        'unpaid_invoices' => array_map(static function (array $r): array {
            return [
                'id' => (int) $r['id'],
                'invoice_number' => $r['invoice_number'],
                'customer_name' => $r['customer_name'],
                'total_amount' => (float) $r['total_amount'],
                'balance_amount' => (float) $r['balance_amount'],
                'due_date' => $r['due_date'],
                'status' => $r['status'],
            ];
        }, $unpaidInvoices),
    ];

    sendSuccessResponse('Dashboard alerts fetched successfully.', $alerts);
}
