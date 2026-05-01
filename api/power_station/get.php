<?php

header("Content-Type: application/json");

require_once "../../config/db_connect.php";

$conn = getConnection();

/* =========================
   GET ID (optional)
========================= */
$id = $_GET['id'] ?? null;

try {

    if ($id) {

        $stmt = $conn->prepare("
            SELECT * FROM power_stations
            WHERE id = ?
        ");
        $stmt->execute([$id]);

        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$data) {
            echo json_encode([
                "success" => false,
                "message" => "Station not found"
            ]);
            exit;
        }

        echo json_encode([
            "success" => true,
            "data" => $data
        ]);

    } else {

        $stmt = $conn->prepare("
            SELECT * FROM power_stations
            ORDER BY created_at DESC
        ");
        $stmt->execute();

        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            "success" => true,
            "data" => $data
        ]);
    }

} catch (PDOException $e) {

    echo json_encode([
        "success" => false,
        "message" => "Database error"
    ]);
}