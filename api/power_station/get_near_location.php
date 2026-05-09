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

$user_id = (int)($user['id'] ?? 0);

if ($user_id <= 0) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "message" => "Invalid token payload"
    ]);
    exit;
}

/* =========================================
   GET USER LOCATION (OPTIONAL)
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
   RADIUS (OPTIONAL FILTER)
========================================= */
$radius = isset($_GET['radius']) ? (int)$_GET['radius'] : null;

/* =========================================
   BASE QUERY: ALL AVAILABLE STATIONS
========================================= */
try {

    $hasLocation = !empty($userData['latitude']) && !empty($userData['longitude']);

    if ($hasLocation) {

        $lat = (float)$userData['latitude'];
        $lng = (float)$userData['longitude'];

        $stmt = $conn->prepare("
            SELECT 
                id,
                created_by,
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
                created_at,
                updated_at,

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
        ");

        $stmt->execute([
            ":lat" => $lat,
            ":lng" => $lng
        ]);

        $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        /* APPLY RADIUS FILTER ONLY IF PROVIDED */
        if ($radius) {
            $stations = array_values(array_filter($stations, function ($s) use ($radius) {
                return isset($s['distance']) && $s['distance'] <= $radius;
            }));
        }

        /* SORT BY DISTANCE */
        usort($stations, fn($a, $b) => $a['distance'] <=> $b['distance']);

    } else {

        /* NO LOCATION → RETURN ALL AVAILABLE */
        $stmt = $conn->prepare("
            SELECT 
                id,
                created_by,
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
                created_at,
                updated_at,
                0 AS distance
            FROM power_stations
            WHERE availability_status = 'available'
            ORDER BY created_at DESC
        ");

        $stmt->execute();
        $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /* =========================================
       RESPONSE
    ========================================= */
    echo json_encode([
        "success" => true,
        "message" => "Available power stations fetched successfully",
        "count" => count($stations),
        "has_location" => $hasLocation,
        "radius" => $radius,
        "data" => $stations
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database error"
    ]);
}