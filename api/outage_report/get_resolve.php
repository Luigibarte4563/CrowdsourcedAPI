<?php

header("Content-Type: application/json; charset=UTF-8");

error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';

try {

    /* ================= DATABASE CONNECTION ================= */
    $conn = getConnection();
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

} catch (Exception $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database connection failed",
        "error" => $e->getMessage()
    ]);

    exit;
}

/* ================= AUTH VALIDATION ================= */
try {

    $user = getUserFromJWT();

    if (!$user || !isset($user['id'])) {

        http_response_code(401);

        echo json_encode([
            "success" => false,
            "message" => "Unauthorized"
        ]);

        exit;
    }

} catch (Exception $e) {

    http_response_code(401);

    echo json_encode([
        "success" => false,
        "message" => "Invalid token",
        "error" => $e->getMessage()
    ]);

    exit;
}

try {

    /* ================= COUNT RESOLVED OUTAGES ================= */
    $sql = "
        SELECT COUNT(*) AS total_resolved
        FROM outage_reports
        WHERE LOWER(TRIM(status)) = 'resolved'
    ";

    $stmt = $conn->prepare($sql);

    $stmt->execute();

    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    $totalResolved = isset($result['total_resolved'])
        ? (int)$result['total_resolved']
        : 0;

    /* ================= SUCCESS RESPONSE ================= */
    echo json_encode([
        "success" => true,
        "message" => "Resolved outage count fetched successfully",
        "total_resolved" => $totalResolved
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database query failed",
        "error" => $e->getMessage()
    ]);
}