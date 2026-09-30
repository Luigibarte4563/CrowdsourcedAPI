<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../../auth/rbac.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$user_id = (int)requireAuthUser()['id'];

try {
    $stmt = $conn->prepare("
        SELECT id, device_name, device_type, capacity_mah, current_percentage, is_primary, created_at, updated_at
        FROM battery_devices
        WHERE user_id = :user_id
        ORDER BY is_primary DESC, id DESC
    ");
    $stmt->execute([":user_id" => $user_id]);
    $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($devices as &$device) {
        $device['estimated_hours_remaining'] = null;

        $logStmt = $conn->prepare("
            SELECT battery_percentage_start, battery_percentage_end, usage_minutes, estimated_watts, activity, logged_at
            FROM battery_usage_logs
            WHERE battery_device_id = :id
            ORDER BY logged_at DESC
            LIMIT 20
        ");
        $logStmt->execute([":id" => $device['id']]);
        $logs = $logStmt->fetchAll(PDO::FETCH_ASSOC);

        $device['recent_logs'] = $logs;

        /* Simple budgeting: average % consumed per hour from logs */
        $rateSum = 0.0;
        $rateCount = 0;
        foreach ($logs as $log) {
            if (!empty($log['usage_minutes']) && $log['usage_minutes'] > 0) {
                $drop = (float)$log['battery_percentage_start'] - (float)$log['battery_percentage_end'];
                if ($drop > 0) {
                    $hours = (int)$log['usage_minutes'] / 60;
                    $rateSum += $drop / $hours;
                    $rateCount++;
                }
            }
        }
        if ($rateCount > 0) {
            $avgRate = $rateSum / $rateCount;
            if ($avgRate > 0) {
                $device['estimated_hours_remaining'] = round((float)$device['current_percentage'] / $avgRate, 1);
                $device['estimated_usage_rate_per_hour'] = round($avgRate, 2);
            }
        }
    }
    unset($device);

    echo json_encode([
        "success" => true,
        "count" => count($devices),
        "data" => $devices
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error"]);
}
