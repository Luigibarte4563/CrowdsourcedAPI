<?php

header("Content-Type: application/json");

session_start();

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../services/get_coordinates.php';

$conn = getConnection();

/* =========================================
   AUTH CHECK
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

$station_name = trim($data["station_name"] ?? "");
$location_name = trim($data["location_name"] ?? "");

if ($station_name === "" || $location_name === "") {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "station_name and location_name required"
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
   OPTIONAL FIELDS
========================================= */
$station_type = $data["station_type"] ?? "power_station";
$access_type = $data["access_type"] ?? "free";
$availability_status = $data["availability_status"] ?? "available";
$operating_hours = $data["operating_hours"] ?? null;
$charging_type = $data["charging_type"] ?? null;
$description = $data["description"] ?? null;
$image = $data["image"] ?? null;

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
        "message" => "Power station created"
    ]);

} catch (PDOException $e) {

    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error"
    ]);
}