<?php

header("Content-Type: application/json");
session_start();

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../services/get_coordinates.php';

$conn = getConnection();

$user_id = $_SESSION['user']['id'] ?? null;

if (!$user_id) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized"
    ]);
    exit;
}

/* ================= INPUT ================= */
$data = json_decode(file_get_contents("php://input"), true);

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

    $a = sin($dLat/2) ** 2 +
        cos(deg2rad($lat1)) *
        cos(deg2rad($lat2)) *
        sin($dLng/2) ** 2;

    return 2 * atan2(sqrt($a), sqrt(1-$a)) * $R;
}

$barangays = [
    ["name"=>"Lucao","lat"=>16.0435,"lng"=>120.3310,"radius"=>2000],
    ["name"=>"Tapuac","lat"=>16.0460,"lng"=>120.3450,"radius"=>2000],
    ["name"=>"Pantal","lat"=>16.0468,"lng"=>120.3330,"radius"=>2000],
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