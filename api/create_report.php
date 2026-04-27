<?php

header("Content-Type: application/json");

include "../config/db_connect.php";

$conn = getConnection();

$data = json_decode(file_get_contents("php://input"), true);

// Required fields
$user_id = $data["user_id"] ?? null;
$location_name = $data["location_name"] ?? null;
$description = $data["description"] ?? null;

// Optional fields
$latitude = $data["latitude"] ?? null;
$longitude = $data["longitude"] ?? null;

$category = $data["category"] ?? "power_outage";
$severity = $data["severity"] ?? "moderate";
$status = $data["status"] ?? "unverified";

// Validation
if (!$user_id || !$location_name || !$description) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Missing required fields"
    ]);

    exit;
}

try {

    $stmt = $conn->prepare("
        INSERT INTO outage_reports
        (
            user_id,
            location_name,
            latitude,
            longitude,
            category,
            severity,
            description,
            status
        )
        VALUES
        (
            :user_id,
            :location_name,
            :latitude,
            :longitude,
            :category,
            :severity,
            :description,
            :status
        )
    ");

    $stmt->execute([
        ":user_id" => $user_id,
        ":location_name" => $location_name,
        ":latitude" => $latitude,
        ":longitude" => $longitude,
        ":category" => $category,
        ":severity" => $severity,
        ":description" => $description,
        ":status" => $status
    ]);

    http_response_code(201);

    echo json_encode([
        "success" => true,
        "message" => "Report created successfully"
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to create report",
        "error" => $e->getMessage()
    ]);
}
?>