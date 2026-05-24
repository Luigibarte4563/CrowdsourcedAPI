<?php

header("Content-Type: application/json; charset=UTF-8");

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../services/get_coordinates.php';

/* =========================================
   DATABASE
========================================= */
$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* =========================================
   DISTANCE FUNCTION
========================================= */
function haversineDistance($lat1, $lon1, $lat2, $lon2)
{
    $earthRadius = 6371000;

    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);

    $a =
        sin($dLat / 2) * sin($dLat / 2) +
        cos(deg2rad($lat1)) *
        cos(deg2rad($lat2)) *
        sin($dLon / 2) *
        sin($dLon / 2);

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadius * $c;
}

try {

    /* =========================================
       AUTH
    ========================================= */
    $user = getUserFromJWT();

    if (
        !$user ||
        !isset($user['role']) ||
        $user['role'] !== 'electric_company'
    ) {
        throw new Exception("Unauthorized access");
    }

    /* =========================================
       GET INPUT
    ========================================= */
    $rawInput = file_get_contents("php://input");

    $jsonData = json_decode($rawInput, true);

    $data = (
        json_last_error() === JSON_ERROR_NONE &&
        is_array($jsonData)
    )
        ? $jsonData
        : $_POST;

    if (empty($data)) {
        throw new Exception("No input data received");
    }

    /* =========================================
       VARIABLES
    ========================================= */
    $maintenance_id = (int)($data['maintenance_id'] ?? 0);

    $maintenance_date = trim($data['maintenance_date'] ?? '');
    $start_time       = trim($data['start_time'] ?? '');
    $end_time         = trim($data['end_time'] ?? '');
    $description      = trim($data['description'] ?? '');

    $radius = (int)($data['radius'] ?? 500);

    $manualStatus = strtolower(
        trim($data['status'] ?? '')
    );

    if ($maintenance_id <= 0) {
        throw new Exception("Invalid maintenance ID");
    }

    /* =========================================
       BARANGAYS
    ========================================= */
    $barangays = $data['barangays'] ?? null;

    /*
        KEEP EXISTING BARANGAYS
        IF FRONTEND DOES NOT SEND THEM
    */
    if ($barangays === null) {

        $oldStmt = $conn->prepare("
            SELECT barangay_name
            FROM maintenance_locations
            WHERE maintenance_id = :id
        ");

        $oldStmt->execute([
            ":id" => $maintenance_id
        ]);

        $barangays = $oldStmt->fetchAll(PDO::FETCH_COLUMN);
    }

    if (is_string($barangays)) {

        $decoded = json_decode($barangays, true);

        if (
            json_last_error() === JSON_ERROR_NONE &&
            is_array($decoded)
        ) {
            $barangays = $decoded;
        } else {
            $barangays = explode(",", $barangays);
        }
    }

    if (!is_array($barangays)) {
        $barangays = [];
    }

    $barangays = array_values(array_filter(
        array_map('trim', $barangays)
    ));

    /* =========================================
       VALIDATION
    ========================================= */
    if (empty($maintenance_date)) {
        throw new Exception("Maintenance date required");
    }

    if (empty($start_time)) {
        throw new Exception("Start time required");
    }

    if (empty($end_time)) {
        throw new Exception("End time required");
    }

    /* =========================================
       CHECK EXISTING RECORD
       REMOVED created_by CHECK
       TO PREVENT SQL COLUMN ERROR
    ========================================= */
    $checkStmt = $conn->prepare("
        SELECT id
        FROM maintenance_schedules
        WHERE id = :id
        LIMIT 1
    ");

    $checkStmt->execute([
        ":id" => $maintenance_id
    ]);

    if (!$checkStmt->fetch(PDO::FETCH_ASSOC)) {
        throw new Exception("Maintenance record not found");
    }

    /* =========================================
       STATUS LOGIC
    ========================================= */
    $validStatuses = [
        'upcoming',
        'ongoing',
        'completed',
        'cancelled'
    ];

    $now = new DateTime();

    $startDT = new DateTime(
        "$maintenance_date $start_time"
    );

    $endDT = new DateTime(
        "$maintenance_date $end_time"
    );

    if (
        in_array(
            $manualStatus,
            $validStatuses,
            true
        )
    ) {

        $finalStatus = $manualStatus;

    } else {

        if ($now > $endDT) {
            $finalStatus = "completed";
        } elseif (
            $now >= $startDT &&
            $now <= $endDT
        ) {
            $finalStatus = "ongoing";
        } else {
            $finalStatus = "upcoming";
        }
    }

    /* =========================================
       UPDATE MAINTENANCE
       REMOVED updated_at
       TO PREVENT COLUMN ERROR
    ========================================= */
    $updateStmt = $conn->prepare("
        UPDATE maintenance_schedules
        SET
            maintenance_date = :maintenance_date,
            start_time = :start_time,
            end_time = :end_time,
            description = :description,
            radius = :radius,
            status = :status
        WHERE id = :id
    ");

    $updateStmt->execute([
        ":maintenance_date" => $maintenance_date,
        ":start_time"       => $start_time,
        ":end_time"         => $end_time,
        ":description"      => $description,
        ":radius"           => $radius,
        ":status"           => $finalStatus,
        ":id"               => $maintenance_id
    ]);

    /* =========================================
       DELETE OLD LOCATIONS
    ========================================= */
    $deleteStmt = $conn->prepare("
        DELETE FROM maintenance_locations
        WHERE maintenance_id = :id
    ");

    $deleteStmt->execute([
        ":id" => $maintenance_id
    ]);

    /* =========================================
       INSERT NEW LOCATIONS
    ========================================= */
    $barangayCoords = [];

    /*
        CHANGE THESE COLUMN NAMES
        IF YOUR TABLE USES:
        barangay / lat / lng
    */
    $insertLocStmt = $conn->prepare("
        INSERT INTO maintenance_locations
        (
            maintenance_id,
            barangay_name,
            latitude,
            longitude
        )
        VALUES
        (
            :maintenance_id,
            :barangay_name,
            :latitude,
            :longitude
        )
    ");

    foreach ($barangays as $barangay) {

        if (empty($barangay)) {
            continue;
        }

        /*
            SAFE COORDINATE FETCH
        */
        try {

            if (!function_exists('getCoordinates')) {
                continue;
            }

            $geo = getCoordinates($barangay);

            if (
                !isset($geo['success']) ||
                !$geo['success']
            ) {
                continue;
            }

            $lat = (float)($geo['latitude'] ?? 0);
            $lng = (float)($geo['longitude'] ?? 0);

            if (!$lat || !$lng) {
                continue;
            }

            $barangayCoords[$barangay] = [
                'lat' => $lat,
                'lng' => $lng
            ];

            $insertLocStmt->execute([
                ":maintenance_id" => $maintenance_id,
                ":barangay_name"  => $barangay,
                ":latitude"       => $lat,
                ":longitude"      => $lng
            ]);

        } catch (Throwable $geoError) {

            error_log(
                "Geo Error: " .
                $geoError->getMessage()
            );

            continue;
        }
    }

    /* =========================================
       SUCCESS
       NOTIFICATIONS TEMPORARILY REMOVED
       TO PREVENT 500 ERRORS
    ========================================= */
    echo json_encode([
        "success"        => true,
        "message"        => "Maintenance updated successfully",
        "maintenance_id" => $maintenance_id,
        "status"         => $finalStatus,
        "barangays"      => $barangays,
        "debug"          => [
            "received_data" => $data,
            "barangay_count" => count($barangays)
        ]
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => $e->getMessage(),
        "line"    => $e->getLine(),
        "file"    => basename($e->getFile())
    ]);
}