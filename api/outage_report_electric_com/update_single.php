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

$id = (int)($data["id"] ?? 0);
$status = $data["status"] ?? null;

if ($id <= 0 || !$status) {
    http_response_code(400);
    echo json_encode(["success"=>false,"message"=>"ID and status required"]);
    exit;
}

$valid = ['active','under_review','verified','resolved','rejected'];

if (!in_array($status, $valid)) {
    http_response_code(400);
    echo json_encode(["success"=>false,"message"=>"Invalid status"]);
    exit;
}

$sql = "UPDATE outage_reports SET status = :status";

$params = [
    ":status"=>$status,
    ":id"=>$id
];

if ($status === "verified") {
    $sql .= ", verified_at = NOW(), verified_by = :company_id";
    $params[":company_id"] = $company_id;
}

if ($status === "resolved") {
    $sql .= ", resolved_at = NOW(), resolved_by = :company_id, is_active = 0";
    $params[":company_id"] = $company_id;
}

$sql .= " WHERE id = :id";

$stmt = $conn->prepare($sql);
$stmt->execute($params);

echo json_encode([
    "success"=>true,
    "message"=>"Updated successfully"
]);