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

    $company_name = $company['company_name'];

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
    $notify_all = $data['notify_all'] ?? false;

    if (!$maintenance_date || !$start_time || !$end_time) {
        throw new Exception("Missing required fields");
    }

    if (!is_array($barangays) || empty($barangays)) {
        throw new Exception("No barangays selected");
    }

    /* =========================================
       INSERT MAINTENANCE
    ========================================= */
    $insert = $conn->prepare("
        INSERT INTO maintenance_schedules (
            electric_company_id,
            affected_barangays,
            radius,
            maintenance_date,
            start_time,
            end_time,
            description
        ) VALUES (
            :company_id,
            :barangays,
            :radius,
            :date,
            :start,
            :end,
            :desc
        )
    ");

    $insert->execute([
        ":company_id" => $company['id'],
        ":barangays" => json_encode($barangays),
        ":radius" => $radius,
        ":date" => $maintenance_date,
        ":start" => $start_time,
        ":end" => $end_time,
        ":desc" => $description
    ]);

    $maintenance_id = $conn->lastInsertId();

    /* =========================================
       GET BARANGAY COORDINATES
    ========================================= */
    $barangayCoords = [];

    foreach ($barangays as $b) {

        $geo = getCoordinates($b);

        if (!$geo["success"])
            continue;

        $barangayCoords[$b] = [
            "lat" => $geo["latitude"],
            "lng" => $geo["longitude"]
        ];
    }

    /* =========================================
       GET USERS
    ========================================= */
    $userStmt = $conn->prepare("
        SELECT id, latitude, longitude
        FROM users
        WHERE role = 'user'
    ");
    $userStmt->execute();
    $users = $userStmt->fetchAll(PDO::FETCH_ASSOC);

    $notified = 0;

    /* =========================================
       NOTIFICATION LOGIC
    ========================================= */
    foreach ($users as $u) {

        $userId = $u['id'];

        if (!$u['latitude'] || !$u['longitude']) {
            continue;
        }

        $affected = [];

        /* =========================================
           CHECK ALL SELECTED BARANGAYS
        ========================================= */
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

This is an official maintenance announcement.

📍 All Affected Areas: {$allBarangays}
📍 Your Affected Barangay(s): {$affectedList}
📅 Date: {$formattedDate}
🕒 Time: {$formattedStart} - {$formattedEnd}

⚡ Please prepare for temporary interruption.
{$company_name}";

    $location = $allBarangays;

} else {

    $message = "ℹ Power Maintenance Advisory

This is an official maintenance announcement.

📍 Affected Areas: {$allBarangays}
📅 Date: {$formattedDate}
🕒 Time: {$formattedStart} - {$formattedEnd}

⚡ Your area is NOT directly affected, but nearby areas may experience temporary fluctuations.
{$company_name}";

    $location = $allBarangays;
}

        createNotification(
            $conn,
            [$userId],
            $title,
            $message,
            "maintenance",
            $maintenance_id,
            "maintenance",
            $location
        );

        $notified++;
    }

    /* =========================================
       RESPONSE
    ========================================= */
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