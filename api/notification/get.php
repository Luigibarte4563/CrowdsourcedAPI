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

try {

    $stmt = $conn->prepare("
        SELECT *
        FROM notifications
        WHERE user_id = :user_id
        ORDER BY created_at DESC
    ");

    $stmt->execute([":user_id" => $user_id]);

    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "data" => $data
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database error"
    ]);
}