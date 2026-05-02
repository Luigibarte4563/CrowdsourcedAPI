<?php

header("Content-Type: application/json");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../services/get_coordinates.php';

$conn = getConnection();

/* =========================================
   INPUT JSON
========================================= */
$data = json_decode(file_get_contents("php://input"), true);

if (!$data) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Invalid JSON body"
    ]);
    exit;
}

/* =========================================
   REQUIRED INPUTS
========================================= */
$user_id = $data["user_id"] ?? null;
$id      = $data["id"] ?? null; // IMPORTANT: this is your real primary key

if (!$user_id || !$id) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "user_id and id are required"
    ]);
    exit;
}

/* =========================================
   CHECK IF REPORT EXISTS + OWNERSHIP
========================================= */
$stmt = $conn->prepare("
    SELECT * FROM outage_reports 
    WHERE id = :id
    LIMIT 1
");

$stmt->execute([":id" => $id]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    http_response_code(404);
    echo json_encode([
        "success" => false,
        "message" => "Report not found"
    ]);
    exit;
}

if ($report["user_id"] != $user_id) {
    http_response_code(403);
    echo json_encode([
        "success" => false,
        "message" => "You are not allowed to update this report"
    ]);
    exit;
}

/* =========================================
   FIELDS (FALLBACK TO EXISTING VALUES)
========================================= */
$location_name   = trim($data["location_name"] ?? $report["location_name"]);
$description     = trim($data["description"] ?? $report["description"]);
$category        = $data["category"] ?? $report["category"];
$severity        = $data["severity"] ?? $report["severity"];
$image_proof     = $data["image_proof"] ?? $report["image_proof"];
$affected_houses = $data["affected_houses"] ?? $report["affected_houses"];
$is_active       = $data["is_active"] ?? $report["is_active"];
$hazard_type     = $data["hazard_type"] ?? $report["hazard_type"];
$started_at      = $data["started_at"] ?? $report["started_at"];
$status          = $data["status"] ?? $report["status"];
$verified_by     = $data["verified_by"] ?? $report["verified_by"];

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
   GET COORDINATES (if location changed OR always safe)
========================================= */
$geo = getCoordinates($location_name);

if (!$geo["success"]) {
    http_response_code(404);
    echo json_encode([
        "success" => false,
        "message" => $geo["message"]
    ]);
    exit;
}

$latitude  = $geo["latitude"];
$longitude = $geo["longitude"];

/* =========================================
   ENUM SAFETY
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
$allowedActive   = ['yes', 'no', 'unknown'];
$allowedHazard   = ['none', 'smoke', 'sparks', 'fire', 'fallen_wire', 'explosion_sound'];
$allowedStatus   = ['unverified', 'under_review', 'verified', 'resolved', 'fake_report'];

if (!in_array($category, $allowedCategory)) $category = "power_outage";
if (!in_array($severity, $allowedSeverity)) $severity = "moderate";
if (!in_array($is_active, $allowedActive)) $is_active = "yes";
if (!in_array($hazard_type, $allowedHazard)) $hazard_type = "none";
if (!in_array($status, $allowedStatus)) $status = "unverified";

/* =========================================
   UPDATE QUERY
========================================= */
try {

    $stmt = $conn->prepare("
        UPDATE outage_reports SET
            location_name = :location_name,
            latitude = :latitude,
            longitude = :longitude,
            category = :category,
            severity = :severity,
            description = :description,
            image_proof = :image_proof,
            affected_houses = :affected_houses,
            is_active = :is_active,
            hazard_type = :hazard_type,
            started_at = :started_at,
            status = :status,
            verified_by = :verified_by
        WHERE id = :id AND user_id = :user_id
    ");

    $stmt->execute([
        ":id" => $id,
        ":user_id" => $user_id,
        ":location_name" => $location_name,
        ":latitude" => $latitude,
        ":longitude" => $longitude,
        ":category" => $category,
        ":severity" => $severity,
        ":description" => $description,
        ":image_proof" => $image_proof,
        ":affected_houses" => $affected_houses,
        ":is_active" => $is_active,
        ":hazard_type" => $hazard_type,
        ":started_at" => $started_at,
        ":status" => $status,
        ":verified_by" => $verified_by
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Report updated successfully"
    ]);

} catch (PDOException $e) {

    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error"
    ]);
}