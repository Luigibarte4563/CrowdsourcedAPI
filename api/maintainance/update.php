<?php

header("Content-Type: application/json");
session_start();

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../services/get_coordinates.php';

$conn = getConnection();

$electric_company_id = $_SESSION['user']['electric_company_id'] ?? null;

if (!$electric_company_id) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

$id = $data["id"] ?? null;

if (!$id) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Maintenance ID required"]);
    exit;
}

/* OPTIONAL FIELDS */
$affected_area = $data["affected_area"] ?? null;
$maintenance_date = $data["maintenance_date"] ?? null;
$start_time = $data["start_time"] ?? null;
$end_time = $data["end_time"] ?? null;
$description = $data["description"] ?? null;
$radius = $data["radius"] ?? null;

/* =========================================
   OPTIONAL GEOCODING IF AREA CHANGED
========================================= */
$latitude = null;
$longitude = null;

if ($affected_area) {

    $geo = getCoordinates($affected_area);

    if ($geo["success"]) {
        $latitude = $geo["latitude"];
        $longitude = $geo["longitude"];
    }
}

try {

    $stmt = $conn->prepare("
        UPDATE maintenance_schedules
        SET 
            affected_area = COALESCE(:affected_area, affected_area),
            maintenance_date = COALESCE(:maintenance_date, maintenance_date),
            start_time = COALESCE(:start_time, start_time),
            end_time = COALESCE(:end_time, end_time),
            description = COALESCE(:description, description),
            radius = COALESCE(:radius, radius),
            latitude = COALESCE(:latitude, latitude),
            longitude = COALESCE(:longitude, longitude)
        WHERE id = :id
        AND electric_company_id = :electric_company_id
    ");

    $stmt->execute([
        ":id" => $id,
        ":electric_company_id" => $electric_company_id,
        ":affected_area" => $affected_area,
        ":maintenance_date" => $maintenance_date,
        ":start_time" => $start_time,
        ":end_time" => $end_time,
        ":description" => $description,
        ":radius" => $radius,
        ":latitude" => $latitude,
        ":longitude" => $longitude
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Maintenance updated successfully"
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database error"
    ]);
}