<?php

header("Content-Type: application/json");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';

$conn = getConnection();

/* =========================================
   JWT AUTH (REPLACES SESSION)
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
        "message" => "Invalid token user"
    ]);
    exit;
}

/* =========================================
   FETCH USER LOCATION
========================================= */
try {

    $stmt = $conn->prepare("
        SELECT location_name, latitude, longitude, updated_at
        FROM users
        WHERE id = :user_id
        LIMIT 1
    ");

    $stmt->execute([
        ":user_id" => $user_id
    ]);

    $userData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$userData) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "User not found"
        ]);
        exit;
    }

    echo json_encode([
        "success" => true,
        "data" => [
            "location_name" => $userData["location_name"],
            "latitude" => $userData["latitude"],
            "longitude" => $userData["longitude"],
            "updated_at" => $userData["updated_at"]
        ]
    ]);

} catch (PDOException $e) {

    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error"
    ]);
}