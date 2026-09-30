<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../../auth/rbac.php';
require_once __DIR__ . '/../services/lookup.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* Staff-only: lineman, electric_company, admin */
$user = requireRole(requireAuthUser(), ['lineman', 'electric_company', 'admin']);

$data = json_decode(file_get_contents("php://input"), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid JSON input"]);
    exit;
}

$outageReportId = (int)($data["outage_report_id"] ?? 0);
$updateMessage  = trim($data["update_message"] ?? "");
$statusName     = trim($data["status"] ?? "");

if ($outageReportId <= 0 || $updateMessage === "") {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "outage_report_id and update_message are required"]);
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

    $status_id = null;
    if ($statusName !== "") {
        $status_id = getStatusId($conn, $statusName);
        if (!$status_id) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Invalid status"]);
            exit;
        }
    }

    /* Preserve history in outage_report_updates */
    $conn->prepare("
        INSERT INTO outage_report_updates (outage_report_id, updated_by, status_id, update_message)
        VALUES (?, ?, ?, ?)
    ")->execute([$outageReportId, $user['id'], $status_id, $updateMessage]);

    /* Optionally advance the active status */
    if ($status_id) {
        $sql = "UPDATE outage_reports SET status_id = :status_id, updated_at = NOW()";
        $params = [":status_id" => $status_id, ":id" => $outageReportId];

        if ($statusName === 'resolved') {
            $sql .= ", resolved_at = NOW(), is_active = 0";
        }

        $sql .= " WHERE id = :id";
        $conn->prepare($sql)->execute($params);
    }

    echo json_encode([
        "success" => true,
        "message" => "Field update recorded",
        "status" => $statusName !== "" ? $statusName : null
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error"]);
}
