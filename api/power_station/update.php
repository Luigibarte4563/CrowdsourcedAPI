<?php

header("Content-Type: application/json");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../services/get_coordinates.php';

$conn = getConnection();

/* =========================================
   JWT AUTH
========================================= */
$user = getUserFromJWT();

if (!$user || !isset($user['id'])) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized (invalid JWT)"
    ]);
    exit;
}

$user_id = (int)$user['id'];

/* =========================================
   INPUT
========================================= */
$data = json_decode(file_get_contents("php://input"), true);

if (!$data || json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Invalid JSON"
    ]);
    exit;
}

$id = (int)($data["id"] ?? 0);

if ($id <= 0) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "ID required"
    ]);
    exit;
}

/* =========================================
   GET EXISTING (SECURITY CHECK)
========================================= */
$stmt = $conn->prepare("
    SELECT 
        id,
        station_name,
        location_name,
        latitude,
        longitude,
        station_type,
        access_type,
        availability_status,
        operating_hours,
        charging_type,
        description
    FROM power_stations
    WHERE id = :id AND created_by = :user_id
    LIMIT 1
");

$stmt->execute([
    ":id" => $id,
    ":user_id" => $user_id
]);

$station = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$station) {
    http_response_code(404);
    echo json_encode([
        "success" => false,
        "message" => "Not found or unauthorized"
    ]);
    exit;
}

/* =========================================
   ENUM VALIDATION (IMPORTANT FIX)
========================================= */
$valid_station_types = ["power_station", "solar_station", "charging_station", "generator_station"];
$valid_access_types = ["free", "paid"];
$valid_status = ["available", "busy", "offline", "maintenance"];

$station_type = $data["station_type"] ?? $station["station_type"];
$access_type = $data["access_type"] ?? $station["access_type"];
$availability_status = $data["availability_status"] ?? $station["availability_status"];

if (!in_array($station_type, $valid_station_types)) {
    $station_type = $station["station_type"];
}

if (!in_array($access_type, $valid_access_types)) {
    $access_type = $station["access_type"];
}

if (!in_array($availability_status, $valid_status)) {
    $availability_status = $station["availability_status"];
}

/* =========================================
   INPUT FIELDS
========================================= */
$station_name = $data["station_name"] ?? $station["station_name"];
$location_name = $data["location_name"] ?? $station["location_name"];

$operating_hours = $data["operating_hours"] ?? $station["operating_hours"];
$charging_type   = $data["charging_type"] ?? $station["charging_type"];
$description     = $data["description"] ?? $station["description"];

/* =========================================
   COORDINATES
========================================= */
$latitude = $data["latitude"] ?? null;
$longitude = $data["longitude"] ?? null;

/* GEOCODE IF LOCATION CHANGED */
if ($latitude === null || $longitude === null) {

    if ($location_name !== $station["location_name"]) {

        $geo = getCoordinates($location_name);

        if (!$geo["success"]) {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => $geo["message"]
            ]);
            exit;
        }

        $latitude = $geo["latitude"];
        $longitude = $geo["longitude"];

    } else {
        $latitude = $station["latitude"];
        $longitude = $station["longitude"];
    }
}

/* STRICT VALIDATION */
if (!is_numeric($latitude) || !is_numeric($longitude)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Invalid coordinates"
    ]);
    exit;
}

$latitude = (float)$latitude;
$longitude = (float)$longitude;

/* =========================================
   UPDATE QUERY
========================================= */
try {

    $stmt = $conn->prepare("
        UPDATE power_stations SET
            station_name = :station_name,
            location_name = :location_name,
            latitude = :latitude,
            longitude = :longitude,
            station_type = :station_type,
            access_type = :access_type,
            availability_status = :availability_status,
            operating_hours = :operating_hours,
            charging_type = :charging_type,
            description = :description
        WHERE id = :id
        AND created_by = :user_id
    ");

    $stmt->execute([
        ":id" => $id,
        ":user_id" => $user_id,
        ":station_name" => $station_name,
        ":location_name" => $location_name,
        ":latitude" => $latitude,
        ":longitude" => $longitude,
        ":station_type" => $station_type,
        ":access_type" => $access_type,
        ":availability_status" => $availability_status,
        ":operating_hours" => $operating_hours,
        ":charging_type" => $charging_type,
        ":description" => $description
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Updated successfully",
        "data" => [
            "id" => $id,
            "latitude" => $latitude,
            "longitude" => $longitude
        ]
    ]);

} catch (PDOException $e) {

    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error"
    ]);
}