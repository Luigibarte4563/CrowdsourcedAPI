<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../../auth/rbac.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$user = requireAuthUser();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid report id"]);
    exit;
}

try {
    /* Report + names */
    $stmt = $conn->prepare("
        SELECT
            orp.*,
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
        WHERE orp.id = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$report) {
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Report not found"]);
        exit;
    }

    /* Regular users may only view their own report */
    $role = $user['role'] ?? '';
    $isStaff = in_array($role, ['lineman', 'electric_company', 'admin'], true);
    if (!$isStaff && (int)$report['user_id'] !== (int)$user['id']) {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Forbidden"]);
        exit;
    }

    /* Images */
    $imgStmt = $conn->prepare("SELECT id, uploaded_by, image_url, created_at FROM outage_report_images WHERE outage_report_id = ? ORDER BY id");
    $imgStmt->execute([$id]);
    $report['images'] = $imgStmt->fetchAll(PDO::FETCH_ASSOC);

    /* Updates (history) */
    $upStmt = $conn->prepare("
        SELECT u.id, u.update_message, u.created_at, st.status_name AS to_status
        FROM outage_report_updates u
        LEFT JOIN outage_statuses st ON st.id = u.status_id
        WHERE u.outage_report_id = ?
        ORDER BY u.id
    ");
    $upStmt->execute([$id]);
    $report['updates'] = $upStmt->fetchAll(PDO::FETCH_ASSOC);

    /* Verifications (history) */
    $verStmt = $conn->prepare("
        SELECT id, verified_by, verification_status, notes, verified_at
        FROM outage_report_verifications
        WHERE outage_report_id = ?
        ORDER BY verified_at
    ");
    $verStmt->execute([$id]);
    $report['verifications'] = $verStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "data" => $report
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error"]);
}
