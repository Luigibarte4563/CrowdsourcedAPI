<?php

header("Content-Type: application/json");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../services/get_coordinates.php';
require_once __DIR__ . '/../services/lookup.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* JWT AUTH */
$user = getUserFromJWT();
if (!$user || !isset($user['id'])) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Unauthorized (invalid JWT)"]);
    exit;
}
$user_id = (int)$user['id'];

/* INPUT */
$data = json_decode(file_get_contents("php://input"), true);
if (!$data || json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid JSON"]);
    exit;
}

$id = (int)($data["id"] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "ID required"]);
    exit;
}

/* Existing (ownership check) */
$stmt = $conn->prepare("
    SELECT
        ps.id,
        ps.station_name,
        ps.location_name,
        ps.latitude,
        ps.longitude,
        ps.station_type_id,
        ps.access_type,
        ps.availability_status,
        ps.operating_hours,
        ps.charging_type,
        ps.description,
        pst.type_name AS station_type
    FROM power_stations ps
    JOIN power_station_types pst ON pst.id = ps.station_type_id
    WHERE ps.id = :id AND ps.created_by = :user_id
    LIMIT 1
");
$stmt->execute([":id" => $id, ":user_id" => $user_id]);
$station = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$station) {
    http_response_code(404);
    echo json_encode(["success" => false, "message" => "Not found or unauthorized"]);
    exit;
}

/* ENUM VALIDATION */
$valid_station_types = ["power_station", "solar_station", "charging_station", "generator_station"];
$valid_access_types = ["free", "paid"];
$valid_status = ["available", "busy", "offline", "maintenance"];

$station_type = $data["station_type"] ?? $station["station_type"];
$access_type = $data["access_type"] ?? $station["access_type"];
$availability_status = $data["availability_status"] ?? $station["availability_status"];

if (!in_array($station_type, $valid_station_types)) {
    $station_type = $station["station_type"];
}
$station_type_id = getStationTypeId($conn, $station_type);

if (!in_array($access_type, $valid_access_types)) {
    $access_type = $station["access_type"];
}
if (!in_array($availability_status, $valid_status)) {
    $availability_status = $station["availability_status"];
}

/* INPUT FIELDS */
$station_name = $data["station_name"] ?? $station["station_name"];
$location_name = $data["location_name"] ?? $station["location_name"];
$operating_hours = $data["operating_hours"] ?? $station["operating_hours"];
$charging_type = $data["charging_type"] ?? $station["charging_type"];
$description = $data["description"] ?? $station["description"];

/* COORDINATES */
$latitude = $data["latitude"] ?? null;
$longitude = $data["longitude"] ?? null;

if ($latitude === null || $longitude === null) {
    if ($location_name !== $station["location_name"]) {
        $geo = getCoordinates($location_name);
        if (!$geo["success"]) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => $geo["message"]]);
            exit;
        }
        $latitude = $geo["latitude"];
        $longitude = $geo["longitude"];
    } else {
        $latitude = $station["latitude"];
        $longitude = $station["longitude"];
    }
}

if (!is_numeric($latitude) || !is_numeric($longitude)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid coordinates"]);
    exit;
}

$latitude = (float)$latitude;
$longitude = (float)$longitude;

try {
    $barangay_id = null;
    if (!empty($data["barangay_name"])) {
        $barangay_id = resolveBarangay($conn, $data["barangay_name"]);
    }

    $stmt = $conn->prepare("
        UPDATE power_stations SET
            station_name = :station_name,
            location_name = :location_name,
            latitude = :latitude,
            longitude = :longitude,
            station_type_id = :station_type_id,
            access_type = :access_type,
            availability_status = :availability_status,
            operating_hours = :operating_hours,
            charging_type = :charging_type,
            description = :description,
            barangay_id = :barangay_id
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
        ":station_type_id" => $station_type_id,
        ":access_type" => $access_type,
        ":availability_status" => $availability_status,
        ":operating_hours" => $operating_hours,
        ":charging_type" => $charging_type,
        ":description" => $description,
        ":barangay_id" => $barangay_id
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Updated successfully",
        "data" => ["id" => $id, "latitude" => $latitude, "longitude" => $longitude]
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database error"]);
}
