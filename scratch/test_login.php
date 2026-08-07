<?php
$ch = curl_init('http://127.0.0.1:8000/api/auth.php?action=login');
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'email' => 'admin@adsdash.local',
    'password' => 'AdminPassword@123'
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$res = curl_exec($ch);
echo "Response: " . $res . "\n";
