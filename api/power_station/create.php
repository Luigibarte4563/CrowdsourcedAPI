<?php

header("Content-Type: application/json");

require_once "../../config/db_connect.php";

$conn = getConnection();
session_start();

/* =========================================
   ONLY JSON INPUT (STRICT)
========================================= */
$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

/* =========================================
   CHECK JSON VALIDITY
========================================= */
if (!$data) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Invalid JSON body"
    ]);
    exit;
}

/* =========================================
   AUTH CHECK (SESSION)
========================================= */
$created_by = $_SESSION['user_id'] ?? null;

if (!$created_by) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized: Please login first"
    ]);
    exit;
}

/* =========================================
   INPUTS
========================================= */
$station_name  = trim($data["station_name"] ?? "");
$location_name = trim($data["location_name"] ?? "");

$latitude  = $data["latitude"] ?? null;
$longitude = $data["longitude"] ?? null;

$station_type = $data["station_type"] ?? "power_station";
$access_type  = $data["access_type"] ?? "free";
$availability_status = $data["availability_status"] ?? "available";

$operating_hours = $data["operating_hours"] ?? null;
$charging_type   = $data["charging_type"] ?? null;
$description     = $data["description"] ?? null;
$image           = $data["image"] ?? null;

/* =========================================
   VALIDATION
========================================= */
if ($station_name === "" || $location_name === "") {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "station_name and location_name are required"
    ]);
    exit;
}

/* =========================================
   ENUM VALIDATION
========================================= */
$allowedType = [
    'power_station',
    'solar_station',
    'charging_station',
    'generator_station'
];

$allowedAccess = ['free', 'paid'];

$allowedStatus = ['available', 'busy', 'offline', 'maintenance'];

if (!in_array($station_type, $allowedType)) {
    $station_type = "power_station";
}

if (!in_array($access_type, $allowedAccess)) {
    $access_type = "free";
}

if (!in_array($availability_status, $allowedStatus)) {
    $availability_status = "available";
}

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
        ":created_by" => $created_by,
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