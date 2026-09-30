<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../../auth/rbac.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$user_id = (int)requireAuthUser()['id'];

$data = json_decode(file_get_contents("php://input"), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid JSON input"]);
    exit;
}

$device_id = (int)($data["device_id"] ?? 0);
$percentage = isset($data["current_percentage"]) ? (float)$data["current_percentage"] : -1;

if ($device_id <= 0) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "device_id required"]);
    exit;
}
if ($percentage < 0 || $percentage > 100) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "current_percentage must be 0-100"]);
    exit;
}

try {
    $stmt = $conn->prepare("
        UPDATE battery_devices
        SET current_percentage = :percentage
        WHERE id = :id AND user_id = :user_id
    ");
    $stmt->execute([":percentage" => $percentage, ":id" => $device_id, ":user_id" => $user_id]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Device not found"]);
        exit;
    }

    echo json_encode([
        "success" => true,
        "message" => "Battery percentage updated",
        "current_percentage" => $percentage
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error"]);
}
