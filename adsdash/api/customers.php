<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/auth.php';

// Optional auth check (non-blocking helper for development per auth.php)
requireAuth();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
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
} catch (Throwable $e) {
    // Log exception internally if needed, but do not expose raw DB / script errors to client
    sendErrorResponse('Internal Server Error', 500);
}

/**
 * Handle GET requests:
 * - Single customer by ID: GET /api/customers.php?id=1
 * - List active customers with search & pagination: GET /api/customers.php?page=1&limit=20&search=Nova
 */
function handleGet(PDO $pdo): void
{
    if (isset($_GET['id'])) {
        $id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
        if ($id === false || $id <= 0) {
            sendErrorResponse('Invalid customer ID.', 400);
        }

        $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = :id AND status = "active"');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $customer = $stmt->fetch();

        if (!$customer) {
            sendErrorResponse('Customer not found.', 404);
        }

        sendSuccessResponse('Customer fetched successfully.', $customer);
    }

    // List active customers with optional search & pagination
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

    $whereClauses = ['status = "active"'];
    $params = [];

    if ($search !== '') {
        $whereClauses[] = '(company_name LIKE :s_comp OR contact_person LIKE :s_cont OR phone LIKE :s_phone OR email LIKE :s_email OR city LIKE :s_city)';
        $params[':s_comp'] = '%' . $search . '%';
        $params[':s_cont'] = '%' . $search . '%';
        $params[':s_phone'] = '%' . $search . '%';
        $params[':s_email'] = '%' . $search . '%';
        $params[':s_city'] = '%' . $search . '%';
    }

    $whereSql = implode(' AND ', $whereClauses);

    // Count total matching records
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE {$whereSql}");
    foreach ($params as $key => $val) {
        $countStmt->bindValue($key, $val, PDO::PARAM_STR);
    }
    $countStmt->execute();
    $total = (int) $countStmt->fetchColumn();

    $totalPages = $total > 0 ? (int) ceil($total / $limit) : 0;
    $offset = ($page - 1) * $limit;

    // Fetch records
    $sql = "SELECT id, company_name, contact_person, phone, email, address, city, state, country, gstin, source, notes, status, created_by, created_at, updated_at 
            FROM customers 
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
    $customers = $stmt->fetchAll();

    $pagination = [
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'total_pages' => $totalPages,
    ];

    sendSuccessResponse('Customers fetched successfully.', $customers, 200, $pagination);
}

/**
 * Handle POST request to create a customer.
 */
function handlePost(PDO $pdo): void
{
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $companyName = trim((string) ($input['company_name'] ?? ''));
    $contactPerson = trim((string) ($input['contact_person'] ?? ''));

    if ($companyName === '' || $contactPerson === '') {
        sendErrorResponse('Company name and contact person are required.', 400);
    }

    $email = isset($input['email']) ? trim((string) $input['email']) : null;
    if ($email !== null && $email !== '') {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            sendErrorResponse('Invalid email address format.', 400);
        }
    } else {
        $email = null;
    }

    $phone = isset($input['phone']) ? trim((string) $input['phone']) : null;
    if ($phone !== null && $phone !== '') {
        if (strlen($phone) > 30) {
            sendErrorResponse('Phone number must not exceed 30 characters.', 400);
        }
    } else {
        $phone = null;
    }

    $gstin = isset($input['gstin']) ? trim((string) $input['gstin']) : null;
    if ($gstin !== null && $gstin !== '') {
        if (strlen($gstin) > 20) {
            sendErrorResponse('GSTIN must not exceed 20 characters.', 400);
        }
        if (!preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}[A-Z0-9]{2}$/i', $gstin)) {
            sendErrorResponse('Invalid GSTIN format.', 400);
        }
    } else {
        $gstin = null;
    }

    $address = isset($input['address']) ? trim((string) $input['address']) : null;
    $address = ($address === null || trim((string) $address) === '') ? null : trim((string) $address);

    $city = isset($input['city']) ? trim((string) $input['city']) : null;
    $city = ($city === null || trim((string) $city) === '') ? null : trim((string) $city);

    $state = isset($input['state']) ? trim((string) $input['state']) : null;
    $state = ($state === null || trim((string) $state) === '') ? null : trim((string) $state);

    $country = isset($input['country']) && trim((string) $input['country']) !== '' 
        ? trim((string) $input['country']) 
        : 'India';

    $source = isset($input['source']) ? trim((string) $input['source']) : null;
    $source = ($source === null || trim((string) $source) === '') ? null : trim((string) $source);

    $notes = isset($input['notes']) ? trim((string) $input['notes']) : null;
    $notes = ($notes === null || trim((string) $notes) === '') ? null : trim((string) $notes);

    $status = 'active';
    $createdBy = getCurrentUserId();

    $sql = 'INSERT INTO customers (
        company_name, contact_person, phone, email, address, city, state, country, gstin, source, notes, status, created_by
    ) VALUES (
        :company_name, :contact_person, :phone, :email, :address, :city, :state, :country, :gstin, :source, :notes, :status, :created_by
    )';

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':company_name', $companyName, PDO::PARAM_STR);
    $stmt->bindValue(':contact_person', $contactPerson, PDO::PARAM_STR);
    $stmt->bindValue(':phone', $phone, $phone === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':email', $email, $email === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':address', $address, $address === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':city', $city, $city === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':state', $state, $state === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':country', $country, PDO::PARAM_STR);
    $stmt->bindValue(':gstin', $gstin, $gstin === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':source', $source, $source === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':notes', $notes, $notes === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':status', $status, PDO::PARAM_STR);
    $stmt->bindValue(':created_by', $createdBy, $createdBy === null ? PDO::PARAM_NULL : PDO::PARAM_INT);

    $stmt->execute();
    $newId = (int) $pdo->lastInsertId();

    // Fetch created customer
    $fetchStmt = $pdo->prepare('SELECT * FROM customers WHERE id = :id');
    $fetchStmt->bindValue(':id', $newId, PDO::PARAM_INT);
    $fetchStmt->execute();
    $newCustomer = $fetchStmt->fetch();

    sendSuccessResponse('Customer created successfully.', $newCustomer, 201);
}

/**
 * Handle PUT request to update a customer.
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
        sendErrorResponse('Customer ID is required.', 400);
    }

    // Check if customer exists
    $checkStmt = $pdo->prepare('SELECT * FROM customers WHERE id = :id');
    $checkStmt->bindValue(':id', $id, PDO::PARAM_INT);
    $checkStmt->execute();
    $existing = $checkStmt->fetch();

    if (!$existing) {
        sendErrorResponse('Customer not found.', 404);
    }

    // Validate provided fields
    $companyName = isset($input['company_name']) ? trim((string) $input['company_name']) : $existing['company_name'];
    $contactPerson = isset($input['contact_person']) ? trim((string) $input['contact_person']) : $existing['contact_person'];

    if ($companyName === '' || $contactPerson === '') {
        sendErrorResponse('Company name and contact person cannot be empty.', 400);
    }

    $email = array_key_exists('email', $input) ? trim((string) $input['email']) : $existing['email'];
    if ($email !== null && $email !== '') {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            sendErrorResponse('Invalid email address format.', 400);
        }
    } else {
        $email = null;
    }

    $phone = array_key_exists('phone', $input) ? trim((string) $input['phone']) : $existing['phone'];
    if ($phone !== null && $phone !== '') {
        if (strlen($phone) > 30) {
            sendErrorResponse('Phone number must not exceed 30 characters.', 400);
        }
    } else {
        $phone = null;
    }

    $gstin = array_key_exists('gstin', $input) ? trim((string) $input['gstin']) : $existing['gstin'];
    if ($gstin !== null && $gstin !== '') {
        if (strlen($gstin) > 20) {
            sendErrorResponse('GSTIN must not exceed 20 characters.', 400);
        }
        if (!preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}[A-Z0-9]{2}$/i', $gstin)) {
            sendErrorResponse('Invalid GSTIN format.', 400);
        }
    } else {
        $gstin = null;
    }

    $address = array_key_exists('address', $input) ? trim((string) $input['address']) : $existing['address'];
    $address = ($address === null || trim((string) $address) === '') ? null : trim((string) $address);

    $city = array_key_exists('city', $input) ? trim((string) $input['city']) : $existing['city'];
    $city = ($city === null || trim((string) $city) === '') ? null : trim((string) $city);

    $state = array_key_exists('state', $input) ? trim((string) $input['state']) : $existing['state'];
    $state = ($state === null || trim((string) $state) === '') ? null : trim((string) $state);

    $country = array_key_exists('country', $input) ? trim((string) $input['country']) : $existing['country'];
    $country = ($country === null || trim((string) $country) === '') ? 'India' : trim((string) $country);

    $source = array_key_exists('source', $input) ? trim((string) $input['source']) : $existing['source'];
    $source = ($source === null || trim((string) $source) === '') ? null : trim((string) $source);

    $notes = array_key_exists('notes', $input) ? trim((string) $input['notes']) : $existing['notes'];
    $notes = ($notes === null || trim((string) $notes) === '') ? null : trim((string) $notes);

    $status = array_key_exists('status', $input) ? trim((string) $input['status']) : $existing['status'];
    if (!in_array($status, ['active', 'inactive'], true)) {
        sendErrorResponse('Status must be either active or inactive.', 400);
    }

    $sql = 'UPDATE customers SET
        company_name = :company_name,
        contact_person = :contact_person,
        phone = :phone,
        email = :email,
        address = :address,
        city = :city,
        state = :state,
        country = :country,
        gstin = :gstin,
        source = :source,
        notes = :notes,
        status = :status,
        updated_at = CURRENT_TIMESTAMP
    WHERE id = :id';

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':company_name', $companyName, PDO::PARAM_STR);
    $stmt->bindValue(':contact_person', $contactPerson, PDO::PARAM_STR);
    $stmt->bindValue(':phone', $phone, $phone === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':email', $email, $email === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':address', $address, $address === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':city', $city, $city === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':state', $state, $state === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':country', $country, PDO::PARAM_STR);
    $stmt->bindValue(':gstin', $gstin, $gstin === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':source', $source, $source === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':notes', $notes, $notes === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':status', $status, PDO::PARAM_STR);
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);

    $stmt->execute();

    // Fetch updated customer
    $fetchStmt = $pdo->prepare('SELECT * FROM customers WHERE id = :id');
    $fetchStmt->bindValue(':id', $id, PDO::PARAM_INT);
    $fetchStmt->execute();
    $updatedCustomer = $fetchStmt->fetch();

    sendSuccessResponse('Customer updated successfully.', $updatedCustomer, 200);
}

/**
 * Handle DELETE request to soft delete a customer (status = 'inactive').
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
        sendErrorResponse('Customer ID is required.', 400);
    }

    // Check if customer exists
    $checkStmt = $pdo->prepare('SELECT id, status FROM customers WHERE id = :id');
    $checkStmt->bindValue(':id', $id, PDO::PARAM_INT);
    $checkStmt->execute();
    $existing = $checkStmt->fetch();

    if (!$existing) {
        sendErrorResponse('Customer not found.', 404);
    }

    $stmt = $pdo->prepare("UPDATE customers SET status = 'inactive', updated_at = CURRENT_TIMESTAMP WHERE id = :id");
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();

    sendSuccessResponse('Customer deactivated successfully.');
}
