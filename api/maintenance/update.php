<?php

header("Content-Type: application/json; charset=UTF-8");

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../services/get_coordinates.php';
require_once __DIR__ . '/../services/create_notification.php'; // ← ADDED

/* =========================================
   DATABASE
========================================= */
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

    $a =
        sin($dLat / 2) * sin($dLat / 2) +
        cos(deg2rad($lat1)) *
        cos(deg2rad($lat2)) *
        sin($dLon / 2) *
        sin($dLon / 2);

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadius * $c;
}

try {

    /* =========================================
       AUTH
    ========================================= */
    $user = getUserFromJWT();

    if (
        !$user ||
        !isset($user['role']) ||
        $user['role'] !== 'electric_company'
    ) {
        throw new Exception("Unauthorized access");
    }

    /* =========================================
       COMPANY NAME (for notification sender label)
    ========================================= */
    $companyStmt = $conn->prepare("
        SELECT name
        FROM users
        WHERE id = :user_id
        AND role = 'electric_company'
        LIMIT 1
    ");
    $companyStmt->execute([":user_id" => $user['id']]);
    $company = $companyStmt->fetch(PDO::FETCH_ASSOC);
    $company_name = $company['name'] ?? 'Electric Company';

    /* =========================================
       GET INPUT
    ========================================= */
    $rawInput = file_get_contents("php://input");

    $jsonData = json_decode($rawInput, true);

    $data = (
        json_last_error() === JSON_ERROR_NONE &&
        is_array($jsonData)
    )
        ? $jsonData
        : $_POST;

    if (empty($data)) {
        throw new Exception("No input data received");
    }

    /* =========================================
       VARIABLES
    ========================================= */
    $maintenance_id = (int)($data['maintenance_id'] ?? 0);

    $maintenance_date = trim($data['maintenance_date'] ?? '');
    $start_time       = trim($data['start_time'] ?? '');
    $end_time         = trim($data['end_time'] ?? '');
    $description      = trim($data['description'] ?? '');

    $radius = (int)($data['radius'] ?? 500);

    $manualStatus = strtolower(
        trim($data['status'] ?? '')
    );

    if ($maintenance_id <= 0) {
        throw new Exception("Invalid maintenance ID");
    }

    /* =========================================
       BARANGAYS
    ========================================= */
    $barangays = $data['barangays'] ?? null;

    if ($barangays === null) {

        $oldStmt = $conn->prepare("
            SELECT barangay_name
            FROM maintenance_locations
            WHERE maintenance_id = :id
        ");

        $oldStmt->execute([
            ":id" => $maintenance_id
        ]);

        $barangays = $oldStmt->fetchAll(PDO::FETCH_COLUMN);
    }

    if (is_string($barangays)) {

        $decoded = json_decode($barangays, true);

        if (
            json_last_error() === JSON_ERROR_NONE &&
            is_array($decoded)
        ) {
            $barangays = $decoded;
        } else {
            $barangays = explode(",", $barangays);
        }
    }

    if (!is_array($barangays)) {
        $barangays = [];
    }

    $barangays = array_values(array_filter(
        array_map('trim', $barangays)
    ));

    /* =========================================
       VALIDATION
    ========================================= */
    if (empty($maintenance_date)) {
        throw new Exception("Maintenance date required");
    }

    if (empty($start_time)) {
        throw new Exception("Start time required");
    }

    if (empty($end_time)) {
        throw new Exception("End time required");
    }

    /* =========================================
       CHECK EXISTING RECORD
    ========================================= */
    $checkStmt = $conn->prepare("
        SELECT id
        FROM maintenance_schedules
        WHERE id = :id
        LIMIT 1
    ");

    $checkStmt->execute([
        ":id" => $maintenance_id
    ]);

    if (!$checkStmt->fetch(PDO::FETCH_ASSOC)) {
        throw new Exception("Maintenance record not found");
    }

    /* =========================================
       STATUS LOGIC
    ========================================= */
    $validStatuses = [
        'upcoming',
        'ongoing',
        'completed',
        'cancelled'
    ];

    $now = new DateTime();

    $startDT = new DateTime(
        "$maintenance_date $start_time"
    );

    $endDT = new DateTime(
        "$maintenance_date $end_time"
    );

    if (
        in_array(
            $manualStatus,
            $validStatuses,
            true
        )
    ) {

        $finalStatus = $manualStatus;

    } else {

        if ($now > $endDT) {
            $finalStatus = "completed";
        } elseif (
            $now >= $startDT &&
            $now <= $endDT
        ) {
            $finalStatus = "ongoing";
        } else {
            $finalStatus = "upcoming";
        }
    }

    /* =========================================
       UPDATE MAINTENANCE
    ========================================= */
    $updateStmt = $conn->prepare("
        UPDATE maintenance_schedules
        SET
            maintenance_date = :maintenance_date,
            start_time = :start_time,
            end_time = :end_time,
            description = :description,
            radius = :radius,
            status = :status
        WHERE id = :id
    ");

    $updateStmt->execute([
        ":maintenance_date" => $maintenance_date,
        ":start_time"       => $start_time,
        ":end_time"         => $end_time,
        ":description"      => $description,
        ":radius"           => $radius,
        ":status"           => $finalStatus,
        ":id"               => $maintenance_id
    ]);

    /* =========================================
       DELETE OLD LOCATIONS
    ========================================= */
    $deleteStmt = $conn->prepare("
        DELETE FROM maintenance_locations
        WHERE maintenance_id = :id
    ");

    $deleteStmt->execute([
        ":id" => $maintenance_id
    ]);

    /* =========================================
       INSERT NEW LOCATIONS
    ========================================= */
    $barangayCoords = [];

    $insertLocStmt = $conn->prepare("
        INSERT INTO maintenance_locations
        (
            maintenance_id,
            barangay_name,
            latitude,
            longitude
        )
        VALUES
        (
            :maintenance_id,
            :barangay_name,
            :latitude,
            :longitude
        )
    ");

    foreach ($barangays as $barangay) {

        if (empty($barangay)) {
            continue;
        }

        try {

            if (!function_exists('getCoordinates')) {
                continue;
            }

            $geo = getCoordinates($barangay);

            if (
                !isset($geo['success']) ||
                !$geo['success']
            ) {
                continue;
            }

            $lat = (float)($geo['latitude'] ?? 0);
            $lng = (float)($geo['longitude'] ?? 0);

            if (!$lat || !$lng) {
                continue;
            }

            $barangayCoords[$barangay] = [
                'lat' => $lat,
                'lng' => $lng
            ];

            $insertLocStmt->execute([
                ":maintenance_id" => $maintenance_id,
                ":barangay_name"  => $barangay,
                ":latitude"       => $lat,
                ":longitude"      => $lng
            ]);

        } catch (Throwable $geoError) {

            error_log(
                "Geo Error: " .
                $geoError->getMessage()
            );

            continue;
        }
    }

    /* =========================================
       NOTIFICATIONS — STATUS-AWARE
    ========================================= */
    $notified = 0;

    // Only notify if we have coordinate data to work with
    if (!empty($barangayCoords)) {

        $userStmt = $conn->prepare("
            SELECT id, latitude, longitude
            FROM users
            WHERE role = 'user'
        ");
        $userStmt->execute();
        $allUsers = $userStmt->fetchAll(PDO::FETCH_ASSOC);

        $formattedDate  = date("F d, Y", strtotime($maintenance_date));
        $formattedStart = date("h:i A", strtotime($start_time));
        $formattedEnd   = date("h:i A", strtotime($end_time));
        $allBarangays   = implode(", ", $barangays);

        // Status-specific notification title + icon
        $statusConfig = [
            'upcoming' => [
                'title' => "Maintenance Update: Rescheduled",
                'icon'  => "⏳",
                'label' => "UPCOMING"
            ],
            'ongoing' => [
                'title' => "Power Maintenance Now Ongoing",
                'icon'  => "⚡",
                'label' => "NOW ONGOING"
            ],
            'completed' => [
                'title' => "Power Restored – Maintenance Done",
                'icon'  => "✅",
                'label' => "COMPLETED"
            ],
            'cancelled' => [
                'title' => "Maintenance Cancelled",
                'icon'  => "🚫",
                'label' => "CANCELLED"
            ],
        ];

        $cfg = $statusConfig[$finalStatus] ?? [
            'title' => "🔔 Maintenance Update",
            'icon'  => "🔔",
            'label' => strtoupper($finalStatus)
        ];

        foreach ($allUsers as $u) {

            if (!$u['latitude'] || !$u['longitude']) {
                continue;
            }

            // Find which barangays are near this user
            $affected = [];

            foreach ($barangayCoords as $name => $coord) {

                $distance = haversineDistance(
                    $coord['lat'],
                    $coord['lng'],
                    (float)$u['latitude'],
                    (float)$u['longitude']
                );

                if ($distance <= $radius) {
                    $affected[] = $name;
                }
            }

            $affectedList = !empty($affected)
                ? implode(", ", $affected)
                : null;

            // Build message body based on status and proximity
            if ($finalStatus === 'upcoming') {

                if (!empty($affected)) {
                    $message = "{$cfg['icon']} Maintenance Update – {$cfg['label']}

📍 Affected Area: {$allBarangays}
📍 Your Area: {$affectedList}
📅 {$formattedDate}
🕒 {$formattedStart} – {$formattedEnd}

Details have been updated. Please prepare accordingly.

{$company_name}";
                } else {
                    $message = "{$cfg['icon']} Maintenance Advisory – {$cfg['label']}

📍 Affected Area: {$allBarangays}
📅 {$formattedDate}
🕒 {$formattedStart} – {$formattedEnd}

No direct impact expected in your area.

{$company_name}";
                }

            } elseif ($finalStatus === 'ongoing') {

                if (!empty($affected)) {
                    $message = "{$cfg['icon']} Power Interruption – {$cfg['label']}

⚠ Power is currently interrupted in your area.
📍 Your Area: {$affectedList}
📍 All Affected: {$allBarangays}
📅 {$formattedDate}
🕒 {$formattedStart} – {$formattedEnd}

Our team is actively working on this. Thank you for your patience.

{$company_name}";
                } else {
                    $message = "{$cfg['icon']} Nearby Maintenance – {$cfg['label']}

📍 Affected Area: {$allBarangays}
📅 {$formattedDate}
🕒 {$formattedStart} – {$formattedEnd}

No interruption expected in your immediate area.

{$company_name}";
                }

            } elseif ($finalStatus === 'completed') {

                if (!empty($affected)) {
                    $message = "{$cfg['icon']} Power Restored – {$cfg['label']}

✅ Power has been restored in your area.
📍 Your Area: {$affectedList}
📍 All Restored: {$allBarangays}
📅 {$formattedDate}
🕒 {$formattedStart} – {$formattedEnd}

Thank you for your patience!

{$company_name}";
                } else {
                    $message = "{$cfg['icon']} Maintenance Completed – {$cfg['label']}

📍 Area: {$allBarangays}
📅 {$formattedDate}
🕒 {$formattedStart} – {$formattedEnd}

Maintenance has concluded with no impact to your area.

{$company_name}";
                }

            } elseif ($finalStatus === 'cancelled') {

                $message = "{$cfg['icon']} Maintenance Cancelled

The scheduled maintenance for {$allBarangays} on {$formattedDate} has been cancelled.
🕒 Originally: {$formattedStart} – {$formattedEnd}

No power interruption will occur.

{$company_name}";

            } else {

                // Fallback for any future statuses
                $message = "{$cfg['icon']} Maintenance Status: {$cfg['label']}

📍 Area: {$allBarangays}
📅 {$formattedDate}
🕒 {$formattedStart} – {$formattedEnd}

{$company_name}";
            }

            createNotification(
                $conn,
                [$u['id']],
                $cfg['title'],
                $message,
                "maintenance",
                $maintenance_id,
                "maintenance",
                $allBarangays
            );

            $notified++;
        }
    }

    /* =========================================
       SUCCESS
    ========================================= */
    echo json_encode([
        "success"        => true,
        "message"        => "Maintenance updated successfully",
        "maintenance_id" => $maintenance_id,
        "status"         => $finalStatus,
        "barangays"      => $barangays,
        "users_notified" => $notified,
        "debug"          => [
            "received_data"  => $data,
            "barangay_count" => count($barangays)
        ]
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => $e->getMessage(),
        "line"    => $e->getLine(),
        "file"    => basename($e->getFile())
    ]);
}