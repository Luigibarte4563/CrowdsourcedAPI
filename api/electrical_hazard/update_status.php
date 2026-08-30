<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../../auth/rbac.php';
require_once __DIR__ . '/../services/create_notification.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$user  = requireAuthUser();
$user_id = (int)$user['id'];

$data = json_decode(file_get_contents("php://input"), true);
if (!is_array($data)) {
    $data = $_POST;
}

$hazard_id = (int)($data["hazard_id"] ?? 0);
$status    = trim($data["status"] ?? "");

$validStatuses = ['reported', 'verified', 'resolved'];
if (!in_array($status, $validStatuses, true)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid status"]);
    exit;
}

if ($hazard_id <= 0) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "hazard_id required"]);
    exit;
}

try {
    $stmt = $conn->prepare("SELECT reported_by FROM electrical_hazards WHERE id = ? LIMIT 1");
    $stmt->execute([$hazard_id]);
    $hazard = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$hazard) {
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Hazard not found"]);
        exit;
    }

    /* Regular users may only resolve/report own; staff (lineman) verify/resolve */
    $role = $user['role'] ?? '';
    $isStaff = in_array($role, ['lineman', 'electric_company', 'admin'], true);

    if (!$isStaff && (int)$hazard['reported_by'] !== $user_id) {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Forbidden"]);
        exit;
    }

    $sql = "UPDATE electrical_hazards SET status = :status";
    $params = [":status" => $status, ":id" => $hazard_id];

    if ($status === 'resolved') {
        $sql .= ", resolved_at = NOW()";
    }
    $sql .= " WHERE id = :id";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    if ($isStaff && ($status === 'verified' || $status === 'resolved')) {
        createNotification($conn, [(int)$hazard['reported_by']], "Electrical Hazard " . ucfirst($status),
            "Your electrical hazard report has been marked as " . $status . ".",
            "electrical_hazard", $hazard_id, "electrical_hazard");
    }

    echo json_encode([
        "success" => true,
        "message" => "Hazard status updated",
        "status" => $status
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error"]);
}
