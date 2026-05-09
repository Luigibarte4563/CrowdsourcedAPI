<?php

header("Content-Type: application/json; charset=UTF-8");

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

try {

    /* =========================================
       FETCH USER REPORTS
       - excludes cancelled/rejected reports
    ========================================= */
    $stmt = $conn->prepare("
        SELECT 
            id,
            user_id,
            location_name,
            latitude,
            longitude,
            category,
            severity,
            description,
            image_proof,
            affected_houses,
            is_active,
            hazard_type,
            started_at,
            status,
            verified_by_company_id,
            resolved_by_company_id,
            verified_at,
            resolved_at,
            resolution_note,
            created_at,
            updated_at
        FROM outage_reports
        WHERE user_id = :user_id
          AND status != 'rejected'
        ORDER BY created_at DESC
    ");

    $stmt->execute([
        ":user_id" => $user_id
    ]);

    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* =========================================
       RESPONSE
    ========================================= */
    echo json_encode([
        "success" => true,
        "message" => "Reports fetched successfully",
        "count" => count($reports),
        "data" => $reports
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database error"
    ]);
}