<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo 'Database connection failed.';
    exit;
}

try {
    $stmt = $pdo->query('SELECT 1');
    if ($stmt !== false) {
        echo 'Database connected successfully!';
    } else {
        http_response_code(500);
        echo 'Database query failed.';
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Database query failed.';
}
