<?php

header("Content-Type: application/json");
session_start();

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '../../services/get_coordinates.php';

$conn = getConnection();

/* =========================================
   AUTH CHECK
========================================= */
$electric_company_id = $_SESSION['user']['electric_company_id'] ?? null;

if (!$electric_company_id) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized (no electric company)"
    ]);
    exit;
}

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

/* =========================================
   REQUIRED FIELDS
========================================= */
$affected_area    = trim($data["affected_area"] ?? "");
$maintenance_date = $data["maintenance_date"] ?? null;
$start_time       = $data["start_time"] ?? null;
$end_time         = $data["end_time"] ?? null;

if ($affected_area === "" || !$maintenance_date || !$start_time || !$end_time) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "affected_area, maintenance_date, start_time, end_time are required"
    ]);
    exit;
}

/* =========================================
   GEOCODING
========================================= */
$geo = getCoordinates($affected_area);

if (!$geo["success"]) {
    http_response_code(404);
    echo json_encode([
        "success" => false,
        "message" => "Cannot find location coordinates for: " . $affected_area
    ]);
    exit;
}

$latitude  = $geo["latitude"];
$longitude = $geo["longitude"];

/* =========================================
   OPTIONAL FIELDS
========================================= */
$radius       = $data["radius"] ?? 2000;
$description  = $data["description"] ?? "";
$estimated_restoration_time = $data["estimated_restoration_time"] ?? null;

/* =========================================
   HELPER: DISTANCE (Haversine)
========================================= */
function distance($lat1, $lon1, $lat2, $lon2) {
    $earth = 6371000;

    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);

    $a = sin($dLat/2) * sin($dLat/2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon/2) * sin($dLon/2);

    $c = 2 * atan2(sqrt($a), sqrt(1-$a));

    return $earth * $c;
}

/* =========================================
   START TRANSACTION
========================================= */
try {

    $conn->beginTransaction();

    /* =========================================
       1. INSERT MAINTENANCE
    ========================================= */
    $stmt = $conn->prepare("
        INSERT INTO maintenance_schedules (
            electric_company_id,
            affected_area,
            latitude,
            longitude,
            radius,
            maintenance_date,
            start_time,
            end_time,
            description,
            estimated_restoration_time
        )
        VALUES (
            :electric_company_id,
            :affected_area,
            :latitude,
            :longitude,
            :radius,
            :maintenance_date,
            :start_time,
            :end_time,
            :description,
            :estimated_restoration_time
        )
    ");

    $stmt->execute([
        ":electric_company_id" => $electric_company_id,
        ":affected_area" => $affected_area,
        ":latitude" => $latitude,
        ":longitude" => $longitude,
        ":radius" => $radius,
        ":maintenance_date" => $maintenance_date,
        ":start_time" => $start_time,
        ":end_time" => $end_time,
        ":description" => $description,
        ":estimated_restoration_time" => $estimated_restoration_time
    ]);

    $maintenance_id = $conn->lastInsertId();

    /* =========================================
       2. GET USERS
    ========================================= */
    $stmt = $conn->prepare("
        SELECT id, name, latitude, longitude
        FROM users
        WHERE latitude IS NOT NULL AND longitude IS NOT NULL
    ");

    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* =========================================
       3. BUILD NOTIFICATIONS
    ========================================= */
    $notifStmt = $conn->prepare("
        INSERT INTO notifications (user_id, title, message, type)
        VALUES (:user_id, :title, :message, 'maintenance')
    ");

    $count = 0;

    foreach ($users as $u) {

        $dist = distance(
            $latitude,
            $longitude,
            $u["latitude"],
            $u["longitude"]
        );

        if ($dist <= $radius) {

            $notifStmt->execute([
                ":user_id" => $u["id"],
                ":title"   => "Scheduled Maintenance",
                ":message" => "Power interruption at {$affected_area}"
            ]);

            $count++;
        }
    }

    $conn->commit();

    /* =========================================
       RESPONSE
    ========================================= */
    echo json_encode([
        "success" => true,
        "message" => "Maintenance created and notifications sent",
        "maintenance_id" => $maintenance_id,
        "notifications_sent" => $count,
        "latitude" => $latitude,
        "longitude" => $longitude
    ]);

} catch (PDOException $e) {

    $conn->rollBack();

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database error"
    ]);
}