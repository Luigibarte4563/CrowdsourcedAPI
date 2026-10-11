<?php

require_once __DIR__ . '/../../config/cors.php';

header("Content-Type: application/json");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../services/lookup.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* JWT AUTH */
$user = getUserFromJWT();
if (!$user) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Unauthorized (invalid JWT)"]);
    exit;
}
$user_id = $user['id'] ?? null;
if (!$user_id) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid token user"]);
    exit;
}

/* INPUT */
$data = json_decode(file_get_contents("php://input"), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid JSON"]);
    exit;
}

$address = trim($data["address"] ?? $data["location_name"] ?? "");

if ($address === "") {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "address is required"]);
    exit;
}

/* =========================================
   BARANGAY -> COORDINATES
   The barangay supplies the point; `address` is the label the user sees and is
   never geocoded. This is what "nearby" lookups centre on, so a wrong point
   would silently mis-rank every result.
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
            ? "We do not have map coordinates for $barangay_name yet, so we cannot save your location. Please choose a nearby barangay."
            : "Unknown barangay: $barangay_name",
        "field" => "barangayName",
        "barangays_without_coordinates" => barangaysMissingCoordinates($conn)
    ]);
    exit;
}

$barangay_id = $barangay["id"];
$barangay_name = $barangay["name"];
$latitude  = $barangay["latitude"];
$longitude = $barangay["longitude"];

/* UPSERT user_locations (is_primary) */
try {
    $existing = $conn->prepare("
        SELECT id FROM user_locations
        WHERE user_id = :user_id AND is_primary = 1
        LIMIT 1
    ");
    $existing->execute([":user_id" => $user_id]);
    $existingRow = $existing->fetch(PDO::FETCH_ASSOC);

    if ($existingRow) {
        $stmt = $conn->prepare("
            UPDATE user_locations
            SET barangay_id = :barangay_id,
                address = :address,
                latitude = :latitude,
                longitude = :longitude,
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ":barangay_id" => $barangay_id,
            ":address" => $address,
            ":latitude" => $latitude,
            ":longitude" => $longitude,
            ":id" => $existingRow['id']
        ]);
    } else {
        $stmt = $conn->prepare("
            INSERT INTO user_locations (user_id, barangay_id, address, latitude, longitude, is_primary)
            VALUES (:user_id, :barangay_id, :address, :latitude, :longitude, 1)
        ");
        $stmt->execute([
            ":user_id" => $user_id,
            ":barangay_id" => $barangay_id,
            ":address" => $address,
            ":latitude" => $latitude,
            ":longitude" => $longitude
        ]);
    }

    echo json_encode([
        "success" => true,
        "message" => "Location updated successfully",
        "data" => [
            "address" => $address,
            "latitude" => $latitude,
            "longitude" => $longitude,
            "barangay" => $barangay_name,
            "barangay_id" => $barangay_id
        ]
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database error"]);
}
