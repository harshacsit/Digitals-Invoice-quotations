<?php

require_once __DIR__ . '/../config/database.php';

try {
    $colsStmt = $pdo->query("SHOW COLUMNS FROM email_logs LIKE 'attempt_count'");
    if (!$colsStmt->fetch()) {
        $pdo->exec("ALTER TABLE email_logs 
            ADD COLUMN attempt_count INT UNSIGNED NOT NULL DEFAULT 1 AFTER status,
            ADD COLUMN last_attempt_at DATETIME NULL DEFAULT NULL AFTER attempt_count,
            ADD COLUMN next_retry_at DATETIME NULL DEFAULT NULL AFTER last_attempt_at");
        echo "[MIGRATION] Added attempt_count, last_attempt_at, next_retry_at to email_logs table.\n";
    } else {
        echo "[MIGRATION] Columns attempt_count, last_attempt_at, next_retry_at already exist in email_logs.\n";
    }
} catch (Throwable $e) {
    echo "[MIGRATION EXCEPTION] " . $e->getMessage() . "\n";
}
