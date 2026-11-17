<?php

header("Content-Type: application/json; charset=UTF-8");

error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
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
        echo json_encode(["success" => false, "message" => "Invalid hazard ID"]);
        exit;
    }

    $stmt = $conn->prepare("
        SELECT * FROM electrical_hazards
        WHERE id = :id AND reported_by = :user_id
        LIMIT 1
    ");
    $stmt->execute([":id" => $id, ":user_id" => $user_id]);
    $hazard = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$hazard) {
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Hazard not found"]);
        exit;
    }

    /* Safe input */
    $location_name = trim($data["location_name"] ?? $hazard["location_name"]);
    $description   = trim($data["description"] ?? $hazard["description"]);
    $hazard_type   = getHazardType($data["hazard_type"] ?? $hazard["hazard_type"]);
    $severity      = getSeverity($conn, $data["severity"] ?? $hazard["severity"]);

    $latitude  = (float)$hazard["latitude"];
    $longitude = (float)$hazard["longitude"];
    $barangay_id = $hazard["barangay_id"];

    /* Barangay + coordinates */
    if (!empty($data["barangay_name"])) {
        $requestedBarangay = trim($data["barangay_name"]);
        $barangay = resolveBarangayCoords($conn, $requestedBarangay);

        if (!$barangay) {
            $existsStmt = $conn->prepare("SELECT id FROM barangays WHERE barangay_name = ? LIMIT 1");
            $existsStmt->execute([$requestedBarangay]);
            $exists = (bool)$existsStmt->fetchColumn();

            http_response_code($exists ? 503 : 400);
            echo json_encode([
                "success" => false,
                "message" => $exists
                    ? "We do not have map coordinates for $requestedBarangay yet."
                    : "Unknown barangay: $requestedBarangay",
                "field" => "barangayName",
                "barangays_without_coordinates" => barangaysMissingCoordinates($conn)
            ]);
            exit;
        }

        $barangay_id = $barangay["id"];
        $latitude  = $barangay["latitude"];
        $longitude = $barangay["longitude"];
    }

    if (
        isset($data["latitude"], $data["longitude"])
        && is_numeric($data["latitude"]) && is_numeric($data["longitude"])
    ) {
        $pin_lat = (float)$data["latitude"];
        $pin_lng = (float)$data["longitude"];

        if (!is_finite($pin_lat) || !is_finite($pin_lng)
            || $pin_lat < -90 || $pin_lat > 90
            || $pin_lng < -180 || $pin_lng > 180) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Invalid coordinates"]);
            exit;
        }

        $latitude = $pin_lat;
        $longitude = $pin_lng;
    }

    $stmt = $conn->prepare("
        UPDATE electrical_hazards SET
            barangay_id = :barangay_id,
            hazard_type = :hazard_type,
            severity = :severity,
            location_name = :location_name,
            latitude = :latitude,
            longitude = :longitude,
            description = :description,
            updated_by = :updated_by,
            updated_at = NOW()
        WHERE id = :id
        AND reported_by = :user_id
    ");

    $stmt->execute([
        ":id" => $id,
        ":user_id" => $user_id,
        ":barangay_id" => $barangay_id,
        ":hazard_type" => $hazard_type,
        ":severity" => $severity,
        ":location_name" => $location_name,
        ":latitude" => $latitude,
        ":longitude" => $longitude,
        ":description" => $description,
        ":updated_by" => $user_id,
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Hazard updated successfully"
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error"]);
}
