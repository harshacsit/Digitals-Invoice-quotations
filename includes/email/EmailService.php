<?php

declare(strict_types=1);

require_once __DIR__ . '/MailConfig.php';
require_once __DIR__ . '/../../config/database.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

class EmailService
{
    private MailConfig $mailConfig;
    private ?PDO $pdo;

    public function __construct(?MailConfig $mailConfig = null, ?PDO $pdo = null)
    {
        $this->mailConfig = $mailConfig ?? new MailConfig();
        if ($pdo !== null) {
            $this->pdo = $pdo;
        } else {
            global $pdo;
            $this->pdo = $pdo instanceof PDO ? $pdo : null;
        }
    }

    /**
     * Send email using PHPMailer & SMTP, logging status to email_logs database table.
     *
     * Expected $params array structure:
     * [
     *   'to' => 'customer@example.com',        // string or array of emails
     *   'recipient_name' => 'Ravi Kumar',     // optional
     *   'subject' => 'Your Quotation',
     *   'html' => '<h1>Quotation</h1>',
     *   'text' => 'Plain text version',
     *   'email_type' => 'quotation',           // quotation|invoice|payment|campaign|system|other
     *   'reference_type' => 'quotation',       // optional
     *   'reference_id' => 10,                 // optional
     *   'sent_by' => 1,                        // optional user_id
     *   'reply_to' => 'billing@example.com',   // optional
     *   'attachments' => [                     // optional
     *       ['path' => '/path/to/file.pdf', 'name' => 'Quotation-QT-1001.pdf']
     *   ]
     * ]
     */
    public function send(array $params): array
    {
        // 1. Validate Recipient Email(s)
        $to = $params['to'] ?? null;
        $recipients = [];

        if (is_string($to)) {
            $to = trim($to);
            if ($to !== '') {
                $recipients[] = $to;
            }
        } elseif (is_array($to)) {
            foreach ($to as $email) {
                if (is_string($email) && trim($email) !== '') {
                    $recipients[] = trim($email);
                }
            }
        }

        if ($recipients === []) {
            return [
                'success' => false,
                'message' => 'Unable to send email. Invalid recipient address.'
            ];
        }

        foreach ($recipients as $email) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return [
                    'success' => false,
                    'message' => 'Unable to send email. Invalid recipient email format.'
                ];
            }
        }

        // 2. Validate Subject & Body
        $subject = trim((string) ($params['subject'] ?? ''));
        $htmlBody = (string) ($params['html'] ?? '');
        $textBody = (string) ($params['text'] ?? strip_tags($htmlBody));

        if ($subject === '') {
            return [
                'success' => false,
                'message' => 'Unable to send email. Email subject is required.'
            ];
        }

        if ($htmlBody === '' && $textBody === '') {
            return [
                'success' => false,
                'message' => 'Unable to send email. Email body content is required.'
            ];
        }

        // 3. Validate Attachments
        $attachments = $params['attachments'] ?? [];
        $validatedAttachments = [];

        if (is_array($attachments)) {
            foreach ($attachments as $att) {
                if (!is_array($att) || empty($att['path'])) {
                    continue;
                }
                $path = (string) $att['path'];
                $name = (string) ($att['name'] ?? basename($path));

                // Security check: Must not be remote URL & must exist locally
                if (filter_var($path, FILTER_VALIDATE_URL) !== false) {
                    error_log("[EmailService] Security Warning: Remote attachment URL blocked: {$path}");
                    return [
                        'success' => false,
                        'message' => 'Unable to send email. Remote attachment URLs are not permitted.'
                    ];
                }

                if (!file_exists($path) || !is_readable($path)) {
                    error_log("[EmailService] Attachment file not found or unreadable: {$path}");
                    return [
                        'success' => false,
                        'message' => 'Unable to send email. Attachment file not found.'
                    ];
                }

                $validatedAttachments[] = ['path' => $path, 'name' => $name];
            }
        }

        // 4. Parse Metadata for Logging
        $allowedTypes = ['quotation', 'invoice', 'payment', 'campaign', 'system', 'other'];
        $rawType = strtolower(trim((string) ($params['email_type'] ?? 'other')));
        $emailType = in_array($rawType, $allowedTypes, true) ? $rawType : 'other';

        $referenceType = isset($params['reference_type']) ? trim((string) $params['reference_type']) : null;
        if ($referenceType === '') {
            $referenceType = null;
        }

        $referenceId = isset($params['reference_id']) ? filter_var($params['reference_id'], FILTER_VALIDATE_INT) : null;
        if ($referenceId === false || $referenceId <= 0) {
            $referenceId = null;
        }

        $sentBy = isset($params['sent_by']) ? filter_var($params['sent_by'], FILTER_VALIDATE_INT) : null;
        if ($sentBy === false || $sentBy <= 0) {
            $sentBy = null;
        }

        $recipientName = isset($params['recipient_name']) ? trim((string) $params['recipient_name']) : null;
        if ($recipientName === '') {
            $recipientName = null;
        }

        $attachmentName = null;
        if ($validatedAttachments !== []) {
            $names = array_column($validatedAttachments, 'name');
            $attachmentName = implode(', ', $names);
            if (strlen($attachmentName) > 255) {
                $attachmentName = substr($attachmentName, 0, 252) . '...';
            }
        }

        // 5. Create Initial email_logs Records (Status = 'queued')
        $logIds = [];
        foreach ($recipients as $recipientEmail) {
            $logId = $this->createEmailLog([
                'recipient_email' => $recipientEmail,
                'recipient_name' => $recipientName,
                'subject' => $subject,
                'email_type' => $emailType,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'attachment_name' => $attachmentName,
                'status' => 'queued',
                'sent_by' => $sentBy,
            ]);
            if ($logId > 0) {
                $logIds[$recipientEmail] = $logId;
            }
        }

        // 6. Validate SMTP Configuration
        if (!$this->mailConfig->isConfigured()) {
            error_log('[EmailService] SMTP configuration is incomplete or missing.');
            foreach ($logIds as $logId) {
                $this->updateEmailLogStatus($logId, 'failed', null, 'SMTP configuration is missing.');
            }
            return [
                'success' => false,
                'message' => 'Unable to send email. SMTP configuration is missing.'
            ];
        }

        // 7. Configure PHPMailer & Send SMTP Email
        $credentials = $this->mailConfig->getSmtpCredentials();

        try {
            $mail = new PHPMailer(true);

            // SMTP Setup
            $mail->isSMTP();
            $mail->Host = $credentials['host'];
            $mail->Port = $credentials['port'];
            $mail->SMTPAuth = true;
            $mail->Username = $credentials['username'];
            $mail->Password = $credentials['password'];

            // Encryption Setup
            if ($credentials['encryption'] === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }

            // Sender & Reply-To
            $mail->setFrom($credentials['from_address'], $credentials['from_name']);

            $replyTo = $params['reply_to'] ?? null;
            if (is_string($replyTo) && filter_var(trim($replyTo), FILTER_VALIDATE_EMAIL) !== false) {
                $mail->addReplyTo(trim($replyTo));
            }

            // Recipients
            foreach ($recipients as $email) {
                $mail->addAddress($email);
            }

            // Content
            if ($htmlBody !== '') {
                $mail->isHTML(true);
                $mail->Body = $htmlBody;
                $mail->AltBody = $textBody;
            } else {
                $mail->isHTML(false);
                $mail->Body = $textBody;
            }

            $mail->Subject = $subject;

            // Add Attachments
            foreach ($validatedAttachments as $att) {
                $mail->addAttachment($att['path'], $att['name']);
            }

            // Send Email
            $mail->send();

            $messageId = null;
            try {
                $messageId = $mail->getLastMessageID();
            } catch (Throwable $e) {
                // message_id optional
            }

            // Update log records to 'sent'
            foreach ($logIds as $logId) {
                $this->updateEmailLogStatus($logId, 'sent', $messageId, null);
            }

            return [
                'success' => true,
                'message' => 'Email sent successfully.'
            ];

        } catch (PHPMailerException $e) {
            $errorMsg = $e->getMessage();
            error_log('[EmailService] PHPMailer Error: ' . $errorMsg);
            foreach ($logIds as $logId) {
                $this->updateEmailLogStatus($logId, 'failed', null, $errorMsg);
            }
            return [
                'success' => false,
                'message' => 'Unable to send email.'
            ];
        } catch (Throwable $e) {
            $errorMsg = $e->getMessage();
            error_log('[EmailService] Unexpected Error: ' . $errorMsg);
            foreach ($logIds as $logId) {
                $this->updateEmailLogStatus($logId, 'failed', null, $errorMsg);
            }
            return [
                'success' => false,
                'message' => 'Unable to send email.'
            ];
        }
    }

    /**
     * Create email_logs database record with status = 'queued'
     */
    private function createEmailLog(array $data): int
    {
        if (!$this->pdo instanceof PDO) {
            return 0;
        }

        try {
            $sql = "INSERT INTO email_logs 
                        (recipient_email, recipient_name, subject, email_type, reference_type, reference_id, attachment_name, status, sent_by, created_at)
                    VALUES 
                        (:recipient_email, :recipient_name, :subject, :email_type, :reference_type, :reference_id, :attachment_name, :status, :sent_by, CURRENT_TIMESTAMP)";

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':recipient_email', $data['recipient_email'], PDO::PARAM_STR);
            $stmt->bindValue(':recipient_name', $data['recipient_name'], $data['recipient_name'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':subject', $data['subject'], PDO::PARAM_STR);
            $stmt->bindValue(':email_type', $data['email_type'], PDO::PARAM_STR);
            $stmt->bindValue(':reference_type', $data['reference_type'], $data['reference_type'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':reference_id', $data['reference_id'], $data['reference_id'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':attachment_name', $data['attachment_name'], $data['attachment_name'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':status', $data['status'], PDO::PARAM_STR);
            $stmt->bindValue(':sent_by', $data['sent_by'], $data['sent_by'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->execute();

            return (int) $this->pdo->lastInsertId();

        } catch (Throwable $e) {
            error_log('[EmailService] Database Log Error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Update email_logs record status ('sent' or 'failed')
     */
    private function updateEmailLogStatus(int $logId, string $status, ?string $messageId = null, ?string $errorMessage = null): void
    {
        if ($logId <= 0 || !$this->pdo instanceof PDO) {
            return;
        }

        try {
            if ($status === 'sent') {
                $sql = "UPDATE email_logs 
                        SET status = 'sent', 
                            sent_at = CURRENT_TIMESTAMP, 
                            message_id = :message_id,
                            last_attempt_at = CURRENT_TIMESTAMP,
                            next_retry_at = NULL 
                        WHERE id = :id";
                $stmt = $this->pdo->prepare($sql);
                $stmt->bindValue(':message_id', $messageId, $messageId === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $stmt->bindValue(':id', $logId, PDO::PARAM_INT);
                $stmt->execute();
            } elseif ($status === 'failed') {
                $sql = "UPDATE email_logs 
                        SET status = 'failed', 
                            error_message = :error_message,
                            last_attempt_at = CURRENT_TIMESTAMP,
                            next_retry_at = CASE 
                                WHEN attempt_count = 1 THEN DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 5 MINUTE)
                                WHEN attempt_count = 2 THEN DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 30 MINUTE)
                                ELSE NULL
                            END
                        WHERE id = :id";
                $stmt = $this->pdo->prepare($sql);
                $stmt->bindValue(':error_message', $errorMessage, $errorMessage === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $stmt->bindValue(':id', $logId, PDO::PARAM_INT);
                $stmt->execute();
            }
        } catch (Throwable $e) {
            error_log('[EmailService] Database Log Update Error: ' . $e->getMessage());
        }
    }
}
