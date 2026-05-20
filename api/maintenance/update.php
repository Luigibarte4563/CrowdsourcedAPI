<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../services/get_coordinates.php';
require_once __DIR__ . '/../services/create_notification.php';

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
       GET COMPANY
    ========================================= */
    $companyStmt = $conn->prepare("
        SELECT id, name AS company_name
        FROM users
        WHERE id = :id
        AND role = 'electric_company'
        LIMIT 1
    ");

    $companyStmt->execute([":id" => $user['id']]);
    $company = $companyStmt->fetch(PDO::FETCH_ASSOC);

    if (!$company) {
        throw new Exception("Company not found");
    }

    /* =========================================
       INPUT
    ========================================= */
    $data = json_decode(file_get_contents("php://input"), true);
    if (!$data) $data = $_POST;

    $maintenance_id = $data['maintenance_id'] ?? null;
    $maintenance_date = $data['maintenance_date'] ?? '';
    $start_time = $data['start_time'] ?? '';
    $end_time = $data['end_time'] ?? '';
    $description = $data['description'] ?? '';
    $radius = (int)($data['radius'] ?? 2000);
    $barangays = $data['barangays'] ?? [];
    $manualStatus = strtolower(trim($data['status'] ?? ''));

    if (!$maintenance_id) {
        throw new Exception("Maintenance ID is required");
    }

    if (!is_array($barangays)) {
        $barangays = [];
    }

    /* =========================================
       OWNERSHIP CHECK
    ========================================= */
    $check = $conn->prepare("
        SELECT id
        FROM maintenance_schedules
        WHERE id = :id
        AND created_by = :user_id
        LIMIT 1
    ");

    $check->execute([
        ":id" => $maintenance_id,
        ":user_id" => $user['id']
    ]);

    if (!$check->fetch()) {
        throw new Exception("Maintenance not found or not allowed");
    }

    /* =========================================
       COMPANY NAME
    ========================================= */
    $companyName = $company['company_name'] ?? 'Electric Company';

    /* =========================================
       STATUS LOGIC (DB-COMPATIBLE)
    ========================================= */

    $now = new DateTime();

    $startDT = (!empty($maintenance_date) && !empty($start_time))
        ? new DateTime("$maintenance_date $start_time")
        : null;

    $endDT = (!empty($maintenance_date) && !empty($end_time))
        ? new DateTime("$maintenance_date $end_time")
        : null;

    $validStatuses = ['upcoming', 'ongoing', 'completed', 'cancelled'];

    if (in_array($manualStatus, $validStatuses, true)) {
        $finalStatus = $manualStatus;
    } else {
        if ($startDT && $endDT) {
            if ($now > $endDT) {
                $finalStatus = "completed";
            } elseif ($now >= $startDT && $now <= $endDT) {
                $finalStatus = "ongoing";
            } else {
                $finalStatus = "upcoming";
            }
        } else {
            $finalStatus = "upcoming";
        }
    }

    /* =========================================
       UPDATE MAIN SCHEDULE
    ========================================= */
    $update = $conn->prepare("
        UPDATE maintenance_schedules
        SET
            maintenance_date = :date,
            start_time = :start,
            end_time = :end,
            description = :desc,
            radius = :radius,
            status = :status,
            updated_at = NOW()
        WHERE id = :id
    ");

    $update->execute([
        ":date" => $maintenance_date,
        ":start" => $start_time,
        ":end" => $end_time,
        ":desc" => $description,
        ":radius" => $radius,
        ":status" => $finalStatus,
        ":id" => $maintenance_id
    ]);

    if ($update->rowCount() === 0) {
        throw new Exception("Update failed or no changes detected");
    }

    /* =========================================
       DELETE OLD LOCATIONS
    ========================================= */
    $conn->prepare("
        DELETE FROM maintenance_locations
        WHERE maintenance_id = :id
    ")->execute([":id" => $maintenance_id]);

    /* =========================================
       REINSERT LOCATIONS
    ========================================= */
    $barangayCoords = [];

    foreach ($barangays as $b) {
        if (!$b) continue;

        $geo = getCoordinates($b);

        if (!isset($geo["success"]) || !$geo["success"]) continue;

        $barangayCoords[$b] = [
            "lat" => $geo["latitude"],
            "lng" => $geo["longitude"]
        ];
    }

    $insertLoc = $conn->prepare("
        INSERT INTO maintenance_locations
        (maintenance_id, barangay_name, latitude, longitude)
        VALUES (:id, :name, :lat, :lng)
    ");

    foreach ($barangayCoords as $name => $coord) {
        $insertLoc->execute([
            ":id" => $maintenance_id,
            ":name" => $name,
            ":lat" => $coord["lat"],
            ":lng" => $coord["lng"]
        ]);
    }

    /* =========================================
       STATUS-BASED NOTIFICATION
    ========================================= */

    switch ($finalStatus) {
        case "upcoming":
            $statusHeader = "🟡 UPCOMING MAINTENANCE";
            $statusNote = "Prepare for possible power interruption.";
            break;

        case "ongoing":
            $statusHeader = "🔴 ONGOING MAINTENANCE";
            $statusNote = "Power interruption is currently happening.";
            break;

        case "completed":
            $statusHeader = "🟢 MAINTENANCE COMPLETED";
            $statusNote = "Power has been restored.";
            break;

        case "cancelled":
            $statusHeader = "⚫ MAINTENANCE CANCELLED";
            $statusNote = "The scheduled maintenance has been cancelled.";
            break;

        default:
            $statusHeader = "⚡ MAINTENANCE UPDATE";
            $statusNote = "";
            break;
    }

    /* =========================================
       NOTIFICATIONS
    ========================================= */
    $usersStmt = $conn->prepare("
        SELECT id, latitude, longitude
        FROM users
        WHERE role = 'user'
    ");

    $usersStmt->execute();
    $users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

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

        $allBarangays = implode(", ", $barangays);
        $affectedList = !empty($affected) ? implode(", ", $affected) : "No direct impact";

        $message = "{$statusHeader}

📍 Areas: {$allBarangays}
📍 Your Area: {$affectedList}
📅 {$maintenance_date}
🕒 {$start_time} - {$end_time}

{$statusNote}

{$companyName}";

        createNotification(
            $conn,
            [$u['id']],
            "Maintenance " . ucfirst($finalStatus),
            $message,
            "maintenance_update",
            $maintenance_id,
            "maintenance",
            $allBarangays
        );
    }

    /* =========================================
       RESPONSE
    ========================================= */
    echo json_encode([
        "success" => true,
        "message" => "Maintenance updated successfully",
        "maintenance_id" => $maintenance_id,
        "status" => $finalStatus
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}