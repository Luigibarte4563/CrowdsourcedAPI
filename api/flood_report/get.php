<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../../auth/rbac.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

requireAuthUser();

$status     = $_GET['status'] ?? null;
$flood_level = $_GET['flood_level'] ?? null;
$barangay   = $_GET['barangay'] ?? null;

$sql = "
    SELECT
        fr.id,
        fr.reported_by,
        fr.location_name,
        fr.latitude,
        fr.longitude,
        fr.flood_depth_cm,
        fr.flood_level,
        fr.description,
        fr.image_proof,
        fr.status,
        fr.reported_at,
        fr.updated_at,
        b.barangay_name
    FROM flood_reports fr
    LEFT JOIN barangays b ON b.id = fr.barangay_id
    WHERE 1=1
";

$params = [];

if (!empty($status)) {
    $sql .= " AND fr.status = :status";
    $params[':status'] = $status;
}
if (!empty($flood_level)) {
    $sql .= " AND fr.flood_level = :flood_level";
    $params[':flood_level'] = $flood_level;
}
if (!empty($barangay)) {
    $sql .= " AND b.barangay_name = :barangay";
    $params[':barangay'] = $barangay;
}

$sql .= " ORDER BY fr.reported_at DESC";

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
