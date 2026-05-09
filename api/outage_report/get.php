<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';

$conn = getConnection();

/* =========================================
   JWT AUTH
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
   OPTIONAL FILTERS
========================================= */
$status   = $_GET['status'] ?? null;
$category = $_GET['category'] ?? null;

/* =========================================
   BASE QUERY
   IMPORTANT FIX: hide cancelled reports
========================================= */
$sql = "
    SELECT 
        id,
        user_id,
        report_key,
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
        verified_by_company_id,
        resolved_by_company_id,
        verified_at,
        resolved_at,
        resolution_note,
        maintenance_id,
        created_at,
        updated_at
    FROM outage_reports
    WHERE status != 'rejected'
      AND is_active = 1
";

$params = [];

/* =========================================
   FILTER: STATUS
========================================= */
if (!empty($status)) {
    $sql .= " AND status = :status";
    $params[':status'] = $status;
}

/* =========================================
   FILTER: CATEGORY
========================================= */
if (!empty($category)) {
    $sql .= " AND category = :category";
    $params[':category'] = $category;
}

/* =========================================
   ORDER BY LATEST
========================================= */
$sql .= " ORDER BY created_at DESC";

try {

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "message" => "Reports fetched successfully",
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