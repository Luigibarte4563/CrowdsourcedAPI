<?php

header("Content-Type: application/json");

require_once "../config/db_connect.php";

$conn = getConnection();

session_start();

/* =========================================
   HYBRID INPUT (JSON + POST + GET)
========================================= */
$data = json_decode(file_get_contents("php://input"), true);

if (!$data) {
    $data = $_POST;
}

if (!$data) {
    $data = $_GET;
}

/* =========================================
   USER AUTH (SESSION PRIORITY)
========================================= */
$user_id = $_SESSION['user_id'] ?? ($data['user_id'] ?? null);

if (!$user_id) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized: Please login first"
    ]);
    exit;
}

/* =========================================
   REQUIRED FIELDS
========================================= */
$location_name = trim($data["location_name"] ?? "");
$description   = trim($data["description"] ?? "");

/* =========================================
   OPTIONAL FIELDS
========================================= */
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
        "message" => "Missing required fields"
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

    $sql = "
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
    ";

    $stmt = $conn->prepare($sql);

    $stmt->execute([
        ":user_id"       => $user_id,
        ":location_name" => $location_name,
        ":latitude"      => $latitude,
        ":longitude"     => $longitude,
        ":category"      => $category,
        ":severity"      => $severity,
        ":description"   => $description,
        ":image_proof"   => $image_proof,
        ":status"        => $status
    ]);

    http_response_code(201);

    echo json_encode([
        "success" => true,
        "message" => "Report created successfully",
        "data" => [
            "user_id" => $user_id,
            "location_name" => $location_name,
            "category" => $category,
            "severity" => $severity
        ]
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database error"
    ]);
}