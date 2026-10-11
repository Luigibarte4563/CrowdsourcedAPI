<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../../auth/rbac.php';
require_once __DIR__ . '/../services/create_notification.php';
require_once __DIR__ . '/../services/lookup.php';
require_once __DIR__ . '/../services/maintenance_notice.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$user = requireRole(requireAuthUser(), ['electric_company', 'admin']);

$company_id = (int)$user['id'];

/* =========================================
   INPUT
========================================= */
$data = json_decode(file_get_contents("php://input"), true);
if (!is_array($data)) {
    $data = $_POST;
}

$maintenance_date = trim($data['maintenance_date'] ?? '');
$start_time       = trim($data['start_time'] ?? '');
$end_time         = trim($data['end_time'] ?? '');
$description      = trim($data['description'] ?? '');
$radius           = (int)($data['radius'] ?? 2000);
$barangays        = $data['barangays'] ?? [];

if ($maintenance_date === '' || $start_time === '' || $end_time === '') {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Missing required fields"]);
    exit;
}

if (!is_array($barangays) || empty($barangays)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "No barangays selected"]);
    exit;
}

$barangays = array_values(array_filter(array_map('trim', $barangays)));

try {
    /* =========================================
       DUPLICATE CHECK (by date + barangay)
    ========================================= */
    $check = $conn->prepare("
        SELECT ml.barangay_id
        FROM maintenance_schedules ms
        JOIN maintenance_locations ml ON ms.id = ml.maintenance_id
        WHERE ms.status IN ('upcoming','ongoing')
          AND ms.maintenance_date = :date
    ");
    $check->execute([":date" => $maintenance_date]);
    $existingBarangayIds = array_column($check->fetchAll(PDO::FETCH_ASSOC), 'barangay_id');

    $blocked = [];
    foreach ($barangays as $b) {
        $bid = findBarangayId($conn, $b);
        if ($bid !== null && in_array($bid, $existingBarangayIds, true)) {
            $blocked[] = $b;
        }
    }

    if (!empty($blocked)) {
        http_response_code(409);
        echo json_encode(["success" => false, "message" => "Maintenance already exists for: " . implode(", ", array_unique($blocked))]);
        exit;
    }

    /* =========================================
       INSERT MAINTENANCE
    ========================================= */
    $insert = $conn->prepare("
        INSERT INTO maintenance_schedules (
            created_by,
            maintenance_date,
            start_time,
            end_time,
            radius,
            description,
            status
        ) VALUES (:company_id, :date, :start, :end, :radius, :desc, 'upcoming')
    ");
    $insert->execute([
        ":company_id" => $company_id,
        ":date" => $maintenance_date,
        ":start" => $start_time,
        ":end" => $end_time,
        ":radius" => $radius,
        ":desc" => $description !== '' ? $description : null
    ]);
    $maintenance_id = (int)$conn->lastInsertId();

    /* =========================================
       INSERT LOCATIONS (barangay_id based)
    ========================================= */
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

    /* =========================================
       NOTIFY RESIDENTS (message says whether they are in range)
    ========================================= */
    $notified = 0;

    $userStmt = $conn->prepare("
        SELECT u.id, ul.latitude, ul.longitude, b.barangay_name AS user_barangay
        FROM users u
        JOIN roles r ON r.id = u.role_id
        LEFT JOIN user_locations ul ON ul.user_id = u.id AND ul.is_primary = 1
        LEFT JOIN barangays b ON b.id = ul.barangay_id
        WHERE r.role_name = 'user'
    ");
    $userStmt->execute();
    $users = $userStmt->fetchAll(PDO::FETCH_ASSOC);

    $companyNameStmt = $conn->prepare("SELECT CONCAT_WS(' ', first_name, middle_name, last_name) AS name FROM users WHERE id = ? LIMIT 1");
    $companyNameStmt->execute([$company_id]);
    $companyRow = $companyNameStmt->fetch(PDO::FETCH_ASSOC);
    $company_name = trim($companyRow['name'] ?? 'Electric Company');

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

    foreach ($users as $u) {
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
            'scheduled',
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

    echo json_encode([
        "success" => true,
        "message" => "Maintenance created successfully",
        "maintenance_id" => $maintenance_id,
        "barangays" => $barangays,
        "users_notified" => $notified
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
