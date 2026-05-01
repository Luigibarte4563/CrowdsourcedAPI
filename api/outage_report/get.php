<?php

header("Content-Type: application/json");

include "../../config/db_connect.php";

$conn = getConnection();

try {

    $stmt = $conn->prepare("
        SELECT 
            r.id,
            r.user_id,
            u.name AS reporter_name,
            r.location_name,
            r.latitude,
            r.longitude,
            r.category,
            r.severity,
            r.description,
            r.image_proof,
            r.status,
            r.verified_by,
            r.created_at,
            r.updated_at
        FROM outage_reports r
        LEFT JOIN users u ON r.user_id = u.id
        ORDER BY r.created_at DESC
    ");

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
        "message" => "Failed to fetch reports"
    ]);
}