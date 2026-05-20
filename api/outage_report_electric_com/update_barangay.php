<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$user = getUserFromJWT();

if (!$user || $user['role'] !== 'electric_company') {
    http_response_code(403);
    echo json_encode(["success"=>false,"message"=>"Access denied"]);
    exit;
}

$company_id = $user['id'];

$data = json_decode(file_get_contents("php://input"), true);

$barangay = $data["barangay"] ?? null;
$status = $data["status"] ?? null;

if (!$barangay || !$status) {
    http_response_code(400);
    echo json_encode(["success"=>false,"message"=>"Barangay and status required"]);
    exit;
}

/* simplified geo lookup (reuse yours if needed) */
$stmt = $conn->prepare("
    UPDATE outage_reports
    SET status = :status,
        verified_at = IF(:status='verified', NOW(), verified_at),
        verified_by = IF(:status='verified', :company_id, verified_by),
        resolved_at = IF(:status='resolved', NOW(), resolved_at),
        resolved_by = IF(:status='resolved', :company_id, resolved_by),
        is_active = IF(:status='resolved', 0, is_active)
    WHERE location_name LIKE :barangay
");

$stmt->execute([
    ":status"=>$status,
    ":company_id"=>$company_id,
    ":barangay"=>"%$barangay%"
]);

echo json_encode([
    "success"=>true,
    "message"=>"Barangay updated successfully"
]);