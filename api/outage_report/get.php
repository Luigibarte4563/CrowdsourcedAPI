<?php

header("Content-Type: application/json; charset=UTF-8");

session_start();

require_once __DIR__ . '/../../config/db_connect.php';

$conn = getConnection();

/* =========================================
   STRICT SESSION CHECK (SECURITY FIX)
========================================= */
$user_id = $_SESSION['user']['id'] ?? null;

if (!$user_id) {
    http_response_code(401);

    echo json_encode([
        "success" => false,
        "message" => "Unauthorized (no session)"
    ]);
    exit;
}

/* =========================================
   OPTIONAL QUERY PARAMETERS
========================================= */
$status  = $_GET['status'] ?? null;
$category = $_GET['category'] ?? null;
$active   = $_GET['is_active'] ?? null;

/* =========================================
   BASE QUERY (USER-LOCKED DATA)
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
    WHERE user_id = :user_id
      AND is_deleted = 0
";

$params = [
    ":user_id" => $user_id
];

/* =========================================
   FILTERS (OPTIONAL)
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