<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {

    /* =========================================
       AUTH
    ========================================= */
    $user = getUserFromJWT();

    if (!$user || ($user['role'] ?? '') !== 'electric_company') {
        throw new Exception("Unauthorized");
    }

    /* =========================================
       INPUT
    ========================================= */
    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);

    if (!$data) {
        $data = $_POST;
    }

    $maintenance_id = $data['maintenance_id'] ?? null;

    if (!$maintenance_id) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Maintenance ID is required"
        ]);
        exit;
    }

    /* =========================================
       VERIFY OWNERSHIP (FIXED: NO electric_companies TABLE)
    ========================================= */
    $stmt = $conn->prepare("
        SELECT ms.id
        FROM maintenance_schedules ms
        JOIN users u ON u.id = ms.created_by
        WHERE ms.id = :id
        AND u.id = :user_id
        AND u.role = 'electric_company'
        LIMIT 1
    ");

    $stmt->execute([
        ":id" => $maintenance_id,
        ":user_id" => $user['id']
    ]);

    $maintenance = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$maintenance) {
        throw new Exception("Maintenance not found or unauthorized");
    }

    /* =========================================
       DELETE DEPENDENT DATA (SAFE ORDER)
    ========================================= */

    $conn->prepare("DELETE FROM notifications WHERE maintenance_id = :id")
        ->execute([":id" => $maintenance_id]);

    $conn->prepare("DELETE FROM maintenance_locations WHERE maintenance_id = :id")
        ->execute([":id" => $maintenance_id]);

    $conn->prepare("DELETE FROM maintenance_schedules WHERE id = :id")
        ->execute([":id" => $maintenance_id]);

    /* =========================================
       RESPONSE
    ========================================= */
    echo json_encode([
        "success" => true,
        "message" => "Maintenance deleted successfully",
        "maintenance_id" => $maintenance_id
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}