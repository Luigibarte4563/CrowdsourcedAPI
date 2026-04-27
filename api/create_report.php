<?php

header("Content-Type: application/json");

include "../config/db_connect.php";

$conn = getConnection();

$data = json_decode(file_get_contents("php://input"), true);

$user_id = $data["user_id"] ?? "";
$location = $data["location_name"] ?? "";
$description = $data["description"] ?? "";

if (!$user_id || !$location || !$description) {
    http_response_code(400);
    echo json_encode(["error" => "Missing fields"]);
    exit;
}

try {

    $stmt = $conn->prepare(
        "INSERT INTO outage_reports
        (user_id, location_name, description)
        VALUES (:user_id, :location, :description)"
    );

    $stmt->execute([
        ":user_id" => $user_id,
        ":location" => $location,
        ":description" => $description
    ]);

    http_response_code(201);
    echo json_encode([
        "message" => "Report created"
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Failed to create report"]);
}