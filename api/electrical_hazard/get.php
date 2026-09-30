<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../../auth/rbac.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

requireAuthUser();

$status   = $_GET['status'] ?? null;
$severity = $_GET['severity'] ?? null;
$barangay = $_GET['barangay'] ?? null;

$sql = "
    SELECT
        eh.id,
        eh.reported_by,
        eh.location_name,
        eh.latitude,
        eh.longitude,
        eh.description,
        eh.severity,
        eh.status,
        eh.image_proof,
        eh.reported_at,
        eh.resolved_at,
        b.barangay_name,
        ht.hazard_name AS hazard_type
    FROM electrical_hazards eh
    LEFT JOIN barangays b ON b.id = eh.barangay_id
    LEFT JOIN hazard_types ht ON ht.id = eh.hazard_type_id
    WHERE 1=1
";

$params = [];

if (!empty($status)) {
    $sql .= " AND eh.status = :status";
    $params[':status'] = $status;
}
if (!empty($severity)) {
    $sql .= " AND eh.severity = :severity";
    $params[':severity'] = $severity;
}
if (!empty($barangay)) {
    $sql .= " AND b.barangay_name = :barangay";
    $params[':barangay'] = $barangay;
}

$sql .= " ORDER BY eh.reported_at DESC";

try {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "count" => count($rows),
        "data" => $rows
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error"]);
}
