<?php

header("Content-Type: application/json");

require_once "../../config/db_connect.php";

$conn = getConnection();
session_start();

$data = json_decode(file_get_contents("php://input"), true);

$id = $data['id'] ?? null;

if (!$id) {
    echo json_encode([
        "success" => false,
        "message" => "Station ID required"
    ]);
    exit;
}

/* =========================
   FIELDS
========================= */
$station_name = $data['station_name'] ?? null;
$location_name = $data['location_name'] ?? null;
$latitude = $data['latitude'] ?? null;
$longitude = $data['longitude'] ?? null;
$station_type = $data['station_type'] ?? null;
$access_type = $data['access_type'] ?? null;
$availability_status = $data['availability_status'] ?? null;
$operating_hours = $data['operating_hours'] ?? null;
$charging_type = $data['charging_type'] ?? null;
$description = $data['description'] ?? null;

/* =========================
   BUILD UPDATE
========================= */
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
            description = :description,
            updated_at = NOW()
        WHERE id = :id
    ");

    $stmt->execute([
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
        ":id" => $id
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Station updated successfully"
    ]);

} catch (PDOException $e) {

    echo json_encode([
        "success" => false,
        "message" => "Database error"
    ]);
}