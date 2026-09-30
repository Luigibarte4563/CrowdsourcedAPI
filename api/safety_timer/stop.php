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

$timer_id = (int)($data["timer_id"] ?? 0);
if ($timer_id <= 0) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "timer_id required"]);
    exit;
}

try {
    $stmt = $conn->prepare("
        UPDATE safety_timers
        SET status = 'stopped', completed_at = NOW()
        WHERE id = ? AND user_id = ?
    ");
    $stmt->execute([$timer_id, $user_id]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Timer not found"]);
        exit;
    }

    echo json_encode([
        "success" => true,
        "message" => "Timer stopped"
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error"]);
}
