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

$data = json_decode(file_get_contents("php://input"), true);

$id = $data["id"] ?? null;

if (!$id) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "ID required"
    ]);
    exit;
}

/* GET EXISTING */
$stmt = $conn->prepare("SELECT * FROM power_stations WHERE id = :id");
$stmt->execute([":id" => $id]);
$station = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$station) {
    echo json_encode([
        "success" => false,
        "message" => "Not found"
    ]);
    exit;
}

/* UPDATE FIELDS */
$station_name = $data["station_name"] ?? $station["station_name"];
$location_name = $data["location_name"] ?? $station["location_name"];

if ($location_name !== $station["location_name"]) {
    $geo = getCoordinates($location_name);
    $latitude = $geo["latitude"];
    $longitude = $geo["longitude"];
} else {
    $latitude = $station["latitude"];
    $longitude = $station["longitude"];
}

$station_type = $data["station_type"] ?? $station["station_type"];
$access_type = $data["access_type"] ?? $station["access_type"];
$availability_status = $data["availability_status"] ?? $station["availability_status"];
$operating_hours = $data["operating_hours"] ?? $station["operating_hours"];
$charging_type = $data["charging_type"] ?? $station["charging_type"];
$description = $data["description"] ?? $station["description"];

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
    ");

    $stmt->execute([
        ":id" => $id,
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
        "message" => "Updated successfully"
    ]);

} catch (PDOException $e) {

    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error"
    ]);
}