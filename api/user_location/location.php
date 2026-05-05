<?php

header("Content-Type: application/json");

session_start();

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../services/get_coordinates.php';

$conn = getConnection();

/* =========================================
   GET USER FROM SESSION
========================================= */
$user_id = $_SESSION['user']['id'] ?? null;

if (!$user_id) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized (no session)"
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
        "message" => "Invalid JSON body"
    ]);
    exit;
}

/* =========================================
   REQUIRED INPUT
========================================= */
$location_name = trim($data["location_name"] ?? "");

if ($location_name === "") {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "location_name is required"
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
   DAGUPAN VALIDATION (OPTIONAL SAFETY)
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

$barangays = [
    ["name"=>"Bonuan Gueset","lat"=>16.0585,"lng"=>120.3345,"radius"=>2500],
    ["name"=>"Bonuan Boquig","lat"=>16.0600,"lng"=>120.3200,"radius"=>2000],
    ["name"=>"Bonuan Binloc","lat"=>16.0620,"lng"=>120.3100,"radius"=>2000],
    ["name"=>"Lucao","lat"=>16.0435,"lng"=>120.3310,"radius"=>1800],
    ["name"=>"Tapuac","lat"=>16.0460,"lng"=>120.3450,"radius"=>1800],
    ["name"=>"Tambac","lat"=>16.0520,"lng"=>120.3400,"radius"=>1500],
    ["name"=>"Pantal","lat"=>16.0468,"lng"=>120.3330,"radius"=>1500],
];

function getBarangay($lat, $lng, $barangays) {
    foreach ($barangays as $b) {
        $distance = haversineDistance($lat, $lng, $b["lat"], $b["lng"]);
        if ($distance <= $b["radius"]) {
            return $b["name"];
        }
    }
    return null;
}

$barangay = getBarangay($latitude, $longitude, $barangays);

/* =========================================
   SAVE USER LOCATION
========================================= */
try {

    $stmt = $conn->prepare("
        UPDATE users
        SET location_name = :location_name,
            updated_at = NOW()
        WHERE id = :user_id
    ");

    $stmt->execute([
        ":location_name" => $location_name,
        ":user_id" => $user_id
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Location updated successfully",
        "data" => [
            "location_name" => $location_name,
            "latitude" => $latitude,
            "longitude" => $longitude,
            "barangay" => $barangay
        ]
    ]);

} catch (PDOException $e) {

    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error"
    ]);
}