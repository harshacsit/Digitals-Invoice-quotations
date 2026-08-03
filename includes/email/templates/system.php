<?php

declare(strict_types=1);

require_once __DIR__ . '/EmailTemplate.php';

/**
 * Generate Generic System Notification Email Template
 *
 * @param array $data System notification information
 * @return array ['subject' => string, 'html' => string, 'text' => string]
 */
function generateSystemEmail(array $data): array
{
    $recipientName = EmailTemplate::e($data['recipient_name'] ?? 'User');
    $title = EmailTemplate::e($data['title'] ?? 'Account Notification');
    $message = EmailTemplate::e($data['message'] ?? 'You have a new notification from AdsDash.');

    $actionUrl = EmailTemplate::safeUrl($data['action_url'] ?? null);
    $actionText = EmailTemplate::e($data['action_text'] ?? 'View Details');

    $customSubject = !empty($data['subject']) ? trim((string) $data['subject']) : null;
    $subject = $customSubject ?: "Notification: {$title} — Bhimavaram Digitals";

    // Build Action Button HTML safely
    $actionButtonHtml = '';
    $actionTextPlain = '';
    if ($actionUrl !== null) {
        $actionButtonHtml = "<p style=\"text-align:center; margin-top:24px;\"><a href=\"{$actionUrl}\" class=\"btn-primary\">{$actionText}</a></p>";
        $actionTextPlain = "\nAction: {$actionText} ({$data['action_url']})";
    }

    // Build HTML Content
    $bodyHtml = <<<HTML
<h2 style="color:#0f172a; margin-top:0;">{$title}</h2>
<p>Hello <strong>{$recipientName}</strong>,</p>
<div style="background-color:#f8fafc; padding:16px; border-radius:6px; border:1px solid #e2e8f0; margin:16px 0; color:#334155;">
  {$message}
</div>
{$actionButtonHtml}
<p style="margin-top:24px;">Best regards,<br><strong>Bhimavaram Digitals System Admin</strong></p>
HTML;

    $html = EmailTemplate::wrapHtml($subject, $bodyHtml);

    // Build Plain Text Content
    $text = <<<TEXT
{$title} — Bhimavaram Digitals

Hello {$data['recipient_name']},

{$data['message']}{$actionTextPlain}

Regards,
Bhimavaram Digitals System Admin
TEXT;

    return [
        'subject' => $subject,
        'html' => $html,
        'text' => $text,
    ];
}
