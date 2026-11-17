<?php

header("Content-Type: application/json");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../../auth/rbac.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* Staff-only: electric_company / admin can create notifications */
$user = requireRole(requireAuthUser(), ['electric_company', 'admin']);

$data = json_decode(file_get_contents("php://input"), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid JSON"]);
    exit;
}

$notifications = $data["notifications"] ?? null;

if (!$notifications && !isset($data["user_id"], $data["title"], $data["message"])) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Missing required fields"]);
    exit;
}

try {
    $conn->beginTransaction();

    $stmt = $conn->prepare("
        INSERT INTO notifications
            (user_id, notification_type, title, message)
        VALUES (:user_id, :type, :title, :message)
    ");

    $count = 0;

    $insertOne = function ($userId, $title, $message, $type) use ($conn, $stmt, &$count) {
        $type = getNotificationType($type);
        if (!$type) return;

        $stmt->execute([
            ":user_id" => (int)$userId,
            ":type" => $type,
            ":title" => $title,
            ":message" => $message
        ]);
        $count++;
    };

    if ($notifications) {
        foreach ($notifications as $n) {
            if (!isset($n["user_id"], $n["title"], $n["message"])) {
                continue;
            }
            $insertOne($n["user_id"], $n["title"], $n["message"], $n["type"] ?? "maintenance");
        }
    } else {
        $insertOne($data["user_id"], $data["title"], $data["message"], $data["type"] ?? "maintenance");
    }

    $conn->commit();

    echo json_encode([
        "success" => true,
        "message" => "Notification(s) created",
        "created" => $count
    ]);
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
