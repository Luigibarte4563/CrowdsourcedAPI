<?php

header("Content-Type: application/json");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';

$conn = getConnection();

/* =========================================
   JWT AUTH
========================================= */
$user = getUserFromJWT();

if (!$user) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized (invalid JWT)"
    ]);
    exit;
}

$user_id = $user['id'];

/* =========================================
   INPUT JSON
========================================= */
$data = json_decode(file_get_contents("php://input"), true);

if (json_last_error() !== JSON_ERROR_NONE) {
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

/* =========================================
   SOFT DELETE (CANCEL REPORT)
========================================= */
try {

    // ensure report belongs to user AND is still active
    $stmt = $conn->prepare("
        UPDATE outage_reports
        SET 
            status = 'rejected',
            is_active = 0,
            resolution_note = 'Cancelled by user'
        WHERE id = :id
        AND user_id = :user_id
        AND status IN ('active','under_review')
    ");

    $stmt->execute([
        ":id" => $id,
        ":user_id" => $user_id
    ]);

    if ($stmt->rowCount() === 0) {

        echo json_encode([
            "success" => false,
            "message" => "Cannot cancel this report (already resolved or not found)"
        ]);
        exit;
    }

    echo json_encode([
        "success" => true,
        "message" => "Report cancelled successfully"
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database error"
    ]);
}