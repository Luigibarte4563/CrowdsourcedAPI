<?php

header("Content-Type: application/json");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* =========================================
   JWT AUTH (REPLACES SESSION)
========================================= */
$user = getUserFromJWT();

if (!$user) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized (invalid JWT)"
    ]);
    exit;
}

if (($user['role'] ?? null) !== 'electric_company') {
    http_response_code(403);
    echo json_encode([
        "success" => false,
        "message" => "Forbidden: insufficient permissions"
    ]);
    exit;
}

$electric_company_id = $user['electric_company_id'] ?? $user['id'] ?? null;

if (!$electric_company_id) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "message" => "Invalid token data"
    ]);
    exit;
}

/* =========================================
   INPUT
========================================= */
$data = json_decode(file_get_contents("php://input"), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Invalid JSON"
    ]);
    exit;
}

$maintenance_date = $data["maintenance_date"] ?? null;
$start_time = $data["start_time"] ?? null;
$end_time = $data["end_time"] ?? null;
$description = $data["description"] ?? "";
$barangays = $data["barangays"] ?? [];

/* =========================================
   VALIDATION
========================================= */
if (!$maintenance_date || !$start_time || !$end_time) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Missing required fields"
    ]);
    exit;
}

if (!is_array($barangays) || count($barangays) === 0) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "No barangays selected"
    ]);
    exit;
}

/* =========================================
   BARANGAY MAP
========================================= */
$barangay_map = [
    "Bonuan Gueset" => [16.0585, 120.3345],
    "Bonuan Boquig" => [16.0600, 120.3200],
    "Bonuan Binloc" => [16.0620, 120.3100],
    "Lucao" => [16.0435, 120.3310],
    "Tapuac" => [16.0460, 120.3450],
    "Tambac" => [16.0520, 120.3400],
    "Pantal" => [16.0468, 120.3330],
    "Bacayao Norte" => [16.0300, 120.3200],
    "Bacayao Sur" => [16.0250, 120.3250],
    "Malued" => [16.0400, 120.3200],
    "Mayombo" => [16.0480, 120.3100],
    "Mangin" => [16.0550, 120.3500],
    "Tebeng" => [16.0600, 120.3450],
    "Pogo Chico" => [16.0510, 120.3600],
    "Pogo Grande" => [16.0550, 120.3650],
    "Herrero" => [16.0450, 120.3350],
    "Poblacion Centro" => [16.0430, 120.3335],
    "Poblacion Oeste" => [16.0410, 120.3300],
    "Poblacion Este" => [16.0440, 120.3360]
];

$first = $barangays[0] ?? null;

if (!$first || !isset($barangay_map[$first])) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Invalid barangay"
    ]);
    exit;
}

[$latitude, $longitude] = $barangay_map[$first];

/* =========================================
   INSERT
========================================= */
try {

    $stmt = $conn->prepare("
        INSERT INTO maintenance_schedules (
            electric_company_id,
            affected_area,
            latitude,
            longitude,
            maintenance_date,
            start_time,
            end_time,
            description,
            affected_barangays
        )
        VALUES (
            :electric_company_id,
            :affected_area,
            :latitude,
            :longitude,
            :maintenance_date,
            :start_time,
            :end_time,
            :description,
            :affected_barangays
        )
    ");

    $stmt->execute([
        ":electric_company_id" => $electric_company_id,
        ":affected_area" => $first,
        ":latitude" => $latitude,
        ":longitude" => $longitude,
        ":maintenance_date" => $maintenance_date,
        ":start_time" => $start_time,
        ":end_time" => $end_time,
        ":description" => $description,
        ":affected_barangays" => json_encode($barangays)
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Maintenance created successfully"
    ]);

} catch (Exception $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Server error"
    ]);
}