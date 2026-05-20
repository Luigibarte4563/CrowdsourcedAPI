<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';

/* =========================================
   DB CONNECTION SAFETY
========================================= */
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
   JWT AUTH (SAFE)
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

if (!$user || !isset($user['id'])) {
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
$severity = $_GET['severity'] ?? null;

/* =========================================
   BASE QUERY
========================================= */
$sql = "
    SELECT COUNT(*) AS total_active_reports
    FROM outage_reports
    WHERE status != 'rejected'
      AND is_active = 1
";

$params = [];

/* FILTER: STATUS */
if (!empty($status)) {
    $sql .= " AND status = :status";
    $params[':status'] = $status;
}

/* FILTER: CATEGORY */
if (!empty($category)) {
    $sql .= " AND category = :category";
    $params[':category'] = $category;
}

/* FILTER: SEVERITY */
if (!empty($severity)) {
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

    $count = $result['total_active_reports'] ?? 0;

    echo json_encode([
        "success" => true,
        "message" => "Total active reports fetched successfully",
        "total_active_reports" => (int)$count
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database query failed"
        // "error" => $e->getMessage() // enable for debugging
    ]);
}