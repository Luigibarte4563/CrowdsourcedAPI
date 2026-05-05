<?php

header("Content-Type: application/json");
session_start();

require_once __DIR__ . '/../../config/db_connect.php';

$conn = getConnection();

$user_id = $_SESSION['user']['id'] ?? null;

if (!$user_id) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

$notification_id = $data["id"] ?? null;

if (!$notification_id) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "ID required"]);
    exit;
}

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

    echo json_encode([
        "success" => true,
        "message" => "Marked as read"
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database error"
    ]);
}