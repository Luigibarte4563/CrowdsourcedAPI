<?php

header("Content-Type: application/json; charset=UTF-8");

error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../../auth/rbac.php';
require_once __DIR__ . '/../services/get_coordinates.php';
require_once __DIR__ . '/../services/create_notification.php';
require_once __DIR__ . '/../services/lookup.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$user = requireRole(requireAuthUser(), ['electric_company', 'admin']);

try {
    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) {
        $data = $_POST;
    }
    if (empty($data)) {
        throw new Exception("No input data received");
    }

    $maintenance_id = (int)($data['maintenance_id'] ?? 0);
    $maintenance_date = trim($data['maintenance_date'] ?? '');
    $start_time       = trim($data['start_time'] ?? '');
    $end_time         = trim($data['end_time'] ?? '');
    $description      = trim($data['description'] ?? '');
    $radius           = (int)($data['radius'] ?? 500);
    $manualStatus     = strtolower(trim($data['status'] ?? ''));

    if ($maintenance_id <= 0) {
        throw new Exception("Invalid maintenance ID");
    }

    $barangays = $data['barangays'] ?? null;
    if ($barangays === null) {
        $oldStmt = $conn->prepare("
            SELECT b.barangay_name
            FROM maintenance_locations ml
            JOIN barangays b ON b.id = ml.barangay_id
            WHERE ml.maintenance_id = :id
        ");
        $oldStmt->execute([":id" => $maintenance_id]);
        $barangays = $oldStmt->fetchAll(PDO::FETCH_COLUMN);
    }

    if (is_string($barangays)) {
        $decoded = json_decode($barangays, true);
        $barangays = (is_array($decoded)) ? $decoded : explode(",", $barangays);
    }

    if (!is_array($barangays)) {
        $barangays = [];
    }

    $barangays = array_values(array_filter(array_map('trim', $barangays)));

    if ($maintenance_date === '' || $start_time === '' || $end_time === '') {
        throw new Exception("Maintenance date, start time, end time are required");
    }

    /* Check record exists */
    $checkStmt = $conn->prepare("SELECT id FROM maintenance_schedules WHERE id = :id LIMIT 1");
    $checkStmt->execute([":id" => $maintenance_id]);
    if (!$checkStmt->fetch()) {
        throw new Exception("Maintenance record not found");
    }

    /* ============ STATUS LOGIC ============ */
    $validStatuses = ['upcoming', 'ongoing', 'completed', 'cancelled'];
    $now = new DateTime();

    try {
        $startDT = new DateTime("$maintenance_date $start_time");
        $endDT   = new DateTime("$maintenance_date $end_time");
    } catch (Exception $e) {
        throw new Exception("Invalid date/time values");
    }

    if (in_array($manualStatus, $validStatuses, true)) {
        $finalStatus = $manualStatus;
    } else {
        if ($now > $endDT) {
            $finalStatus = "completed";
        } elseif ($now >= $startDT && $now <= $endDT) {
            $finalStatus = "ongoing";
        } else {
            $finalStatus = "upcoming";
        }
    }

    /* Update schedule */
    $updateStmt = $conn->prepare("
        UPDATE maintenance_schedules
        SET maintenance_date = :date,
            start_time = :start,
            end_time = :end,
            description = :desc,
            radius = :radius,
            status = :status
        WHERE id = :id
    ");
    $updateStmt->execute([
        ":date" => $maintenance_date,
        ":start" => $start_time,
        ":end" => $end_time,
        ":desc" => $description,
        ":radius" => $radius,
        ":status" => $finalStatus,
        ":id" => $maintenance_id
    ]);

    /* Replace locations (barangay_id based) */
    $conn->prepare("DELETE FROM maintenance_locations WHERE maintenance_id = :id")
        ->execute([":id" => $maintenance_id]);

    $barangayCoords = [];
    $insertLocStmt = $conn->prepare("
        INSERT INTO maintenance_locations (maintenance_id, barangay_id, latitude, longitude)
        VALUES (:maintenance_id, :barangay_id, :lat, :lng)
    ");

    foreach ($barangays as $barangay) {
        if ($barangay === '') continue;
        $barangayId = resolveBarangay($conn, $barangay);
        if ($barangayId === null) continue;

        $lat = null;
        $lng = null;
        try {
            $geo = getCoordinates($barangay);
            if ($geo['success'] ?? false) {
                $lat = (float)$geo['latitude'];
                $lng = (float)$geo['longitude'];
            }
        } catch (Throwable $e) {
            $lat = null;
            $lng = null;
        }

        $barangayCoords[$barangay] = ['lat' => $lat, 'lng' => $lng];
        $insertLocStmt->execute([
            ":maintenance_id" => $maintenance_id,
            ":barangay_id" => $barangayId,
            ":lat" => $lat,
            ":lng" => $lng
        ]);
    }

    /* ============ NOTIFICATIONS ============ */
    $notified = 0;

    $companyNameStmt = $conn->prepare("SELECT CONCAT_WS(' ', first_name, middle_name, last_name) AS name FROM users WHERE id = ? LIMIT 1");
    $companyNameStmt->execute([$user['id']]);
    $companyRow = $companyNameStmt->fetch(PDO::FETCH_ASSOC);
    $company_name = trim($companyRow['name'] ?? 'Electric Company');

    if (!empty($barangayCoords)) {
        $userStmt = $conn->prepare("
            SELECT u.id, ul.latitude, ul.longitude
            FROM users u
            JOIN roles r ON r.id = u.role_id
            LEFT JOIN user_locations ul ON ul.user_id = u.id AND ul.is_primary = 1
            WHERE r.role_name = 'user'
        ");
        $userStmt->execute();
        $allUsers = $userStmt->fetchAll(PDO::FETCH_ASSOC);

        $formattedDate  = date("F d, Y", strtotime($maintenance_date));
        $formattedStart = date("h:i A", strtotime($start_time));
        $formattedEnd   = date("h:i A", strtotime($end_time));
        $allBarangays   = implode(", ", $barangays);

        $statusConfig = [
            'upcoming'  => ['title' => "Maintenance Update: Rescheduled", 'label' => "UPCOMING"],
            'ongoing'   => ['title' => "Power Maintenance Now Ongoing",   'label' => "NOW ONGOING"],
            'completed' => ['title' => "Power Restored – Maintenance Done", 'label' => "COMPLETED"],
            'cancelled' => ['title' => "Maintenance Cancelled",           'label' => "CANCELLED"],
        ];
        $cfg = $statusConfig[$finalStatus] ?? ['title' => "Maintenance Update", 'label' => strtoupper($finalStatus)];

        foreach ($allUsers as $u) {
            if (empty($u['latitude']) || empty($u['longitude'])) continue;

            $affected = [];
            foreach ($barangayCoords as $name => $coord) {
                if (empty($coord['lat']) || empty($coord['lng'])) continue;
                if (haversineDistanceMeters($coord['lat'], $coord['lng'], (float)$u['latitude'], (float)$u['longitude']) <= $radius) {
                    $affected[] = $name;
                }
            }

            $affectedList = !empty($affected) ? implode(", ", $affected) : null;
            $message = "{$cfg['label']} Maintenance\n\n"
                . "Affected Area: {$allBarangays}\n"
                . "Your Area: " . ($affectedList ?? $allBarangays) . "\n"
                . "Date: {$formattedDate}\n"
                . "Time: {$formattedStart} - {$formattedEnd}\n\n"
                . $company_name;

            createNotification($conn, [$u['id']], $cfg['title'], $message, "maintenance", $maintenance_id, "maintenance");
            $notified++;
        }
    }

    echo json_encode([
        "success" => true,
        "message" => "Maintenance updated successfully",
        "maintenance_id" => $maintenance_id,
        "status" => $finalStatus,
        "barangays" => $barangays,
        "users_notified" => $notified
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage(),
        "line" => $e->getLine(),
        "file" => basename($e->getFile())
    ]);
}
