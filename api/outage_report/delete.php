<?php

header("Content-Type: application/json");

require_once __DIR__ . '/../../config/db_connect.php';

$conn = getConnection();

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
$user_id = (int)($data["user_id"] ?? 0);

/* =========================================
   VALIDATION
========================================= */
if ($id <= 0 || $user_id <= 0) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Valid id and user_id are required"
    ]);
    exit;
}

try {

    /* =========================================
       HARD DELETE (PERMANENT REMOVE)
    ========================================= */
    $stmt = $conn->prepare("
        DELETE FROM outage_reports
        WHERE id = :id
        AND user_id = :user_id
    ");

    $stmt->execute([
        ":id" => $id,
        ":user_id" => $user_id
    ]);

    $affected = $stmt->rowCount();

    if ($affected === 0) {
        echo json_encode([
            "success" => false,
            "message" => "No record found or unauthorized"
        ]);
        exit;
    }

    echo json_encode([
        "success" => true,
        "message" => "Report permanently deleted",
        "deleted_rows" => $affected
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database error",
        "error" => $e->getMessage()
    ]);
}