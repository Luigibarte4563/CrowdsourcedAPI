<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';

require_once __DIR__ . '/../services/create_notification.php';
require_once __DIR__ . '/../services/get_coordinates.php';

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
       COMPANY (FIXED: NO electric_companies TABLE)
    ========================================= */
    $stmt = $conn->prepare("
        SELECT id, name
        FROM users
        WHERE id = :user_id
        AND role = 'electric_company'
        LIMIT 1
    ");
    $stmt->execute([":user_id" => $user['id']]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$company) {
        throw new Exception("Company not found");
    }

    $company_id = $company['id'];
    $company_name = $company['name'];

    /* =========================================
       INPUT
    ========================================= */
    $data = json_decode(file_get_contents("php://input"), true);

    $maintenance_date = $data['maintenance_date'] ?? '';
    $start_time = $data['start_time'] ?? '';
    $end_time = $data['end_time'] ?? '';
    $description = $data['description'] ?? '';
    $radius = (int) ($data['radius'] ?? 2000);
    $barangays = $data['barangays'] ?? [];

    if (!$maintenance_date || !$start_time || !$end_time) {
        throw new Exception("Missing required fields");
    }

    if (!is_array($barangays) || empty($barangays)) {
        throw new Exception("No barangays selected");
    }

    /* =========================================
       DUPLICATE CHECK (FIXED STATUS)
    ========================================= */
    $check = $conn->prepare("
        SELECT ms.id, ms.status, ml.barangay_name
        FROM maintenance_schedules ms
        JOIN maintenance_locations ml ON ms.id = ml.maintenance_id
        WHERE ms.status IN ('upcoming','ongoing')
        AND ms.maintenance_date = :date
    ");
    $check->execute([":date" => $maintenance_date]);
    $existing = $check->fetchAll(PDO::FETCH_ASSOC);

    $blockedBarangays = [];

    foreach ($existing as $row) {
        if (in_array($row['barangay_name'], $barangays)) {
            $blockedBarangays[] = $row['barangay_name'];
        }
    }

    if (!empty($blockedBarangays)) {
        throw new Exception(
            "Maintenance already exists for: " . implode(", ", array_unique($blockedBarangays))
        );
    }

    /* =========================================
       INSERT MAINTENANCE (FIXED SCHEMA)
    ========================================= */
    $insert = $conn->prepare("
        INSERT INTO maintenance_schedules (
            created_by,
            affected_barangays,
            radius,
            maintenance_date,
            start_time,
            end_time,
            description,
            status
        ) VALUES (
            :company_id,
            :barangays,
            :radius,
            :date,
            :start,
            :end,
            :desc,
            'upcoming'
        )
    ");

    $insert->execute([
        ":company_id" => $company_id,
        ":barangays" => json_encode($barangays),
        ":radius" => $radius,
        ":date" => $maintenance_date,
        ":start" => $start_time,
        ":end" => $end_time,
        ":desc" => $description
    ]);

    $maintenance_id = $conn->lastInsertId();

    /* =========================================
       GET COORDINATES
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

    /* =========================================
       INSERT LOCATIONS
    ========================================= */
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

    /* =========================================
       USERS
    ========================================= */
    $userStmt = $conn->prepare("
        SELECT id, latitude, longitude
        FROM users
        WHERE role = 'user'
    ");
    $userStmt->execute();
    $users = $userStmt->fetchAll(PDO::FETCH_ASSOC);

    $notified = 0;

    foreach ($users as $u) {

        if (!$u['latitude'] || !$u['longitude']) continue;

        $affected = [];

        foreach ($barangayCoords as $name => $coord) {

            $distance = haversineDistance(
                $coord['lat'],
                $coord['lng'],
                $u['latitude'],
                $u['longitude']
            );

            if ($distance <= $radius) {
                $affected[] = $name;
            }
        }

        $title = "Scheduled Power Maintenance";

        $formattedDate = date("F d, Y", strtotime($maintenance_date));
        $formattedStart = date("h:i A", strtotime($start_time));
        $formattedEnd = date("h:i A", strtotime($end_time));

        $allBarangays = implode(", ", $barangays);
        $affectedList = !empty($affected) ? implode(", ", $affected) : null;

        if (!empty($affected)) {

            $message = "⚠ Power Interruption Notice

📍 Affected: {$allBarangays}
📍 Your Area: {$affectedList}
📅 {$formattedDate}
🕒 {$formattedStart} - {$formattedEnd}

{$company_name}";
        } else {

            $message = "ℹ Power Advisory

📍 Affected: {$allBarangays}
📅 {$formattedDate}
🕒 {$formattedStart} - {$formattedEnd}

No direct impact in your area.

{$company_name}";
        }

        createNotification(
            $conn,
            [$u['id']],
            $title,
            $message,
            "maintenance",
            $maintenance_id,
            "maintenance",
            $allBarangays
        );

        $notified++;
    }

    echo json_encode([
        "success" => true,
        "message" => "Maintenance created successfully",
        "maintenance_id" => $maintenance_id,
        "users_notified" => $notified
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}