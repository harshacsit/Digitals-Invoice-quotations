<?php

declare(strict_types=1);

/**
 * AdsDash SMTP Email Configuration
 * Reads configuration from environment variables or local .env file.
 *
 * DO NOT hardcode real credentials in this file.
 */

// Auto-load .env file if present in project root
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($lines)) {
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_contains($line, '=')) {
                [$key, $val] = explode('=', $line, 2);
                $key = trim($key);
                $val = trim($val, " \t\n\r\0\x0B\"'");
                if (getenv($key) === false || getenv($key) === '') {
                    putenv("{$key}={$val}");
                    $_ENV[$key] = $val;
                }
            }
        }
    }
}

return [
    'host' => getenv('MAIL_HOST') ?: '',
    'port' => (int) (getenv('MAIL_PORT') ?: 587),
    'username' => getenv('MAIL_USERNAME') ?: '',
    'password' => getenv('MAIL_PASSWORD') ?: '',
    'encryption' => strtolower(getenv('MAIL_ENCRYPTION') ?: 'tls'),
    'from_address' => getenv('MAIL_FROM_ADDRESS') ?: '',
    'from_name' => getenv('MAIL_FROM_NAME') ?: 'Bhimavaram Digitals',
];
