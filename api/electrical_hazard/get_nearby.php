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

/* Unresolved hazards are relevant for risk display */
$sql = "
    SELECT
        eh.id,
        eh.location_name,
        eh.latitude,
        eh.longitude,
        eh.description,
        eh.severity,
        eh.status,
        eh.reported_at,
        b.barangay_name,
        ht.hazard_name AS hazard_type,
        (
            6371000 * ACOS(
                LEAST(1,
                COS(RADIANS(:lat)) * COS(RADIANS(eh.latitude)) *
                COS(RADIANS(eh.longitude) - RADIANS(:lng)) +
                SIN(RADIANS(:lat)) * SIN(RADIANS(eh.latitude))
                )
            )
        ) AS distance
    FROM electrical_hazards eh
    LEFT JOIN barangays b ON b.id = eh.barangay_id
    LEFT JOIN hazard_types ht ON ht.id = eh.hazard_type_id
    WHERE eh.status != 'resolved'
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
