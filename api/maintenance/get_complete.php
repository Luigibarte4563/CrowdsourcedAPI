<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {

    /* =========================================
       AUTH
    ========================================= */
    $user = getUserFromJWT();

    if (!$user || ($user['role'] ?? '') !== 'electric_company') {
        throw new Exception("Unauthorized");
    }

    /* =========================================
       GET COMPANY INFO
    ========================================= */
    $stmt = $conn->prepare("
        SELECT id, name
        FROM users
        WHERE id = :id
        AND role = 'electric_company'
        LIMIT 1
    ");

    $stmt->execute([
        ":id" => $user['id']
    ]);

    $company = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$company) {
        throw new Exception("Company not found");
    }

    $company_id = $company['id'];

    /* =========================================
       GET COMPLETED MAINTENANCE
    ========================================= */
    $query = $conn->prepare("
        SELECT 
            ms.*,
            u.name AS company_name
        FROM maintenance_schedules ms
        LEFT JOIN users u ON ms.created_by = u.id
        WHERE ms.status = 'completed'
        ORDER BY ms.id DESC
    ");

    $query->execute();
    $data = $query->fetchAll(PDO::FETCH_ASSOC);

    /* =========================================
       COUNT TOTAL COMPLETED
    ========================================= */
    $countStmt = $conn->prepare("
        SELECT COUNT(*) AS total_completed
        FROM maintenance_schedules
        WHERE status = 'completed'
    ");

    $countStmt->execute();
    $count = $countStmt->fetch(PDO::FETCH_ASSOC);

    /* =========================================
       RESPONSE
    ========================================= */
    echo json_encode([
        "success" => true,
        "message" => "Completed maintenance fetched successfully",
        "count" => (int)$count['total_completed'],
        "data" => $data
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}