<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../../auth/rbac.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

requireAuthUser();

$status    = $_GET['status'] ?? null;
$barangay  = $_GET['barangay'] ?? null;
$from_date = $_GET['from_date'] ?? null;

$sql = "
    SELECT
        c.id,
        c.cluster_date,
        c.center_latitude,
        c.center_longitude,
        c.radius_meters,
        c.report_count,
        c.affected_houses,
        c.confidence_score,
        c.severity_score,
        c.forecast_level,
        c.status,
        c.calculated_at,
        b.barangay_name
    FROM outage_clusters c
    LEFT JOIN barangays b ON b.id = c.barangay_id
    WHERE 1=1
";

$params = [];

if (!empty($status)) {
    $sql .= " AND c.status = :status";
    $params[':status'] = $status;
}
if (!empty($barangay)) {
    $sql .= " AND b.barangay_name = :barangay";
    $params[':barangay'] = $barangay;
}
if (!empty($from_date)) {
    $sql .= " AND c.cluster_date >= :from_date";
    $params[':from_date'] = $from_date;
}

$sql .= " ORDER BY c.calculated_at DESC";

try {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $clusters = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* Optionally attach constituent reports per cluster */
    $includeReports = isset($_GET['include_reports']) && $_GET['include_reports'] === '1';
    if ($includeReports) {
        $reportStmt = $conn->prepare("
            SELECT ocr.outage_report_id, ocr.distance_meters, o.report_key, o.location_name
            FROM outage_cluster_reports ocr
            JOIN outage_reports o ON o.id = ocr.outage_report_id
            WHERE ocr.cluster_id = ?
            ORDER BY ocr.distance_meters ASC
        ");
        foreach ($clusters as &$cl) {
            $reportStmt->execute([$cl['id']]);
            $cl['reports'] = $reportStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($cl);
    }

    echo json_encode([
        "success" => true,
        "count" => count($clusters),
        "data" => $clusters
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error"]);
}
