<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../../auth/rbac.php';
require_once __DIR__ . '/../services/get_coordinates.php';
require_once __DIR__ . '/../services/lookup.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$user_id = (int)requireAuthUser()['id'];

$data = json_decode(file_get_contents("php://input"), true);
if (!is_array($data)) {
    $data = $_POST;
}

$location_name = trim($data["location_name"] ?? "");
$description   = trim($data["description"] ?? "");
$hazard_name   = trim($data["hazard_type"] ?? "none");
$severity      = $data["severity"] ?? "moderate";
$image_proof   = $data["image_url"] ?? $data["image_proof"] ?? null;

$validSeverity = ['low', 'moderate', 'high', 'critical'];
if (!in_array($severity, $validSeverity, true)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid severity"]);
    exit;
}

if ($location_name === "") {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "location_name is required"]);
    exit;
}

$hazard_type_id = getHazardTypeId($conn, $hazard_name);
if (!$hazard_type_id) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid hazard_type"]);
    exit;
}

if (isset($data["latitude"], $data["longitude"]) && is_numeric($data["latitude"]) && is_numeric($data["longitude"])) {
    $latitude = (float)$data["latitude"];
    $longitude = (float)$data["longitude"];
} else {
    $geo = getCoordinates($location_name);
    if (!$geo['success'] ?? false) {
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Unable to resolve location coordinates"]);
        exit;
    }
    $latitude = (float)$geo["latitude"];
    $longitude = (float)$geo["longitude"];
}

$barangay_id = null;
if (!empty($data["barangay_name"])) {
    $barangay_id = resolveBarangay($conn, $data["barangay_name"]);
}

try {
    $stmt = $conn->prepare("
        INSERT INTO electrical_hazards (
            reported_by, barangay_id, hazard_type_id, location_name, latitude,
            longitude, description, severity, image_proof
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $user_id,
        $barangay_id,
        $hazard_type_id,
        $location_name,
        $latitude,
        $longitude,
        $description !== "" ? $description : null,
        $severity,
        $image_proof
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Electrical hazard reported",
        "hazard_id" => (int)$conn->lastInsertId()
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error"]);
}
