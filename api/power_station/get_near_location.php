<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';

$conn = getConnection();

/* =========================================
   JWT AUTH
========================================= */
$user = getUserFromJWT();

if (!$user || !isset($user['id'])) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized (invalid JWT)"
    ]);
    exit;
}

$user_id = (int)$user['id'];

/* =========================================
   GET USER LOCATION
========================================= */
try {

    $stmt = $conn->prepare("
        SELECT latitude, longitude
        FROM user_locations
        WHERE user_id = :id AND is_primary = 1
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
   RADIUS VALIDATION
========================================= */
$radius = isset($_GET['radius']) ? (int)$_GET['radius'] : null;

if ($radius !== null && $radius <= 0) {
    $radius = null;
}

/* DEFAULT RADIUS (optional safety)
$radius = $radius ?? 5000; // 5km default
*/

/* =========================================
   MAIN LOGIC
========================================= */
try {

    $hasLocation =
        isset($userData['latitude'], $userData['longitude']) &&
        $userData['latitude'] !== null &&
        $userData['longitude'] !== null;

    if ($hasLocation) {

        $lat = (float)$userData['latitude'];
        $lng = (float)$userData['longitude'];

        $sql = "
            SELECT 
                ps.id,
                ps.created_by,
                ps.station_name,
                ps.location_name,
                ps.latitude,
                ps.longitude,
                pst.type_name AS station_type,
                ps.access_type,
                ps.availability_status,
                ps.operating_hours,
                ps.charging_type,
                ps.description,
                ps.image,
                ps.created_at,
                ps.updated_at,
                b.barangay_name,

                (
                    6371000 * ACOS(
                        LEAST(1,
                        COS(RADIANS(:lat)) *
                        COS(RADIANS(ps.latitude)) *
                        COS(RADIANS(ps.longitude) - RADIANS(:lng)) +
                        SIN(RADIANS(:lat)) *
                        SIN(RADIANS(ps.latitude))
                        )
                    )
                ) AS distance

            FROM power_stations ps
            LEFT JOIN power_station_types pst ON pst.id = ps.station_type_id
            LEFT JOIN barangays b ON b.id = ps.barangay_id
            WHERE ps.availability_status = 'available'
        ";

        $stmt = $conn->prepare($sql);

        $stmt->execute([
            ":lat" => $lat,
            ":lng" => $lng
        ]);

        $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        /* FILTER BY RADIUS (SAFE) */
        if ($radius !== null) {
            $stations = array_values(array_filter($stations, function ($s) use ($radius) {
                return isset($s['distance']) && $s['distance'] <= $radius;
            }));
        }

        /* SORT BY DISTANCE */
        usort($stations, function ($a, $b) {
            return ($a['distance'] ?? 0) <=> ($b['distance'] ?? 0);
        });

    } else {

        $stmt = $conn->prepare("
            SELECT 
                ps.id,
                ps.created_by,
                ps.station_name,
                ps.location_name,
                ps.latitude,
                ps.longitude,
                pst.type_name AS station_type,
                ps.access_type,
                ps.availability_status,
                ps.operating_hours,
                ps.charging_type,
                ps.description,
                ps.image,
                ps.created_at,
                ps.updated_at,
                b.barangay_name,
                0 AS distance
            FROM power_stations ps
            LEFT JOIN power_station_types pst ON pst.id = ps.station_type_id
            LEFT JOIN barangays b ON b.id = ps.barangay_id
            WHERE ps.availability_status = 'available'
            ORDER BY ps.created_at DESC
        ");

        $stmt->execute();
        $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /* =========================================
       RESPONSE
    ========================================= */
    echo json_encode([
        "success" => true,
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