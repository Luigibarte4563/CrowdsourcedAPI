<?php

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

if (!$user || !isset($user['id'])) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit;
}

$status   = $_GET['status'] ?? null;
$category = $_GET['category'] ?? null;
$severity = $_GET['severity'] ?? null;

$sql = "
    SELECT COUNT(*) AS total_active_reports
    FROM outage_reports orp
    JOIN outage_statuses st  ON st.id = orp.status_id
    JOIN outage_categories oc ON oc.id = orp.category_id
    JOIN severity_levels sv   ON sv.id = orp.severity_id
    WHERE st.status_name != 'rejected'
      AND orp.is_active = 1
";

$params = [];

if (!empty($status)) {
    $sql .= " AND st.status_name = :status";
    $params[':status'] = $status;
}
if (!empty($category)) {
    $sql .= " AND oc.category_name = :category";
    $params[':category'] = $category;
}
if (!empty($severity)) {
    $sql .= " AND sv.severity_name = :severity";
    $params[':severity'] = $severity;
}

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
    echo json_encode(["success" => false, "message" => "Database query failed"]);
}
