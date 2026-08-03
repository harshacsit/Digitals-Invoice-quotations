<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/response.php';

/**
 * Securely initialize PHP Session
 */
function initSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        // Secure cookie parameters (development on http://localhost -> secure=false)
        // For production HTTPS environments, change 'secure' => true
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => false,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

/**
 * Fetch currently authenticated user from session & database
 */
function getCurrentUser(): ?array
{
    static $cachedUser = null;
    static $checked = false;

    if ($checked) {
        return $cachedUser;
    }

    initSession();
    $checked = true;

    $userId = $_SESSION['user_id'] ?? null;
    if ($userId === null || !is_numeric($userId)) {
        $cachedUser = null;
        return null;
    }

    global $pdo;
    if (!$pdo instanceof PDO) {
        $cachedUser = null;
        return null;
    }

    try {
        $stmt = $pdo->prepare('SELECT id, name, email, role, phone, status, last_login_at, created_at, updated_at FROM users WHERE id = :id');
        $stmt->bindValue(':id', (int) $userId, PDO::PARAM_INT);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || $user['status'] !== 'active') {
            // Invalidate session if user deleted or inactivated
            $_SESSION = [];
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params["path"], $params["domain"],
                    $params["secure"], $params["httponly"]
                );
            }
            @session_destroy();
            $cachedUser = null;
            return null;
        }

        $cachedUser = [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'phone' => $user['phone'],
            'status' => $user['status'],
            'last_login_at' => $user['last_login_at'],
            'created_at' => $user['created_at'],
            'updated_at' => $user['updated_at'],
        ];

        return $cachedUser;
    } catch (Throwable $e) {
        $cachedUser = null;
        return null;
    }
}

/**
 * Return authenticated user ID or null
 */
function getCurrentUserId(): ?int
{
    $user = getCurrentUser();
    return $user !== null ? (int) $user['id'] : null;
}

/**
 * Check if current request has an authenticated session
 */
function isAuthenticated(): bool
{
    return getCurrentUser() !== null;
}

/**
 * Enforce authentication (Returns HTTP 401 if unauthenticated)
 */
function requireAuth(): void
{
    initSession();
    if (!isAuthenticated()) {
        sendErrorResponse('Authentication required.', 401);
    }
}

/**
 * Enforce role-based authorization (Returns HTTP 403 if unauthorized)
 */
function requireRole(array $allowedRoles): void
{
    requireAuth();
    $user = getCurrentUser();
    if ($user === null || !in_array($user['role'], $allowedRoles, true)) {
        sendErrorResponse('You do not have permission to perform this action.', 403);
    }
}
