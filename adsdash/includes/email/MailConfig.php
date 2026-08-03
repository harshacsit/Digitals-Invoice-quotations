<?php

declare(strict_types=1);

class MailConfig
{
    private array $rawConfig;

    public function __construct(?array $customConfig = null)
    {
        if ($customConfig !== null) {
            $this->rawConfig = $customConfig;
        } else {
            $configFile = __DIR__ . '/../../config/email.php';
            if (file_exists($configFile)) {
                $this->rawConfig = require $configFile;
            } else {
                $this->rawConfig = [];
            }
        }
    }

    /**
     * Check if required SMTP configuration parameters are present and non-placeholder.
     * Returns false if host, username, password, or from_address are missing/placeholders.
     */
    public function isConfigured(): bool
    {
        $host = trim((string) ($this->rawConfig['host'] ?? ''));
        $username = trim((string) ($this->rawConfig['username'] ?? ''));
        $password = trim((string) ($this->rawConfig['password'] ?? ''));
        $fromAddress = trim((string) ($this->rawConfig['from_address'] ?? ''));

        if ($host === '' || $username === '' || $password === '' || $fromAddress === '') {
            return false;
        }

        // Placeholder detection
        if (str_contains($host, 'example.com') || str_contains($username, 'your-email') || str_contains($password, 'your-smtp-password')) {
            return false;
        }

        return true;
    }

    /**
     * Get safe configuration representation for internal/diagnostic inspection.
     * NEVER returns password.
     */
    public function getConfig(): array
    {
        return [
            'host' => $this->rawConfig['host'] ?? '',
            'port' => (int) ($this->rawConfig['port'] ?? 587),
            'username' => $this->rawConfig['username'] ?? '',
            'encryption' => $this->rawConfig['encryption'] ?? 'tls',
            'from_address' => $this->rawConfig['from_address'] ?? '',
            'from_name' => $this->rawConfig['from_name'] ?? 'Bhimavaram Digitals',
            'is_configured' => $this->isConfigured(),
        ];
    }

    /**
     * Internal getter for EmailService. Returns complete credentials array.
     * NEVER expose directly to API responses or logs.
     */
    public function getSmtpCredentials(): array
    {
        return [
            'host' => trim((string) ($this->rawConfig['host'] ?? '')),
            'port' => (int) ($this->rawConfig['port'] ?? 587),
            'username' => trim((string) ($this->rawConfig['username'] ?? '')),
            'password' => (string) ($this->rawConfig['password'] ?? ''),
            'encryption' => trim(strtolower((string) ($this->rawConfig['encryption'] ?? 'tls'))),
            'from_address' => trim((string) ($this->rawConfig['from_address'] ?? '')),
            'from_name' => trim((string) ($this->rawConfig['from_name'] ?? 'Bhimavaram Digitals')),
        ];
    }
}
