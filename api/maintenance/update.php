<?php

header("Content-Type: application/json; charset=UTF-8");

error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../../auth/rbac.php';
require_once __DIR__ . '/../services/create_notification.php';
require_once __DIR__ . '/../services/lookup.php';
require_once __DIR__ . '/../services/maintenance_notice.php';

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
    $insertLoc = $conn->prepare("
        INSERT INTO maintenance_locations (maintenance_id, barangay_id, latitude, longitude)
        VALUES (:maintenance_id, :barangay_id, :lat, :lng)
    ");

/* Resolve every chosen barangay up front, so a typo fails loudly instead of
       silently dropping that barangay from the affected area, and so a known
       barangay with no coordinates is reported rather than stored as a point
       nothing can draw. */
    $resolvedLocations = [];
    $missingCoords = [];

    foreach ($barangays as $chosen) {
        $chosen = trim((string)$chosen);
        if ($chosen === '') continue;

        $barangay = resolveBarangayCoords($conn, $chosen);

        if (!$barangay) {
            $existsStmt = $conn->prepare("SELECT id FROM barangays WHERE barangay_name = ? LIMIT 1");
            $existsStmt->execute([$chosen]);

            if ($existsStmt->fetchColumn()) {
                $missingCoords[] = $chosen;
            } else {
                http_response_code(400);
                echo json_encode([
                    "success" => false,
                    "message" => "Unknown barangay: $chosen",
                    "field" => "barangays"
                ]);
                exit;
            }
            continue;
        }

        $resolvedLocations[$chosen] = $barangay;
    }

    if ($missingCoords) {
        http_response_code(503);
        echo json_encode([
            "success" => false,
            "message" => "No map coordinates yet for: " . implode(", ", $missingCoords) . ". The maintenance map cannot be drawn until these are added.",
            "field" => "barangays",
            "barangays_without_coordinates" => barangaysMissingCoordinates($conn)
        ]);
        exit;
    }

    foreach ($resolvedLocations as $chosen => $barangay) {
        $barangayCoords[$chosen] = [
            'lat' => $barangay["latitude"],
            'lng' => $barangay["longitude"]
        ];

        $insertLoc->execute([
            ":maintenance_id" => $maintenance_id,
            ":barangay_id" => $barangay["id"],
            ":lat" => $barangay["latitude"],
            ":lng" => $barangay["longitude"]
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
            SELECT u.id, ul.latitude, ul.longitude, b.barangay_name AS user_barangay
            FROM users u
            JOIN roles r ON r.id = u.role_id
            LEFT JOIN user_locations ul ON ul.user_id = u.id AND ul.is_primary = 1
            LEFT JOIN barangays b ON b.id = ul.barangay_id
            WHERE r.role_name = 'user'
        ");
        $userStmt->execute();
        $allUsers = $userStmt->fetchAll(PDO::FETCH_ASSOC);

        $formattedDate  = date("F d, Y", strtotime($maintenance_date));
        $formattedStart = date("h:i A", strtotime($start_time));
        $formattedEnd   = date("h:i A", strtotime($end_time));

        /* Lower-cased lookup so a resident whose saved barangay is on the schedule
           counts as affected even when their pin sits farther than `radius` from the
           barangay's centroid. */
        $barangayByLower = [];
        foreach (array_keys($barangayCoords) as $name) {
            $barangayByLower[strtolower($name)] = $name;
        }

        foreach ($allUsers as $u) {
            if (empty($u['latitude']) || empty($u['longitude'])) continue;

            $affected = [];
            foreach ($barangayCoords as $name => $coord) {
                if (empty($coord['lat']) || empty($coord['lng'])) continue;
                if (haversineDistanceMeters($coord['lat'], $coord['lng'], (float)$u['latitude'], (float)$u['longitude']) <= $radius) {
                    $affected[] = $name;
                }
            }

            $userBarangay = trim((string)($u['user_barangay'] ?? ''));
            if ($userBarangay !== '' && isset($barangayByLower[strtolower($userBarangay)])) {
                $affected[] = $barangayByLower[strtolower($userBarangay)];
            }
            $affected = array_values(array_unique($affected));

            $copy = maintenanceNotificationCopy(
                $finalStatus,
                $barangays,
                $affected,
                $userBarangay !== '' ? $userBarangay : null,
                $formattedDate,
                "{$formattedStart} - {$formattedEnd}",
                $description !== '' ? $description : null,
                $company_name
            );

            createNotification($conn, [$u['id']], $copy['title'], $copy['message'], "maintenance", $maintenance_id, "maintenance");
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
