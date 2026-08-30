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

$barangay = $data["barangay"] ?? null;
$status   = $data["status"] ?? null;

if (!$barangay || !$status) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Barangay and status required"]);
    exit;
}

try {
    $statusId = getStatusId($conn, $status);
    $barangayId = resolveBarangay($conn, $barangay);

    $sql = "UPDATE outage_reports SET status_id = :status_id, updated_at = NOW()";
    $params = [":status_id" => $statusId, ":barangay_id" => $barangayId];

    if ($status === "resolved") {
        $sql .= ", resolved_at = NOW(), is_active = 0";
    }

    $sql .= " WHERE barangay_id = :barangay_id";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    echo json_encode([
        "success" => true,
        "message" => "Barangay updated successfully",
        "affected" => $stmt->rowCount()
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error"]);
}
