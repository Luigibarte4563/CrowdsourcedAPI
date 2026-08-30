<?php

header("Content-Type: application/json; charset=UTF-8");

error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../services/get_coordinates.php';
require_once __DIR__ . '/../services/lookup.php';

try {
    $conn = getConnection();
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $user = getUserFromJWT();
    if (!$user) {
        http_response_code(401);
        echo json_encode(["success" => false, "message" => "Unauthorized"]);
        exit;
    }
    $user_id = $user['id'];

    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Invalid JSON input"]);
        exit;
    }

    $id = (int)($data["id"] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Invalid report ID"]);
        exit;
    }

    $stmt = $conn->prepare("
        SELECT * FROM outage_reports
        WHERE id = :id AND user_id = :user_id
        LIMIT 1
    ");
    $stmt->execute([":id" => $id, ":user_id" => $user_id]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$report) {
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Report not found"]);
        exit;
    }

    /* ================= SAFE INPUT ================= */
    $location_name   = trim($data["location_name"] ?? $report["location_name"]);
    $description     = trim($data["description"] ?? $report["description"]);
    $affected_houses = max(1, (int)($data["affected_houses"] ?? $report["affected_houses"]));
    $started_at      = $data["started_at"] ?? $report["started_at"];

    /* Lookup ids (strings -> normalized) */
    $category_id    = getCategoryId($conn, $data["category"] ?? getCategoryNameById($conn, $report["category_id"]));
    $severity_id    = getSeverityId($conn, $data["severity"] ?? getSeverityNameById($conn, $report["severity_id"]));
    $hazard_type_id = getHazardTypeId($conn, $data["hazard_type"] ?? getHazardNameById($conn, $report["hazard_type_id"]));

    $latitude  = (float)$report["latitude"];
    $longitude = (float)$report["longitude"];
    $barangay_id = $report["barangay_id"];

    /* ================= GEO + BARANGAY UPDATE ================= */
    if (!empty($data["location_name"]) && $data["location_name"] !== $report["location_name"]) {
        $geo = getCoordinates($location_name);
        if (!$geo || empty($geo["latitude"]) || empty($geo["longitude"])) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Invalid location (geocoding failed)"]);
            exit;
        }
        $latitude = (float)$geo["latitude"];
        $longitude = (float)$geo["longitude"];

        if (!empty($data["barangay_name"])) {
            $barangay_id = resolveBarangay($conn, $data["barangay_name"]);
        }
    }

    $stmt = $conn->prepare("
        UPDATE outage_reports SET
            barangay_id = :barangay_id,
            category_id = :category_id,
            severity_id = :severity_id,
            hazard_type_id = :hazard_type_id,
            location_name = :location_name,
            latitude = :latitude,
            longitude = :longitude,
            description = :description,
            affected_houses = :affected_houses,
            started_at = :started_at,
            updated_at = NOW()
        WHERE id = :id
        AND user_id = :user_id
    ");

    $stmt->execute([
        ":id" => $id,
        ":user_id" => $user_id,
        ":barangay_id" => $barangay_id,
        ":category_id" => $category_id,
        ":severity_id" => $severity_id,
        ":hazard_type_id" => $hazard_type_id,
        ":location_name" => $location_name,
        ":latitude" => $latitude,
        ":longitude" => $longitude,
        ":description" => $description,
        ":affected_houses" => $affected_houses,
        ":started_at" => $started_at
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Report updated successfully"
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error"]);
}
