<?php

header("Content-Type: application/json; charset=UTF-8");

session_start();

require_once __DIR__ . '/../../config/db_connect.php';

$conn = getConnection();

/* =========================================
   GET USER FROM SESSION
========================================= */
$user_id = $_SESSION['user']['id'] ?? null;

if (!$user_id) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized (no session)"
    ]);
    exit;
}

try {

    /* =========================================
       FETCH ONLY USER CREATED STATIONS
    ========================================= */
    $stmt = $conn->prepare("
        SELECT 
            id,
            created_by,
            station_name,
            location_name,
            latitude,
            longitude,
            station_type,
            access_type,
            availability_status,
            operating_hours,
            charging_type,
            description,
            image,
            created_at,
            updated_at
        FROM power_stations
        WHERE created_by = :user_id
        ORDER BY created_at DESC
    ");

    $stmt->execute([
        ":user_id" => $user_id
    ]);

    $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* =========================================
       RESPONSE
    ========================================= */
    echo json_encode([
        "success" => true,
        "message" => "My stations fetched successfully",
        "count" => count($stations),
        "data" => $stations
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database error"
    ]);
}