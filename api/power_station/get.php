<?php

header("Content-Type: application/json");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';

$conn = getConnection();

/* =========================================
   JWT AUTH
========================================= */
$user = getUserFromJWT();

if (!$user) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized (invalid JWT)"
    ]);
    exit;
}

$user_id = $user['id'] ?? null;

if (!$user_id) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "message" => "Invalid user token"
    ]);
    exit;
}

/* =========================================
   FETCH ALL POWER STATIONS
========================================= */
try {

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
        ORDER BY created_at DESC
    ");

    $stmt->execute();

    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "message" => empty($data)
            ? "No power stations found"
            : "Power stations loaded successfully",
        "count" => count($data),
        "data" => $data
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database error"
    ]);
}