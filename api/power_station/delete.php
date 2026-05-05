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

$data = json_decode(file_get_contents("php://input"), true);

$id = $data["id"] ?? null;

if (!$id) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "ID required"
    ]);
    exit;
}

try {

    $stmt = $conn->prepare("
        DELETE FROM power_stations
        WHERE id = :id AND created_by = :user_id
    ");

    $stmt->execute([
        ":id" => $id,
        ":user_id" => $user_id
    ]);

    if ($stmt->rowCount() === 0) {
        echo json_encode([
            "success" => false,
            "message" => "Not found or unauthorized"
        ]);
        exit;
    }

    echo json_encode([
        "success" => true,
        "message" => "Deleted successfully"
    ]);

} catch (PDOException $e) {

    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error"
    ]);
}