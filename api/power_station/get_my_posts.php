<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';

$conn = getConnection();

/* =========================================
   JWT AUTH
========================================= */
$user = getUserFromJWT();

if (!$user || !isset($user['id'])) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized (invalid JWT)"
    ]);
    exit;
}

$user_id = (int)$user['id'];

if ($user_id <= 0) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "message" => "Invalid user token"
    ]);
    exit;
}

try {

    /* =========================================
       FETCH USER STATIONS
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
       RESPONSE (CLEAN + CONSISTENT)
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