<?php

require_once __DIR__ . '/../../config/cors.php';

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
        'roles'              => "SELECT 'user' AS role_name UNION ALL SELECT 'lineman' UNION ALL SELECT 'electric_company' UNION ALL SELECT 'admin'",
        'barangays'          => "SELECT id, barangay_name, city, province, latitude, longitude FROM barangays ORDER BY barangay_name",
        'outage_categories'  => "SELECT 'power_outage' AS category_name UNION ALL SELECT 'low_voltage' UNION ALL SELECT 'power_fluctuation' UNION ALL SELECT 'transformer_explosion' UNION ALL SELECT 'fallen_power_line' UNION ALL SELECT 'electrical_fire' UNION ALL SELECT 'scheduled_maintenance' UNION ALL SELECT 'unknown_issue'",
        'severity_levels'    => "SELECT 'low' AS severity_name, 1 AS priority UNION ALL SELECT 'moderate', 2 UNION ALL SELECT 'high', 3 UNION ALL SELECT 'critical', 4",
        'hazard_types'       => "SELECT 'none' AS hazard_name UNION ALL SELECT 'smoke' UNION ALL SELECT 'sparks' UNION ALL SELECT 'fire' UNION ALL SELECT 'fallen_wire' UNION ALL SELECT 'explosion_sound' UNION ALL SELECT 'submerged_electrical_equipment' UNION ALL SELECT 'low_hanging_cable' UNION ALL SELECT 'exposed_wire' UNION ALL SELECT 'electrical_shock_risk'",
        'outage_statuses'    => "SELECT 'active' AS status_name, 'Currently active report' AS description UNION ALL SELECT 'under_review', 'Being reviewed' UNION ALL SELECT 'verified', 'Verified by authorized personnel' UNION ALL SELECT 'resolved', 'Issue has been resolved' UNION ALL SELECT 'rejected', 'Report was rejected'",
        'power_station_types'=> "SELECT 'power_station' AS type_name UNION ALL SELECT 'solar_station' UNION ALL SELECT 'charging_station' UNION ALL SELECT 'generator_station'",
        'safety_timer_types' => "SELECT 'sealed_refrigerator' AS timer_name, 4 AS default_duration_hours, 1 AS warning_hours_before, 'Food safety timer for a sealed refrigerator' AS description UNION ALL SELECT 'deep_freezer_24h', 24, 4, 'Conservative deep freezer safety timer' UNION ALL SELECT 'deep_freezer_48h', 48, 6, 'Extended deep freezer safety timer' UNION ALL SELECT 'medication', 4, 1, 'Medication temperature safety timer'",
        'notification_types' => "SELECT 'maintenance' AS type_name UNION ALL SELECT 'outage' UNION ALL SELECT 'emergency' UNION ALL SELECT 'flood' UNION ALL SELECT 'electrical_hazard' UNION ALL SELECT 'battery' UNION ALL SELECT 'safety_timer' UNION ALL SELECT 'system'"
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
