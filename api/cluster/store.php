<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../../auth/rbac.php';
require_once __DIR__ . '/../services/lookup.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$user = requireAuthUser();
$user_id = (int)$user['id'];

/* Only field/staff users may persist outage clusters */
if (!hasRole($user, ['lineman', 'electric_company', 'admin'])) {
    denyAccess();
}

$data = json_decode(file_get_contents("php://input"), true);
if (!is_array($data)) {
    $data = $_POST;
}

$barangay_id     = (int)($data["barangay_id"] ?? 0);
$center_lat      = isset($data["latitude"]) ? (float)$data["latitude"] : null;
$center_lng      = isset($data["longitude"]) ? (float)$data["longitude"] : null;
$radius_meters   = (int)($data["radius_meters"] ?? 500);
$report_count    = (int)($data["report_count"] ?? 1);
$affected_houses = (int)($data["affected_houses"] ?? 0);
$confidence      = isset($data["confidence_score"]) ? (float)$data["confidence_score"] : 0.0;
$severity_score  = isset($data["severity_score"]) ? (float)$data["severity_score"] : 0.0;
$forecast_level  = $data["forecast_level"] ?? "low";
$report_ids      = isset($data["report_ids"]) && is_array($data["report_ids"]) ? $data["report_ids"] : [];

$validForecast = ['low', 'moderate', 'high', 'critical'];
if (!in_array($forecast_level, $validForecast, true)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid forecast_level"]);
    exit;
}
if ($center_lat === null || $center_lng === null || $barangay_id <= 0) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "barangay_id, latitude and longitude are required"]);
    exit;
}

$clusterDate = $data["cluster_date"] ?? date("Y-m-d");

try {
    $insert = $conn->prepare("
        INSERT INTO outage_clusters (
            barangay_id, cluster_date, center_latitude, center_longitude,
            radius_meters, report_count, affected_houses,
            confidence_score, severity_score, forecast_level, status
        ) VALUES (
            :barangay_id, :cluster_date, :center_lat, :center_lng,
            :radius, :report_count, :affected_houses,
            :confidence, :severity, :forecast, 'active'
        )
    ");
    $insert->execute([
        ":barangay_id"     => $barangay_id,
        ":cluster_date"    => $clusterDate,
        ":center_lat"      => $center_lat,
        ":center_lng"      => $center_lng,
        ":radius"          => $radius_meters,
        ":report_count"    => $report_count,
        ":affected_houses" => $affected_houses,
        ":confidence"      => $confidence,
        ":severity"        => $severity_score,
        ":forecast"        => $forecast_level
    ]);
    $cluster_id = (int)$conn->lastInsertId();

    /* Link the constituent reports with their distance from the center */
    $link = $conn->prepare("
        INSERT IGNORE INTO outage_cluster_reports (cluster_id, outage_report_id, distance_meters)
        VALUES (?, ?, ?)
    ");
    foreach ($report_ids as $rid) {
        $rid = (int)$rid;
        if ($rid <= 0) continue;
        $detail = $conn->prepare("SELECT latitude, longitude FROM outage_reports WHERE id = ? LIMIT 1");
        $detail->execute([$rid]);
        $r = $detail->fetch(PDO::FETCH_ASSOC);
        $distance = null;
        if ($r && $r['latitude'] !== null && $r['longitude'] !== null) {
            $distance = haversineDistanceMeters($center_lat, $center_lng, (float)$r['latitude'], (float)$r['longitude']);
        }
        $link->execute([$cluster_id, $rid, $distance]);
    }

    echo json_encode([
        "success" => true,
        "message" => "Cluster persisted",
        "cluster_id" => $cluster_id
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error"]);
}
