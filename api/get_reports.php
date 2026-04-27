<?php

header("Content-Type: application/json");

include "../config/db_connect.php";

$conn = getConnection();

try {

    $stmt = $conn->prepare(
        "SELECT * FROM outage_reports ORDER BY id DESC"
    );

    $stmt->execute();

    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($reports);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "error" => "Failed to fetch reports"
    ]);
}