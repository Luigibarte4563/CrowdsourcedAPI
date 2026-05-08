<?php

header("Content-Type: application/json");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../services/get_coordinates.php';

$conn = getConnection();

/* =========================================
   JWT AUTH (REPLACES SESSION)
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
        "message" => "Invalid token user"
    ]);
    exit;
}

/* ================= INPUT ================= */
$data = json_decode(file_get_contents("php://input"), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Invalid JSON"
    ]);
    exit;
}

$location_name = trim($data["location_name"] ?? "");

if ($location_name === "") {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "location_name is required"
    ]);
    exit;
}

/* ================= GEOCODE ================= */
$geo = getCoordinates($location_name);

if (!$geo["success"]) {
    http_response_code(404);
    echo json_encode([
        "success" => false,
        "message" => "Location not found"
    ]);
    exit;
}

$latitude = $geo["latitude"];
$longitude = $geo["longitude"];

/* ================= OPTIONAL BARANGAY CHECK ================= */
function haversine($lat1, $lng1, $lat2, $lng2) {
    $R = 6371000;

    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);

    $a = sin($dLat / 2) ** 2 +
        cos(deg2rad($lat1)) *
        cos(deg2rad($lat2)) *
        sin($dLng / 2) ** 2;

    return 2 * atan2(sqrt($a), sqrt(1 - $a)) * $R;
}

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

$barangay = null;

foreach ($barangays as $b) {
    if (haversine($latitude, $longitude, $b["lat"], $b["lng"]) <= $b["radius"]) {
        $barangay = $b["name"];
        break;
    }
}

/* ================= UPDATE USER ================= */
try {

    $stmt = $conn->prepare("
        UPDATE users
        SET location_name = :location_name,
            latitude = :latitude,
            longitude = :longitude,
            updated_at = NOW()
        WHERE id = :user_id
    ");

    $stmt->execute([
        ":location_name" => $location_name,
        ":latitude" => $latitude,
        ":longitude" => $longitude,
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