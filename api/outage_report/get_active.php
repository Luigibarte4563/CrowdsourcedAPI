<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';

$conn = getConnection();

/* =========================================
   JWT AUTH (OPTIONAL PROTECTION)
   - remove this block if you want public endpoint
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
$severity = $_GET['severity'] ?? null;

/* =========================================
   BASE QUERY
========================================= */
$sql = "
    SELECT COUNT(*) AS total_active_reports
    FROM outage_reports
    WHERE is_deleted = 0
      AND is_active = 1
";

$params = [];

/* =========================================
   FILTERS
========================================= */
if ($status) {
    $sql .= " AND status = :status";
    $params[':status'] = $status;
}

if ($category) {
    $sql .= " AND category = :category";
    $params[':category'] = $category;
}

if ($severity) {
    $sql .= " AND severity = :severity";
    $params[':severity'] = $severity;
}

/* =========================================
   EXECUTE
========================================= */
try {

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "message" => "Total active reports fetched successfully",
        "total_active_reports" => (int)$result["total_active_reports"]
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database error"
    ]);
}