<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';

$conn = getConnection();

/* =========================================
   JWT AUTH (STILL REQUIRED)
========================================= */
$user = getUserFromJWT();

if (!$user) {
    http_response_code(401);

    echo json_encode([
        "success" => false,
        "message" => "Unauthorized (invalid JWT)"
    ]);
    exit;
}

/* =========================================
   OPTIONAL QUERY PARAMETERS
========================================= */
$status   = $_GET['status'] ?? null;
$category = $_GET['category'] ?? null;
$active   = $_GET['is_active'] ?? null;

/* =========================================
   BASE QUERY (NO USER FILTER = ALL REPORTS)
========================================= */
$sql = "
    SELECT 
        id,
        user_id,
        location_name,
        latitude,
        longitude,
        category,
        severity,
        description,
        image_proof,
        affected_houses,
        is_active,
        hazard_type,
        started_at,
        status,
        verified_by,
        created_at,
        updated_at
    FROM outage_reports
    WHERE is_deleted = 0
";

$params = [];

/* =========================================
   OPTIONAL FILTERS
========================================= */
if ($status) {
    $sql .= " AND status = :status";
    $params[':status'] = $status;
}

if ($category) {
    $sql .= " AND category = :category";
    $params[':category'] = $category;
}

if ($active !== null) {
    $sql .= " AND is_active = :is_active";
    $params[':is_active'] = $active;
}

/* =========================================
   ORDER BY LATEST
========================================= */
$sql .= " ORDER BY created_at DESC";

/* =========================================
   EXECUTE
========================================= */
try {

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "message" => "All reports fetched successfully",
        "count" => count($reports),
        "data" => $reports
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database error"
    ]);
}