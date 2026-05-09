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
   INPUT
========================================= */
$data = json_decode(file_get_contents("php://input"), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Invalid JSON"
    ]);
    exit;
}

$id = isset($data["id"]) ? (int)$data["id"] : 0;

if ($id <= 0) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Valid station ID required"
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
        ":id" => $id,
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
        // "error" => $e->getMessage() // enable only for debugging
    ]);
}