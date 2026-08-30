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
    echo json_encode(["success" => false, "message" => "Invalid or missing token"]);
    exit;
}

if (!$user || !isset($user['id'])) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit;
}

$user_id = (int)$user['id'];

try {
    $stmt = $conn->prepare("
        SELECT
            orp.id,
            orp.report_key,
            orp.location_name,
            orp.latitude,
            orp.longitude,
            orp.description,
            orp.affected_houses,
            orp.is_active,
            orp.started_at,
            orp.resolved_at,
            orp.resolution_note,
            orp.created_at,
            b.barangay_name,
            oc.category_name AS category,
            sv.severity_name AS severity,
            hz.hazard_name AS hazard_type,
            st.status_name AS status
        FROM outage_reports orp
        JOIN outage_categories oc ON oc.id = orp.category_id
        JOIN severity_levels sv   ON sv.id = orp.severity_id
        JOIN hazard_types hz     ON hz.id = orp.hazard_type_id
        JOIN outage_statuses st  ON st.id = orp.status_id
        LEFT JOIN barangays b    ON b.id = orp.barangay_id
        WHERE orp.user_id = :user_id
          AND st.status_name != 'rejected'
        ORDER BY orp.created_at DESC
    ");
    $stmt->execute([":user_id" => $user_id]);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "count" => count($reports),
        "data" => $reports
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database query error"]);
}
