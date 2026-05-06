<?php

header("Content-Type: application/json");
session_start();

require_once __DIR__ . '/../../config/db_connect.php';

$conn = getConnection();

$user_id = $_SESSION['user']['id'] ?? null;

if (!$user_id) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized"
    ]);
    exit;
}

try {

    $stmt = $conn->prepare("
        SELECT location_name, latitude, longitude, updated_at
        FROM users
        WHERE id = :user_id
        LIMIT 1
    ");

    $stmt->execute([":user_id" => $user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode([
            "success" => false,
            "message" => "User not found"
        ]);
        exit;
    }

    echo json_encode([
        "success" => true,
        "data" => [
            "location_name" => $user["location_name"],
            "latitude" => $user["latitude"],
            "longitude" => $user["longitude"],
            "updated_at" => $user["updated_at"]
        ]
    ]);

} catch (PDOException $e) {

    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error"
    ]);
}