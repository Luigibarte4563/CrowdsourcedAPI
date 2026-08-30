<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../../auth/rbac.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    requireRole(requireAuthUser(), ['electric_company', 'admin']);

    $query = $conn->prepare("
        SELECT
            ms.*,
            CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name) AS company_name
        FROM maintenance_schedules ms
        LEFT JOIN users u ON ms.created_by = u.id
        WHERE ms.status = 'completed'
        ORDER BY ms.id DESC
    ");
    $query->execute();
    $data = $query->fetchAll(PDO::FETCH_ASSOC);

    $countStmt = $conn->prepare("SELECT COUNT(*) AS total_completed FROM maintenance_schedules WHERE status = 'completed'");
    $countStmt->execute();
    $count = $countStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "message" => "Completed maintenance fetched successfully",
        "count" => (int)$count['total_completed'],
        "data" => $data
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
