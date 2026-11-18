<?php

header("Content-Type: application/json; charset=UTF-8");

error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../../auth/rbac.php';
require_once __DIR__ . '/../../auth/lineman_access.php';

try {
    $conn = getConnection();
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $user = requireRole(requireAuthUser(), ['electric_company', 'admin', 'lineman']);

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
            orp.started_at,
            orp.resolved_at,
            orp.resolution_note,
            orp.created_at,
            orp.updated_at,
            b.id AS barangay_id,
            b.barangay_name,
            orp.category,
            orp.severity,
            orp.hazard_type,
            orp.status AS status
        FROM outage_reports orp
        LEFT JOIN barangays b    ON b.id = orp.barangay_id
        WHERE 1=1
    ";

    $params = [];

    if (!empty($status)) {
        $sql .= " AND orp.status = :status";
        $params[':status'] = $status;
    }
    if (!empty($severity)) {
        $sql .= " AND sl.severity_name = :severity";
        $params[':severity'] = $severity;
    }
    if ($active !== null) {
        $sql .= " AND orp.status = :active_status";
        $params[':active_status'] = $active ? 'active' : 'resolved';
    }

    /*
     * Same scoping rule as outage/get.php, applied last so a lineman's own filters can
     * only ever narrow their assigned set further. Returns '' for company/admin, so
     * their access here is unchanged.
     */
    $sql .= lineman_scope_sql($conn, $user, 'orp.barangay_id', $params);

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
