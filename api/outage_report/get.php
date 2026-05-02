<?php

header("Content-Type: application/json");

require_once __DIR__ . '/../../config/db_connect.php';

$conn = getConnection();

/* =========================================
   OPTIONAL QUERY PARAMETERS
========================================= */
$user_id = $_GET['user_id'] ?? null;
$status  = $_GET['status'] ?? null;
$category = $_GET['category'] ?? null;

/* =========================================
   BASE QUERY
========================================= */
$sql = "SELECT * FROM outage_reports WHERE 1=1";
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
        "message" => "Database error"
    ]);
}