<?php

header("Content-Type: application/json");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../services/get_coordinates.php';
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

/* GEOCODE */
try {
    $geo = getCoordinates($address);
} catch (Throwable $e) {
    $geo = null;
}

if (!$geo || !$geo["success"]) {
    http_response_code(404);
    echo json_encode(["success" => false, "message" => "Location not found"]);
    exit;
}

$latitude  = (float)$geo["latitude"];
$longitude = (float)$geo["longitude"];

/* BARANGAY (optional explicit override, else geo matching) */
$barangay_name = trim($data["barangay_name"] ?? "");
$barangay_id = null;

if ($barangay_name !== "") {
    $barangay_id = resolveBarangay($conn, $barangay_name);
} else {
    $known = [
        ["name"=>"Bonuan Gueset","lat"=>16.0585,"lng"=>120.3345,"radius"=>2500],
        ["name"=>"Bonuan Boquig","lat"=>16.0600,"lng"=>120.3200,"radius"=>2000],
        ["name"=>"Bonuan Binloc","lat"=>16.0620,"lng"=>120.3100,"radius"=>2000],
        ["name"=>"Lucao","lat"=>16.0435,"lng"=>120.3310,"radius"=>1800],
        ["name"=>"Tapuac","lat"=>16.0460,"lng"=>120.3450,"radius"=>1800],
        ["name"=>"Tambac","lat"=>16.0520,"lng"=>120.3400,"radius"=>1500],
        ["name"=>"Pantal","lat"=>16.0468,"lng"=>120.3330,"radius"=>1500],
        ["name"=>"Mayombo","lat"=>16.0480,"lng"=>120.3100,"radius"=>1500],
        ["name"=>"Poblacion Oeste","lat"=>16.0420,"lng"=>120.3355,"radius"=>1200],
        ["name"=>"Poblacion Este","lat"=>16.0425,"lng"=>120.3385,"radius"=>1200]
    ];
    foreach ($known as $b) {
        if (haversineDistanceMeters($latitude, $longitude, $b["lat"], $b["lng"]) <= $b["radius"]) {
            $barangay_id = resolveBarangay($conn, $b["name"]);
            $barangay_name = $b["name"];
            break;
        }
    }
}

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
