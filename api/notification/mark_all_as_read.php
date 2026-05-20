<?php

header("Content-Type: application/json");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';

$conn = getConnection();

/* =========================
   JWT AUTH
========================= */
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

try {

    $stmt = $conn->prepare("
        UPDATE notifications
        SET is_read = 1
        WHERE user_id = :user_id AND is_read = 0
    ");

    $stmt->execute([
        ":user_id" => $user_id
    ]);

    echo json_encode([
        "success" => true,
        "message" => "All notifications marked as read",
        "updated" => $stmt->rowCount()
    ]);

} catch (PDOException $e) {

    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error"
    ]);
}