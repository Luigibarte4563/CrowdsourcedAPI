<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';

/* 🚨 FORCE SAFE ERROR MODE (IMPORTANT) */
ini_set('display_errors', 0);
error_reporting(0);

ob_start(); // 🔥 prevent accidental output

try {

    $conn = getConnection();
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    /* =========================================
       AUTH (SAFE WRAP)
    ========================================= */
    $user = null;

    try {
        $user = getUserFromJWT();
    } catch (Throwable $e) {
        $user = null;
    }

    if (!$user) {

        http_response_code(401);

        ob_clean();
        echo json_encode([
            "success" => false,
            "message" => "Unauthorized"
        ]);

        exit;
    }

    /* =========================================
       QUERY
    ========================================= */
    $sql = "
        SELECT 
            ms.id,
            ms.electric_company_id,
            ec.company_name,
            ms.radius,
            ms.maintenance_date,
            ms.start_time,
            ms.end_time,
            ms.description,
            ms.affected_barangays,
            ms.estimated_restoration_time,
            ms.status,
            ms.created_at,
            ms.updated_at
        FROM maintenance_schedules ms
        LEFT JOIN electric_companies ec 
            ON ec.id = ms.electric_company_id
        ORDER BY ms.id DESC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* =========================================
       LOCATIONS
    ========================================= */
    $locStmt = $conn->prepare("
        SELECT maintenance_id, barangay_name, latitude, longitude
        FROM maintenance_locations
    ");

    $locStmt->execute();
    $locationsRaw = $locStmt->fetchAll(PDO::FETCH_ASSOC);

    $locations = [];

    foreach ($locationsRaw as $loc) {

        $mid = $loc["maintenance_id"];

        $locations[$mid][] = [
            "barangay_name" => $loc["barangay_name"],
            "latitude" => (float)$loc["latitude"],
            "longitude" => (float)$loc["longitude"]
        ];
    }

    /* =========================================
       RESPONSE BUILD
    ========================================= */
    $data = [];

    foreach ($rows as $row) {

        $id = (int)$row["id"];

        $data[] = [
            "id" => $id,
            "company_name" => $row["company_name"],
            "radius" => (int)$row["radius"],
            "maintenance_date" => $row["maintenance_date"],
            "start_time" => $row["start_time"],
            "end_time" => $row["end_time"],
            "description" => $row["description"],
            "affected_barangays" => json_decode($row["affected_barangays"], true),
            "locations" => $locations[$id] ?? [],
            "estimated_restoration_time" => $row["estimated_restoration_time"],
            "status" => $row["status"],
            "created_at" => $row["created_at"],
            "updated_at" => $row["updated_at"]
        ];
    }

    ob_clean();

    echo json_encode([
        "success" => true,
        "count" => count($data),
        "data" => $data
    ]);

} catch (Throwable $e) {

    ob_clean();
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Server error"
    ]);
}