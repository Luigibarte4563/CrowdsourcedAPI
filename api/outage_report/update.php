<?php

header("Content-Type: application/json");

session_start();

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../services/get_coordinates.php';

$conn = getConnection();

/* =========================================
   CHECK SESSION
========================================= */
$user_id = $_SESSION['user']['id'] ?? null;

if (!$user_id) {

    http_response_code(401);

    echo json_encode([
        "success" => false,
        "message" => "Unauthorized"
    ]);

    exit;
}

/* =========================================
   INPUT JSON
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

/* =========================================
   REQUIRED
========================================= */
$id = $data["id"] ?? null;

if (!$id) {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Report ID required"
    ]);

    exit;
}

/* =========================================
   GET OWN REPORT
========================================= */
$stmt = $conn->prepare("
    SELECT * FROM outage_reports
    WHERE id = :id AND user_id = :user_id
    LIMIT 1
");

$stmt->execute([
    ":id" => $id,
    ":user_id" => $user_id
]);

$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {

    http_response_code(403);

    echo json_encode([
        "success" => false,
        "message" => "Report not found or not yours"
    ]);

    exit;
}

/* =========================================
   FIELDS (NEW DATABASE READY)
========================================= */
$location_name   = trim($data["location_name"] ?? $report["location_name"]);
$description     = trim($data["description"] ?? $report["description"]);
$category        = $data["category"] ?? $report["category"];
$severity        = $data["severity"] ?? $report["severity"];
$affected_houses = $data["affected_houses"] ?? $report["affected_houses"];
$status          = $data["status"] ?? $report["status"];

$is_active       = $data["is_active"] ?? $report["is_active"];
$hazard_type     = $data["hazard_type"] ?? $report["hazard_type"];
$started_at      = $data["started_at"] ?? $report["started_at"];

/* =========================================
   COORDINATES (ONLY IF LOCATION CHANGED)
========================================= */
if ($location_name !== $report["location_name"]) {

    $geo = getCoordinates($location_name);

    if (!$geo["success"]) {

        http_response_code(400);

        echo json_encode([
            "success" => false,
            "message" => $geo["message"]
        ]);

        exit;
    }

    $latitude  = $geo["latitude"];
    $longitude = $geo["longitude"];

} else {

    $latitude  = $report["latitude"];
    $longitude = $report["longitude"];
}

/* =========================================
   UPDATE QUERY (FULL SUPPORT)
========================================= */
$stmt = $conn->prepare("
    UPDATE outage_reports SET
        location_name = :location_name,
        latitude = :latitude,
        longitude = :longitude,
        category = :category,
        severity = :severity,
        description = :description,
        affected_houses = :affected_houses,
        is_active = :is_active,
        hazard_type = :hazard_type,
        started_at = :started_at,
        status = :status
    WHERE id = :id AND user_id = :user_id
");

$success = $stmt->execute([
    ":id" => $id,
    ":user_id" => $user_id,
    ":location_name" => $location_name,
    ":latitude" => $latitude,
    ":longitude" => $longitude,
    ":category" => $category,
    ":severity" => $severity,
    ":description" => $description,
    ":affected_houses" => $affected_houses,
    ":is_active" => $is_active,
    ":hazard_type" => $hazard_type,
    ":started_at" => $started_at,
    ":status" => $status
]);

echo json_encode([
    "success" => $success,
    "message" => $success ? "Report updated successfully" : "Update failed"
]);