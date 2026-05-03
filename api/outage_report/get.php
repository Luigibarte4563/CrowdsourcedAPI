<?php

header("Content-Type: application/json");

require_once __DIR__ . '/../../config/db_connect.php';

$conn = getConnection();

/* =========================================
   OPTIONAL QUERY PARAMETERS
========================================= */
$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;
$status  = $_GET['status'] ?? null;
$category = $_GET['category'] ?? null;
$active   = $_GET['is_active'] ?? null;

/* =========================================
   BASE QUERY (UPDATED FOR NEW DB)
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
   FILTERS (OPTIONAL)
========================================= */

if ($user_id) {
    $sql .= " AND user_id = :user_id";
    $params[':user_id'] = $user_id;
}

if ($status) {
    $sql .= " AND status = :status";
    $params[':status'] = $status;
}

if ($category) {
    $sql .= " AND category = :category";
    $params[':category'] = $category;
}

if ($active) {
    $sql .= " AND is_active = :is_active";
    $params[':is_active'] = $active;
}

/* =========================================
   ORDER (LATEST FIRST)
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
        "count" => count($reports),
        "data" => $reports
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database error",
        "error" => $e->getMessage()
    ]);
}