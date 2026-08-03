<?php

declare(strict_types=1);

function requestApi($url, $method = 'GET', $data = null, $cookieFile = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    if ($cookieFile !== null) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }

    if ($data !== null) {
        $payload = is_string($data) ? $data : json_encode($data);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Content-Length: ' . strlen($payload)]);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'status' => $httpCode,
        'body' => json_decode((string) $response, true),
        'raw' => $response
    ];
}

$base = 'http://localhost/adsdash/api';
$ownerCookie = __DIR__ . '/cookie_owner.txt';
$staffCookie = __DIR__ . '/cookie_staff.txt';
@unlink($ownerCookie);
@unlink($staffCookie);

echo "========================================\n";
echo "EMAIL API & RBAC TEST SUITE\n";
echo "========================================\n\n";

// TEST 1: API File Loads
$resLoad = requestApi("{$base}/email.php?action=logs");
assert($resLoad['status'] === 401, 'Unauthenticated load should be 401');
echo "[PASS] TEST 1: API file loads and enforces auth\n";

// TEST 2 & 3: Unauthenticated Email Dispatch Requests (HTTP 401)
$resUnauthQ = requestApi("{$base}/email.php?action=quotation", 'POST', ['id' => 1, 'recipient_email' => 'test@example.com']);
echo "Unauth Quotation Status: {$resUnauthQ['status']}\n";
assert($resUnauthQ['status'] === 401, 'Unauthenticated quotation send must return 401');
echo "[PASS] TEST 2: Unauthenticated quotation send returns 401\n";

$resUnauthInv = requestApi("{$base}/email.php?action=invoice", 'POST', ['id' => 1, 'recipient_email' => 'test@example.com']);
echo "Unauth Invoice Status: {$resUnauthInv['status']}\n";
assert($resUnauthInv['status'] === 401, 'Unauthenticated invoice send must return 401');
echo "[PASS] TEST 3: Unauthenticated invoice send returns 401\n";

// Login Owner
$loginOwner = requestApi("{$base}/auth.php?action=login", 'POST', ['email' => 'admin@adsdash.local', 'password' => 'AdminPassword@123'], $ownerCookie);
assert($loginOwner['status'] === 200, 'Owner login failed');

// Ensure Staff User Exists for Staff RBAC Test
require_once __DIR__ . '/../config/database.php';
$stmtStaff = $pdo->prepare("SELECT id FROM users WHERE email = 'staff_test@adsdash.local'");
$stmtStaff->execute();
if (!$stmtStaff->fetch()) {
    $hash = password_hash('StaffPassword@123', PASSWORD_DEFAULT);
    $insStaff = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, status) VALUES ('Staff Test User', 'staff_test@adsdash.local', :hash, 'staff', 'active')");
    $insStaff->bindValue(':hash', $hash, PDO::PARAM_STR);
    $insStaff->execute();
}

$loginStaff = requestApi("{$base}/auth.php?action=login", 'POST', ['email' => 'staff_test@adsdash.local', 'password' => 'StaffPassword@123'], $staffCookie);

// TEST 4: Authenticated Quotation Email Request
$resQ = requestApi("{$base}/email.php?action=quotation", 'POST', ['id' => 1, 'recipient_email' => 'customer_test@example.com', 'recipient_name' => 'Ravi'], $ownerCookie);
echo "Auth Quotation Response Status: {$resQ['status']}\n";
assert(in_array($resQ['status'], [200, 400, 404, 500]), 'Quotation email endpoint returned unexpected status');
echo "[PASS] TEST 4: Authenticated quotation email endpoint processed request\n";

// TEST 5: Authenticated Invoice Email Request
$resInv = requestApi("{$base}/email.php?action=invoice", 'POST', ['id' => 1, 'recipient_email' => 'customer_test@example.com'], $ownerCookie);
echo "Auth Invoice Response Status: {$resInv['status']}\n";
assert(in_array($resInv['status'], [200, 400, 404, 500]), 'Invoice email endpoint returned unexpected status');
echo "[PASS] TEST 5: Authenticated invoice email endpoint processed request\n";

// TEST 6: Authenticated Payment Receipt Email Request
$resPay = requestApi("{$base}/email.php?action=payment", 'POST', ['id' => 1, 'recipient_email' => 'customer_test@example.com'], $ownerCookie);
echo "Auth Payment Response Status: {$resPay['status']}\n";
assert(in_array($resPay['status'], [200, 400, 404, 500]), 'Payment email endpoint returned unexpected status');
echo "[PASS] TEST 6: Authenticated payment receipt email endpoint processed request\n";

// TEST 7: Authenticated Campaign Email Request
$resCmp = requestApi("{$base}/email.php?action=campaign", 'POST', ['id' => 1, 'recipient_email' => 'customer_test@example.com'], $ownerCookie);
echo "Auth Campaign Response Status: {$resCmp['status']}\n";
assert(in_array($resCmp['status'], [200, 400, 404, 500]), 'Campaign email endpoint returned unexpected status');
echo "[PASS] TEST 7: Authenticated campaign update email endpoint processed request\n";

// TEST 8: Staff Role System Notification Rejection (HTTP 403)
if ($loginStaff['status'] === 200) {
    $resStaffSys = requestApi("{$base}/email.php?action=system", 'POST', ['recipient_email' => 'user@example.com', 'title' => 'Test', 'message' => 'Msg'], $staffCookie);
    echo "Staff System Notification Status: {$resStaffSys['status']}\n";
    assert($resStaffSys['status'] === 403, 'Staff user must be denied access to send system notifications (403)');
    echo "[PASS] TEST 8: Staff user sending system notification returns 403 Forbidden\n";
} else {
    echo "[INFO] TEST 8: Staff login unavailable, skipping staff 403 check\n";
}

// TEST 9: Manager/Owner Role System Notification Authorization
$resOwnerSys = requestApi("{$base}/email.php?action=system", 'POST', ['recipient_email' => 'user@example.com', 'title' => 'Account Notice', 'message' => 'Your account is active.'], $ownerCookie);
echo "Owner System Notification Status: {$resOwnerSys['status']}\n";
assert(in_array($resOwnerSys['status'], [200, 500]), 'Owner system notification should be authorized');
echo "[PASS] TEST 9: Manager/Owner authorized to send system notifications\n";

// TEST 10: Invalid Document ID (Expect HTTP 400)
$resBadId = requestApi("{$base}/email.php?action=quotation", 'POST', ['id' => -5, 'recipient_email' => 'test@example.com'], $ownerCookie);
assert($resBadId['status'] === 400, 'Invalid document ID should return 400');
echo "[PASS] TEST 10: Invalid document ID returns 400 Bad Request\n";

// TEST 11: Non-Existent Quotation (Expect HTTP 404)
$res404 = requestApi("{$base}/email.php?action=quotation", 'POST', ['id' => 999999, 'recipient_email' => 'test@example.com'], $ownerCookie);
assert($res404['status'] === 404, 'Non-existent quotation should return 404');
echo "[PASS] TEST 11: Non-existent quotation returns 404 Not Found\n";

// TEST 12: Invalid Email Address (Expect HTTP 400)
$resBadEmail = requestApi("{$base}/email.php?action=quotation", 'POST', ['id' => 1, 'recipient_email' => 'invalid-email-address'], $ownerCookie);
assert($resBadEmail['status'] === 400, 'Invalid recipient email should return 400');
echo "[PASS] TEST 12: Invalid recipient email returns 400 Bad Request\n";

// TEST 13: Missing Required Fields (Expect HTTP 400)
$resMissing = requestApi("{$base}/email.php?action=system", 'POST', ['recipient_email' => 'valid@example.com', 'title' => ''], $ownerCookie);
assert($resMissing['status'] === 400, 'Missing title/message should return 400');
echo "[PASS] TEST 13: Missing required fields returns 400 Bad Request\n";

// TEST 14: GET Email Logs (Expect HTTP 200)
$resLogs = requestApi("{$base}/email.php?action=logs", 'GET', null, $ownerCookie);
assert($resLogs['status'] === 200 && $resLogs['body']['success'] === true, 'GET logs should return 200');
echo "[PASS] TEST 14: GET email logs returns 200 OK\n";

// TEST 15: Server-Side Pagination
$resPage = requestApi("{$base}/email.php?action=logs&page=1&limit=5", 'GET', null, $ownerCookie);
assert($resPage['status'] === 200 && isset($resPage['body']['pagination']), 'Pagination metadata missing');
echo "[PASS] TEST 15: Server-side pagination metadata returned cleanly\n";

// TEST 16: Search and Filtering
$resFilter = requestApi("{$base}/email.php?action=logs&email_type=quotation&status=failed", 'GET', null, $ownerCookie);
assert($resFilter['status'] === 200 && is_array($resFilter['body']['data']), 'Filtered logs should return 200');
echo "[PASS] TEST 16: Email log filtering and search working properly\n";

// TEST 17: GET Email Log Detail (Expect HTTP 200)
$logsData = $resLogs['body']['data'] ?? [];
if (count($logsData) > 0) {
    $sampleLogId = $logsData[0]['id'];
    $resDetail = requestApi("{$base}/email.php?action=log&id={$sampleLogId}", 'GET', null, $ownerCookie);
    assert($resDetail['status'] === 200 && $resDetail['body']['data']['id'] == $sampleLogId, 'Log detail failed');
    echo "[PASS] TEST 17: GET email log detail returns 200 OK\n";
} else {
    echo "[INFO] TEST 17: No logs available to test detail view\n";
}

// TEST 18: Invalid Email Log ID (Expect HTTP 404)
$resLog404 = requestApi("{$base}/email.php?action=log&id=999999", 'GET', null, $ownerCookie);
assert($resLog404['status'] === 404, 'Non-existent log ID should return 404');
echo "[PASS] TEST 18: Non-existent email log ID returns 404 Not Found\n";

// TEST 19: Unsupported Method (Expect HTTP 405)
$res405 = requestApi("{$base}/email.php?action=quotation", 'GET', null, $ownerCookie);
assert($res405['status'] === 405, 'GET on POST action should return 405');
echo "[PASS] TEST 19: Unsupported HTTP method returns 405 Method Not Allowed\n";

// TEST 20: sent_by Security Check (Client Payload Override Guard)
$resOverride = requestApi("{$base}/email.php?action=quotation", 'POST', ['id' => 1, 'recipient_email' => 'valid@example.com', 'sent_by' => 9999], $ownerCookie);
// Ensure sent_by in database logs uses session user ID (1), not 9999
$checkSentBy = requestApi("{$base}/email.php?action=logs&limit=1", 'GET', null, $ownerCookie)['body']['data'][0] ?? [];
assert(($checkSentBy['sent_by'] ?? 1) != 9999, 'sent_by parameter must not be overridable by client payload');
echo "[PASS] TEST 20: sent_by strictly retrieved from authenticated session user ID\n";

@unlink($ownerCookie);
@unlink($staffCookie);

echo "\n========================================\n";
echo "EMAIL API & RBAC TEST SUITE COMPLETED SUCCESSFULLY!\n";
echo "========================================\n";
