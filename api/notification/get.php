<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';

ini_set('display_errors', 0);
error_reporting(E_ALL);

$conn = getConnection();

try {

    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    /* =========================================
       AUTH
    ========================================= */
    $user = getUserFromJWT();

    if (!$user || !isset($user["id"])) {

        http_response_code(401);
        echo json_encode([
            "success" => false,
            "message" => "Unauthorized"
        ]);
        exit;
    }

    $user_id = (int)$user["id"];

    /* =========================================
       QUERY PARAMS
    ========================================= */
    $limit      = max(1, min((int)($_GET['limit'] ?? 50), 200));
    $offset     = max(0, (int)($_GET['offset'] ?? 0));
    $onlyUnread = (int)($_GET['unread'] ?? 0);

    $type            = $_GET['type'] ?? null;
    $source_type     = $_GET['source_type'] ?? null;
    $maintenance_id  = $_GET['maintenance_id'] ?? null;

    /* =========================================
       BASE QUERY
    ========================================= */
    $sql = "
        SELECT
            id,
            user_id,
            title,
            message,
            type,
            is_read,
            maintenance_id,
            source_type,
            location,
            created_at
        FROM notifications
        WHERE user_id = :user_id
    ";

    $params = [
        ":user_id" => $user_id
    ];

    /* =========================================
       FILTER: UNREAD
    ========================================= */
    if ($onlyUnread === 1) {
        $sql .= " AND is_read = 0 ";
    }

    /* =========================================
       FILTER: TYPE
    ========================================= */
    if (!empty($type)) {
        $sql .= " AND type = :type ";
        $params[":type"] = $type;
    }

    /* =========================================
       FILTER: SOURCE TYPE
    ========================================= */
    if (!empty($source_type)) {
        $sql .= " AND source_type = :source_type ";
        $params[":source_type"] = $source_type;
    }

    /* =========================================
       FILTER: MAINTENANCE ID
    ========================================= */
    if (!empty($maintenance_id)) {
        $sql .= " AND maintenance_id = :maintenance_id ";
        $params[":maintenance_id"] = $maintenance_id;
    }

    /* =========================================
       ORDER + PAGINATION
    ========================================= */
    $sql .= " ORDER BY created_at DESC ";
    $sql .= " LIMIT $limit OFFSET $offset ";

    $stmt = $conn->prepare($sql);

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    $stmt->execute();

    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* =========================================
       UNREAD COUNT
    ========================================= */
    $unreadStmt = $conn->prepare("
        SELECT COUNT(*) as unread_count
        FROM notifications
        WHERE user_id = :user_id
        AND is_read = 0
    ");

    $unreadStmt->execute([
        ":user_id" => $user_id
    ]);

    $unread = $unreadStmt->fetch(PDO::FETCH_ASSOC);

    /* =========================================
       RESPONSE (CLEAN FOR FRONTEND)
    ========================================= */
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

    error_log("Get Notifications Error: " . $e->getMessage());

    echo json_encode([
        "success" => false,
        "message" => "Server error",
        "error" => $e->getMessage()
    ]);
}

exit;
?>