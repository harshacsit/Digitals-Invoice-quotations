<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/EmailDispatcher.php';

class EmailRetryService
{
    private PDO $pdo;
    private EmailDispatcher $dispatcher;
    private int $maxAttempts = 3;

    public function __construct(?PDO $pdo = null, ?EmailDispatcher $dispatcher = null)
    {
        if ($pdo !== null) {
            $this->pdo = $pdo;
        } else {
            global $pdo;
            $this->pdo = $pdo instanceof PDO ? $pdo : null;
        }

        $this->dispatcher = $dispatcher ?? new EmailDispatcher($this->pdo);
    }

    /**
     * Get all failed email logs eligible for automatic retry.
     * Criteria: status = 'failed', attempt_count < 3, (next_retry_at IS NULL OR next_retry_at <= CURRENT_TIMESTAMP)
     */
    public function getPendingRetries(int $limit = 50): array
    {
        if (!$this->pdo instanceof PDO) {
            return [];
        }

        try {
            $sql = "SELECT id, recipient_email, recipient_name, subject, email_type, reference_type, reference_id, attempt_count, status, error_message, created_at
                    FROM email_logs
                    WHERE status = 'failed'
                      AND attempt_count < :max_attempts
                      AND (next_retry_at IS NULL OR next_retry_at <= CURRENT_TIMESTAMP)
                    ORDER BY id ASC
                    LIMIT :limit";

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':max_attempts', $this->maxAttempts, PDO::PARAM_INT);
            $stmt->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('[EmailRetryService] getPendingRetries Exception: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Retry a specific failed email log entry by ID.
     * Manual retries bypass next_retry_at delay but still respect status != 'sent' and maxAttempts.
     */
    public function retryEmail(int $logId, bool $isManual = false): array
    {
        if ($logId <= 0 || !$this->pdo instanceof PDO) {
            return ['success' => false, 'message' => 'Valid email log ID is required.'];
        }

        try {
            // 1. Fetch Log Record
            $stmt = $this->pdo->prepare("SELECT * FROM email_logs WHERE id = :id FOR UPDATE");
            $stmt->bindValue(':id', $logId, PDO::PARAM_INT);
            $stmt->execute();
            $log = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$log) {
                return ['success' => false, 'message' => 'Email log entry not found.'];
            }

            // 2. Validate Status
            if ($log['status'] === 'sent') {
                return ['success' => false, 'message' => 'Email has already been sent successfully.'];
            }

            $currentAttempts = (int) ($log['attempt_count'] ?? 1);

            // 3. Enforce Max Attempts (Manual retries can override limit up to maxAttempts + 1 if explicitly triggered by admin)
            if ($currentAttempts >= $this->maxAttempts && !$isManual) {
                return ['success' => false, 'message' => 'Maximum retry attempts reached.'];
            }

            // 4. Increment Attempt Counter & Set Last Attempt Timestamp
            $newAttemptCount = $currentAttempts + 1;
            $updStmt = $this->pdo->prepare("UPDATE email_logs SET attempt_count = :ac, last_attempt_at = CURRENT_TIMESTAMP WHERE id = :id");
            $updStmt->bindValue(':ac', $newAttemptCount, PDO::PARAM_INT);
            $updStmt->bindValue(':id', $logId, PDO::PARAM_INT);
            $updStmt->execute();

            // 5. Re-dispatch email using EmailDispatcher based on reference_type / email_type
            $refType = strtolower(trim((string) ($log['reference_type'] ?? '')));
            $refId = (int) ($log['reference_id'] ?? 0);
            $emailType = strtolower(trim((string) ($log['email_type'] ?? '')));
            $recipientEmail = trim((string) $log['recipient_email']);
            $recipientName = $log['recipient_name'] ? trim((string) $log['recipient_name']) : null;
            $sentBy = $log['sent_by'] ? (int) $log['sent_by'] : null;

            $result = ['success' => false, 'message' => 'Unsupported email operation.'];

            if ($refType === 'quotation' || $emailType === 'quotation') {
                if ($refId > 0) {
                    $result = $this->dispatcher->sendQuotation($refId, $recipientEmail, $recipientName, $sentBy);
                }
            } elseif ($refType === 'invoice' || $emailType === 'invoice') {
                if ($refId > 0) {
                    $result = $this->dispatcher->sendInvoice($refId, $recipientEmail, $recipientName, $sentBy);
                }
            } elseif ($refType === 'payment' || $emailType === 'payment') {
                if ($refId > 0) {
                    $result = $this->dispatcher->sendPaymentReceipt($refId, $recipientEmail, $recipientName, $sentBy);
                }
            } elseif ($refType === 'campaign' || $emailType === 'campaign') {
                if ($refId > 0) {
                    $result = $this->dispatcher->sendCampaignUpdate($refId, $recipientEmail, $recipientName, $sentBy);
                }
            } elseif ($emailType === 'system' || $emailType === 'other') {
                $result = $this->dispatcher->sendSystemNotification(
                    $recipientEmail,
                    $recipientName,
                    $log['subject'],
                    'System Notification (Retry)',
                    null,
                    null,
                    $sentBy
                );
            }

            return $result;

        } catch (Throwable $e) {
            error_log('[EmailRetryService] retryEmail Exception: ' . $e->getMessage());
            return ['success' => false, 'message' => 'An error occurred while retrying the email.'];
        }
    }

    /**
     * Run automatic retry on all pending eligible failed emails.
     */
    public function retryAllEligible(int $limit = 50): array
    {
        $pending = $this->getPendingRetries($limit);
        $total = count($pending);

        if ($total === 0) {
            return [
                'success' => true,
                'retried' => 0,
                'succeeded' => 0,
                'failed' => 0,
                'message' => 'No email retries pending.'
            ];
        }

        $succeeded = 0;
        $failed = 0;

        foreach ($pending as $log) {
            $logId = (int) $log['id'];
            $res = $this->retryEmail($logId, false);
            if (!empty($res['success'])) {
                $succeeded++;
            } else {
                $failed++;
            }
        }

        return [
            'success' => true,
            'retried' => $total,
            'succeeded' => $succeeded,
            'failed' => $failed,
            'message' => "Executed retries on {$total} emails: {$succeeded} succeeded, {$failed} failed."
        ];
    }
}
