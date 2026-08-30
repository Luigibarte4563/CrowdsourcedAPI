<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../../auth/rbac.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$user = requireAuthUser();
$user_id = (int)$user['id'];

$data = json_decode(file_get_contents("php://input"), true);
if (!is_array($data)) {
    $data = $_POST;
}

$device_name = trim($data["device_name"] ?? "");
$device_type = $data["device_type"] ?? "";

$validTypes = ['phone', 'laptop', 'powerbank', 'ups', 'tablet', 'other'];
if (!in_array($device_type, $validTypes, true)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid device_type"]);
    exit;
}

if ($device_name === "") {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "device_name is required"]);
    exit;
}

$capacity = isset($data["capacity_mah"]) ? (int)$data["capacity_mah"] : null;
$percentage = isset($data["current_percentage"]) ? (float)$data["current_percentage"] : 100.0;
$isPrimary = !empty($data["is_primary"]) ? 1 : 0;

if ($capacity !== null && $capacity < 0) {
    $capacity = null;
}
$percentage = max(0, min(100, $percentage));

try {
    if ($isPrimary) {
        $conn->prepare("UPDATE battery_devices SET is_primary = 0 WHERE user_id = ?")->execute([$user_id]);
    }

    $stmt = $conn->prepare("
        INSERT INTO battery_devices (user_id, device_name, device_type, capacity_mah, current_percentage, is_primary)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$user_id, $device_name, $device_type, $capacity, $percentage, $isPrimary]);

    echo json_encode([
        "success" => true,
        "message" => "Battery device created",
        "device_id" => (int)$conn->lastInsertId()
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error"]);
}
