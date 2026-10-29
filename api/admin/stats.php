<?php

require_once __DIR__ . '/../../config/cors.php';

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../../auth/rbac.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

requireRole(requireAuthUser(), ['admin']);

/*
 * Platform analytics in one payload, admin-only.
 *
 * Every figure is an aggregate the existing endpoints cannot answer: the staff
 * overview counts reports, but nothing in the API counts USERS, and no endpoint
 * groups by day or barangay. Group rows are normalised to { label, count } so the
 * frontend reads them all the same way.
 */

function scalarCount(PDO $conn, $sql, array $params = []) {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function groupCount(PDO $conn, $sql, array $params = []) {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[] = ["label" => (string)$row['label'], "count" => (int)$row['count']];
    }
    return $rows;
}

try {
    /* Reports per day for the last 30 days, zero-filled. */
    $trendRows = [];
    $stmt = $conn->prepare("
        SELECT DATE(created_at) AS day, COUNT(*) AS total
        FROM outage_reports
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
        GROUP BY DATE(created_at)
    ");
    $stmt->execute();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $trendRows[$row['day']] = (int)$row['total'];
    }
    $trend = [];
    for ($i = 29; $i >= 0; $i--) {
        $day = date('Y-m-d', strtotime("-$i days"));
        $trend[] = ["date" => $day, "count" => $trendRows[$day] ?? 0];
    }

    echo json_encode([
        "success" => true,
        "message" => "Platform stats fetched successfully",
        "data" => [
            "users" => [
                "total"            => scalarCount($conn, "SELECT COUNT(*) FROM users"),
                "new_last_30_days" => scalarCount(
                    $conn,
                    "SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
                ),
                "by_role" => groupCount(
                    $conn,
                    "SELECT r.role_name AS label, COUNT(*) AS `count`
                     FROM users u JOIN roles r ON r.id = u.role_id
                     GROUP BY r.role_name ORDER BY `count` DESC"
                ),
            ],
            "outages" => [
                "total"  => scalarCount($conn, "SELECT COUNT(*) FROM outage_reports"),
                "active" => scalarCount($conn, "SELECT COUNT(*) FROM outage_reports WHERE is_active = 1"),
                "by_status" => groupCount(
                    $conn,
                    "SELECT s.status_name AS label, COUNT(*) AS `count`
                     FROM outage_reports o JOIN outage_statuses s ON s.id = o.status_id
                     GROUP BY s.status_name ORDER BY `count` DESC"
                ),
                "by_severity" => groupCount(
                    $conn,
                    "SELECT v.severity_name AS label, COUNT(*) AS `count`
                     FROM outage_reports o JOIN severity_levels v ON v.id = o.severity_id
                     GROUP BY v.severity_name ORDER BY `count` DESC"
                ),
            ],
            "floods" => [
                "total"      => scalarCount($conn, "SELECT COUNT(*) FROM flood_reports"),
                "unresolved" => scalarCount($conn, "SELECT COUNT(*) FROM flood_reports WHERE status <> 'cleared'"),
                "by_level" => groupCount(
                    $conn,
                    "SELECT flood_level AS label, COUNT(*) AS `count`
                     FROM flood_reports GROUP BY flood_level ORDER BY `count` DESC"
                ),
            ],
            "hazards" => [
                "total"      => scalarCount($conn, "SELECT COUNT(*) FROM electrical_hazards"),
                "unresolved" => scalarCount($conn, "SELECT COUNT(*) FROM electrical_hazards WHERE status <> 'resolved'"),
                "by_severity" => groupCount(
                    $conn,
                    "SELECT severity AS label, COUNT(*) AS `count`
                     FROM electrical_hazards GROUP BY severity ORDER BY `count` DESC"
                ),
            ],
            "maintenance" => [
                "total" => scalarCount($conn, "SELECT COUNT(*) FROM maintenance_schedules"),
                "by_status" => groupCount(
                    $conn,
                    "SELECT status AS label, COUNT(*) AS `count`
                     FROM maintenance_schedules GROUP BY status ORDER BY `count` DESC"
                ),
            ],
            "power_stations" => [
                "total"     => scalarCount($conn, "SELECT COUNT(*) FROM power_stations"),
                "available" => scalarCount(
                    $conn,
                    "SELECT COUNT(*) FROM power_stations WHERE availability_status = 'available'"
                ),
            ],
            "notifications" => [
                "total" => scalarCount($conn, "SELECT COUNT(*) FROM notifications"),
            ],
            "trend" => $trend,
            "top_barangays" => groupCount(
                $conn,
                "SELECT b.barangay_name AS label, COUNT(*) AS `count`
                 FROM outage_reports o JOIN barangays b ON b.id = o.barangay_id
                 GROUP BY b.barangay_name ORDER BY `count` DESC LIMIT 10"
            ),
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error"]);
}
