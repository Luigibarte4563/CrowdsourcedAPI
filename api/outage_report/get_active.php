<?php

require_once __DIR__ . '/../../config/cors.php';

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../../auth/lineman_access.php';

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
    WHERE orp.status != 'rejected'
";

$params = [];

if (!empty($status)) {
    $sql .= " AND orp.status = :status";
    $params[':status'] = $status;
}
if (!empty($category)) {
    $sql .= " AND orp.category = :category";
    $params[':category'] = $category;
}
if (!empty($severity)) {
    $sql .= " AND orp.severity = :severity";
    $params[':severity'] = $severity;
}

/*
 * Scoped for a lineman, like the outage list endpoints.
 *
 * Without this the count would be city-wide while `outage/get.php` returns only the
 * lineman's assigned barangays, so the dashboard would show "3 active" above a list of
 * 1. electric_company and admin are unaffected - `lineman_scope_sql` returns '' for them.
 */
$sql .= lineman_scope_sql($conn, $user, 'orp.barangay_id', $params);

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
