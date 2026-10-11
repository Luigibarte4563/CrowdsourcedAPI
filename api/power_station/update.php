<?php

header("Content-Type: application/json");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
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

/* =========================================
   COORDINATES
   A supplied barangay supplies the point (its stored centroid); an explicit pin
   refines it. With neither, the stored point is kept - this is a partial
   update, and re-resolving an address nobody touched is not the client's call.
   ========================================= */
$barangay_id = $station["barangay_id"];
$latitude  = $station["latitude"];
$longitude = $station["longitude"];

if (!empty($data["barangay_name"])) {
    $requestedBarangay = trim($data["barangay_name"]);
    $barangay = resolveBarangayCoords($conn, $requestedBarangay);

    if (!$barangay) {
        $existsStmt = $conn->prepare("SELECT id FROM barangays WHERE barangay_name = ? LIMIT 1");
        $existsStmt->execute([$requestedBarangay]);
        $exists = (bool)$existsStmt->fetchColumn();

        http_response_code($exists ? 503 : 400);
        echo json_encode([
            "success" => false,
            "message" => $exists
                ? "We do not have map coordinates for $requestedBarangay yet."
                : "Unknown barangay: $requestedBarangay",
            "field" => "barangayName",
            "barangays_without_coordinates" => barangaysMissingCoordinates($conn)
        ]);
        exit;
    }

    $barangay_id = $barangay["id"];
    $latitude  = $barangay["latitude"];
    $longitude = $barangay["longitude"];
}

if (
    isset($data["latitude"], $data["longitude"])
    && is_numeric($data["latitude"]) && is_numeric($data["longitude"])
) {
    $pinLat = (float)$data["latitude"];
    $pinLng = (float)$data["longitude"];

    if (!is_finite($pinLat) || !is_finite($pinLng)
        || $pinLat < -90 || $pinLat > 90 || $pinLng < -180 || $pinLng > 180) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Invalid coordinates"]);
        exit;
    }

    $latitude  = $pinLat;
    $longitude = $pinLng;
}

$latitude = (float)$latitude;
$longitude = (float)$longitude;

try {

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
