<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/auth.php';

// Auth guard (non-blocking for development per auth.php)
requireAuth();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? null;

try {
    if ($method === 'GET' && $action === 'availability') {
        handleAvailability($pdo);
    } else {
        switch ($method) {
            case 'GET':
                handleGet($pdo);
                break;
            case 'POST':
                handlePost($pdo);
                break;
            case 'PUT':
                handlePut($pdo);
                break;
            case 'DELETE':
                handleDelete($pdo);
                break;
            default:
                sendErrorResponse('Method Not Allowed', 405);
                break;
        }
    }
} catch (Throwable $e) {
    sendErrorResponse('Internal Server Error', 500);
}

/**
 * Handle GET requests:
 * - Single screen by ID: GET /api/screens.php?id=1
 * - Paginated & filtered screens: GET /api/screens.php?page=1&limit=20&search=Phoenix&city=Bhimavaram&type=tv_display&status=available
 */
function handleGet(PDO $pdo): void
{
    if (isset($_GET['id'])) {
        $id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
        if ($id === false || $id <= 0) {
            sendErrorResponse('Invalid screen ID.', 400);
        }

        $stmt = $pdo->prepare('SELECT * FROM screens WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $screen = $stmt->fetch();

        if (!$screen) {
            sendErrorResponse('Screen not found.', 404);
        }

        sendSuccessResponse('Screen fetched successfully.', $screen);
    }

    // List pagination, search & filters
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
    $city = isset($_GET['city']) ? trim((string) $_GET['city']) : '';
    $type = isset($_GET['type']) ? trim((string) $_GET['type']) : '';
    $status = isset($_GET['status']) ? trim((string) $_GET['status']) : '';

    $whereClauses = [];
    $params = [];

    // By default, hide inactive screens unless status filter is explicitly provided
    if ($status !== '') {
        $whereClauses[] = 'status = :status_filter';
        $params[':status_filter'] = $status;
    } else {
        $whereClauses[] = 'status != "inactive"';
    }

    if ($city !== '') {
        $whereClauses[] = 'city = :city_filter';
        $params[':city_filter'] = $city;
    }

    if ($type !== '') {
        $whereClauses[] = 'screen_type = :type_filter';
        $params[':type_filter'] = $type;
    }

    if ($search !== '') {
        $whereClauses[] = '(name LIKE :s_name OR location LIKE :s_loc OR city LIKE :s_city OR dimensions LIKE :s_dim)';
        $params[':s_name'] = '%' . $search . '%';
        $params[':s_loc'] = '%' . $search . '%';
        $params[':s_city'] = '%' . $search . '%';
        $params[':s_dim'] = '%' . $search . '%';
    }

    $whereSql = implode(' AND ', $whereClauses);

    // Count query
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM screens WHERE {$whereSql}");
    foreach ($params as $key => $val) {
        $countStmt->bindValue($key, $val, PDO::PARAM_STR);
    }
    $countStmt->execute();
    $total = (int) $countStmt->fetchColumn();

    $totalPages = $total > 0 ? (int) ceil($total / $limit) : 0;
    $offset = ($page - 1) * $limit;

    // Fetch query
    $sql = "SELECT id, name, screen_type, location, city, state, dimensions, monthly_rate, status, description, image_path, created_at, updated_at 
            FROM screens 
            WHERE {$whereSql} 
            ORDER BY id DESC 
            LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $screens = $stmt->fetchAll();

    $pagination = [
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'total_pages' => $totalPages,
    ];

    sendSuccessResponse('Screens fetched successfully.', $screens, 200, $pagination);
}

/**
 * Handle POST request to create an advertising screen.
 */
function handlePost(PDO $pdo): void
{
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $name = trim((string) ($input['name'] ?? ''));
    $screenType = trim((string) ($input['screen_type'] ?? ''));
    $location = trim((string) ($input['location'] ?? ''));
    $city = trim((string) ($input['city'] ?? ''));
    $monthlyRateRaw = $input['monthly_rate'] ?? null;

    if ($name === '') {
        sendErrorResponse('Screen name is required.', 400);
    }
    if ($location === '') {
        sendErrorResponse('Location is required.', 400);
    }
    if ($city === '') {
        sendErrorResponse('City is required.', 400);
    }
    if ($monthlyRateRaw === null || !is_numeric($monthlyRateRaw) || (float) $monthlyRateRaw < 0) {
        sendErrorResponse('Monthly rate must be a non-negative number.', 400);
    }
    $monthlyRate = (float) $monthlyRateRaw;

    $allowedTypes = ['tv_display', 'billboard', 'digital_board', 'other'];
    if ($screenType === '' || !in_array($screenType, $allowedTypes, true)) {
        sendErrorResponse('Invalid screen type.', 400);
    }

    $allowedStatuses = ['available', 'maintenance', 'inactive'];
    $status = trim((string) ($input['status'] ?? 'available'));
    if (!in_array($status, $allowedStatuses, true)) {
        sendErrorResponse('Invalid status.', 400);
    }

    $state = isset($input['state']) && trim((string) $input['state']) !== '' ? trim((string) $input['state']) : null;
    $dimensions = isset($input['dimensions']) && trim((string) $input['dimensions']) !== '' ? trim((string) $input['dimensions']) : null;
    $description = isset($input['description']) && trim((string) $input['description']) !== '' ? trim((string) $input['description']) : null;
    $imagePath = isset($input['image_path']) && trim((string) $input['image_path']) !== '' ? trim((string) $input['image_path']) : null;

    $sql = 'INSERT INTO screens (
        name, screen_type, location, city, state, dimensions, monthly_rate, status, description, image_path
    ) VALUES (
        :name, :screen_type, :location, :city, :state, :dimensions, :monthly_rate, :status, :description, :image_path
    )';

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':name', $name, PDO::PARAM_STR);
    $stmt->bindValue(':screen_type', $screenType, PDO::PARAM_STR);
    $stmt->bindValue(':location', $location, PDO::PARAM_STR);
    $stmt->bindValue(':city', $city, PDO::PARAM_STR);
    $stmt->bindValue(':state', $state, $state === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':dimensions', $dimensions, $dimensions === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':monthly_rate', $monthlyRate, PDO::PARAM_STR);
    $stmt->bindValue(':status', $status, PDO::PARAM_STR);
    $stmt->bindValue(':description', $description, $description === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':image_path', $imagePath, $imagePath === null ? PDO::PARAM_NULL : PDO::PARAM_STR);

    $stmt->execute();
    $newId = (int) $pdo->lastInsertId();

    $fetchStmt = $pdo->prepare('SELECT * FROM screens WHERE id = :id');
    $fetchStmt->bindValue(':id', $newId, PDO::PARAM_INT);
    $fetchStmt->execute();
    $newScreen = $fetchStmt->fetch();

    sendSuccessResponse('Screen created successfully.', $newScreen, 201);
}

/**
 * Handle PUT request to update a screen.
 */
function handlePut(PDO $pdo): void
{
    $id = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : null;
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    if (!is_array($input)) {
        $input = [];
    }

    if (!$id && isset($input['id'])) {
        $id = filter_var($input['id'], FILTER_VALIDATE_INT);
    }

    if ($id === false || $id === null || $id <= 0) {
        sendErrorResponse('Screen ID is required.', 400);
    }

    $checkStmt = $pdo->prepare('SELECT * FROM screens WHERE id = :id');
    $checkStmt->bindValue(':id', $id, PDO::PARAM_INT);
    $checkStmt->execute();
    $existing = $checkStmt->fetch();

    if (!$existing) {
        sendErrorResponse('Screen not found.', 404);
    }

    $name = isset($input['name']) ? trim((string) $input['name']) : $existing['name'];
    if ($name === '') {
        sendErrorResponse('Screen name cannot be empty.', 400);
    }

    $location = isset($input['location']) ? trim((string) $input['location']) : $existing['location'];
    if ($location === '') {
        sendErrorResponse('Location cannot be empty.', 400);
    }

    $city = isset($input['city']) ? trim((string) $input['city']) : $existing['city'];
    if ($city === '') {
        sendErrorResponse('City cannot be empty.', 400);
    }

    $screenType = isset($input['screen_type']) ? trim((string) $input['screen_type']) : $existing['screen_type'];
    $allowedTypes = ['tv_display', 'billboard', 'digital_board', 'other'];
    if (!in_array($screenType, $allowedTypes, true)) {
        sendErrorResponse('Invalid screen type.', 400);
    }

    $status = isset($input['status']) ? trim((string) $input['status']) : $existing['status'];
    $allowedStatuses = ['available', 'maintenance', 'inactive'];
    if (!in_array($status, $allowedStatuses, true)) {
        sendErrorResponse('Invalid status.', 400);
    }

    $monthlyRate = $existing['monthly_rate'];
    if (array_key_exists('monthly_rate', $input)) {
        $monthlyRateRaw = $input['monthly_rate'];
        if ($monthlyRateRaw === null || !is_numeric($monthlyRateRaw) || (float) $monthlyRateRaw < 0) {
            sendErrorResponse('Monthly rate must be a non-negative number.', 400);
        }
        $monthlyRate = (float) $monthlyRateRaw;
    }

    $state = array_key_exists('state', $input) ? (trim((string) $input['state']) !== '' ? trim((string) $input['state']) : null) : $existing['state'];
    $dimensions = array_key_exists('dimensions', $input) ? (trim((string) $input['dimensions']) !== '' ? trim((string) $input['dimensions']) : null) : $existing['dimensions'];
    $description = array_key_exists('description', $input) ? (trim((string) $input['description']) !== '' ? trim((string) $input['description']) : null) : $existing['description'];
    $imagePath = array_key_exists('image_path', $input) ? (trim((string) $input['image_path']) !== '' ? trim((string) $input['image_path']) : null) : $existing['image_path'];

    $sql = 'UPDATE screens SET
        name = :name,
        screen_type = :screen_type,
        location = :location,
        city = :city,
        state = :state,
        dimensions = :dimensions,
        monthly_rate = :monthly_rate,
        status = :status,
        description = :description,
        image_path = :image_path,
        updated_at = CURRENT_TIMESTAMP
    WHERE id = :id';

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':name', $name, PDO::PARAM_STR);
    $stmt->bindValue(':screen_type', $screenType, PDO::PARAM_STR);
    $stmt->bindValue(':location', $location, PDO::PARAM_STR);
    $stmt->bindValue(':city', $city, PDO::PARAM_STR);
    $stmt->bindValue(':state', $state, $state === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':dimensions', $dimensions, $dimensions === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':monthly_rate', $monthlyRate, PDO::PARAM_STR);
    $stmt->bindValue(':status', $status, PDO::PARAM_STR);
    $stmt->bindValue(':description', $description, $description === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':image_path', $imagePath, $imagePath === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);

    $stmt->execute();

    $fetchStmt = $pdo->prepare('SELECT * FROM screens WHERE id = :id');
    $fetchStmt->bindValue(':id', $id, PDO::PARAM_INT);
    $fetchStmt->execute();
    $updatedScreen = $fetchStmt->fetch();

    sendSuccessResponse('Screen updated successfully.', $updatedScreen, 200);
}

/**
 * Handle DELETE request to soft delete a screen (status = 'inactive').
 */
function handleDelete(PDO $pdo): void
{
    $id = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : null;
    if (!$id) {
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);
        if (is_array($input) && isset($input['id'])) {
            $id = filter_var($input['id'], FILTER_VALIDATE_INT);
        }
    }

    if ($id === false || $id === null || $id <= 0) {
        sendErrorResponse('Screen ID is required.', 400);
    }

    $checkStmt = $pdo->prepare('SELECT id, status FROM screens WHERE id = :id');
    $checkStmt->bindValue(':id', $id, PDO::PARAM_INT);
    $checkStmt->execute();
    $existing = $checkStmt->fetch();

    if (!$existing) {
        sendErrorResponse('Screen not found.', 404);
    }

    $stmt = $pdo->prepare("UPDATE screens SET status = 'inactive', updated_at = CURRENT_TIMESTAMP WHERE id = :id");
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();

    sendSuccessResponse('Screen deactivated successfully.');
}

/**
 * Handle availability check:
 * GET /api/screens.php?action=availability&id=1&start_date=2026-08-10&end_date=2026-08-20
 */
function handleAvailability(PDO $pdo): void
{
    $screenId = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : null;
    if (!$screenId && isset($_GET['screen_id'])) {
        $screenId = filter_var($_GET['screen_id'], FILTER_VALIDATE_INT);
    }

    if ($screenId === false || $screenId === null || $screenId <= 0) {
        sendErrorResponse('Valid screen ID is required.', 400);
    }

    // Verify screen exists
    $checkStmt = $pdo->prepare('SELECT id FROM screens WHERE id = :id');
    $checkStmt->bindValue(':id', $screenId, PDO::PARAM_INT);
    $checkStmt->execute();
    if (!$checkStmt->fetch()) {
        sendErrorResponse('Screen not found.', 404);
    }

    $startDateStr = isset($_GET['start_date']) ? trim((string) $_GET['start_date']) : '';
    $endDateStr = isset($_GET['end_date']) ? trim((string) $_GET['end_date']) : '';

    if ($startDateStr === '' || $endDateStr === '') {
        sendErrorResponse('Start date and end date are required.', 400);
    }

    $startDateObj = DateTime::createFromFormat('Y-m-d', $startDateStr);
    $endDateObj = DateTime::createFromFormat('Y-m-d', $endDateStr);

    $isStartValid = $startDateObj && $startDateObj->format('Y-m-d') === $startDateStr;
    $isEndValid = $endDateObj && $endDateObj->format('Y-m-d') === $endDateStr;

    if (!$isStartValid || !$isEndValid) {
        sendErrorResponse('Invalid start or end date format. Use YYYY-MM-DD.', 400);
    }

    if ($startDateObj > $endDateObj) {
        sendErrorResponse('Start date must be before or equal to end date.', 400);
    }

    // Check overlap in campaign_screens (ignore cancelled bookings)
    $sql = "SELECT COUNT(*) FROM campaign_screens 
            WHERE screen_id = :screen_id 
              AND status != 'cancelled'
              AND start_date <= :end_date 
              AND end_date >= :start_date";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':screen_id', $screenId, PDO::PARAM_INT);
    $stmt->bindValue(':end_date', $endDateStr, PDO::PARAM_STR);
    $stmt->bindValue(':start_date', $startDateStr, PDO::PARAM_STR);
    $stmt->execute();

    $bookedCount = (int) $stmt->fetchColumn();

    if ($bookedCount > 0) {
        sendSuccessResponse('Screen availability status fetched.', [
            'screen_id' => $screenId,
            'available' => false,
            'message' => 'Screen is already booked for the selected dates.'
        ]);
    } else {
        sendSuccessResponse('Screen availability status fetched.', [
            'screen_id' => $screenId,
            'available' => true
        ]);
    }
}
