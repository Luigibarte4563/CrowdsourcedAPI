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

    if (!$user) {

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
       QUERY
    ========================================= */
    $sql = "
        SELECT
            ms.id,
            ms.electric_company_id,
            ec.company_name,

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

        INNER JOIN electric_companies ec
            ON ms.electric_company_id = ec.id

        LEFT JOIN maintenance_locations ml
            ON ms.id = ml.maintenance_id

        WHERE ms.status != 'done'
    ";

    $params = [];

    /* =========================================
       FILTER STATUS
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

    /* =========================================
       ORDER
    ========================================= */
    $sql .= "
        ORDER BY
            ms.maintenance_date ASC,
            ms.start_time ASC
    ";

    /* =========================================
       EXECUTE
    ========================================= */
    $stmt = $conn->prepare($sql);

    $stmt->execute($params);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* =========================================
       FORMAT MAP DATA
    ========================================= */
    $maintenances = [];

    foreach ($rows as $row) {

        $id = $row['id'];

        if (!isset($maintenances[$id])) {

            $maintenances[$id] = [

                "maintenance_id" => (int)$row['id'],

                "electric_company_id" => (int)$row['electric_company_id'],

                "company_name" => $row['company_name'],

                "affected_barangays" => json_decode(
                    $row['affected_barangays'],
                    true
                ),

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
           ADD LOCATION
        ========================================= */
        if (
            !empty($row['latitude']) &&
            !empty($row['longitude'])
        ) {

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

        "message" => $e->getMessage()
    ]);
} 