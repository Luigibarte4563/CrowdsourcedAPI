<?php

require_once __DIR__ . '/../../config/cors.php';

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../../auth/rbac.php';
require_once __DIR__ . '/../../auth/lineman_access.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* A lineman sees only their assigned barangays; company and admin are unaffected. */
$user = requireRole(requireAuthUser(), ['lineman', 'electric_company', 'admin']);

$status   = $_GET['status'] ?? null;
$category = $_GET['category'] ?? null;
$severity = $_GET['severity'] ?? null;
$barangay = $_GET['barangay'] ?? null;

$sql = "
    SELECT
        orp.id,
        orp.report_key,
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
        b.id AS barangay_id,
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
if (!empty($category)) {
    $sql .= " AND oc.category_name = :category";
    $params[':category'] = $category;
}
if (!empty($severity)) {
    $sql .= " AND sv.severity_name = :severity";
    $params[':severity'] = $severity;
}
if (!empty($barangay)) {
    $sql .= " AND b.barangay_name = :barangay";
    $params[':barangay'] = $barangay;
}

/*
 * Applied last, and as a subquery in the WHERE clause rather than a PHP-side filter.
 *
 * A lineman's ?barangay=Poblacion cannot widen their scope: the two conditions are
 * ANDed, so asking for an unassigned barangay yields an empty result rather than the
 * rows behind it. company/admin get an empty string here and are not narrowed at all.
 */
$sql .= lineman_scope_sql($conn, $user, 'orp.barangay_id', $params);

$sql .= " ORDER BY orp.created_at DESC";

try {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "count" => count($reports),
        "data" => $reports
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error"]);
}
