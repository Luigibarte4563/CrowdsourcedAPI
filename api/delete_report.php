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
        DELETE FROM outage_reports
        WHERE id = :id
    ");

    $stmt->execute([
        ":id" => $id
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Report deleted successfully"
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Failed to delete report",
        "error" => $e->getMessage()
    ]);
}