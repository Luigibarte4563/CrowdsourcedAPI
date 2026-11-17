<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../../auth/rbac.php';
require_once __DIR__ . '/../services/lookup.php';
require_once __DIR__ . '/../../auth/lineman_access.php';

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
    /* Existence + assignment check first: a denied field update must not reach
       the report, nor be able to advance the report's status. */
    require_outage_access($conn, $user, $outageReportId);

    /* Store the latest update message on the report itself. */
    $status = null;
    if ($statusName !== "") {
        $status = getStatus($conn, $statusName);
        if (!$status) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Invalid status"]);
            exit;
        }
    }

    if ($status !== null) {
        $sql = "UPDATE outage_reports SET status = :status, update_message = :update_message, updated_at = NOW()";
        $params = [":status" => $status, ":update_message" => $updateMessage, ":id" => $outageReportId];

        if ($status === 'resolved') {
            $sql .= ", resolved_at = NOW()";
        }

        $sql .= " WHERE id = :id";
        $conn->prepare($sql)->execute($params);
    } else {
        $conn->prepare("
            UPDATE outage_reports
            SET update_message = :update_message, updated_at = NOW()
            WHERE id = :id
        ")->execute([
            ":update_message" => $updateMessage,
            ":id" => $outageReportId,
        ]);
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
