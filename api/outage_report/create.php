<?php

header("Content-Type: application/json");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../services/get_coordinates.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';

$conn = getConnection();

/* =========================================
   JWT AUTH (REPLACED SESSION)
========================================= */
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

/* =========================================
   INPUT JSON
========================================= */
$data = json_decode(file_get_contents("php://input"), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Invalid JSON body"
    ]);
    exit;
}

/* =========================================
   REQUIRED INPUTS
========================================= */
$location_name = trim($data["location_name"] ?? "");
$description   = trim($data["description"] ?? "");

if ($location_name === "" || $description === "") {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "location_name and description are required"
    ]);
    exit;
}

/* =========================================
   GET COORDINATES
========================================= */
$geo = getCoordinates($location_name);

if (!$geo["success"]) {
    http_response_code(404);
    echo json_encode([
        "success" => false,
        "message" => $geo["message"]
    ]);
    exit;
}

$latitude  = $geo["latitude"];
$longitude = $geo["longitude"];

/* =========================================
   DISTANCE FUNCTION
========================================= */
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

/* =========================================
   BARANGAY DATA (UNCHANGED)
========================================= */
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

/* =========================================
   CHECK LOCATION
========================================= */
function isInsideDagupanBarangay($lat, $lng, $barangays) {

    foreach ($barangays as $b) {

        $distance = haversineDistance($lat, $lng, $b["lat"], $b["lng"]);

        if ($distance <= $b["radius"]) {
            return $b["name"];
        }
    }

    return false;
}

$matched_barangay = isInsideDagupanBarangay($latitude, $longitude, $barangays);

if (!$matched_barangay) {
    http_response_code(403);
    echo json_encode([
        "success" => false,
        "message" => "Location is outside Dagupan coverage"
    ]);
    exit;
}

/* =========================================
   OPTIONAL FIELDS
========================================= */
$category        = $data["category"] ?? "power_outage";
$severity        = $data["severity"] ?? "moderate";
$image_proof     = $data["image_proof"] ?? null;
$affected_houses = $data["affected_houses"] ?? 1;
$is_active       = $data["is_active"] ?? "yes";
$hazard_type     = $data["hazard_type"] ?? "none";
$started_at      = $data["started_at"] ?? null;
$status          = "unverified";
$verified_by     = null;

/* =========================================
   INSERT
========================================= */
try {

    $stmt = $conn->prepare("
        INSERT INTO outage_reports (
            user_id,
            location_name,
            latitude,
            longitude,
            category,
            severity,
            description,
            image_proof,
            affected_houses,
            is_active,
            hazard_type,
            started_at,
            status,
            verified_by
        ) VALUES (
            :user_id,
            :location_name,
            :latitude,
            :longitude,
            :category,
            :severity,
            :description,
            :image_proof,
            :affected_houses,
            :is_active,
            :hazard_type,
            :started_at,
            :status,
            :verified_by
        )
    ");

    $stmt->execute([
        ":user_id" => $user_id,
        ":location_name" => $location_name,
        ":latitude" => $latitude,
        ":longitude" => $longitude,
        ":category" => $category,
        ":severity" => $severity,
        ":description" => $description,
        ":image_proof" => $image_proof,
        ":affected_houses" => $affected_houses,
        ":is_active" => $is_active,
        ":hazard_type" => $hazard_type,
        ":started_at" => $started_at,
        ":status" => $status,
        ":verified_by" => $verified_by
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Outage report created successfully",
        "barangay" => $matched_barangay
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database error"
    ]);
}