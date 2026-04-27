<?php

header("Content-Type: application/json");

include "../config/db_connect.php";

$conn = getConnection();

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
            status,
            created_at
        FROM outage_reports
        ORDER BY created_at DESC
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
        "message" => "Failed to fetch reports",
        "error" => $e->getMessage()
    ]);
}