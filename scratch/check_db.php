<?php
$passwords = ['', 'root', 'Harsha', 'harsha@123'];
foreach ($passwords as $p) {
    try {
        $pdo = new PDO("mysql:host=127.0.0.1;charset=utf8mb4", "root", $p);
        echo "SUCCESS: MySQL root password is '{$p}'\n";
        
        // Check if adsdash database exists
        $stmt = $pdo->query("SHOW DATABASES LIKE 'adsdash'");
        if ($stmt->fetch()) {
            echo "Database 'adsdash' exists!\n";
        } else {
            echo "Database 'adsdash' DOES NOT EXIST!\n";
        }
        exit(0);
    } catch (Exception $e) {
        echo "FAILED for password '{$p}': " . $e->getMessage() . "\n";
    }
}
