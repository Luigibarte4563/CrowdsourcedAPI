<?php

header("Content-Type: application/json; charset=UTF-8");

error_reporting(E_ALL);
ini_set('display_errors', 0);

try {

    require_once __DIR__ . '/../../config/db_connect.php';
    require_once __DIR__ . '/../../auth/jwt_auth.php';
    require_once __DIR__ . '/../services/get_coordinates.php';

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

    $user_id = $user['id'];

    /* ================= INPUT ================= */
    $data = json_decode(file_get_contents("php://input"), true);

    if (!$data) {
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
            "message" => "Invalid report ID"
        ]);
        exit;
    }

    /* ================= FETCH REPORT ================= */
    $stmt = $conn->prepare("
        SELECT * FROM outage_reports
        WHERE id = :id AND user_id = :user_id
        LIMIT 1
    ");

    $stmt->execute([
        ":id" => $id,
        ":user_id" => $user_id
    ]);

    $report = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$report) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Report not found"
        ]);
        exit;
    }

    /* ================= SAFE INPUT ================= */
    $location_name   = trim($data["location_name"] ?? $report["location_name"]);
    $description     = trim($data["description"] ?? $report["description"]);
    $category        = $data["category"] ?? $report["category"];
    $severity        = $data["severity"] ?? $report["severity"];
    $affected_houses = (int)($data["affected_houses"] ?? $report["affected_houses"]);
    $hazard_type     = $data["hazard_type"] ?? $report["hazard_type"];
    $started_at      = $data["started_at"] ?? $report["started_at"];

    $latitude  = $report["latitude"];
    $longitude = $report["longitude"];

    /* ================= GEO UPDATE ================= */
    if (!empty($data["location_name"]) && $data["location_name"] !== $report["location_name"]) {

        $geo = getCoordinates($location_name);

        if (!$geo["success"]) {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "Invalid location"
            ]);
            exit;
        }

        $latitude = $geo["latitude"];
        $longitude = $geo["longitude"];
    }

    /* ================= UPDATE ================= */
    $stmt = $conn->prepare("
        UPDATE outage_reports SET
            location_name = :location_name,
            latitude = :latitude,
            longitude = :longitude,
            category = :category,
            severity = :severity,
            description = :description,
            affected_houses = :affected_houses,
            hazard_type = :hazard_type,
            started_at = :started_at,
            updated_at = NOW()
        WHERE id = :id
        AND user_id = :user_id
    ");

    $stmt->execute([
        ":id" => $id,
        ":user_id" => $user_id,
        ":location_name" => $location_name,
        ":latitude" => $latitude,
        ":longitude" => $longitude,
        ":category" => $category,
        ":severity" => $severity,
        ":description" => $description,
        ":affected_houses" => $affected_houses,
        ":hazard_type" => $hazard_type,
        ":started_at" => $started_at
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Report updated successfully"
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Server error",
        "error" => $e->getMessage()
    ]);
}