<?php

header("Content-Type: application/json");

session_start();

require_once __DIR__ . '/../../config/db_connect.php';

$conn = getConnection();

/* =========================================
   GET USER FROM SESSION (IMPORTANT FIX)
========================================= */
$user_id = $_SESSION['user']['id'] ?? null;

if (!$user_id) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized"
    ]);
    exit;
}

/* =========================================
   INPUT JSON
========================================= */
$data = json_decode(file_get_contents("php://input"), true);

if (!$data) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Invalid JSON body"
    ]);
    exit;
}

/* =========================================
   REQUIRED INPUT
========================================= */
$id = (int)($data["id"] ?? 0);

if ($id <= 0) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Valid id required"
    ]);
    exit;
}

try {

    $stmt = $conn->prepare("
        DELETE FROM outage_reports
        WHERE id = :id
        AND user_id = :user_id
    ");

    $stmt->execute([
        ":id" => $id,
        ":user_id" => $user_id
    ]);

    if ($stmt->rowCount() === 0) {
        echo json_encode([
            "success" => false,
            "message" => "No record found or unauthorized"
        ]);
        exit;
    }

    echo json_encode([
        "success" => true,
        "message" => "Report deleted successfully"
    ]);

} catch (PDOException $e) {

    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error"
    ]);
}