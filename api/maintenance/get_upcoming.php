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
       JWT AUTH
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

    $today = date("Y-m-d");
    $now   = date("H:i:s");

    /* =========================================
       TOTAL UPCOMING MAINTENANCE
    ========================================= */
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total
        FROM maintenance_schedules
        WHERE
            maintenance_date > :today

            OR (
                maintenance_date = :today
                AND end_time >= :now_time
            )
    ");

    $stmt->execute([
        ":today"    => $today,
        ":now_time" => $now
    ]);

    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    /* =========================================
       RESPONSE
    ========================================= */
    echo json_encode([

        "success" => true,

        "upcoming_count" => (int)$result['total'],

        "current_date" => $today,

        "current_time" => $now
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    error_log(
        "Upcoming Maintenance Count Error: " .
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