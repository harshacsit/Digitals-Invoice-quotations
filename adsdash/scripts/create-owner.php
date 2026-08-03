<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

if (php_sapi_name() !== 'cli') {
    echo "This script can only be executed via PHP CLI.\n";
    exit(1);
}

$options = getopt('', ['name:', 'email:', 'password:']);

$name = $options['name'] ?? getenv('OWNER_NAME') ?: 'System Owner';
$email = $options['email'] ?? getenv('OWNER_EMAIL') ?: 'admin@adsdash.local';
$password = $options['password'] ?? getenv('OWNER_PASSWORD') ?: 'AdminPassword@123';

if (trim($email) === '' || trim($password) === '') {
    echo "Error: Email and Password are required.\n";
    echo "Usage: php scripts/create-owner.php --name=\"Admin Owner\" --email=\"admin@adsdash.local\" --password=\"SecurePass123!\"\n";
    exit(1);
}

$email = trim(strtolower($email));
$name = trim($name);

try {
    // Check if email exists
    $stmt = $pdo->prepare('SELECT id, role FROM users WHERE email = :email');
    $stmt->bindValue(':email', $email, PDO::PARAM_STR);
    $stmt->execute();
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    if ($existing) {
        // Update existing user to owner role and update password
        $upd = $pdo->prepare('UPDATE users SET name = :name, password_hash = :hash, role = \'owner\', status = \'active\', updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $upd->bindValue(':name', $name, PDO::PARAM_STR);
        $upd->bindValue(':hash', $passwordHash, PDO::PARAM_STR);
        $upd->bindValue(':id', $existing['id'], PDO::PARAM_INT);
        $upd->execute();
        echo "Successfully updated existing user [{$email}] to Owner role.\n";
    } else {
        // Insert new owner user
        $ins = $pdo->prepare('INSERT INTO users (name, email, password_hash, role, status) VALUES (:name, :email, :hash, \'owner\', \'active\')');
        $ins->bindValue(':name', $name, PDO::PARAM_STR);
        $ins->bindValue(':email', $email, PDO::PARAM_STR);
        $ins->bindValue(':hash', $passwordHash, PDO::PARAM_STR);
        $ins->execute();
        echo "Successfully created new Owner account [{$email}].\n";
    }
} catch (Throwable $e) {
    echo "Database Error: " . $e->getMessage() . "\n";
    exit(1);
}
