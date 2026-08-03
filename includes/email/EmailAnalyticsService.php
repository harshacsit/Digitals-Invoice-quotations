<?php

declare(strict_types=1);

class EmailAnalyticsService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        if ($pdo !== null) {
            $this->pdo = $pdo;
        } else {
            global $pdo;
            $this->pdo = $pdo instanceof PDO ? $pdo : null;
        }
    }

    /**
     * Validate and normalize date parameters (YYYY-MM-DD).
     * Defaults to current month if missing or invalid.
     */
    public function validateDateRange(?string $fromInput, ?string $toInput): array
    {
        $currentMonthStart = date('Y-m-01');
        $today = date('Y-m-d');

        $from = trim((string) $fromInput);
        $to = trim((string) $toInput);

        if ($from === '' || !$this->isValidDate($from)) {
            $from = $currentMonthStart;
        }

        if ($to === '' || !$this->isValidDate($to)) {
            $to = $today;
        }

        // If from > to, swap them safely
        if ($from > $to) {
            $temp = $from;
            $from = $to;
            $to = $temp;
        }

        $fromTs = $from . ' 00:00:00';
        $toNextDayTs = date('Y-m-d 00:00:00', strtotime($to . ' +1 day'));

        return [
            'from' => $from,
            'to' => $to,
            'from_ts' => $fromTs,
            'to_next_day_ts' => $toNextDayTs,
        ];
    }

    private function isValidDate(string $date): bool
    {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }

    /**
     * Fetch complete analytics & reporting metrics for a date range.
     */
    public function getAnalyticsData(string $fromInput = '', string $toInput = ''): array
    {
        $range = $this->validateDateRange($fromInput, $toInput);
        $fromTs = $range['from_ts'];
        $toNextDayTs = $range['to_next_day_ts'];

        if (!$this->pdo instanceof PDO) {
            return $this->getEmptyAnalyticsStructure($range['from'], $range['to']);
        }

        try {
            // 1. Overall Summary Metrics
            $summarySql = "SELECT 
                            COUNT(*) AS total_emails,
                            SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent_emails,
                            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_emails,
                            SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) AS queued_emails,
                            SUM(CASE WHEN status = 'failed' AND attempt_count < 3 AND (next_retry_at IS NULL OR next_retry_at <= CURRENT_TIMESTAMP) THEN 1 ELSE 0 END) AS retry_pending_emails,
                            SUM(CASE WHEN attempt_count > 1 THEN 1 ELSE 0 END) AS retry_attempted
                         FROM email_logs
                         WHERE created_at >= :from_ts AND created_at < :to_next_day";

            $sumStmt = $this->pdo->prepare($summarySql);
            $sumStmt->bindValue(':from_ts', $fromTs, PDO::PARAM_STR);
            $sumStmt->bindValue(':to_next_day', $toNextDayTs, PDO::PARAM_STR);
            $sumStmt->execute();
            $sumRow = $sumStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $totalEmails = (int) ($sumRow['total_emails'] ?? 0);
            $sentEmails = (int) ($sumRow['sent_emails'] ?? 0);
            $failedEmails = (int) ($sumRow['failed_emails'] ?? 0);
            $queuedEmails = (int) ($sumRow['queued_emails'] ?? 0);
            $retryPendingEmails = (int) ($sumRow['retry_pending_emails'] ?? 0);
            $retryAttempted = (int) ($sumRow['retry_attempted'] ?? 0);

            $denominator = $sentEmails + $failedEmails;
            $successRate = $denominator > 0 ? round(($sentEmails / $denominator) * 100, 2) : 0.0;
            $failureRate = $denominator > 0 ? round(($failedEmails / $denominator) * 100, 2) : 0.0;
            $retryRate = $totalEmails > 0 ? round(($retryAttempted / $totalEmails) * 100, 2) : 0.0;

            $summary = [
                'total_emails' => $totalEmails,
                'sent_emails' => $sentEmails,
                'failed_emails' => $failedEmails,
                'queued_emails' => $queuedEmails,
                'retry_pending_emails' => $retryPendingEmails,
                'success_rate' => $successRate,
                'failure_rate' => $failureRate,
                'retry_rate' => $retryRate,
            ];

            // 2. Status Breakdown
            $statusBreakdown = [
                'queued' => $queuedEmails,
                'sent' => $sentEmails,
                'failed' => $failedEmails,
            ];

            // 3. Email Type Breakdown
            $typeSql = "SELECT 
                            email_type,
                            COUNT(*) AS total,
                            SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent,
                            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed
                        FROM email_logs
                        WHERE created_at >= :from_ts AND created_at < :to_next_day
                        GROUP BY email_type
                        ORDER BY total DESC";

            $typeStmt = $this->pdo->prepare($typeSql);
            $typeStmt->bindValue(':from_ts', $fromTs, PDO::PARAM_STR);
            $typeStmt->bindValue(':to_next_day', $toNextDayTs, PDO::PARAM_STR);
            $typeStmt->execute();
            $rawTypeRows = $typeStmt->fetchAll(PDO::FETCH_ASSOC);

            $typeBreakdown = [];
            $typeChartLabels = [];
            $typeChartValues = [];

            foreach ($rawTypeRows as $row) {
                $t = (int) $row['total'];
                $s = (int) $row['sent'];
                $f = (int) $row['failed'];
                $sr = ($s + $f) > 0 ? round(($s / ($s + $f)) * 100, 2) : 0.0;

                $typeBreakdown[] = [
                    'email_type' => $row['email_type'],
                    'total' => $t,
                    'sent' => $s,
                    'failed' => $f,
                    'success_rate' => $sr,
                ];

                $typeChartLabels[] = ucfirst((string) $row['email_type']);
                $typeChartValues[] = $t;
            }

            // 4. Daily Trend & Chart.js Format
            $trendSql = "SELECT 
                            DATE(created_at) AS log_date,
                            COUNT(*) AS total,
                            SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent,
                            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed
                         FROM email_logs
                         WHERE created_at >= :from_ts AND created_at < :to_next_day
                         GROUP BY DATE(created_at)
                         ORDER BY log_date ASC";

            $trStmt = $this->pdo->prepare($trendSql);
            $trStmt->bindValue(':from_ts', $fromTs, PDO::PARAM_STR);
            $trStmt->bindValue(':to_next_day', $toNextDayTs, PDO::PARAM_STR);
            $trStmt->execute();
            $trRows = $trStmt->fetchAll(PDO::FETCH_ASSOC);

            $dateMap = [];
            foreach ($trRows as $r) {
                $dateMap[$r['log_date']] = [
                    'total' => (int) $r['total'],
                    'sent' => (int) $r['sent'],
                    'failed' => (int) $r['failed'],
                ];
            }

            // Zero-fill missing dates
            $dailyTrend = [];
            $chartLabels = [];
            $chartSent = [];
            $chartFailed = [];

            $currDate = new DateTime($range['from']);
            $endDate = new DateTime($range['to']);

            while ($currDate <= $endDate) {
                $dStr = $currDate->format('Y-m-d');
                $dData = $dateMap[$dStr] ?? ['total' => 0, 'sent' => 0, 'failed' => 0];

                $dailyTrend[] = [
                    'date' => $dStr,
                    'total' => $dData['total'],
                    'sent' => $dData['sent'],
                    'failed' => $dData['failed'],
                ];

                $chartLabels[] = $dStr;
                $chartSent[] = $dData['sent'];
                $chartFailed[] = $dData['failed'];

                $currDate->modify('+1 day');
            }

            // 5. Failure Analysis
            $failTypeSql = "SELECT email_type, COUNT(*) AS failed_count 
                            FROM email_logs 
                            WHERE status = 'failed' AND created_at >= :from_ts AND created_at < :to_next_day 
                            GROUP BY email_type ORDER BY failed_count DESC";
            $ftStmt = $this->pdo->prepare($failTypeSql);
            $ftStmt->bindValue(':from_ts', $fromTs, PDO::PARAM_STR);
            $ftStmt->bindValue(':to_next_day', $toNextDayTs, PDO::PARAM_STR);
            $ftStmt->execute();
            $failByType = $ftStmt->fetchAll(PDO::FETCH_ASSOC);

            $failRefSql = "SELECT COALESCE(reference_type, 'system') AS reference_type, COUNT(*) AS failed_count 
                           FROM email_logs 
                           WHERE status = 'failed' AND created_at >= :from_ts AND created_at < :to_next_day 
                           GROUP BY reference_type ORDER BY failed_count DESC";
            $frStmt = $this->pdo->prepare($failRefSql);
            $frStmt->bindValue(':from_ts', $fromTs, PDO::PARAM_STR);
            $frStmt->bindValue(':to_next_day', $toNextDayTs, PDO::PARAM_STR);
            $frStmt->execute();
            $failByRef = $frStmt->fetchAll(PDO::FETCH_ASSOC);

            $errSql = "SELECT error_message, COUNT(*) AS err_count 
                       FROM email_logs 
                       WHERE status = 'failed' AND error_message IS NOT NULL AND created_at >= :from_ts AND created_at < :to_next_day 
                       GROUP BY error_message ORDER BY err_count DESC LIMIT 10";
            $errStmt = $this->pdo->prepare($errSql);
            $errStmt->bindValue(':from_ts', $fromTs, PDO::PARAM_STR);
            $errStmt->bindValue(':to_next_day', $toNextDayTs, PDO::PARAM_STR);
            $errStmt->execute();
            $rawErrRows = $errStmt->fetchAll(PDO::FETCH_ASSOC);

            $topErrors = [];
            foreach ($rawErrRows as $er) {
                $count = (int) $er['err_count'];
                $pct = $failedEmails > 0 ? round(($count / $failedEmails) * 100, 2) : 0.0;
                $topErrors[] = [
                    'error_message' => $this->sanitizeErrorString((string) $er['error_message']),
                    'count' => $count,
                    'percentage' => $pct,
                ];
            }

            // 6. Retry Analytics
            $retrySql = "SELECT 
                            SUM(CASE WHEN attempt_count > 1 THEN 1 ELSE 0 END) AS retry_attempted,
                            SUM(CASE WHEN status = 'failed' AND attempt_count < 3 AND (next_retry_at IS NULL OR next_retry_at <= CURRENT_TIMESTAMP) THEN 1 ELSE 0 END) AS retry_pending,
                            SUM(CASE WHEN status = 'failed' AND attempt_count >= 3 THEN 1 ELSE 0 END) AS max_attempt_reached,
                            COALESCE(AVG(attempt_count), 1.0) AS average_attempt_count
                         FROM email_logs
                         WHERE created_at >= :from_ts AND created_at < :to_next_day";

            $retStmt = $this->pdo->prepare($retrySql);
            $retStmt->bindValue(':from_ts', $fromTs, PDO::PARAM_STR);
            $retStmt->bindValue(':to_next_day', $toNextDayTs, PDO::PARAM_STR);
            $retStmt->execute();
            $retRow = $retStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $retryAnalytics = [
                'retry_attempted' => (int) ($retRow['retry_attempted'] ?? 0),
                'retry_pending' => (int) ($retRow['retry_pending'] ?? 0),
                'max_attempt_reached' => (int) ($retRow['max_attempt_reached'] ?? 0),
                'average_attempt_count' => round((float) ($retRow['average_attempt_count'] ?? 1.0), 2),
            ];

            // 7. Top Recipients (Limit 10)
            $recSql = "SELECT 
                            recipient_email,
                            COUNT(*) AS email_count,
                            SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent_count,
                            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count
                       FROM email_logs
                       WHERE created_at >= :from_ts AND created_at < :to_next_day
                       GROUP BY recipient_email
                       ORDER BY email_count DESC
                       LIMIT 10";

            $recStmt = $this->pdo->prepare($recSql);
            $recStmt->bindValue(':from_ts', $fromTs, PDO::PARAM_STR);
            $recStmt->bindValue(':to_next_day', $toNextDayTs, PDO::PARAM_STR);
            $recStmt->execute();
            $rawRecRows = $recStmt->fetchAll(PDO::FETCH_ASSOC);

            $topRecipients = [];
            foreach ($rawRecRows as $rr) {
                $ec = (int) $rr['email_count'];
                $sc = (int) $rr['sent_count'];
                $fc = (int) $rr['failed_count'];
                $sr = ($sc + $fc) > 0 ? round(($sc / ($sc + $fc)) * 100, 2) : 0.0;

                $topRecipients[] = [
                    'recipient_email' => $rr['recipient_email'],
                    'email_count' => $ec,
                    'sent_count' => $sc,
                    'failed_count' => $fc,
                    'success_rate' => $sr,
                ];
            }

            // 8. Reference Type Breakdown
            $refSql = "SELECT 
                            COALESCE(reference_type, 'system') AS reference_type,
                            COUNT(*) AS total,
                            SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent,
                            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed
                       FROM email_logs
                       WHERE created_at >= :from_ts AND created_at < :to_next_day
                       GROUP BY reference_type
                       ORDER BY total DESC";

            $refStmt = $this->pdo->prepare($refSql);
            $refStmt->bindValue(':from_ts', $fromTs, PDO::PARAM_STR);
            $refStmt->bindValue(':to_next_day', $toNextDayTs, PDO::PARAM_STR);
            $refStmt->execute();
            $rawRefRows = $refStmt->fetchAll(PDO::FETCH_ASSOC);

            $referenceBreakdown = [];
            foreach ($rawRefRows as $rf) {
                $referenceBreakdown[] = [
                    'reference_type' => $rf['reference_type'],
                    'total' => (int) $rf['total'],
                    'sent' => (int) $rf['sent'],
                    'failed' => (int) $rf['failed'],
                ];
            }

            return [
                'date_range' => [
                    'from' => $range['from'],
                    'to' => $range['to'],
                ],
                'summary' => $summary,
                'status_breakdown' => $statusBreakdown,
                'email_type_breakdown' => $typeBreakdown,
                'daily_trend' => $dailyTrend,
                'delivery_trend' => [
                    'labels' => $chartLabels,
                    'sent' => $chartSent,
                    'failed' => $chartFailed,
                ],
                'type_chart' => [
                    'labels' => $typeChartLabels,
                    'values' => $typeChartValues,
                ],
                'failure_analysis' => [
                    'by_type' => $failByType,
                    'by_reference' => $failByRef,
                    'top_errors' => $topErrors,
                ],
                'retry_analytics' => $retryAnalytics,
                'top_recipients' => $topRecipients,
                'reference_breakdown' => $referenceBreakdown,
            ];

        } catch (Throwable $e) {
            error_log('[EmailAnalyticsService] Exception: ' . $e->getMessage());
            return $this->getEmptyAnalyticsStructure($range['from'], $range['to']);
        }
    }

    /**
     * Generate CSV export content for aggregate analytics.
     */
    public function exportAnalyticsCsv(string $fromInput = '', string $toInput = ''): string
    {
        $data = $this->getAnalyticsData($fromInput, $toInput);

        $output = fopen('php://temp', 'r+');
        fputcsv($output, ['AdsDash Email Delivery Analytics Report']);
        fputcsv($output, ['Period:', $data['date_range']['from'] . ' to ' . $data['date_range']['to']]);
        fputcsv($output, []);

        // Summary Metrics Section
        fputcsv($output, ['Summary Metric', 'Value']);
        fputcsv($output, ['Total Emails', $data['summary']['total_emails']]);
        fputcsv($output, ['Sent Emails', $data['summary']['sent_emails']]);
        fputcsv($output, ['Failed Emails', $data['summary']['failed_emails']]);
        fputcsv($output, ['Queued Emails', $data['summary']['queued_emails']]);
        fputcsv($output, ['Retry Pending', $data['summary']['retry_pending_emails']]);
        fputcsv($output, ['Success Rate (%)', $data['summary']['success_rate'] . '%']);
        fputcsv($output, ['Failure Rate (%)', $data['summary']['failure_rate'] . '%']);
        fputcsv($output, ['Retry Rate (%)', $data['summary']['retry_rate'] . '%']);
        fputcsv($output, []);

        // Email Type Performance Section
        fputcsv($output, ['Email Type', 'Total', 'Sent', 'Failed', 'Success Rate (%)']);
        foreach ($data['email_type_breakdown'] as $t) {
            fputcsv($output, [$t['email_type'], $t['total'], $t['sent'], $t['failed'], $t['success_rate'] . '%']);
        }
        fputcsv($output, []);

        // Daily Delivery Trend Section
        fputcsv($output, ['Date', 'Total Emails', 'Sent', 'Failed']);
        foreach ($data['daily_trend'] as $d) {
            fputcsv($output, [$d['date'], $d['total'], $d['sent'], $d['failed']]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv ? $csv : '';
    }

    private function sanitizeErrorString(string $rawError): string
    {
        if (str_contains($rawError, 'SQL') || str_contains($rawError, 'Password') || str_contains($rawError, 'MAIL_PASSWORD')) {
            return 'Email delivery failed.';
        }
        // Strip file paths
        $clean = preg_replace('/[A-Z]:\\\\[^:]+\\\\/i', '', $rawError);
        return trim((string) $clean);
    }

    private function getEmptyAnalyticsStructure(string $from, string $to): array
    {
        return [
            'date_range' => ['from' => $from, 'to' => $to],
            'summary' => [
                'total_emails' => 0,
                'sent_emails' => 0,
                'failed_emails' => 0,
                'queued_emails' => 0,
                'retry_pending_emails' => 0,
                'success_rate' => 0.0,
                'failure_rate' => 0.0,
                'retry_rate' => 0.0,
            ],
            'status_breakdown' => ['queued' => 0, 'sent' => 0, 'failed' => 0],
            'email_type_breakdown' => [],
            'daily_trend' => [],
            'delivery_trend' => ['labels' => [], 'sent' => [], 'failed' => []],
            'type_chart' => ['labels' => [], 'values' => []],
            'failure_analysis' => ['by_type' => [], 'by_reference' => [], 'top_errors' => []],
            'retry_analytics' => [
                'retry_attempted' => 0,
                'retry_pending' => 0,
                'max_attempt_reached' => 0,
                'average_attempt_count' => 1.0,
            ],
            'top_recipients' => [],
            'reference_breakdown' => [],
        ];
    }
}
