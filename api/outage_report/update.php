<?php

header("Content-Type: application/json");

session_start();

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../services/get_coordinates.php';

$conn = getConnection();

/* =========================================
   CHECK SESSION
========================================= */
$user_id = $_SESSION['user']['id'] ?? null;

if (!$user_id) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized"
    ]);
    exit;
}

/* =========================================
   INPUT JSON
========================================= */
$data = json_decode(file_get_contents("php://input"), true);

if (!$data) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Invalid JSON"
    ]);
    exit;
}

/* =========================================
   REQUIRED ID
========================================= */
$id = $data["id"] ?? null;

if (!$id) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Report ID required"
    ]);
    exit;
}

/* =========================================
   GET OWN REPORT
========================================= */
$stmt = $conn->prepare("
    SELECT * FROM outage_reports
    WHERE id = :id AND user_id = :user_id
    LIMIT 1
");

$stmt->execute([
    ":id" => $id,
    ":user_id" => $user_id
]);

$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    http_response_code(403);
    echo json_encode([
        "success" => false,
        "message" => "Report not found or not yours"
    ]);
    exit;
}

/* =========================================
   FIELDS
========================================= */
$location_name   = trim($data["location_name"] ?? $report["location_name"]);
$description     = trim($data["description"] ?? $report["description"]);
$category        = $data["category"] ?? $report["category"];
$severity        = $data["severity"] ?? $report["severity"];
$affected_houses = $data["affected_houses"] ?? $report["affected_houses"];
$status          = $data["status"] ?? $report["status"];

$is_active       = $data["is_active"] ?? $report["is_active"];
$hazard_type     = $data["hazard_type"] ?? $report["hazard_type"];
$started_at      = $data["started_at"] ?? $report["started_at"];

/* =========================================
   GEO UPDATE (ONLY IF LOCATION CHANGED)
========================================= */
if ($location_name !== $report["location_name"]) {

    $geo = getCoordinates($location_name);

    if (!$geo["success"]) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => $geo["message"]
        ]);
        exit;
    }

    $latitude  = $geo["latitude"];
    $longitude = $geo["longitude"];

} else {

    $latitude  = $report["latitude"];
    $longitude = $report["longitude"];
}

/* =========================================
   🔒 DAGUPAN VALIDATION (REQUIRED)
========================================= */

/* Haversine */
function haversineDistance($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371000;

    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);

    $a = sin($dLat/2) * sin($dLat/2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon/2) * sin($dLon/2);

    $c = 2 * atan2(sqrt($a), sqrt(1-$a));

    return $earthRadius * $c;
}

/* Barangays */
$barangays = [
    ["name"=>"Bonuan Gueset","lat"=>16.0585,"lng"=>120.3345,"radius"=>2500],
    ["name"=>"Bonuan Boquig","lat"=>16.0600,"lng"=>120.3200,"radius"=>2000],
    ["name"=>"Bonuan Binloc","lat"=>16.0620,"lng"=>120.3100,"radius"=>2000],
    ["name"=>"Lucao","lat"=>16.0435,"lng"=>120.3310,"radius"=>1800],
    ["name"=>"Tapuac","lat"=>16.0460,"lng"=>120.3450,"radius"=>1800],
    ["name"=>"Tambac","lat"=>16.0520,"lng"=>120.3400,"radius"=>1500],
    ["name"=>"Pantal","lat"=>16.0468,"lng"=>120.3330,"radius"=>1500],
];

/* Get barangay */
function getBarangay($lat, $lng, $barangays) {

    foreach ($barangays as $b) {
        $distance = haversineDistance($lat, $lng, $b["lat"], $b["lng"]);

        if ($distance <= $b["radius"]) {
            return $b["name"];
        }
    }

    return false;
}

$barangay = getBarangay($latitude, $longitude, $barangays);

/* ❌ BLOCK IF OUTSIDE DAGUPAN */
if (!$barangay) {
    http_response_code(403);
    echo json_encode([
        "success" => false,
        "message" => "Location is outside Dagupan City coverage"
    ]);
    exit;
}

/* =========================================
   UPDATE QUERY
========================================= */
$stmt = $conn->prepare("
    UPDATE outage_reports SET
        location_name = :location_name,
        latitude = :latitude,
        longitude = :longitude,
        category = :category,
        severity = :severity,
        description = :description,
        affected_houses = :affected_houses,
        is_active = :is_active,
        hazard_type = :hazard_type,
        started_at = :started_at,
        status = :status
    WHERE id = :id AND user_id = :user_id
");

$success = $stmt->execute([
    ":id" => $id,
    ":user_id" => $user_id,
    ":location_name" => $location_name,
    ":latitude" => $latitude,
    ":longitude" => $longitude,
    ":category" => $category,
    ":severity" => $severity,
    ":description" => $description,
    ":affected_houses" => $affected_houses,
    ":is_active" => $is_active,
    ":hazard_type" => $hazard_type,
    ":started_at" => $started_at,
    ":status" => $status
]);

echo json_encode([
    "success" => $success,
    "message" => $success ? "Report updated successfully" : "Update failed",
    "barangay" => $barangay
]);