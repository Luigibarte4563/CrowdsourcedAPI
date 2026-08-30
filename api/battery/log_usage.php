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

$device_id              = (int)($data["device_id"] ?? 0);
$percentage_start       = isset($data["battery_percentage_start"]) ? (float)$data["battery_percentage_start"] : null;
$percentage_end         = isset($data["battery_percentage_end"]) ? (float)$data["battery_percentage_end"] : null;
$usage_minutes          = isset($data["usage_minutes"]) ? (int)$data["usage_minutes"] : null;
$estimated_watts        = isset($data["estimated_watts"]) ? (float)$data["estimated_watts"] : null;
$activity               = trim($data["activity"] ?? "");

if ($device_id <= 0 || $percentage_start === null || $percentage_end === null) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "device_id, battery_percentage_start and battery_percentage_end are required"]);
    exit;
}

try {
    $check = $conn->prepare("SELECT id FROM battery_devices WHERE id = ? AND user_id = ? LIMIT 1");
    $check->execute([$device_id, $user_id]);
    if (!$check->fetch()) {
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Device not found"]);
        exit;
    }

    $insert = $conn->prepare("
        INSERT INTO battery_usage_logs (
            battery_device_id,
            battery_percentage_start,
            battery_percentage_end,
            usage_minutes,
            estimated_watts,
            activity
        ) VALUES (?, ?, ?, ?, ?, ?)
    ");
    $insert->execute([
        $device_id,
        $percentage_start,
        $percentage_end,
        $usage_minutes,
        $estimated_watts,
        $activity !== "" ? $activity : null
    ]);

    $log_id = (int)$conn->lastInsertId();

    /* Reflect the end battery level on the device */
    $conn->prepare("UPDATE battery_devices SET current_percentage = ? WHERE id = ? AND user_id = ?")
        ->execute([$percentage_end, $device_id, $user_id]);

    echo json_encode([
        "success" => true,
        "message" => "Battery usage logged",
        "log_id" => $log_id
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error"]);
}
