<?php

require_once __DIR__ . '/../config/database.php';

$stmt = $pdo->query("DESCRIBE email_logs");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo "{$row['Field']} ({$row['Type']}) " . ($row['Null'] === 'YES' ? 'NULL' : 'NOT NULL') . " Default: {$row['Default']}\n";
}
