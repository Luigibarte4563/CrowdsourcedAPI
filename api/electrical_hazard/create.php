<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../../auth/rbac.php';
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

/* =========================================
   BARANGAY -> COORDINATES
   The barangay is the coordinate authority. `location_name` is a label shown to
   other users and is never geocoded; an explicit pin refines the centroid.
   ========================================= */
$barangay_name = trim($data["barangay_name"] ?? "");

if ($barangay_name === "") {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "barangay_name is required",
        "field" => "barangayName"
    ]);
    exit;
}

$barangay = resolveBarangayCoords($conn, $barangay_name);

if (!$barangay) {
    $existsStmt = $conn->prepare("SELECT id FROM barangays WHERE barangay_name = ? LIMIT 1");
    $existsStmt->execute([$barangay_name]);
    $exists = (bool)$existsStmt->fetchColumn();

    http_response_code($exists ? 503 : 400);
    echo json_encode([
        "success" => false,
        "message" => $exists
            ? "We do not have map coordinates for $barangay_name yet, so this cannot be placed on the map. Please choose a nearby barangay."
            : "Unknown barangay: $barangay_name",
        "field" => "barangayName",
        "barangays_without_coordinates" => barangaysMissingCoordinates($conn)
    ]);
    exit;
}

$barangay_id = $barangay["id"];
$latitude  = $barangay["latitude"];
$longitude = $barangay["longitude"];

if (
    isset($data["latitude"], $data["longitude"])
    && is_numeric($data["latitude"]) && is_numeric($data["longitude"])
) {
    $pinLat = (float)$data["latitude"];
    $pinLng = (float)$data["longitude"];

    if (!is_finite($pinLat) || !is_finite($pinLng)
        || $pinLat < -90 || $pinLat > 90
        || $pinLng < -180 || $pinLng > 180) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Invalid coordinates"]);
        exit;
    }

    $latitude  = $pinLat;
    $longitude = $pinLng;
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
