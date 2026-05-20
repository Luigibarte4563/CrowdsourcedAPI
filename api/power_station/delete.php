<?php

header("Content-Type: application/json");

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

/* =========================================
   INPUT
========================================= */
$data = json_decode(file_get_contents("php://input"), true);

if (!$data) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Invalid JSON body"
    ]);
    exit;
}

/* USE CONSISTENT NAME */
$station_id = isset($data["station_id"]) ? (int)$data["station_id"] : 0;

if ($station_id <= 0) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "station_id is required"
    ]);
    exit;
}

/* =========================================
   DELETE QUERY
========================================= */
try {

    $stmt = $conn->prepare("
        DELETE FROM power_stations
        WHERE id = :id
        AND created_by = :user_id
    ");

    $stmt->execute([
        ":id" => $station_id,
        ":user_id" => $user_id
    ]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Station not found or not owned by user"
        ]);
        exit;
    }

    echo json_encode([
        "success" => true,
        "message" => "Power station deleted successfully"
    ]);

} catch (PDOException $e) {

    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error"
    ]);
}