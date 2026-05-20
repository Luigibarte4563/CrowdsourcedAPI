<?php

header("Content-Type: application/json; charset=UTF-8");

error_reporting(E_ALL);
ini_set('display_errors', 0);

try {

    require_once __DIR__ . '/../../config/db_connect.php';
    require_once __DIR__ . '/../../auth/jwt_auth.php';

    $conn = getConnection();
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    /* ================= AUTH ================= */
    $user = getUserFromJWT();

    if (!$user || ($user['role'] ?? '') !== 'electric_company') {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "Access denied"
        ]);
        exit;
    }

    /* ================= INPUT ================= */
    $data = json_decode(file_get_contents("php://input"), true);

    if (!$data || json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Invalid JSON input"
        ]);
        exit;
    }

    $id = (int)($data["id"] ?? 0);

    if ($id <= 0) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Valid report ID required"
        ]);
        exit;
    }

    /* ================= VERIFY REPORT ================= */
    $stmt = $conn->prepare("
        SELECT id, status 
        FROM outage_reports 
        WHERE id = :id
        LIMIT 1
    ");

    $stmt->execute([":id" => $id]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$report) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Report not found"
        ]);
        exit;
    }

    /* ================= STATUS CHECK ================= */
    if ($report['status'] === 'resolved') {
        echo json_encode([
            "success" => false,
            "message" => "Report already resolved"
        ]);
        exit;
    }

    if ($report['status'] === 'rejected') {
        echo json_encode([
            "success" => false,
            "message" => "Cannot resolve rejected report"
        ]);
        exit;
    }

    /* ================= UPDATE ================= */
    $resolvedAt = date("Y-m-d H:i:s");

    $update = $conn->prepare("
        UPDATE outage_reports
        SET 
            status = 'resolved',
            is_active = 0,
            resolved_at = :resolved_at
        WHERE id = :id
    ");

    $update->execute([
        ":id" => $id,
        ":resolved_at" => $resolvedAt
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Report marked as resolved",
        "data" => [
            "report_id" => $id,
            "status" => "resolved",
            "resolved_at" => $resolvedAt
        ]
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Server error"
    ]);
}