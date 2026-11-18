<?php

require_once __DIR__ . '/../../config/cors.php';

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';

try {
    $conn = getConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database connection failed"]);
    exit;
}

try {
    $user = getUserFromJWT();
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid token"]);
    exit;
}

if (!$user) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit;
}

/* =========================================
   OPTIONAL FILTERS (readable names)
========================================= */
$status   = $_GET['status'] ?? null;
$category = $_GET['category'] ?? null;

$sql = "
    SELECT
        orp.id,
        orp.report_key,
        orp.location_name,
        orp.latitude,
        orp.longitude,
        orp.description,
        orp.started_at,
        orp.resolved_at,
        orp.resolution_note,
        orp.created_at,
        orp.updated_at,
        b.id AS barangay_id,
        b.barangay_name,
        orp.category,
        orp.severity,
        orp.hazard_type,
        orp.status AS status
    FROM outage_reports orp
    LEFT JOIN barangays b    ON b.id = orp.barangay_id
    WHERE orp.status != 'rejected'
";

$params = [];

if ($status) {
    $sql .= " AND orp.status = :status";
    $params[':status'] = $status;
}

if ($category) {
    $sql .= " AND orp.category = :category";
    $params[':category'] = $category;
}

$sql .= " ORDER BY orp.created_at DESC";

try {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "count" => count($reports),
        "data" => $reports
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database query failed"]);
}
