<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';

/* ✅ NOTIFICATION SERVICE */
require_once __DIR__ . '/../services/create_notification.php';

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

    $companyStmt->execute([
        ":user_id" => $user['id']
    ]);

    $company = $companyStmt->fetch(PDO::FETCH_ASSOC);

    if (!$company) {

        http_response_code(404);

        echo json_encode([
            "success" => false,
            "message" => "Company not found"
        ]);

        exit;
    }

    $electric_company_id = (int)$company['id'];
    $company_name = $company['company_name'] ?? 'Electric Company';

    /* =========================================
       READ JSON INPUT
    ========================================= */
    $rawBody = file_get_contents("php://input");

    if (!$rawBody) {

        http_response_code(400);

        echo json_encode([
            "success" => false,
            "message" => "Empty request body"
        ]);

        exit;
    }

    $data = json_decode($rawBody, true);

    if (json_last_error() !== JSON_ERROR_NONE) {

        http_response_code(400);

        echo json_encode([
            "success" => false,
            "message" => "Invalid JSON",
            "json_error" => json_last_error_msg()
        ]);

        exit;
    }

    /* =========================================
       INPUT FIELDS
    ========================================= */
    $maintenance_date = trim($data['maintenance_date'] ?? '');
    $start_time       = trim($data['start_time'] ?? '');
    $end_time         = trim($data['end_time'] ?? '');
    $description      = trim($data['description'] ?? '');

    $barangays        = $data['barangays'] ?? [];
    $notify_all       = (bool)($data['notify_all'] ?? false);

    $radius           = (int)($data['radius'] ?? 2000);

    /* =========================================
       VALIDATION
    ========================================= */
    if (
        empty($maintenance_date) ||
        empty($start_time) ||
        empty($end_time)
    ) {

        http_response_code(400);

        echo json_encode([
            "success" => false,
            "message" => "Missing required fields"
        ]);

        exit;
    }

    if (!$notify_all && empty($barangays)) {

        http_response_code(400);

        echo json_encode([
            "success" => false,
            "message" => "Please select at least one barangay"
        ]);

        exit;
    }

    if (!is_array($barangays)) {
        $barangays = [];
    }

    /* =========================================
       BARANGAY MAP
    ========================================= */
    $barangay_map = [

        "Bonuan Gueset"    => [16.0585, 120.3345],
        "Bonuan Boquig"    => [16.0600, 120.3200],
        "Bonuan Binloc"    => [16.0620, 120.3100],
        "Lucao"            => [16.0435, 120.3310],
        "Tapuac"           => [16.0460, 120.3450],
        "Tambac"           => [16.0520, 120.3400],
        "Pantal"           => [16.0468, 120.3330],
        "Bacayao Norte"    => [16.0300, 120.3200],
        "Bacayao Sur"      => [16.0250, 120.3250],
        "Malued"           => [16.0400, 120.3200],
        "Mayombo"          => [16.0480, 120.3100],
        "Mangin"           => [16.0550, 120.3500],
        "Tebeng"           => [16.0600, 120.3450],
        "Pogo Chico"       => [16.0510, 120.3600],
        "Pogo Grande"      => [16.0550, 120.3650],
        "Herrero"          => [16.0450, 120.3350],
        "Poblacion Centro" => [16.0430, 120.3335],
        "Poblacion Oeste"  => [16.0410, 120.3300],
        "Poblacion Este"   => [16.0440, 120.3360]

    ];

    /* =========================================
       DEFAULT LOCATION
    ========================================= */
    $first_barangay = $barangays[0] ?? "Poblacion Centro";

    if (!isset($barangay_map[$first_barangay])) {

        http_response_code(400);

        echo json_encode([
            "success" => false,
            "message" => "Invalid barangay selected"
        ]);

        exit;
    }

    [$latitude, $longitude] = $barangay_map[$first_barangay];

    /* =========================================
       DUPLICATE CHECK
    ========================================= */
    $checkStmt = $conn->prepare("
        SELECT id
        FROM maintenance_schedules
        WHERE electric_company_id = :company_id
        AND maintenance_date = :maintenance_date
        AND affected_area = :affected_area
        LIMIT 1
    ");

    $checkStmt->execute([
        ":company_id"       => $electric_company_id,
        ":maintenance_date" => $maintenance_date,
        ":affected_area"    => $first_barangay
    ]);

    if ($checkStmt->fetch()) {

        http_response_code(409);

        echo json_encode([
            "success" => false,
            "message" => "Schedule already exists"
        ]);

        exit;
    }

    /* =========================================
       INSERT MAINTENANCE
    ========================================= */
    $insertStmt = $conn->prepare("
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

            :electric_company_id,
            :affected_area,
            :latitude,
            :longitude,
            :maintenance_date,
            :start_time,
            :end_time,
            :description,
            :radius,
            :affected_barangays
        )
    ");

    $insertStmt->execute([

        ":electric_company_id" => $electric_company_id,
        ":affected_area"       => $notify_all
            ? "ALL AREAS"
            : $first_barangay,

        ":latitude"            => $latitude,
        ":longitude"           => $longitude,
        ":maintenance_date"    => $maintenance_date,
        ":start_time"          => $start_time,
        ":end_time"            => $end_time,
        ":description"         => $description,
        ":radius"              => $radius,

        ":affected_barangays"  => json_encode(
            $notify_all ? ["ALL"] : $barangays
        )
    ]);

    $maintenance_id = (int)$conn->lastInsertId();

    /* =========================================
       GET USERS
    ========================================= */
    if ($notify_all) {

        $userStmt = $conn->prepare("
            SELECT id
            FROM users
        ");

        $userStmt->execute();

    } else {

        $placeholders = implode(
            ',',
            array_fill(0, count($barangays), '?')
        );

        $userStmt = $conn->prepare("
            SELECT id
            FROM users
            WHERE barangay IN ($placeholders)
        ");

        $userStmt->execute($barangays);
    }

    $users = $userStmt->fetchAll(PDO::FETCH_COLUMN);

    /* =========================================
       CREATE NOTIFICATIONS
    ========================================= */
    if (!empty($users)) {

        $title = "Scheduled Power Maintenance";

        if ($notify_all) {

            $message = "{$company_name} scheduled a maintenance on {$maintenance_date} from {$start_time} to {$end_time}.";

        } else {

            $message = "Power interruption in {$first_barangay} on {$maintenance_date} from {$start_time} to {$end_time}.";
        }

        createNotification(
            $conn,
            $users,
            $title,
            $message,
            "maintenance",
            $maintenance_id,
            "maintenance"
        );
    }

    /* =========================================
       SUCCESS RESPONSE
    ========================================= */
    echo json_encode([

        "success" => true,
        "message" => "Maintenance created successfully",

        "maintenance_id" => $maintenance_id,

        "users_notified" => count($users),

        "notify_all" => $notify_all

    ]);

} catch (Throwable $e) {

    http_response_code(500);

    error_log("Maintenance Create Error: " . $e->getMessage());

    echo json_encode([
        "success" => false,
        "message" => "Server error",
        "error" => $e->getMessage()
    ]);
}

exit;
?>