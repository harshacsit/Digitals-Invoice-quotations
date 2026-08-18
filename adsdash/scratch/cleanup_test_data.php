<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$stmt = $pdo->prepare("DELETE FROM email_logs WHERE recipient_email LIKE '%example.com%' OR recipient_email = 'invalid_email'");
$stmt->execute();
$count = $stmt->rowCount();

echo "Successfully cleaned up {$count} dummy test log records.\n";
