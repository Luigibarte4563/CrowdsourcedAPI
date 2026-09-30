<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../../auth/rbac.php';
require_once __DIR__ . '/../services/lookup.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$user = requireRole(requireAuthUser(), ['lineman', 'electric_company', 'admin']);

$data = json_decode(file_get_contents("php://input"), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid JSON input"]);
    exit;
}

$outageReportId      = (int)($data["outage_report_id"] ?? 0);
$verificationStatus  = trim($data["verification_status"] ?? "");
$notes               = trim($data["notes"] ?? "");
$explicitStatus      = trim($data["status"] ?? "");

$allowed = ['confirmed', 'not_confirmed', 'false_report'];
if (!in_array($verificationStatus, $allowed, true)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid verification_status"]);
    exit;
}

$outageReportId = (int)$outageReportId;
if ($outageReportId <= 0) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "outage_report_id is required"]);
    exit;
}

try {
    $stmt = $conn->prepare("SELECT id FROM outage_reports WHERE id = ? LIMIT 1");
    $stmt->execute([$outageReportId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Report not found"]);
        exit;
    }

    /* Preserve verification history */
    $conn->prepare("
        INSERT INTO outage_report_verifications (outage_report_id, verified_by, verification_status, notes)
        VALUES (?, ?, ?, ?)
    ")->execute([$outageReportId, $user['id'], $verificationStatus, $notes !== "" ? $notes : null]);

    /* Determine target status from verification outcome (rule-based) */
    $targetStatus = $verificationStatus === 'confirmed'
        ? 'verified'
        : ($verificationStatus === 'false_report' ? 'rejected' : 'under_review');

    if ($explicitStatus !== "") {
        $targetStatus = $explicitStatus;
    }

    $targetStatusId = getStatusId($conn, $targetStatus);
    if (!$targetStatusId) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Invalid target status"]);
        exit;
    }

    $sql = "UPDATE outage_reports SET status_id = :status_id, updated_at = NOW()";
    $params = [":status_id" => $targetStatusId, ":id" => $outageReportId];

    if ($targetStatus === 'resolved') {
        $sql .= ", resolved_at = NOW(), is_active = 0";
    }
    $sql .= " WHERE id = :id";

    $conn->prepare($sql)->execute($params);

    echo json_encode([
        "success" => true,
        "message" => "Report verified",
        "verification_status" => $verificationStatus,
        "status" => $targetStatus
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error"]);
}
