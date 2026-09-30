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
if ($device_id <= 0) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "device_id required"]);
    exit;
}

try {
    /* Ownership check */
    $stmt = $conn->prepare("SELECT * FROM battery_devices WHERE id = ? AND user_id = ? LIMIT 1");
    $stmt->execute([$device_id, $user_id]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$device) {
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Device not found"]);
        exit;
    }

    $device_name = trim($data["device_name"] ?? $device["device_name"]);
    $device_type = $data["device_type"] ?? $device["device_type"];
    $capacity    = isset($data["capacity_mah"]) ? (int)$data["capacity_mah"] : $device["capacity_mah"];
    $percentage  = isset($data["current_percentage"]) ? (float)$data["current_percentage"] : (float)$device["current_percentage"];

    $validTypes = ['phone', 'laptop', 'powerbank', 'ups', 'tablet', 'other'];
    if (!in_array($device_type, $validTypes, true)) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Invalid device_type"]);
        exit;
    }

    $percentage = max(0, min(100, $percentage));

    if (isset($data["is_primary"]) && $data["is_primary"]) {
        $conn->prepare("UPDATE battery_devices SET is_primary = 0 WHERE user_id = ?")->execute([$user_id]);
    }
    $isPrimary = isset($data["is_primary"]) ? ($data["is_primary"] ? 1 : (int)$device["is_primary"]) : (int)$device["is_primary"];

    $stmt = $conn->prepare("
        UPDATE battery_devices
        SET device_name = :device_name,
            device_type = :device_type,
            capacity_mah = :capacity,
            current_percentage = :percentage,
            is_primary = :is_primary
        WHERE id = :id AND user_id = :user_id
    ");
    $stmt->execute([
        ":device_name" => $device_name,
        ":device_type" => $device_type,
        ":capacity" => $capacity,
        ":percentage" => $percentage,
        ":is_primary" => $isPrimary,
        ":id" => $device_id,
        ":user_id" => $user_id
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Device updated"
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error"]);
}
