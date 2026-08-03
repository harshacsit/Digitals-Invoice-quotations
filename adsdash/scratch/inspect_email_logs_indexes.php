<?php

require_once __DIR__ . '/../config/database.php';

$stmt = $pdo->query("SHOW INDEX FROM email_logs");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo "Index: {$row['Key_name']} | Column: {$row['Column_name']}\n";
}
