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
            ps.id,
            ps.created_by,
            ps.station_name,
            ps.location_name,
            ps.latitude,
            ps.longitude,
            pst.type_name AS station_type,
            ps.access_type,
            ps.availability_status,
            ps.operating_hours,
            ps.charging_type,
            ps.description,
            ps.image,
            ps.created_at,
            ps.updated_at,
            b.barangay_name
        FROM power_stations ps
        LEFT JOIN power_station_types pst ON pst.id = ps.station_type_id
        LEFT JOIN barangays b ON b.id = ps.barangay_id
        WHERE ps.created_by = :user_id
        ORDER BY ps.created_at DESC
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