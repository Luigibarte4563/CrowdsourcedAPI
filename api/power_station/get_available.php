<?php

header("Content-Type: application/json; charset=UTF-8");

error_reporting(E_ALL);
ini_set('display_errors', 0);

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
   COUNT AVAILABLE POWER STATIONS
========================================= */
try {

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total_available
        FROM power_stations
        WHERE availability_status = 'available'
    ");

    $stmt->execute();

    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "message" => "Available power stations count fetched successfully",
        "total_available" => (int)$result["total_available"]
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database error"
    ]);
}