<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';

/* =========================================
   SAFE DB CONNECTION
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
   SAFE JWT AUTH
========================================= */
try {
    $user = getUserFromJWT();
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "message" => "Invalid or missing token"
    ]);
    exit;
}

if (!$user || !isset($user['id'])) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized"
    ]);
    exit;
}

$user_id = (int)$user['id'];

try {

    /* =========================================
       GET USER REPORTS
    ========================================= */
    $stmt = $conn->prepare("
        SELECT 
            id,
            report_key,
            location_name,
            latitude,
            longitude,
            category,
            severity,
            description,
            image_proof,
            affected_houses,
            status,
            is_active,
            hazard_type,
            started_at,
            verified_at,
            resolved_at,
            resolution_note,
            created_at
        FROM outage_reports
        WHERE user_id = :user_id
          AND status != 'rejected'
        ORDER BY created_at DESC
    ");

    $stmt->execute([
        ":user_id" => $user_id
    ]);

    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "count" => count($reports),
        "data" => $reports
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database query error",
        "error" => $e->getMessage() // TEMP DEBUG ONLY
    ]);
}