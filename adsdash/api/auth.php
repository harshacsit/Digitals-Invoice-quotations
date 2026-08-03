<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/auth.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? null;

try {
    switch ($action) {
        case 'login':
            if ($method !== 'POST') {
                sendErrorResponse('Method Not Allowed', 405);
            }
            handleLogin($pdo);
            break;

        case 'logout':
            if ($method !== 'POST') {
                sendErrorResponse('Method Not Allowed', 405);
            }
            handleLogout();
            break;

        case 'me':
            if ($method !== 'GET') {
                sendErrorResponse('Method Not Allowed', 405);
            }
            handleMe();
            break;

        case 'users':
            if ($method !== 'GET') {
                sendErrorResponse('Method Not Allowed', 405);
            }
            handleGetUsers($pdo);
            break;

        case 'create_user':
            if ($method !== 'POST') {
                sendErrorResponse('Method Not Allowed', 405);
            }
            handleCreateUser($pdo);
            break;

        case 'update_user':
            if ($method !== 'PUT' && $method !== 'POST') {
                sendErrorResponse('Method Not Allowed', 405);
            }
            handleUpdateUser($pdo);
            break;

        case 'change_password':
            if ($method !== 'POST') {
                sendErrorResponse('Method Not Allowed', 405);
            }
            handleChangePassword($pdo);
            break;

        default:
            sendErrorResponse('Invalid auth action requested.', 400);
            break;
    }
} catch (Throwable $e) {
    sendErrorResponse('Internal Server Error', 500);
}

/**
 * POST /api/auth.php?action=login
 */
function handleLogin(PDO $pdo): void
{
    initSession();

    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    if (!is_array($input)) {
        sendErrorResponse('Invalid JSON payload.', 400);
    }

    $email = isset($input['email']) ? trim(strtolower((string) $input['email'])) : '';
    $password = isset($input['password']) ? (string) $input['password'] : '';

    if ($email === '' || $password === '') {
        sendErrorResponse('Email and password are required.', 400);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sendErrorResponse('Invalid email address format.', 400);
    }

    $stmt = $pdo->prepare('SELECT id, name, email, password_hash, role, phone, status, last_login_at, created_at, updated_at FROM users WHERE email = :email');
    $stmt->bindValue(':email', $email, PDO::PARAM_STR);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || $user['status'] !== 'active' || !password_verify($password, $user['password_hash'])) {
        sendErrorResponse('Invalid email or password.', 401);
    }

    // Authentication Success
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];

    // Update last_login_at
    $upd = $pdo->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id');
    $upd->bindValue(':id', $user['id'], PDO::PARAM_INT);
    $upd->execute();

    $userData = [
        'id' => (int) $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $user['role'],
        'phone' => $user['phone'],
        'status' => $user['status'],
        'last_login_at' => date('Y-m-d H:i:s'),
    ];

    sendSuccessResponse('Login successful.', ['user' => $userData]);
}

/**
 * POST /api/auth.php?action=logout
 */
function handleLogout(): void
{
    initSession();
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    @session_destroy();

    sendSuccessResponse('Logout successful.');
}

/**
 * GET /api/auth.php?action=me
 */
function handleMe(): void
{
    requireAuth();
    $user = getCurrentUser();

    sendSuccessResponse('Authenticated user fetched successfully.', ['user' => $user]);
}

/**
 * GET /api/auth.php?action=users (Owner & Manager only)
 */
function handleGetUsers(PDO $pdo): void
{
    requireRole(['owner', 'manager']);

    $page = isset($_GET['page']) ? filter_var($_GET['page'], FILTER_VALIDATE_INT) : 1;
    $limit = isset($_GET['limit']) ? filter_var($_GET['limit'], FILTER_VALIDATE_INT) : 20;

    if ($page === false || $page < 1) {
        $page = 1;
    }
    if ($limit === false || $limit < 1) {
        $limit = 20;
    } elseif ($limit > 100) {
        $limit = 100;
    }

    $search = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
    $role = isset($_GET['role']) ? trim((string) $_GET['role']) : '';
    $status = isset($_GET['status']) ? trim((string) $_GET['status']) : '';

    $whereClauses = [];
    $params = [];

    if ($search !== '') {
        $whereClauses[] = '(name LIKE :s_name OR email LIKE :s_email OR phone LIKE :s_phone)';
        $params[':s_name'] = '%' . $search . '%';
        $params[':s_email'] = '%' . $search . '%';
        $params[':s_phone'] = '%' . $search . '%';
    }

    if ($role !== '') {
        $whereClauses[] = 'role = :role';
        $params[':role'] = $role;
    }

    if ($status !== '') {
        $whereClauses[] = 'status = :status';
        $params[':status'] = $status;
    }

    $whereSql = $whereClauses !== [] ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

    $countSql = "SELECT COUNT(*) FROM users {$whereSql}";
    $countStmt = $pdo->prepare($countSql);
    foreach ($params as $key => $val) {
        $countStmt->bindValue($key, $val, PDO::PARAM_STR);
    }
    $countStmt->execute();
    $total = (int) $countStmt->fetchColumn();

    $totalPages = $total > 0 ? (int) ceil($total / $limit) : 0;
    $offset = ($page - 1) * $limit;

    $sql = "SELECT id, name, email, role, phone, status, last_login_at, created_at, updated_at
            FROM users {$whereSql}
            ORDER BY id DESC
            LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $users = array_map(static function (array $r): array {
        return [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'email' => $r['email'],
            'role' => $r['role'],
            'phone' => $r['phone'],
            'status' => $r['status'],
            'last_login_at' => $r['last_login_at'],
            'created_at' => $r['created_at'],
            'updated_at' => $r['updated_at'],
        ];
    }, $rows);

    $pagination = [
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'total_pages' => $totalPages,
    ];

    sendSuccessResponse('Users fetched successfully.', $users, 200, $pagination);
}

/**
 * POST /api/auth.php?action=create_user (Owner only)
 */
function handleCreateUser(PDO $pdo): void
{
    requireRole(['owner']);

    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    if (!is_array($input)) {
        sendErrorResponse('Invalid JSON payload.', 400);
    }

    $name = isset($input['name']) ? trim((string) $input['name']) : '';
    $email = isset($input['email']) ? trim(strtolower((string) $input['email'])) : '';
    $password = isset($input['password']) ? (string) $input['password'] : '';
    $role = isset($input['role']) ? trim((string) $input['role']) : 'staff';
    $phone = isset($input['phone']) && trim((string) $input['phone']) !== '' ? trim((string) $input['phone']) : null;
    $status = isset($input['status']) && in_array($input['status'], ['active', 'inactive'], true) ? $input['status'] : 'active';

    if ($name === '') {
        sendErrorResponse('Name is required.', 400);
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sendErrorResponse('Valid email address is required.', 400);
    }
    if (strlen($password) < 8) {
        sendErrorResponse('Password must be at least 8 characters long.', 400);
    }

    $allowedRoles = ['owner', 'manager', 'staff'];
    if (!in_array($role, $allowedRoles, true)) {
        sendErrorResponse('Invalid user role. Allowed values: owner, manager, staff.', 400);
    }

    // Check duplicate email
    $checkStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email');
    $checkStmt->bindValue(':email', $email, PDO::PARAM_STR);
    $checkStmt->execute();
    if ($checkStmt->fetch()) {
        sendErrorResponse('Email address is already in use.', 409);
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    $sql = 'INSERT INTO users (name, email, password_hash, role, phone, status) VALUES (:name, :email, :hash, :role, :phone, :status)';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':name', $name, PDO::PARAM_STR);
    $stmt->bindValue(':email', $email, PDO::PARAM_STR);
    $stmt->bindValue(':hash', $passwordHash, PDO::PARAM_STR);
    $stmt->bindValue(':role', $role, PDO::PARAM_STR);
    $stmt->bindValue(':phone', $phone, $phone === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':status', $status, PDO::PARAM_STR);

    $stmt->execute();
    $newId = (int) $pdo->lastInsertId();

    $newUser = [
        'id' => $newId,
        'name' => $name,
        'email' => $email,
        'role' => $role,
        'phone' => $phone,
        'status' => $status,
        'created_at' => date('Y-m-d H:i:s'),
    ];

    sendSuccessResponse('User created successfully.', ['user' => $newUser], 201);
}

/**
 * PUT /api/auth.php?action=update_user&id=2
 */
function handleUpdateUser(PDO $pdo): void
{
    requireAuth();
    $currentUser = getCurrentUser();

    $id = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : null;
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    if (!is_array($input)) {
        sendErrorResponse('Invalid JSON payload.', 400);
    }

    if (!$id && isset($input['id'])) {
        $id = filter_var($input['id'], FILTER_VALIDATE_INT);
    }

    if ($id === false || $id === null || $id <= 0) {
        sendErrorResponse('User ID is required.', 400);
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$targetUser) {
        sendErrorResponse('User not found.', 404);
    }

    // Role-based authorization check for editing users
    if ($currentUser['role'] === 'staff') {
        if ($currentUser['id'] !== (int) $id) {
            sendErrorResponse('You do not have permission to modify other users.', 403);
        }
    } elseif ($currentUser['role'] === 'manager') {
        if ($targetUser['role'] === 'owner' && $currentUser['id'] !== (int) $id) {
            sendErrorResponse('Managers cannot modify Owner accounts.', 403);
        }
    }

    $name = isset($input['name']) && trim((string) $input['name']) !== '' ? trim((string) $input['name']) : $targetUser['name'];
    $phone = array_key_exists('phone', $input) ? (trim((string) $input['phone']) !== '' ? trim((string) $input['phone']) : null) : $targetUser['phone'];

    $email = $targetUser['email'];
    if (isset($input['email']) && trim((string) $input['email']) !== '') {
        $newEmail = trim(strtolower((string) $input['email']));
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            sendErrorResponse('Invalid email address format.', 400);
        }
        if ($newEmail !== $targetUser['email']) {
            // Check uniqueness
            $chk = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id != :id');
            $chk->bindValue(':email', $newEmail, PDO::PARAM_STR);
            $chk->bindValue(':id', $id, PDO::PARAM_INT);
            $chk->execute();
            if ($chk->fetch()) {
                sendErrorResponse('Email address is already in use by another user.', 409);
            }
            $email = $newEmail;
        }
    }

    $role = $targetUser['role'];
    if (isset($input['role']) && trim((string) $input['role']) !== '') {
        $requestedRole = trim((string) $input['role']);
        if (!in_array($requestedRole, ['owner', 'manager', 'staff'], true)) {
            sendErrorResponse('Invalid user role.', 400);
        }
        // Only owner can assign/change to 'owner' role or change roles
        if ($currentUser['role'] !== 'owner') {
            sendErrorResponse('Only owners can modify user roles.', 403);
        }
        $role = $requestedRole;
    }

    $status = $targetUser['status'];
    if (isset($input['status']) && trim((string) $input['status']) !== '') {
        $requestedStatus = trim((string) $input['status']);
        if (!in_array($requestedStatus, ['active', 'inactive'], true)) {
            sendErrorResponse('Invalid user status.', 400);
        }
        if ($currentUser['role'] !== 'owner') {
            sendErrorResponse('Only owners can modify user account status.', 403);
        }
        $status = $requestedStatus;
    }

    $updSql = 'UPDATE users SET name = :name, email = :email, phone = :phone, role = :role, status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id';
    $updStmt = $pdo->prepare($updSql);
    $updStmt->bindValue(':name', $name, PDO::PARAM_STR);
    $updStmt->bindValue(':email', $email, PDO::PARAM_STR);
    $updStmt->bindValue(':phone', $phone, $phone === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $updStmt->bindValue(':role', $role, PDO::PARAM_STR);
    $updStmt->bindValue(':status', $status, PDO::PARAM_STR);
    $updStmt->bindValue(':id', $id, PDO::PARAM_INT);
    $updStmt->execute();

    $updatedUser = [
        'id' => (int) $id,
        'name' => $name,
        'email' => $email,
        'role' => $role,
        'phone' => $phone,
        'status' => $status,
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    sendSuccessResponse('User updated successfully.', ['user' => $updatedUser]);
}

/**
 * POST /api/auth.php?action=change_password
 */
function handleChangePassword(PDO $pdo): void
{
    requireAuth();
    $currentUser = getCurrentUser();

    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    if (!is_array($input)) {
        sendErrorResponse('Invalid JSON payload.', 400);
    }

    $currentPassword = isset($input['current_password']) ? (string) $input['current_password'] : '';
    $newPassword = isset($input['new_password']) ? (string) $input['new_password'] : '';

    if ($currentPassword === '' || $newPassword === '') {
        sendErrorResponse('Current password and new password are required.', 400);
    }

    if (strlen($newPassword) < 8) {
        sendErrorResponse('New password must be at least 8 characters long.', 400);
    }

    // Fetch DB hash
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
    $stmt->bindValue(':id', $currentUser['id'], PDO::PARAM_INT);
    $stmt->execute();
    $hash = $stmt->fetchColumn();

    if (!$hash || !password_verify($currentPassword, (string) $hash)) {
        sendErrorResponse('Current password is incorrect.', 401);
    }

    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

    $upd = $pdo->prepare('UPDATE users SET password_hash = :hash, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
    $upd->bindValue(':hash', $newHash, PDO::PARAM_STR);
    $upd->bindValue(':id', $currentUser['id'], PDO::PARAM_INT);
    $upd->execute();

    session_regenerate_id(true);

    sendSuccessResponse('Password changed successfully.');
}
