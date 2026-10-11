<?php

header("Content-Type: application/json");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/env.php';
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
   BARANGAY -> COORDINATES
   The barangay is the coordinate authority; `location_name` is only the label
   shown to other users. An explicit pin dropped on the form's map refines the
   point but never replaces the barangay.
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
    // Tell apart "that barangay does not exist" from "it exists but nobody has
    // entered its coordinates yet" - the second is a server-side data gap and
    // must not read as the user's mistake.
    $existsStmt = $conn->prepare("SELECT id FROM barangays WHERE barangay_name = ? LIMIT 1");
    $existsStmt->execute([$barangay_name]);
    $exists = (bool)$existsStmt->fetchColumn();

    http_response_code($exists ? 503 : 400);
    echo json_encode([
        "success" => false,
        "message" => $exists
            ? "We do not have map coordinates for $barangay_name yet, so this report cannot be placed on the map. Please choose a nearby barangay."
            : "Unknown barangay: $barangay_name",
        "field" => "barangayName",
        "barangays_without_coordinates" => barangaysMissingCoordinates($conn)
    ]);
    exit;
}

$barangay_id = $barangay["id"];

// The barangay centroid is the default point; an explicit pin refines it.
$latitude  = $barangay["latitude"];
$longitude = $barangay["longitude"];

if (
    isset($data["latitude"], $data["longitude"])
    && is_numeric($data["latitude"]) && is_numeric($data["longitude"])
) {
    $pinLat = (float)$data["latitude"];
    $pinLng = (float)$data["longitude"];

    // A pin is user input, so reject nonsense before it is stored (is_finite
    // also catches "1e999" overflowing to INF).
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
        "barangay" => $barangay["name"]
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database insert failed"]);
}
