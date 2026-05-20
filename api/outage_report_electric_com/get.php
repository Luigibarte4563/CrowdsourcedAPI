<?php

header("Content-Type: application/json; charset=UTF-8");

error_reporting(E_ALL);
ini_set('display_errors', 0);

try {

    require_once __DIR__ . '/../../config/db_connect.php';
    require_once __DIR__ . '/../../auth/jwt_auth.php';

    $conn = getConnection();
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    /* =========================================
       AUTH
    ========================================= */
    $user = getUserFromJWT();

    if (!$user || ($user['role'] ?? '') !== 'electric_company') {
        http_response_code(401);
        echo json_encode([
            "success" => false,
            "message" => "Unauthorized"
        ]);
        exit;
    }

    /* =========================================
       OPTIONAL FILTERS
    ========================================= */
    $status = $_GET['status'] ?? null;
    $severity = $_GET['severity'] ?? null;
    $active = isset($_GET['active']) ? (int)$_GET['active'] : null;

    /* =========================================
       QUERY
    ========================================= */
    $sql = "
        SELECT 
            id,
            user_id,
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
        WHERE 1=1
    ";

    $params = [];

    /* =========================================
       FILTER STATUS
    ========================================= */
    if (!empty($status)) {
        $sql .= " AND status = :status";
        $params[':status'] = $status;
    }

    /* =========================================
       FILTER SEVERITY
    ========================================= */
    if (!empty($severity)) {
        $sql .= " AND severity = :severity";
        $params[':severity'] = $severity;
    }

    /* =========================================
       FILTER ACTIVE
    ========================================= */
    if ($active !== null) {
        $sql .= " AND is_active = :active";
        $params[':active'] = $active;
    }

    /* =========================================
       ORDER
    ========================================= */
    $sql .= " ORDER BY created_at DESC";

    /* =========================================
       EXECUTE
    ========================================= */
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* =========================================
       RESPONSE
    ========================================= */
    echo json_encode([
        "success" => true,
        "count" => count($rows),
        "data" => $rows
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Server error"
    ]);
}