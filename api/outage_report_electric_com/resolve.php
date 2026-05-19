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

    if (($user['role'] ?? '') !== 'electric_company') {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "Access denied: Electric company only"
        ]);
        exit;
    }

    /* ================= INPUT ================= */
    $data = json_decode(file_get_contents("php://input"), true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode([
            "success" => false,
            "message" => "Invalid JSON"
        ]);
        exit;
    }

    $id = $data["id"] ?? null;

    if (!$id) {
        echo json_encode([
            "success" => false,
            "message" => "Report ID is required"
        ]);
        exit;
    }

    /* ================= GET REPORT ================= */
    $stmt = $conn->prepare("
        SELECT id, status, resolved_at
        FROM outage_reports
        WHERE id = :id
        LIMIT 1
    ");

    $stmt->execute([":id" => $id]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$report) {
        echo json_encode([
            "success" => false,
            "message" => "Report not found"
        ]);
        exit;
    }

    /* ================= ALREADY RESOLVED ================= */
    if ($report['status'] === 'resolved') {
        echo json_encode([
            "success" => false,
            "message" => "Already resolved"
        ]);
        exit;
    }

    /* ================= UPDATE ================= */
    $resolvedAt = date("Y-m-d H:i:s");

    $stmt = $conn->prepare("
        UPDATE outage_reports
        SET status = 'resolved',
            is_active = 0,
            resolved_at = :resolved_at
        WHERE id = :id
    ");

    $stmt->execute([
        ":id" => $id,
        ":resolved_at" => $resolvedAt
    ]);

    /* ================= CHECK UPDATE ================= */
    if ($stmt->rowCount() === 0) {
        echo json_encode([
            "success" => false,
            "message" => "No changes made"
        ]);
        exit;
    }

    /* ================= SUCCESS ================= */
    echo json_encode([
        "success" => true,
        "message" => "Outage marked as RESOLVED",
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
        "message" => "Server error",
        "error" => $e->getMessage()
    ]);
}