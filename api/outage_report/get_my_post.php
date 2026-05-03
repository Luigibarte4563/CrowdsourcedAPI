<?php

header("Content-Type: application/json");

require_once __DIR__ . '/../../config/db_connect.php';

$conn = getConnection();

session_start();

/* =========================================
   CHECK SESSION LOGIN
========================================= */
$user_id = $_SESSION['user']['id'] ?? null;

if (!$user_id) {

    http_response_code(401);

    echo json_encode([
        "success" => false,
        "message" => "Unauthorized: Please login first"
    ]);

    exit;
}

/* =========================================
   FETCH ONLY USER POSTS (NOT DELETED)
========================================= */

try {

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
            verified_by,
            created_at,
            updated_at
        FROM outage_reports
        WHERE user_id = :user_id
        AND is_deleted = 0
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
        "message" => "Database error"
    ]);
}