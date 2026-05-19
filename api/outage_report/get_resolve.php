<?php

header("Content-Type: application/json; charset=UTF-8");

error_reporting(E_ALL);
ini_set('display_errors', 1);

try {

    require_once __DIR__ . '/../../config/db_connect.php';
    require_once __DIR__ . '/../../auth/jwt_auth.php';

    $conn = getConnection();
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    /* ================= AUTH ================= */
    $user = getUserFromJWT();

    if (!$user) {

        http_response_code(401);

        echo json_encode([
            "success" => false,
            "message" => "Unauthorized"
        ]);

        exit;
    }

    /* ================= COUNT RESOLVED REPORTS ================= */
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total_resolved
        FROM outage_reports
        WHERE status = 'resolved'
    ");

    $stmt->execute();

    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    /* ================= RESPONSE ================= */
    echo json_encode([
        "success" => true,
        "message" => "Resolved reports count fetched successfully",
        "total_resolved" => (int)$result["total_resolved"]
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Server error",
        "error" => $e->getMessage()
    ]);
}