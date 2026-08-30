<?php

header("Content-Type: application/json");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';

$conn = getConnection();

/* =========================================
   JWT AUTH (kept for system security consistency)
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

/* =========================================
   OPTIONAL PAGINATION
========================================= */
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 50;
$offset = ($page - 1) * $limit;

try {

    /* =========================================
       FETCH POWER STATIONS
    ========================================= */
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
            b.barangay_name
        FROM power_stations ps
        LEFT JOIN power_station_types pst ON pst.id = ps.station_type_id
        LEFT JOIN barangays b ON b.id = ps.barangay_id
        ORDER BY ps.created_at DESC
        LIMIT :limit OFFSET :offset
    ");

    $stmt->bindValue(":limit", $limit, PDO::PARAM_INT);
    $stmt->bindValue(":offset", $offset, PDO::PARAM_INT);
    $stmt->execute();

    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* =========================================
       RESPONSE
    ========================================= */
    echo json_encode([
        "success" => true,
        "message" => count($data) === 0
            ? "No power stations found"
            : "Power stations loaded successfully",
        "count" => count($data),
        "page" => $page,
        "limit" => $limit,
        "data" => $data
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database error"
    ]);
}