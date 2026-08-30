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
    $data = $_POST;
}

$device_id = (int)($data["device_id"] ?? 0);
if ($device_id <= 0) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "device_id required"]);
    exit;
}

try {
    $stmt = $conn->prepare("DELETE FROM battery_devices WHERE id = ? AND user_id = ?");
    $stmt->execute([$device_id, $user_id]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Device not found"]);
        exit;
    }

    echo json_encode([
        "success" => true,
        "message" => "Device deleted"
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error"]);
}
