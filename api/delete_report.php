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
$user_id = $data["user_id"] ?? null; // optional but recommended

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
       OPTIONAL: CHECK IF REPORT EXISTS
    ====================================== */
    $check = $conn->prepare("
        SELECT id, user_id
        FROM outage_reports
        WHERE id = :id
    ");

    $check->execute([
        ":id" => $id
    ]);

    $report = $check->fetch(PDO::FETCH_ASSOC);

    if (!$report) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Report not found"
        ]);
        exit;
    }

    /* ======================================
       OPTIONAL SECURITY CHECK (OWNER ONLY)
       Uncomment if you want strict control
    ====================================== */

    /*
    if ($user_id && $report["user_id"] != $user_id) {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "You are not allowed to delete this report"
        ]);
        exit;
    }
    */

    /* ======================================
       DELETE REPORT
    ====================================== */
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
        "message" => "Failed to delete report"
    ]);
}