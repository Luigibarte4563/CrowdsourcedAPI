<?php

header("Content-Type: application/json; charset=UTF-8");

error_reporting(E_ALL);
ini_set('display_errors', 0); // prevent HTML breaking JSON

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';

try {

    /* ================= DB CONNECTION ================= */
    $conn = getConnection();
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

} catch (Exception $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database connection failed"
    ]);
    exit;
}

/* ================= AUTH ================= */
try {
    $user = getUserFromJWT();
} catch (Exception $e) {
    http_response_code(401);

    echo json_encode([
        "success" => false,
        "message" => "Invalid token"
    ]);
    exit;
}

if (!$user || !isset($user['id'])) {
    http_response_code(401);

    echo json_encode([
        "success" => false,
        "message" => "Unauthorized"
    ]);
    exit;
}

try {

    /* ================= COUNT RESOLVED REPORTS ================= */
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total_resolved
        FROM outage_reports
        WHERE status = 'resolved'
          AND is_active = 1
    ");

    $stmt->execute();

    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    $count = $result['total_resolved'] ?? 0;

    /* ================= RESPONSE ================= */
    echo json_encode([
        "success" => true,
        "message" => "Resolved reports count fetched successfully",
        "total_resolved" => (int)$count
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database query failed"
        // "error" => $e->getMessage() // enable only for debugging
    ]);
}