<?php

header("Content-Type: application/json; charset=UTF-8");

// prevent ANY HTML/PHP warning breaking JSON
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../services/get_coordinates.php';

$conn = getConnection();

/* ================================
   JWT AUTH
================================ */
$user = getUserFromJWT();

if (!$user) {
    http_response_code(401);

    echo json_encode([
        "success" => false,
        "message" => "Unauthorized (invalid JWT)"
    ]);

    exit;
}

$user_id = $user['id'] ?? null;

if (!$user_id) {
    http_response_code(401);

    echo json_encode([
        "success" => false,
        "message" => "Invalid user token"
    ]);

    exit;
}

/* ================================
   READ RAW INPUT
================================ */
$rawInput = file_get_contents("php://input");

$data = json_decode($rawInput, true);

if (json_last_error() !== JSON_ERROR_NONE) {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Invalid JSON format",
        "error" => json_last_error_msg()
    ]);

    exit;
}

/* ================================
   VALIDATE ID
================================ */
$id = $data["id"] ?? null;

if (!$id) {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Report ID required"
    ]);

    exit;
}

/* ================================
   GET REPORT (OWNERSHIP CHECK)
================================ */
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

/* ================================
   SAFE UPDATE FIELDS
================================ */
$location_name   = trim($data["location_name"] ?? $report["location_name"]);
$description     = trim($data["description"] ?? $report["description"]);
$category        = $data["category"] ?? $report["category"];
$severity        = $data["severity"] ?? $report["severity"];
$affected_houses = (int)($data["affected_houses"] ?? $report["affected_houses"]);
$hazard_type     = $data["hazard_type"] ?? $report["hazard_type"];
$started_at      = $data["started_at"] ?? $report["started_at"];

/* =========================================
   PROTECT SYSTEM FIELDS
========================================= */

/*
   Users CANNOT update:
   - status
   - is_active
*/

$status    = $report["status"];
$is_active = $report["is_active"];

/* ================================
   GEO UPDATE
================================ */
$latitude  = $report["latitude"];
$longitude = $report["longitude"];

if (
    !empty($data["location_name"]) &&
    $data["location_name"] !== $report["location_name"]
) {

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
}

/* ================================
   BARANGAY CHECK
================================ */
$barangays = [
    ["name"=>"Bonuan Gueset","lat"=>16.0585,"lng"=>120.3345,"radius"=>2500],
    ["name"=>"Bonuan Boquig","lat"=>16.0600,"lng"=>120.3200,"radius"=>2000],
    ["name"=>"Bonuan Binloc","lat"=>16.0620,"lng"=>120.3100,"radius"=>2000],
    ["name"=>"Lucao","lat"=>16.0435,"lng"=>120.3310,"radius"=>1800],
    ["name"=>"Tapuac","lat"=>16.0460,"lng"=>120.3450,"radius"=>1800],
    ["name"=>"Tambac","lat"=>16.0520,"lng"=>120.3400,"radius"=>1500],
    ["name"=>"Pantal","lat"=>16.0468,"lng"=>120.3330,"radius"=>1500],
    ["name"=>"Herrero-Perez","lat"=>16.0455,"lng"=>120.3380,"radius"=>1500],
    ["name"=>"Mayombo","lat"=>16.0480,"lng"=>120.3100,"radius"=>1500],
    ["name"=>"Poblacion Oeste","lat"=>16.0420,"lng"=>120.3355,"radius"=>1200],
    ["name"=>"Poblacion Este","lat"=>16.0425,"lng"=>120.3385,"radius"=>1200]
];

function haversine($lat1, $lon1, $lat2, $lon2) {

    $R = 6371000;

    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);

    $a = sin($dLat / 2) ** 2 +
         cos(deg2rad($lat1)) *
         cos(deg2rad($lat2)) *
         sin($dLon / 2) ** 2;

    return 2 * $R * atan2(sqrt($a), sqrt(1 - $a));
}

$barangay = null;

foreach ($barangays as $b) {

    if (
        haversine(
            $latitude,
            $longitude,
            $b["lat"],
            $b["lng"]
        ) <= $b["radius"]
    ) {

        $barangay = $b["name"];
        break;
    }
}

if (!$barangay) {

    http_response_code(403);

    echo json_encode([
        "success" => false,
        "message" => "Outside coverage area"
    ]);

    exit;
}

/* ================================
   UPDATE QUERY
================================ */
$stmt = $conn->prepare("
    UPDATE outage_reports SET
        location_name = :location_name,
        latitude = :latitude,
        longitude = :longitude,
        category = :category,
        severity = :severity,
        description = :description,
        affected_houses = :affected_houses,
        hazard_type = :hazard_type,
        started_at = :started_at
    WHERE id = :id
    AND user_id = :user_id
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
    ":hazard_type" => $hazard_type,
    ":started_at" => $started_at
]);

echo json_encode([
    "success" => $success,
    "message" => $success
        ? "Updated successfully"
        : "Update failed",
    "barangay" => $barangay
]);