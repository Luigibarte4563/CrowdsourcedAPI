<?php

header("Content-Type: application/json");

include "../config/db_connect.php";

$conn = getConnection();

$data = json_decode(file_get_contents("php://input"), true);

if (!$data) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "No input data provided"
    ]);
    exit;
}

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

    /* ======================================
       CHECK IF REPORT EXISTS
    ====================================== */
    $check = $conn->prepare("
        SELECT id FROM outage_reports WHERE id = :id
    ");

    $check->execute([
        ":id" => $id
    ]);

    if ($check->rowCount() === 0) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Report not found"
        ]);
        exit;
    }

    /* ======================================
       UPDATE REPORT
    ====================================== */
    $stmt = $conn->prepare("
        UPDATE outage_reports SET
            location_name = :location_name,
            latitude = :latitude,
            longitude = :longitude,
            category = :category,
            severity = :severity,
            description = :description,
            image_proof = :image_proof,
            status = :status,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ");

    $stmt->execute([
        ":location_name" => $data["location_name"] ?? null,
        ":latitude" => $data["latitude"] ?? null,
        ":longitude" => $data["longitude"] ?? null,
        ":category" => $data["category"] ?? "power_outage",
        ":severity" => $data["severity"] ?? "moderate",
        ":description" => $data["description"] ?? null,
        ":image_proof" => $data["image_proof"] ?? null,
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
        "message" => "Failed to update report"
    ]);
}