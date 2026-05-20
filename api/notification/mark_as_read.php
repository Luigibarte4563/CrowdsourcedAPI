<?php

header("Content-Type: application/json");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';

$conn = getConnection();

/* =========================
   JWT AUTH
========================= */
$user = getUserFromJWT();

if (!$user || !isset($user["id"])) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized (invalid JWT)"
    ]);
    exit;
}

$user_id = (int)$user["id"];

/* =========================
   INPUT
========================= */
$data = json_decode(file_get_contents("php://input"), true);

if (!$data) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Invalid JSON"
    ]);
    exit;
}

$notification_id = $data["notification_id"] ?? null;

if (!$notification_id) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "notification_id is required"
    ]);
    exit;
}

$notification_id = (int)$notification_id;

try {

    $stmt = $conn->prepare("
        UPDATE notifications
        SET is_read = 1
        WHERE id = :id AND user_id = :user_id
    ");

    $stmt->execute([
        ":id" => $notification_id,
        ":user_id" => $user_id
    ]);

    /* check if anything was updated */
    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Notification not found or already updated"
        ]);
        exit;
    }

    echo json_encode([
        "success" => true,
        "message" => "Notification marked as read"
    ]);

} catch (PDOException $e) {

    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error"
    ]);
}