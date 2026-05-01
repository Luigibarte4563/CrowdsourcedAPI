<?php

header("Content-Type: application/json");

require_once "../../config/db_connect.php";

$conn = getConnection();
session_start();

$id = $_GET['id'] ?? null;

if (!$id) {
    echo json_encode([
        "success" => false,
        "message" => "Station ID required"
    ]);
    exit;
}

try {

    $stmt = $conn->prepare("
        DELETE FROM power_stations
        WHERE id = ?
    ");

    $stmt->execute([$id]);

    echo json_encode([
        "success" => true,
        "message" => "Station deleted successfully"
    ]);

} catch (PDOException $e) {

    echo json_encode([
        "success" => false,
        "message" => "Database error"
    ]);
}