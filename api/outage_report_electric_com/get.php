<?php

header("Content-Type: application/json; charset=UTF-8");

error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../../auth/rbac.php';

try {
    $conn = getConnection();
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    requireRole(requireAuthUser(), ['electric_company', 'admin', 'lineman']);

    $status   = $_GET['status'] ?? null;
    $severity = $_GET['severity'] ?? null;
    $active   = isset($_GET['active']) ? (int)$_GET['active'] : null;

    $sql = "
        SELECT
            orp.id,
            orp.user_id,
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
            orp.updated_at,
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
        WHERE 1=1
    ";

    $params = [];

    if (!empty($status)) {
        $sql .= " AND st.status_name = :status";
        $params[':status'] = $status;
    }
    if (!empty($severity)) {
        $sql .= " AND sv.severity_name = :severity";
        $params[':severity'] = $severity;
    }
    if ($active !== null) {
        $sql .= " AND orp.is_active = :active";
        $params[':active'] = $active;
    }

    $sql .= " ORDER BY orp.created_at DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "count" => count($rows),
        "data" => $rows
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error"]);
}
