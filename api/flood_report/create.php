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

$location_name  = trim($data["location_name"] ?? "");
$description    = trim($data["description"] ?? "");
$flood_level    = $data["flood_level"] ?? "low";
$flood_depth_cm = isset($data["flood_depth_cm"]) ? (float)$data["flood_depth_cm"] : null;
$image_proof    = $data["image_url"] ?? $data["image_proof"] ?? null;

$validLevels = ['low', 'moderate', 'high', 'severe'];
if (!in_array($flood_level, $validLevels, true)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid flood_level"]);
    exit;
}

if ($location_name === "") {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "location_name is required"]);
    exit;
}

/* Coordinates: prefer explicit, else geocode */
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
        INSERT INTO flood_reports (
            reported_by, barangay_id, location_name, latitude, longitude,
            flood_depth_cm, flood_level, description, image_proof
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $user_id,
        $barangay_id,
        $location_name,
        $latitude,
        $longitude,
        $flood_depth_cm,
        $flood_level,
        $description !== "" ? $description : null,
        $image_proof
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Flood report created",
        "flood_report_id" => (int)$conn->lastInsertId()
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error"]);
}
