<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $user = getUserFromJWT();
    if (!$user || !isset($user['id'])) {
        http_response_code(401);
        echo json_encode(["success" => false, "message" => "Unauthorized"]);
        exit;
    }

    $sql = "
        SELECT
            ms.id,
            ms.created_by,
            CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name) AS company_name,
            ms.radius,
            ms.maintenance_date,
            ms.start_time,
            ms.end_time,
            ms.description,
            ms.status,
            ms.created_at,
            b.barangay_name,
            ml.latitude,
            ml.longitude
        FROM maintenance_schedules ms
        LEFT JOIN users u ON u.id = ms.created_by
        LEFT JOIN maintenance_locations ml ON ml.maintenance_id = ms.id
        LEFT JOIN barangays b ON b.id = ml.barangay_id
        LEFT JOIN roles r ON r.id = u.role_id
        WHERE r.role_name = 'electric_company'
        ORDER BY ms.id DESC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [];

    foreach ($rows as $row) {
        $id = $row['id'];

        if (!isset($result[$id])) {
            $result[$id] = [
                "id" => (int)$row['id'],
                "company_name" => $row['company_name'],
                "radius" => (int)$row['radius'],
                "maintenance_date" => $row['maintenance_date'],
                "start_time" => $row['start_time'],
                "end_time" => $row['end_time'],
                "description" => $row['description'],
                "status" => $row['status'],
                "locations" => [],
                "created_at" => $row['created_at']
            ];
        }

        if (!empty($row['latitude']) && !empty($row['longitude'])) {
            $result[$id]['locations'][] = [
                "barangay_name" => $row['barangay_name'],
                "lat" => (float)$row['latitude'],
                "lng" => (float)$row['longitude']
            ];
        }
    }

    echo json_encode([
        "success" => true,
        "total" => count($result),
        "data" => array_values($result)
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
