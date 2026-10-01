<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../../auth/rbac.php';
require_once __DIR__ . '/../services/lookup.php';
require_once __DIR__ . '/../../auth/lineman_access.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$user = requireRole(requireAuthUser(), ['electric_company', 'admin', 'lineman']);

/*
 * This endpoint issues one UPDATE with no WHERE clause - it rewrites every report in
 * Dagupan. There is no barangay to narrow it to, so a lineman cannot be given a
 * scoped version of it: their assignment covers specific barangays, and this reaches
 * far past all of them. Denied outright rather than silently narrowed.
 *
 * electric_company and admin keep the city-wide action exactly as before.
 */
if (lineman_scope_required($user)) {
    denyAccess("City-wide outage updates are limited to electric company and admin accounts.");
}

$data = json_decode(file_get_contents("php://input"), true);

$status = $data["status"] ?? null;

if (!$status) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Status required"]);
    exit;
}

try {
    $statusId = getStatusId($conn, $status);
    $sql = "UPDATE outage_reports SET status_id = :status_id, updated_at = NOW()";
    $params = [":status_id" => $statusId];

    if ($status === "resolved") {
        $sql .= ", resolved_at = NOW(), is_active = 0";
    }

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    echo json_encode([
        "success" => true,
        "message" => "Dagupan updated successfully",
        "affected" => $stmt->rowCount()
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error"]);
}
