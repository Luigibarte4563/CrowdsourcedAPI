<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';

$conn = getConnection();

/* =========================================
   JWT AUTH
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

$user_id = $user['id'] ?? null;

if (!$user_id) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "message" => "Invalid token payload"
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
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);

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
   FALLBACK FUNCTION
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
       NEARBY SEARCH
    ========================================= */
    if (
        $userData &&
        $userData['latitude'] !== null &&
        $userData['longitude'] !== null
    ) {

        $lat = (float) $userData['latitude'];
        $lng = (float) $userData['longitude'];

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
            WHERE availability_status = 'available'
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
       FALLBACK
    ========================================= */
    if (empty($stations)) {
        $stations = fetchAllStations($conn);
    }

    echo json_encode([
        "success" => true,
        "message" => empty($stations)
            ? "No stations found"
            : "Stations loaded successfully",
        "fallback" => empty($userData) || empty($userData['latitude']),
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