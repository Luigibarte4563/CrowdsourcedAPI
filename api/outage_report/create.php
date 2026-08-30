<?php

header("Content-Type: application/json");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../services/get_coordinates.php';
require_once __DIR__ . '/../services/lookup.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* =========================================
   JWT AUTH
========================================= */
$user = getUserFromJWT();
if (!$user) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit;
}
$user_id = $user['id'];

/* =========================================
   INPUT PARSING
========================================= */
$data = json_decode(file_get_contents("php://input"), true);
if (!is_array($data)) {
    $data = $_POST;
}

$location_name = trim($data["location_name"] ?? "");
$description   = trim($data["description"] ?? "");

if ($location_name === "" || $description === "") {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "location_name and description are required"]);
    exit;
}

/* =========================================
   ANTI-SPAM CHECK
========================================= */
try {
    $check = $conn->prepare("
        SELECT id FROM outage_reports
        WHERE user_id = ?
          AND status_id IN (
              SELECT id FROM outage_statuses WHERE status_name IN ('active','under_review','verified')
          )
        LIMIT 1
    ");
    $check->execute([$user_id]);
    if ($check->fetch()) {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "You already have an active report"]);
        exit;
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Anti-spam check failed"]);
    exit;
}

/* =========================================
   COORDINATES
========================================= */
$geo = getCoordinates($location_name);
if (!$geo || empty($geo["latitude"]) || empty($geo["longitude"])) {
    http_response_code(404);
    echo json_encode(["success" => false, "message" => "Unable to resolve location coordinates"]);
    exit;
}

$latitude  = (float)$geo["latitude"];
$longitude = (float)$geo["longitude"];

/* =========================================
   BARANGAY RESOLUTION
   (prefer an explicit barangay_name; otherwise fall back to geo matching)
========================================= */
$barangay_name = trim($data["barangay_name"] ?? "");
$matched_barangay = null;

if ($barangay_name !== "") {
    $matched_barangay = $barangay_name;
} else {
    $known = [
        ["name"=>"Bonuan Gueset","lat"=>16.0585,"lng"=>120.3345,"radius"=>2500],
        ["name"=>"Bonuan Boquig","lat"=>16.0600,"lng"=>120.3200,"radius"=>2000],
        ["name"=>"Bonuan Binloc","lat"=>16.0620,"lng"=>120.3100,"radius"=>4000],
        ["name"=>"Lucao","lat"=>16.0435,"lng"=>120.3310,"radius"=>2500],
        ["name"=>"Tapuac","lat"=>16.0460,"lng"=>120.3450,"radius"=>2000],
        ["name"=>"Tambac","lat"=>16.0520,"lng"=>120.3400,"radius"=>2000],
        ["name"=>"Pantal","lat"=>16.0468,"lng"=>120.3330,"radius"=>2000],
        ["name"=>"Mayombo","lat"=>16.0480,"lng"=>120.3100,"radius"=>2500],
        ["name"=>"Poblacion Oeste","lat"=>16.0420,"lng"=>120.3355,"radius"=>1500],
        ["name"=>"Poblacion Este","lat"=>16.0425,"lng"=>120.3385,"radius"=>1500]
    ];

    $input = strtolower($location_name);

    if (str_contains($input, "binloc")) {
        $matched_barangay = "Bonuan Binloc";
    } elseif (str_contains($input, "bonuan")) {
        $matched_barangay = "Bonuan Gueset";
    } elseif (str_contains($input, "lucao")) {
        $matched_barangay = "Lucao";
    } else {
        $bestMatch = null;
        $bestDistance = PHP_FLOAT_MAX;
        foreach ($known as $b) {
            $distance = haversineDistanceMeters($latitude, $longitude, $b["lat"], $b["lng"]);
            if ($distance <= $b["radius"] && $distance < $bestDistance) {
                $bestDistance = $distance;
                $bestMatch = $b["name"];
            }
        }
        $matched_barangay = $bestMatch;
    }
}

if (!$matched_barangay) {
    http_response_code(403);
    echo json_encode([
        "success" => false,
        "message" => "Outside coverage area",
        "debug" => ["input" => $location_name, "lat" => $latitude, "lng" => $longitude]
    ]);
    exit;
}

$barangay_id = resolveBarangay($conn, $matched_barangay);

/* =========================================
   LOOKUP FIELDS (strings -> normalized ids)
========================================= */
$category_id    = getCategoryId($conn, $data["category"] ?? null);
$severity_id    = getSeverityId($conn, $data["severity"] ?? null);
$hazard_type_id = getHazardTypeId($conn, $data["hazard_type"] ?? null);

if (!$category_id || !$severity_id || !$hazard_type_id) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid lookup value"]);
    exit;
}

$affected_houses = max(1, (int)($data["affected_houses"] ?? 1));
$started_at      = $data["started_at"] ?? null;
$image_url       = $data["image_url"] ?? null;

/* =========================================
   INSERT REPORT
========================================= */
try {
    $stmt = $conn->prepare("
        INSERT INTO outage_reports (
            user_id,
            barangay_id,
            category_id,
            severity_id,
            hazard_type_id,
            status_id,
            report_key,
            location_name,
            latitude,
            longitude,
            description,
            affected_houses,
            is_active,
            started_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
    ");

    $status_id = getStatusId($conn, 'active');

    $stmt->execute([
        $user_id,
        $barangay_id,
        $category_id,
        $severity_id,
        $hazard_type_id,
        $status_id,
        uniqid("OR-"),
        $location_name,
        $latitude,
        $longitude,
        $description,
        $affected_houses,
        $started_at
    ]);

    $outage_report_id = (int)$conn->lastInsertId();

    /* Optional evidence image -> outage_report_images */
    if (!empty($image_url)) {
        $imgStmt = $conn->prepare("
            INSERT INTO outage_report_images (outage_report_id, uploaded_by, image_url)
            VALUES (?, ?, ?)
        ");
        $imgStmt->execute([$outage_report_id, $user_id, $image_url]);
    }

    echo json_encode([
        "success" => true,
        "message" => "Report created",
        "report_id" => $outage_report_id,
        "barangay" => $matched_barangay
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database insert failed"]);
}
