<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../../auth/rbac.php';
require_once __DIR__ . '/../services/lookup.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$user = requireRole(requireAuthUser(), ['electric_company', 'admin', 'lineman']);

$data = json_decode(file_get_contents("php://input"), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid JSON input"]);
    exit;
}

$id     = (int)($data["id"] ?? 0);
$status = trim($data["status"] ?? "");

if ($id <= 0 || $status === "") {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "id and status required"]);
    exit;
}

$valid = ['active', 'under_review', 'verified', 'resolved', 'rejected'];
if (!in_array($status, $valid, true)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid status"]);
    exit;
}

try {
    $statusId = getStatusId($conn, $status);
    $sql = "UPDATE outage_reports SET status_id = :status_id, updated_at = NOW()";
    $params = [":status_id" => $statusId, ":id" => $id];

    if ($status === "resolved") {
        $sql .= ", resolved_at = NOW(), is_active = 0";
    }

    $sql .= " WHERE id = :id";
    $stmt = $conn->prepare($sql)->execute($params);

    echo json_encode(["success" => true, "message" => "Updated successfully"]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error"]);
}
