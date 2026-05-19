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

    if (!$user || ($user['role'] ?? '') !== 'electric_company') {
        http_response_code(401);
        echo json_encode([
            "success" => false,
            "message" => "Unauthorized"
        ]);
        exit;
    }

    /* ================= COMPANY ================= */
    $stmt = $conn->prepare("
        SELECT id
        FROM electric_companies
        WHERE user_id = :user_id
        LIMIT 1
    ");

    $stmt->execute([
        ":user_id" => $user['id'] ?? 0
    ]);

    $company = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$company) {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "Company not found"
        ]);
        exit;
    }

    $company_id = (int)$company['id'];

    /* ================= OUTAGE DATA (FIXED) ================= */
    $stmt = $conn->prepare("
        SELECT 
            id,
            location_name,
            latitude,
            longitude,
            category,
            severity,
            description,
            image_proof,
            affected_houses,
            is_active,
            hazard_type,
            status,
            started_at,
            verified_at,
            resolved_at,
            resolution_note,
            created_at,
            updated_at
        FROM outage_reports
        ORDER BY created_at DESC
    ");

    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* ================= RESPONSE ================= */
    echo json_encode([
        "success" => true,
        "count" => count($rows),
        "data" => $rows
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Server error",
        "error" => $e->getMessage()
    ]);
}