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

if (!$data) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Invalid JSON"
    ]);
    exit;
}

/* REQUIRED FIELDS */
$station_name  = trim($data["station_name"] ?? "");
$location_name = trim($data["location_name"] ?? "");

if ($station_name === "" || $location_name === "") {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "station_name and location_name are required"
    ]);
    exit;
}

/* =========================================
   ENUM VALIDATION (BASED ON SCHEMA)
========================================= */
$valid_station_types = [
    "power_station",
    "solar_station",
    "charging_station",
    "generator_station"
];

$valid_access_types = ["free", "paid"];

$valid_availability = ["available", "busy", "offline", "maintenance"];

$station_type = $data["station_type"] ?? "power_station";
$access_type = $data["access_type"] ?? "free";
$availability_status = $data["availability_status"] ?? "available";

if (!in_array($station_type, $valid_station_types)) {
    $station_type = "power_station";
}

if (!in_array($access_type, $valid_access_types)) {
    $access_type = "free";
}

if (!in_array($availability_status, $valid_availability)) {
    $availability_status = "available";
}

/* OPTIONAL FIELDS */
$operating_hours = $data["operating_hours"] ?? null;
$charging_type   = $data["charging_type"] ?? null;
$description     = $data["description"] ?? null;
$image           = $data["image"] ?? null;

/* =========================================
   LIMIT: ONLY 1 POWER STATION PER USER
========================================= */
try {

    $check = $conn->prepare("
        SELECT id
        FROM power_stations
        WHERE created_by = :created_by
        LIMIT 1
    ");

    $check->execute([":created_by" => $user_id]);

    if ($check->fetch(PDO::FETCH_ASSOC)) {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "You already have a power station. Please update it instead."
        ]);
        exit;
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Failed to check station limit"
    ]);
    exit;
}

/* =========================================
   GEOCODING
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

$latitude = $geo["latitude"];
$longitude = $geo["longitude"];

/* =========================================
   INSERT
========================================= */
try {

    $stmt = $conn->prepare("
        INSERT INTO power_stations (
            created_by,
            station_name,
            location_name,
            latitude,
            longitude,
            station_type,
            access_type,
            availability_status,
            operating_hours,
            charging_type,
            description,
            image
        ) VALUES (
            :created_by,
            :station_name,
            :location_name,
            :latitude,
            :longitude,
            :station_type,
            :access_type,
            :availability_status,
            :operating_hours,
            :charging_type,
            :description,
            :image
        )
    ");

    $stmt->execute([
        ":created_by" => $user_id,
        ":station_name" => $station_name,
        ":location_name" => $location_name,
        ":latitude" => $latitude,
        ":longitude" => $longitude,
        ":station_type" => $station_type,
        ":access_type" => $access_type,
        ":availability_status" => $availability_status,
        ":operating_hours" => $operating_hours,
        ":charging_type" => $charging_type,
        ":description" => $description,
        ":image" => $image
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Power station created successfully"
    ]);

} catch (PDOException $e) {

    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error"
    ]);
}