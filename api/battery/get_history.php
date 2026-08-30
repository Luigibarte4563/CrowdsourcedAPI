<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../../auth/rbac.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$user_id = (int)requireAuthUser()['id'];

$device_id = (int)($_GET['device_id'] ?? 0);
if ($device_id <= 0) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "device_id required"]);
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

    $stmt = $conn->prepare("
        SELECT id, battery_device_id, battery_percentage_start, battery_percentage_end,
               usage_minutes, estimated_watts, activity, logged_at
        FROM battery_usage_logs
        WHERE battery_device_id = :id
        ORDER BY logged_at DESC
    ");
    $stmt->execute([":id" => $device_id]);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "count" => count($logs),
        "data" => $logs
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error"]);
}
