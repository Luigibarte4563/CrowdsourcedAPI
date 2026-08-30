<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';

ini_set('display_errors', 0);
error_reporting(E_ALL);

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $user = getUserFromJWT();
    if (!$user || !isset($user["id"])) {
        http_response_code(401);
        echo json_encode(["success" => false, "message" => "Unauthorized"]);
        exit;
    }
    $user_id = (int)$user["id"];

    $limit      = max(1, min((int)($_GET['limit'] ?? 50), 200));
    $offset     = max(0, (int)($_GET['offset'] ?? 0));
    $onlyUnread = (int)($_GET['unread'] ?? 0);

    $type           = $_GET['type'] ?? null;
    $maintenance_id = $_GET['maintenance_id'] ?? null;

    $sql = "
        SELECT
            n.id,
            n.user_id,
            n.title,
            n.message,
            nt.type_name AS type,
            n.is_read,
            n.outage_report_id,
            n.maintenance_id,
            n.flood_report_id,
            n.electrical_hazard_id,
            n.safety_timer_id,
            n.created_at
        FROM notifications n
        JOIN notification_types nt ON nt.id = n.notification_type_id
        WHERE n.user_id = :user_id
    ";

    $params = [":user_id" => $user_id];

    if ($onlyUnread === 1) {
        $sql .= " AND n.is_read = 0 ";
    }
    if (!empty($type)) {
        $sql .= " AND nt.type_name = :type ";
        $params[":type"] = $type;
    }
    if (!empty($maintenance_id)) {
        $sql .= " AND n.maintenance_id = :maintenance_id ";
        $params[":maintenance_id"] = $maintenance_id;
    }

    $sql .= " ORDER BY n.created_at DESC ";
    $sql .= " LIMIT :limit OFFSET :offset ";

    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(":limit", $limit, PDO::PARAM_INT);
    $stmt->bindValue(":offset", $offset, PDO::PARAM_INT);
    $stmt->execute();

    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $unreadStmt = $conn->prepare("
        SELECT COUNT(*) as unread_count
        FROM notifications
        WHERE user_id = :user_id AND is_read = 0
    ");
    $unreadStmt->execute([":user_id" => $user_id]);
    $unread = $unreadStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "total" => count($notifications),
        "unread_count" => (int)$unread['unread_count'],
        "limit" => $limit,
        "offset" => $offset,
        "data" => $notifications
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error"]);
}
