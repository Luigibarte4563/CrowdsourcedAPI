<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';

ini_set('display_errors', 0);
error_reporting(E_ALL);

$conn = getConnection();

try {

    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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
       QUERY PARAMS
    ========================================= */
    $upcomingOnly = isset($_GET['upcoming'])
        ? (int)$_GET['upcoming']
        : 1;

    $limit = isset($_GET['limit'])
        ? (int)$_GET['limit']
        : 100;

    if ($limit <= 0) {
        $limit = 100;
    }

    /* =========================================
       DATE/TIME
    ========================================= */
    date_default_timezone_set("Asia/Manila");

    $today = date("Y-m-d");
    $now   = date("H:i:s");

    /* =========================================
       GET MAINTENANCE MAP DATA
    ========================================= */
    $sql = "
        SELECT
            ms.id,
            ms.electric_company_id,
            ec.company_name,

            ms.affected_area,
            ms.latitude,
            ms.longitude,
            ms.radius,

            ms.maintenance_date,
            ms.start_time,
            ms.end_time,

            ms.description,
            ms.affected_barangays,

            ms.created_at

        FROM maintenance_schedules ms

        LEFT JOIN electric_companies ec
        ON ec.id = ms.electric_company_id
    ";

    /* =========================================
       UPCOMING FILTER
    ========================================= */
    if ($upcomingOnly === 1) {

        $sql .= "
            WHERE
                ms.maintenance_date > :today

                OR (

                    ms.maintenance_date = :today
                    AND ms.end_time >= :now_time
                )
        ";
    }

    $sql .= "
        ORDER BY
            ms.maintenance_date ASC,
            ms.start_time ASC

        LIMIT $limit
    ";

    $stmt = $conn->prepare($sql);

    if ($upcomingOnly === 1) {

        $stmt->bindValue(
            ":today",
            $today
        );

        $stmt->bindValue(
            ":now_time",
            $now
        );
    }

    $stmt->execute();

    $maintenance = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* =========================================
       FORMAT DATA
    ========================================= */
    $formatted = [];

    foreach ($maintenance as $m) {

        $formatted[] = [

            "id" => (int)$m['id'],

            "company_name" =>
                $m['company_name'],

            "affected_area" =>
                $m['affected_area'],

            "latitude" =>
                (float)$m['latitude'],

            "longitude" =>
                (float)$m['longitude'],

            "radius" =>
                (int)$m['radius'],

            "maintenance_date" =>
                $m['maintenance_date'],

            "start_time" =>
                date(
                    "h:i A",
                    strtotime($m['start_time'])
                ),

            "end_time" =>
                date(
                    "h:i A",
                    strtotime($m['end_time'])
                ),

            "description" =>
                $m['description'],

            "affected_barangays" =>
                json_decode(
                    $m['affected_barangays'],
                    true
                ),

            "created_at" =>
                $m['created_at']
        ];
    }

    /* =========================================
       RESPONSE
    ========================================= */
    echo json_encode([

        "success" => true,

        "count" => count($formatted),

        "upcoming_only" =>
            $upcomingOnly === 1,

        "data" => $formatted
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    error_log(
        "Get Maintenance Map Error: " .
        $e->getMessage()
    );

    echo json_encode([

        "success" => false,

        "message" => "Server error",

        "error" => $e->getMessage()
    ]);
}

exit;
?>