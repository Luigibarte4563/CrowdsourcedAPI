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
$user_id       = $data["user_id"] ?? null;
$location_name = trim($data["location_name"] ?? "");
$description   = trim($data["description"] ?? "");

/* =========================================
   VALIDATION
========================================= */
if (!$user_id || $location_name === "" || $description === "") {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "user_id, location_name, and description are required"
    ]);
    exit;
}

/* =========================================
   GET COORDINATES (SAFE)
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
   OPTIONAL INPUTS (NOW MATCH DB EXACTLY)
========================================= */
$category        = $data["category"] ?? "power_outage";
$severity        = $data["severity"] ?? "moderate";
$image_proof     = $data["image_proof"] ?? null;
$affected_houses = $data["affected_houses"] ?? 1;
$is_active       = $data["is_active"] ?? "yes";
$hazard_type     = $data["hazard_type"] ?? "none";
$started_at      = $data["started_at"] ?? null;
$status          = $data["status"] ?? "unverified";
$verified_by     = $data["verified_by"] ?? null;

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
            affected_houses,
            is_active,
            hazard_type,
            started_at,
            status,
            verified_by
        ) VALUES (
            :user_id,
            :location_name,
            :latitude,
            :longitude,
            :category,
            :severity,
            :description,
            :image_proof,
            :affected_houses,
            :is_active,
            :hazard_type,
            :started_at,
            :status,
            :verified_by
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
        ":affected_houses" => $affected_houses,
        ":is_active" => $is_active,
        ":hazard_type" => $hazard_type,
        ":started_at" => $started_at,
        ":status" => $status,
        ":verified_by" => $verified_by
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Outage report created successfully"
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database error",
        "error" => $e->getMessage() // remove in production
    ]);
}