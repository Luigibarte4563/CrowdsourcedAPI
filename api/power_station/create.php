<?php

header("Content-Type: application/json");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../services/lookup.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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

$station_type_id = getStationTypeId($conn, $station_type);

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
   BARANGAY -> COORDINATES
   The barangay is the coordinate authority. `location_name` is a label shown to
   other users and is never geocoded; an explicit pin refines the centroid.
   ========================================= */
$barangay_name = trim($data["barangay_name"] ?? "");

if ($barangay_name === "") {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "barangay_name is required",
        "field" => "barangayName"
    ]);
    exit;
}

$barangay = resolveBarangayCoords($conn, $barangay_name);

if (!$barangay) {
    $existsStmt = $conn->prepare("SELECT id FROM barangays WHERE barangay_name = ? LIMIT 1");
    $existsStmt->execute([$barangay_name]);
    $exists = (bool)$existsStmt->fetchColumn();

    http_response_code($exists ? 503 : 400);
    echo json_encode([
        "success" => false,
        "message" => $exists
            ? "We do not have map coordinates for $barangay_name yet, so this cannot be placed on the map. Please choose a nearby barangay."
            : "Unknown barangay: $barangay_name",
        "field" => "barangayName",
        "barangays_without_coordinates" => barangaysMissingCoordinates($conn)
    ]);
    exit;
}

$barangay_id = $barangay["id"];
$latitude  = $barangay["latitude"];
$longitude = $barangay["longitude"];

if (
    isset($data["latitude"], $data["longitude"])
    && is_numeric($data["latitude"]) && is_numeric($data["longitude"])
) {
    $pinLat = (float)$data["latitude"];
    $pinLng = (float)$data["longitude"];

    if (!is_finite($pinLat) || !is_finite($pinLng)
        || $pinLat < -90 || $pinLat > 90
        || $pinLng < -180 || $pinLng > 180) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Invalid coordinates"]);
        exit;
    }

    $latitude  = $pinLat;
    $longitude = $pinLng;
}

/* =========================================
   INSERT
========================================= */
try {

    $stmt = $conn->prepare("
        INSERT INTO power_stations (
            created_by,
            barangay_id,
            station_type_id,
            station_name,
            location_name,
            latitude,
            longitude,
            access_type,
            availability_status,
            operating_hours,
            charging_type,
            description,
            image
        ) VALUES (
            :created_by,
            :barangay_id,
            :station_type_id,
            :station_name,
            :location_name,
            :latitude,
            :longitude,
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
        ":barangay_id" => $barangay_id,
        ":station_type_id" => $station_type_id,
        ":station_name" => $station_name,
        ":location_name" => $location_name,
        ":latitude" => $latitude,
        ":longitude" => $longitude,
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
