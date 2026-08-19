<?php

declare(strict_types=1);

// Prevent HTML error output in production API responses
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

/**
 * Automatically handle CORS (Cross-Origin Resource Sharing)
 */
function handleCors(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    // Allowed origins configuration via ALLOWED_ORIGINS env var or defaults
    $allowedOriginsRaw = getenv('ALLOWED_ORIGINS') ?: '';
    $allowedOrigins = $allowedOriginsRaw !== '' 
        ? array_map('trim', explode(',', $allowedOriginsRaw)) 
        : [];

    $isAllowed = false;
    if ($origin !== '') {
        if (!empty($allowedOrigins)) {
            $isAllowed = in_array($origin, $allowedOrigins, true);
        } else {
            // Default matching for Vercel frontend deployments and local dev
            $isAllowed = (
                str_ends_with($origin, '.vercel.app') ||
                str_contains($origin, 'localhost') ||
                str_contains($origin, '127.0.0.1')
            );
        }
    }

    if ($isAllowed && $origin !== '') {
        header("Access-Control-Allow-Origin: {$origin}");
        header("Access-Control-Allow-Credentials: true");
        header("Access-Control-Max-Age: 86400");
    } elseif ($origin !== '') {
        header("Access-Control-Allow-Origin: {$origin}");
        header("Access-Control-Allow-Credentials: true");
        header("Access-Control-Max-Age: 86400");
    } else {
        header("Access-Control-Allow-Origin: *");
    }

    header("Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Accept, Authorization, X-Requested-With");

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

handleCors();

/**
 * Send a standardized JSON response.
 *
 * @param bool $success
 * @param string $message
 * @param mixed $data
 * @param int $statusCode
 * @param array|null $pagination
 * @param array|null $errors
 */
function sendJsonResponse(
    bool $success,
    string $message,
    mixed $data = null,
    int $statusCode = 200,
    ?array $pagination = null,
    ?array $errors = null
): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');

    $response = [
        'success' => $success,
        'message' => $message,
    ];

    if ($data !== null) {
        $response['data'] = $data;
    }

    if ($pagination !== null) {
        $response['pagination'] = $pagination;
    }

    if ($errors !== null) {
        $response['errors'] = $errors;
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Helper function to send success responses.
 */
function sendSuccessResponse(
    string $message,
    mixed $data = null,
    int $statusCode = 200,
    ?array $pagination = null
): void {
    sendJsonResponse(true, $message, $data, $statusCode, $pagination);
}

/**
 * Helper function to send error responses.
 */
function sendErrorResponse(
    string $message,
    int $statusCode = 400,
    ?array $errors = null
): void {
    sendJsonResponse(false, $message, null, $statusCode, null, $errors);
}
