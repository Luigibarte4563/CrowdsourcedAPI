<?php

header("Content-Type: application/json");

include "../config/db_connect.php";

$conn = getConnection();

$data = json_decode(file_get_contents("php://input"), true);

$id = $data["id"] ?? null;

if (!$id) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Report ID is required"
    ]);
    exit;
}

try {

    $stmt = $conn->prepare("
        UPDATE outage_reports SET
            location_name = :location_name,
            latitude = :latitude,
            longitude = :longitude,
            category = :category,
            severity = :severity,
            description = :description,
            status = :status
        WHERE id = :id
    ");

    $stmt->execute([
        ":location_name" => $data["location_name"] ?? null,
        ":latitude" => $data["latitude"] ?? null,
        ":longitude" => $data["longitude"] ?? null,
        ":category" => $data["category"] ?? "power_outage",
        ":severity" => $data["severity"] ?? "moderate",
        ":description" => $data["description"] ?? null,
        ":status" => $data["status"] ?? "unverified",
        ":id" => $id
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Report updated successfully"
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to update report",
        "error" => $e->getMessage()
    ]);
}