<?php

header("Content-Type: application/json");
session_start();

require_once __DIR__ . '/../../config/db_connect.php';

$conn = getConnection();

$user_id = $_SESSION['user']['id'] ?? null;

if (!$user_id) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

/* ================================
   SUPPORT SINGLE OR BATCH
================================ */

$notifications = $data["notifications"] ?? null;

/*
Example batch:
{
  "notifications": [
    {
      "user_id": 1,
      "title": "Maintenance",
      "message": "Power interruption"
    },
    {
      "user_id": 2,
      "title": "Maintenance",
      "message": "Power interruption"
    }
  ]
}
*/

try {

    $conn->beginTransaction();

    if ($notifications) {

        // BATCH INSERT
        $stmt = $conn->prepare("
            INSERT INTO notifications (user_id, title, message, type)
            VALUES (:user_id, :title, :message, :type)
        ");

        foreach ($notifications as $n) {

            $stmt->execute([
                ":user_id" => $n["user_id"],
                ":title"   => $n["title"],
                ":message" => $n["message"],
                ":type"    => $n["type"] ?? "maintenance"
            ]);
        }

    } else {

        // SINGLE INSERT
        $stmt = $conn->prepare("
            INSERT INTO notifications (user_id, title, message, type)
            VALUES (:user_id, :title, :message, :type)
        ");

        $stmt->execute([
            ":user_id" => $data["user_id"],
            ":title"   => $data["title"],
            ":message" => $data["message"],
            ":type"    => $data["type"] ?? "maintenance"
        ]);
    }

    $conn->commit();

    echo json_encode([
        "success" => true,
        "message" => "Notification(s) created"
    ]);

} catch (PDOException $e) {

    $conn->rollBack();

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database error"
    ]);
}