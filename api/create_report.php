<?php

header("Content-Type: application/json");

include "../config/db_connect.php";

$conn = getConnection();

$data = json_decode(file_get_contents("php://input"), true);

if (!$data) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "No input data provided"
    ]);
    exit;
}

/* =========================
   REQUIRED FIELDS
========================= */
$user_id = $data["user_id"] ?? null;
$location_name = trim($data["location_name"] ?? "");
$description = trim($data["description"] ?? "");

/* =========================
   OPTIONAL FIELDS
========================= */
$latitude = $data["latitude"] ?? null;
$longitude = $data["longitude"] ?? null;

$category = $data["category"] ?? "power_outage";
$severity = $data["severity"] ?? "moderate";

$image_proof = $data["image_proof"] ?? null;

$status = $data["status"] ?? "unverified";

/* =========================
   VALIDATION
========================= */
if (!$user_id || !$location_name || !$description) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Missing required fields"
    ]);
    exit;
}

/* =========================
   VALID ENUM SAFETY (IMPORTANT)
========================= */

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
$allowedStatus = ['unverified', 'under_review', 'verified', 'resolved', 'fake_report'];

if (!in_array($category, $allowedCategory)) {
    $category = "power_outage";
}

if (!in_array($severity, $allowedSeverity)) {
    $severity = "moderate";
}

if (!in_array($status, $allowedStatus)) {
    $status = "unverified";
}

/* =========================
   INSERT DATA
========================= */
try {

    $stmt = $conn->prepare("
        INSERT INTO outage_reports
        (
            user_id,
            location_name,
            latitude,
            longitude,
            category,
            severity,
            description,
            image_proof,
            status
        )
        VALUES
        (
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
        "message" => "Failed to create report"
    ]);
}