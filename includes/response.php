<?php

declare(strict_types=1);

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
