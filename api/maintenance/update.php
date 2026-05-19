<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../services/get_coordinates.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* =========================================
   DISTANCE FUNCTION (kept for consistency)
========================================= */
function haversineDistance($lat1, $lon1, $lat2, $lon2)
{
    $earthRadius = 6371000;

    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);

    $a = sin($dLat / 2) ** 2 +
        cos(deg2rad($lat1)) *
        cos(deg2rad($lat2)) *
        sin($dLon / 2) ** 2;

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadius * $c;
}

try {

    /* =========================================
       AUTH
    ========================================= */
    $user = getUserFromJWT();

    if (!$user || ($user['role'] ?? '') !== 'electric_company') {
        throw new Exception("Unauthorized");
    }

    /* =========================================
       COMPANY
    ========================================= */
    $stmt = $conn->prepare("
        SELECT id, company_name
        FROM electric_companies
        WHERE user_id = :user_id
        LIMIT 1
    ");
    $stmt->execute([":user_id" => $user['id']]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$company) {
        throw new Exception("Company not found");
    }

    /* =========================================
       INPUT
    ========================================= */
    $data = json_decode(file_get_contents("php://input"), true);

    $maintenance_id = $data['maintenance_id'] ?? null;
    $maintenance_date = $data['maintenance_date'] ?? '';
    $start_time = $data['start_time'] ?? '';
    $end_time = $data['end_time'] ?? '';
    $description = $data['description'] ?? '';
    $radius = (int) ($data['radius'] ?? 2000);
    $barangays = $data['barangays'] ?? [];

    /* 🔥 NEW: STATUS INPUT */
    $status = $data['status'] ?? null;

    if (!$maintenance_id) {
        throw new Exception("Maintenance ID is required");
    }

    if (!$maintenance_date || !$start_time || !$end_time) {
        throw new Exception("Missing required fields");
    }

    /* =========================================
       CHECK OWNERSHIP
    ========================================= */
    $check = $conn->prepare("
        SELECT id
        FROM maintenance_schedules
        WHERE id = :id
        AND electric_company_id = :company_id
        LIMIT 1
    ");

    $check->execute([
        ":id" => $maintenance_id,
        ":company_id" => $company['id']
    ]);

    if (!$check->fetch()) {
        throw new Exception("Maintenance not found or not allowed");
    }

    /* =========================================
       UPDATE MAINTENANCE (WITH STATUS)
    ========================================= */
    $update = $conn->prepare("
        UPDATE maintenance_schedules
        SET
            maintenance_date = :date,
            start_time = :start,
            end_time = :end,
            description = :desc,
            radius = :radius,
            status = COALESCE(:status, status),
            updated_at = NOW()
        WHERE id = :id
    ");

    $update->execute([
        ":date" => $maintenance_date,
        ":start" => $start_time,
        ":end" => $end_time,
        ":desc" => $description,
        ":radius" => $radius,
        ":status" => $status,
        ":id" => $maintenance_id
    ]);

    /* =========================================
       REMOVE OLD LOCATIONS
    ========================================= */
    $del = $conn->prepare("
        DELETE FROM maintenance_locations
        WHERE maintenance_id = :id
    ");
    $del->execute([":id" => $maintenance_id]);

    /* =========================================
       RE-INSERT LOCATIONS
    ========================================= */
    $barangayCoords = [];

    foreach ($barangays as $b) {

        $geo = getCoordinates($b);

        if (!$geo["success"]) continue;

        $barangayCoords[$b] = [
            "lat" => $geo["latitude"],
            "lng" => $geo["longitude"]
        ];
    }

    if (!empty($barangayCoords)) {

        $locInsert = $conn->prepare("
            INSERT INTO maintenance_locations (
                maintenance_id,
                barangay_name,
                latitude,
                longitude
            ) VALUES (
                :maintenance_id,
                :barangay,
                :lat,
                :lng
            )
        ");

        foreach ($barangayCoords as $name => $coord) {

            $locInsert->execute([
                ":maintenance_id" => $maintenance_id,
                ":barangay" => $name,
                ":lat" => $coord["lat"],
                ":lng" => $coord["lng"]
            ]);
        }
    }

    /* =========================================
       RESPONSE
    ========================================= */

    echo json_encode([
        "success" => true,
        "message" => "Maintenance updated successfully",
        "maintenance_id" => $maintenance_id,
        "status" => $status ?? "unchanged"
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}