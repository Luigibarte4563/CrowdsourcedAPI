<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';

session_start();

/* =========================================
   DB CONNECTION
========================================= */
$conn = getConnection();

/* =========================================
   STRICT SESSION CHECK
========================================= */
if (
    !isset($_SESSION['user']) ||
    !isset($_SESSION['user']['id']) ||
    empty($_SESSION['user']['id'])
) {
    http_response_code(401);

    echo json_encode([
        "success" => false,
        "message" => "Unauthorized: Please login first"
    ]);
    exit;
}

$user_id = (int) $_SESSION['user']['id'];

try {

    /* =========================================
       FETCH ONLY CURRENT USER REPORTS
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
            verified_by,
            created_at,
            updated_at
        FROM outage_reports
        WHERE user_id = :user_id
          AND is_deleted = 0
        ORDER BY created_at DESC
    ");

    $stmt->bindParam(":user_id", $user_id, PDO::PARAM_INT);
    $stmt->execute();

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
        "message" => "Database error",
        "error" => $e->getMessage() // remove in production
    ]);
}