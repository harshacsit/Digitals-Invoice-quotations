<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/response.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    sendErrorResponse('Method Not Allowed', 405);
}

sendSuccessResponse('AdsDash API service is operational.', [
    'service' => 'AdsDash API',
    'status' => 'healthy',
    'timestamp' => date('c'),
]);
