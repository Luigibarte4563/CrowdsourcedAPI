<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../../auth/rbac.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $user = requireRole(requireAuthUser(), ['electric_company', 'admin']);

    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);
    if (!$data) {
        $data = $_POST;
    }

    $maintenance_id = $data['maintenance_id'] ?? null;
    if (!$maintenance_id) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Maintenance ID is required"]);
        exit;
    }

    /* Verify ownership */
    $stmt = $conn->prepare("
        SELECT ms.id
        FROM maintenance_schedules ms
        JOIN users u ON u.id = ms.created_by
        JOIN roles r ON r.id = u.role_id
        WHERE ms.id = :id
          AND u.id = :user_id
          AND r.role_name IN ('electric_company', 'admin')
        LIMIT 1
    ");
    $stmt->execute([":id" => $maintenance_id, ":user_id" => $user['id']]);

    if (!$stmt->fetch()) {
        throw new Exception("Maintenance not found or unauthorized");
    }

    /* Delete dependent data in safe order */
    $conn->prepare("DELETE FROM notifications WHERE maintenance_id = :id")->execute([":id" => $maintenance_id]);
    $conn->prepare("DELETE FROM maintenance_locations WHERE maintenance_id = :id")->execute([":id" => $maintenance_id]);
    $conn->prepare("DELETE FROM maintenance_schedules WHERE id = :id")->execute([":id" => $maintenance_id]);

    echo json_encode([
        "success" => true,
        "message" => "Maintenance deleted successfully",
        "maintenance_id" => $maintenance_id
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
