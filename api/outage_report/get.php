<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';

try {
    $conn = getConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database connection failed"
    ]);
    exit;
}

/* =========================================
   JWT AUTH (SAFE HANDLING)
========================================= */
try {
    $user = getUserFromJWT();
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "message" => "Invalid token"
    ]);
    exit;
}

if (!$user) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized"
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
========================================= */
$sql = "
    SELECT *
    FROM outage_reports
    WHERE status != 'rejected'
      AND is_active = 1
";

$params = [];

/* FILTERS */
if ($status) {
    $sql .= " AND status = :status";
    $params[':status'] = $status;
}

if ($category) {
    $sql .= " AND category = :category";
    $params[':category'] = $category;
}

$sql .= " ORDER BY created_at DESC";

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
        "message" => "Database query failed",
        "error" => $e->getMessage() // TEMP DEBUG (remove later)
    ]);
}