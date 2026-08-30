<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$user = getUserFromJWT();
if (!$user || !isset($user['id'])) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit;
}

try {
    $tables = [
        'roles'              => "SELECT id, role_name, description FROM roles ORDER BY id",
        'barangays'          => "SELECT id, barangay_name, city, province, latitude, longitude FROM barangays ORDER BY barangay_name",
        'outage_categories'  => "SELECT id, category_name, description FROM outage_categories",
        'severity_levels'    => "SELECT id, severity_name, priority FROM severity_levels",
        'hazard_types'       => "SELECT id, hazard_name, description FROM hazard_types",
        'outage_statuses'    => "SELECT id, status_name, description FROM outage_statuses",
        'power_station_types'=> "SELECT id, type_name FROM power_station_types",
        'safety_timer_types' => "SELECT id, timer_name, default_duration_hours, warning_hours_before, description FROM safety_timer_types",
        'notification_types' => "SELECT id, type_name FROM notification_types"
    ];

    $result = [];

    foreach ($tables as $key => $sql) {
        $stmt = $conn->query($sql);
        $result[$key] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode([
        "success" => true,
        "data" => $result
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error"]);
}
