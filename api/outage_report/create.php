<?php

header("Content-Type: application/json");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../services/get_coordinates.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';

$conn = getConnection();

/* =========================================
   JWT AUTH
========================================= */
$user = getUserFromJWT();

if (!$user) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized"
    ]);
    exit;
}

$user_id = $user['id'];

/* =========================================
   SAFE INPUT PARSING (FIXED)
========================================= */
$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);

if (!is_array($data)) {
    $data = $_POST; // fallback for form-data
}

/* =========================================
   VALIDATION
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
   ANTI-SPAM CHECK
========================================= */
try {
    $check = $conn->prepare("
        SELECT id FROM outage_reports 
        WHERE user_id = ? 
        AND status IN ('active','under_review','verified')
        LIMIT 1
    ");
    $check->execute([$user_id]);

    if ($check->fetch()) {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "You already have an active report"
        ]);
        exit;
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Anti-spam check failed",
        "debug" => $e->getMessage()
    ]);
    exit;
}

/* =========================================
   GET COORDINATES (SAFE)
========================================= */
$geo = getCoordinates($location_name);

if (!$geo || !isset($geo["success"]) || !$geo["success"]) {
    http_response_code(404);
    echo json_encode([
        "success" => false,
        "message" => $geo["message"] ?? "Geolocation failed"
    ]);
    exit;
}

$latitude  = $geo["latitude"];
$longitude = $geo["longitude"];

/* =========================================
   DISTANCE FUNCTION (MOVED UP FIX)
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
   BARANGAYS
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


function findBarangay($lat, $lng, $barangays) {
    foreach ($barangays as $b) {
        $distance = haversineDistance($lat, $lng, $b["lat"], $b["lng"]);
        if ($distance <= $b["radius"]) return $b["name"];
    }
    return null;
}

$matched_barangay = findBarangay($latitude, $longitude, $barangays);

if (!$matched_barangay) {
    http_response_code(403);
    echo json_encode([
        "success" => false,
        "message" => "Outside coverage area"
    ]);
    exit;
}

/* =========================================
   OPTIONAL FIELDS
========================================= */
$category        = $data["category"] ?? "power_outage";
$severity        = $data["severity"] ?? "moderate";
$image_proof     = $data["image_proof"] ?? null;
$affected_houses = (int) ($data["affected_houses"] ?? 1);
$hazard_type     = $data["hazard_type"] ?? "none";
$started_at      = $data["started_at"] ?? null;

/* =========================================
   INSERT REPORT (FIXED SAFE EXECUTION)
========================================= */
try {

    $stmt = $conn->prepare("
        INSERT INTO outage_reports (
            user_id,
            report_key,
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
            status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, 'active')
    ");

    $stmt->execute([
        $user_id,
        uniqid("OR-"),
        $location_name,
        $latitude,
        $longitude,
        $category,
        $severity,
        $description,
        $image_proof,
        $affected_houses,
        $hazard_type,
        $started_at
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Report created",
        "barangay" => $matched_barangay
    ]);

} catch (PDOException $e) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database insert failed",
        "debug" => $e->getMessage()
    ]);
}