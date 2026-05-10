<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';

/* SERVICES */
require_once __DIR__ . '/../services/create_notification.php';
require_once __DIR__ . '/../services/get_coordinates.php';

ini_set('display_errors', 0);
error_reporting(E_ALL);

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {

    /* =========================================
       AUTH
    ========================================= */
    $user = getUserFromJWT();

    if (!$user) {
        http_response_code(401);
        echo json_encode([
            "success" => false,
            "message" => "Unauthorized"
        ]);
        exit;
    }

    if (($user['role'] ?? '') !== 'electric_company') {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "Forbidden"
        ]);
        exit;
    }

    /* =========================================
       GET COMPANY
    ========================================= */
    $companyStmt = $conn->prepare("
        SELECT id, company_name
        FROM electric_companies
        WHERE user_id = :user_id
        LIMIT 1
    ");

    $companyStmt->execute([":user_id" => $user['id']]);

    $company = $companyStmt->fetch(PDO::FETCH_ASSOC);

    if (!$company) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Company not found"
        ]);
        exit;
    }

    $company_name = $company['company_name'] ?? 'Electric Company';

    /* =========================================
       INPUT
    ========================================= */
    $data = json_decode(file_get_contents("php://input"), true);

    $maintenance_date = trim($data['maintenance_date'] ?? '');
    $start_time       = trim($data['start_time'] ?? '');
    $end_time         = trim($data['end_time'] ?? '');
    $description      = trim($data['description'] ?? '');
    $location_name    = trim($data['location_name'] ?? '');
    $notify_all       = (bool)($data['notify_all'] ?? false);
    $radius           = (int)($data['radius'] ?? 2000);

    if (empty($maintenance_date) || empty($start_time) || empty($end_time)) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Missing required fields"
        ]);
        exit;
    }

    if (!$notify_all && empty($location_name)) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "location_name is required"
        ]);
        exit;
    }

    /* =========================================
       GET LOCATION
    ========================================= */
    if ($notify_all) {

        $location  = "ALL AREAS";
        $latitude  = 16.0430;
        $longitude = 120.3335;

    } else {

        $geo = getCoordinates($location_name);

        if (!$geo["success"]) {
            http_response_code(404);
            echo json_encode([
                "success" => false,
                "message" => $geo["message"]
            ]);
            exit;
        }

        $location  = $geo["location_name"];
        $latitude  = $geo["latitude"];
        $longitude = $geo["longitude"];
    }

    /* =========================================
       INSERT MAINTENANCE
    ========================================= */
    $stmt = $conn->prepare("
        INSERT INTO maintenance_schedules (
            electric_company_id,
            affected_area,
            latitude,
            longitude,
            maintenance_date,
            start_time,
            end_time,
            description,
            radius,
            affected_barangays
        ) VALUES (
            :company_id,
            :area,
            :lat,
            :lng,
            :date,
            :start,
            :end,
            :desc,
            :radius,
            :barangays
        )
    ");

    $stmt->execute([
        ":company_id" => $company['id'],
        ":area" => $location,
        ":lat" => $latitude,
        ":lng" => $longitude,
        ":date" => $maintenance_date,
        ":start" => $start_time,
        ":end" => $end_time,
        ":desc" => $description,
        ":radius" => $radius,
        ":barangays" => json_encode($notify_all ? ["ALL"] : [$location])
    ]);

    $maintenance_id = $conn->lastInsertId();

    /* =========================================
       FORMAT TIME
    ========================================= */
    $formattedDate  = date("F d, Y", strtotime($maintenance_date));
    $formattedStart = date("h:i A", strtotime($start_time));
    $formattedEnd   = date("h:i A", strtotime($end_time));

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

    /* =========================================
       DISTANCE FUNCTION
    ========================================= */
    function haversineDistance($lat1, $lon1, $lat2, $lon2)
    {
        $earthRadius = 6371000;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a =
            sin($dLat / 2) ** 2 +
            cos(deg2rad($lat1)) *
            cos(deg2rad($lat2)) *
            sin($dLon / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /* =========================================
       NOTIFY USERS (FIXED)
    ========================================= */
    foreach ($users as $u) {

        $userId = $u['id'];
        $isAffected = false;

        if (!$notify_all && $u['latitude'] && $u['longitude']) {

            $distance = haversineDistance(
                $latitude,
                $longitude,
                $u['latitude'],
                $u['longitude']
            );

            if ($distance <= $radius) {
                $isAffected = true;
            }
        }

        $title = "Scheduled Power Maintenance";

        if ($isAffected || $notify_all) {

            $message = "
⚠ Your area is affected by scheduled maintenance.

📍 Location: {$location}
📅 Date: {$formattedDate}
🕒 Time: {$formattedStart} - {$formattedEnd}

⚡ {$company_name}
";

        } else {

            $message = "
ℹ Maintenance notice for {$location}.

📅 Date: {$formattedDate}
🕒 Time: {$formattedStart} - {$formattedEnd}

Your area is not directly affected.
";
        }

        createNotification(
            $conn,
            [$userId],
            $title,
            trim($message),
            "maintenance",
            $maintenance_id,
            "maintenance"
        );
    }

    /* =========================================
       RESPONSE
    ========================================= */
    echo json_encode([
        "success" => true,
        "message" => "Maintenance created successfully",
        "maintenance_id" => $maintenance_id,
        "location" => $location,
        "users_notified" => count($users)
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Server error",
        "error" => $e->getMessage()
    ]);
}

exit;
?>