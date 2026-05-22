<?php

header("Content-Type: application/json; charset=UTF-8");
header("Accept: application/json");

/* =========================================
   INPUT HANDLING (ROBUST & SAFE)
========================================= */
$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);

/* fallback for form-data or empty request */
if (!is_array($data)) {
    if (!empty($_POST)) {
        $data = $_POST;
    } else {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Invalid JSON body",
            "debug" => $rawInput
        ]);
        exit;
    }
}

/* =========================================
   VALIDATION
========================================= */
$id = isset($data["id"]) ? (int)$data["id"] : 0;

if ($id <= 0) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Valid report id is required"
    ]);
    exit;
}

/* =========================================
   LOAD DEPENDENCIES (AFTER INPUT)
========================================= */
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';

/* =========================================
   DB CONNECTION
========================================= */
try {
    $conn = getConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database connection failed"
    ]);
    exit;
}

/* =========================================
   JWT AUTH
========================================= */
try {
    $user = getUserFromJWT();
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "message" => "Invalid token"
    ]);
    exit;
}

if (!$user) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized"
    ]);
    exit;
}

$user_id = $user['id'];

/* =========================================
   SOFT DELETE QUERY
========================================= */
try {

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

        http_response_code(404);

        echo json_encode([
            "success" => false,
            "message" => "Report not found or already processed"
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
        // "error" => $e->getMessage() // enable for debugging only
    ]);
}