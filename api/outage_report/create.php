<?php

header("Content-Type: application/json");

require_once "../../config/db_connect.php";

$conn = getConnection();
session_start();

/* =========================================
   ONLY JSON INPUT
========================================= */
$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

/* =========================================
   VALID JSON CHECK
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
   USER AUTH (SESSION ONLY)
========================================= */
$user_id = $_SESSION['user_id'] ?? null;

if (!$user_id) {
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
$location_name = trim($data["location_name"] ?? "");
$description   = trim($data["description"] ?? "");

$latitude  = $data["latitude"] ?? null;
$longitude = $data["longitude"] ?? null;

$category  = $data["category"] ?? "power_outage";
$severity  = $data["severity"] ?? "moderate";
$image_proof = $data["image_proof"] ?? null;
$status    = $data["status"] ?? "unverified";

/* =========================================
   VALIDATION
========================================= */
if ($location_name === "" || $description === "") {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "location_name and description are required"
    ]);
    exit;
}

/* =========================================
   ENUM VALIDATION
========================================= */
$allowedCategory = [
    'power_outage',
    'low_voltage',
    'power_fluctuation',
    'transformer_explosion',
    'fallen_power_line',
    'electrical_fire',
    'scheduled_maintenance',
    'unknown_issue'
];

$allowedSeverity = ['minor', 'moderate', 'critical'];
$allowedStatus   = ['unverified', 'under_review', 'verified', 'resolved', 'fake_report'];

if (!in_array($category, $allowedCategory)) {
    $category = "power_outage";
}

if (!in_array($severity, $allowedSeverity)) {
    $severity = "moderate";
}

if (!in_array($status, $allowedStatus)) {
    $status = "unverified";
}

/* =========================================
   INSERT INTO DATABASE
========================================= */
try {

    $stmt = $conn->prepare("
        INSERT INTO outage_reports (
            user_id,
            location_name,
            latitude,
            longitude,
            category,
            severity,
            description,
            image_proof,
            status
        ) VALUES (
            :user_id,
            :location_name,
            :latitude,
            :longitude,
            :category,
            :severity,
            :description,
            :image_proof,
            :status
        )
    ");

    $stmt->execute([
        ":user_id" => $user_id,
        ":location_name" => $location_name,
        ":latitude" => $latitude,
        ":longitude" => $longitude,
        ":category" => $category,
        ":severity" => $severity,
        ":description" => $description,
        ":image_proof" => $image_proof,
        ":status" => $status
    ]);

    http_response_code(201);

    echo json_encode([
        "success" => true,
        "message" => "Report created successfully"
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database error"
    ]);
}