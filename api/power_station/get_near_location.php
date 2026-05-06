<?php

header("Content-Type: application/json; charset=UTF-8");

session_start();
require_once __DIR__ . '/../../config/db_connect.php';

$conn = getConnection();

/* =========================================
   AUTH CHECK
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
   GET USER LOCATION
========================================= */
try {

    $stmt = $conn->prepare("
        SELECT latitude, longitude
        FROM users
        WHERE id = :id
        LIMIT 1
    ");

    $stmt->execute([":id" => $user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {

    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error (user fetch)"
    ]);
    exit;
}

/* =========================================
   RADIUS
========================================= */
$radius = isset($_GET['radius']) ? (int) $_GET['radius'] : 3000;

/* =========================================
   FUNCTION: FETCH ALL AVAILABLE
========================================= */
function fetchAllStations($conn) {

    $stmt = $conn->prepare("
        SELECT 
            id,
            station_name,
            location_name,
            latitude,
            longitude,
            station_type,
            access_type,
            availability_status,
            operating_hours,
            charging_type,
            description,
            image,
            0 AS distance
        FROM power_stations
        WHERE availability_status = 'available'
        ORDER BY id DESC
    ");

    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* =========================================
   MAIN LOGIC
========================================= */
try {

    $stations = [];

    /* =========================================
       IF USER HAS LOCATION → TRY NEARBY
    ========================================= */
    if (
        $user &&
        $user['latitude'] !== null &&
        $user['longitude'] !== null
    ) {

        $lat = (float) $user['latitude'];
        $lng = (float) $user['longitude'];

        $stmt = $conn->prepare("
            SELECT 
                id,
                station_name,
                location_name,
                latitude,
                longitude,
                station_type,
                access_type,
                availability_status,
                operating_hours,
                charging_type,
                description,
                image,

                (
                    6371000 * ACOS(
                        COS(RADIANS(:lat)) *
                        COS(RADIANS(latitude)) *
                        COS(RADIANS(longitude) - RADIANS(:lng)) +
                        SIN(RADIANS(:lat)) *
                        SIN(RADIANS(latitude))
                    )
                ) AS distance

            FROM power_stations

            HAVING distance <= :radius

            ORDER BY distance ASC
        ");

        $stmt->execute([
            ":lat" => $lat,
            ":lng" => $lng,
            ":radius" => $radius
        ]);

        $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /* =========================================
       FALLBACK: NO NEARBY FOUND
    ========================================= */
    if (empty($stations)) {
        $stations = fetchAllStations($conn);
    }

    echo json_encode([
        "success" => true,
        "message" => empty($stations)
            ? "No stations found"
            : "Stations loaded successfully",
        "fallback" => empty($user) || empty($user['latitude']),
        "radius" => $radius,
        "count" => count($stations),
        "data" => $stations
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database error"
    ]);
}