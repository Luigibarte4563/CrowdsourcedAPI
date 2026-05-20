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

    if (!$user || !isset($user['id'])) {
        http_response_code(401);
        echo json_encode([
            "success" => false,
            "message" => "Unauthorized"
        ]);
        exit;
    }

    /* =========================================
       OPTIONAL FILTERS
    ========================================= */
    $status = $_GET['status'] ?? null;
    $date = $_GET['date'] ?? null;

    /* =========================================
       BASE QUERY (FIXED FOR POWERGUIDE SCHEMA)
    ========================================= */
    $sql = "
        SELECT
            ms.id,
            ms.created_by,
            u.name AS company_name,

            ms.affected_barangays,
            ms.radius,

            ms.maintenance_date,
            ms.start_time,
            ms.end_time,

            ms.description,
            ms.status,
            ms.created_at,

            ml.barangay_name,
            ml.latitude,
            ml.longitude

        FROM maintenance_schedules ms

        INNER JOIN users u
            ON ms.created_by = u.id
            AND u.role = 'electric_company'

        LEFT JOIN maintenance_locations ml
            ON ms.id = ml.maintenance_id

        WHERE ms.status != 'completed'
    ";

    $params = [];

    /* =========================================
       FILTER STATUS (SAFE LOGIC)
    ========================================= */
    if (!empty($status)) {
        $sql .= " AND ms.status = :status";
        $params[':status'] = $status;
    }

    /* =========================================
       FILTER DATE
    ========================================= */
    if (!empty($date)) {
        $sql .= " AND ms.maintenance_date = :date";
        $params[':date'] = $date;
    }

    $sql .= "
        ORDER BY ms.maintenance_date ASC, ms.start_time ASC
    ";

    /* =========================================
       EXECUTE
    ========================================= */
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* =========================================
       GROUPING
    ========================================= */
    $maintenances = [];

    foreach ($rows as $row) {

        $id = $row['id'];

        if (!isset($maintenances[$id])) {

            $maintenances[$id] = [
                "maintenance_id" => (int)$row['id'],
                "created_by" => (int)$row['created_by'],
                "company_name" => $row['company_name'],

                "affected_barangays" => json_decode($row['affected_barangays'], true),
                "radius" => (int)$row['radius'],

                "maintenance_date" => $row['maintenance_date'],
                "start_time" => $row['start_time'],
                "end_time" => $row['end_time'],

                "description" => $row['description'],
                "status" => $row['status'],
                "created_at" => $row['created_at'],

                "locations" => []
            ];
        }

        /* =========================================
           ADD LOCATION (SAFE CHECK)
        ========================================= */
        if ($row['latitude'] !== null && $row['longitude'] !== null) {

            $maintenances[$id]['locations'][] = [
                "barangay_name" => $row['barangay_name'],
                "latitude" => (float)$row['latitude'],
                "longitude" => (float)$row['longitude']
            ];
        }
    }

    /* =========================================
       RESPONSE
    ========================================= */
    echo json_encode([
        "success" => true,
        "message" => "Maintenance map fetched successfully",
        "total" => count($maintenances),
        "data" => array_values($maintenances)
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Server error"
    ]);
}