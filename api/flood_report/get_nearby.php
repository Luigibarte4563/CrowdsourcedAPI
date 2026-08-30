<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../../auth/rbac.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

requireAuthUser();

$lat = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
$lng = isset($_GET['lng']) ? (float)$_GET['lng'] : null;
$radius = isset($_GET['radius']) ? (int)$_GET['radius'] : 3000;

if ($lat === null || $lng === null) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "lat and lng are required"]);
    exit;
}

/* Only active (non-cleared) floods are relevant */
$sql = "
    SELECT
        fr.id,
        fr.location_name,
        fr.latitude,
        fr.longitude,
        fr.flood_depth_cm,
        fr.flood_level,
        fr.description,
        fr.image_proof,
        fr.status,
        fr.reported_at,
        b.barangay_name,
        (
            6371000 * ACOS(
                LEAST(1,
                COS(RADIANS(:lat)) * COS(RADIANS(fr.latitude)) *
                COS(RADIANS(fr.longitude) - RADIANS(:lng)) +
                SIN(RADIANS(:lat)) * SIN(RADIANS(fr.latitude))
                )
            )
        ) AS distance
    FROM flood_reports fr
    LEFT JOIN barangays b ON b.id = fr.barangay_id
    WHERE fr.status != 'cleared'
    HAVING distance <= :radius
    ORDER BY distance ASC
";

try {
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(":lat", $lat);
    $stmt->bindValue(":lng", $lng);
    $stmt->bindValue(":radius", $radius, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "count" => count($rows),
        "radius" => $radius,
        "data" => $rows
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error"]);
}
