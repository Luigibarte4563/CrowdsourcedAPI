<?php

require_once __DIR__ . '/../../config/cors.php';

header("Content-Type: application/json; charset=UTF-8");

error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../../auth/lineman_access.php';

try {
    $conn = getConnection();
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database connection failed"]);
    exit;
}

try {
    $user = getUserFromJWT();
    if (!$user || !isset($user['id'])) {
        http_response_code(401);
        echo json_encode(["success" => false, "message" => "Unauthorized"]);
        exit;
    }
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit;
}

try {
    /* Scoped for a lineman (see get_active.php) so this count agrees with the outage
       list they actually receive. Empty string for electric_company / admin. */
    $scopeParams = [];
    $scope = lineman_scope_sql($conn, $user, 'orp.barangay_id', $scopeParams, 'rs');

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total_resolved
        FROM outage_reports orp
        JOIN outage_statuses st ON st.id = orp.status_id
        WHERE LOWER(TRIM(st.status_name)) = 'resolved'
        {$scope}
    ");
    $stmt->execute($scopeParams);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $totalResolved = isset($result['total_resolved']) ? (int)$result['total_resolved'] : 0;

    echo json_encode([
        "success" => true,
        "message" => "Resolved outage count fetched successfully",
        "total_resolved" => $totalResolved
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database query failed"]);
}
