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

    if (!is_array($data)) {
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

    $latitude  = (float)$report["latitude"];
    $longitude = (float)$report["longitude"];

    /* ================= GEO UPDATE ================= */
    if (!empty($data["location_name"]) && $data["location_name"] !== $report["location_name"]) {

        $geo = getCoordinates($location_name);

        if (!$geo || empty($geo["latitude"]) || empty($geo["longitude"])) {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "Invalid location (geocoding failed)"
            ]);
            exit;
        }

        $latitude = (float)$geo["latitude"];
        $longitude = (float)$geo["longitude"];
    }

    /* ================= BARANGAY VALIDATION (FIXED LOGIC) ================= */
    function haversineDistance($lat1, $lon1, $lat2, $lon2) {
        $earthRadius = 6371000;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat/2) ** 2 +
             cos(deg2rad($lat1)) *
             cos(deg2rad($lat2)) *
             sin($dLon/2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1-$a));

        return $earthRadius * $c;
    }

    $barangays = [
        ["name"=>"Bonuan Gueset","lat"=>16.0585,"lng"=>120.3345,"radius"=>2500],
        ["name"=>"Bonuan Boquig","lat"=>16.0600,"lng"=>120.3200,"radius"=>2000],
        ["name"=>"Bonuan Binloc","lat"=>16.0620,"lng"=>120.3100,"radius"=>4000],
        ["name"=>"Lucao","lat"=>16.0435,"lng"=>120.3310,"radius"=>2500],
        ["name"=>"Tapuac","lat"=>16.0460,"lng"=>120.3450,"radius"=>2000],
        ["name"=>"Tambac","lat"=>16.0520,"lng"=>120.3400,"radius"=>2000],
        ["name"=>"Pantal","lat"=>16.0468,"lng"=>120.3330,"radius"=>2000],
        ["name"=>"Herrero-Perez","lat"=>16.0455,"lng"=>120.3380,"radius"=>2000],
        ["name"=>"Mayombo","lat"=>16.0480,"lng"=>120.3100,"radius"=>2500],
        ["name"=>"Poblacion Oeste","lat"=>16.0420,"lng"=>120.3355,"radius"=>1500],
        ["name"=>"Poblacion Este","lat"=>16.0425,"lng"=>120.3385,"radius"=>1500]
    ];

    function findBarangay($lat, $lng, $barangays, $location_name = "") {

        $input = strtolower($location_name);

        // 🔥 FIX BINLOC ISSUE (keyword override)
        if (str_contains($input, "binloc")) {
            return "Bonuan Binloc";
        }
        if (str_contains($input, "bonuan")) {
            return "Bonuan Gueset";
        }
        if (str_contains($input, "lucao")) {
            return "Lucao";
        }

        $bestMatch = null;
        $bestDistance = PHP_FLOAT_MAX;

        foreach ($barangays as $b) {
            $distance = haversineDistance($lat, $lng, $b["lat"], $b["lng"]);

            if ($distance <= $b["radius"] && $distance < $bestDistance) {
                $bestDistance = $distance;
                $bestMatch = $b["name"];
            }
        }

        return $bestMatch;
    }

    $matched_barangay = findBarangay($latitude, $longitude, $barangays, $location_name);

    /* ================= FINAL COVERAGE CHECK ================= */
    if (!$matched_barangay) {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "Outside coverage area (update blocked)",
            "debug" => [
                "lat" => $latitude,
                "lng" => $longitude,
                "input" => $location_name
            ]
        ]);
        exit;
    }

    /* ================= UPDATE QUERY ================= */
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
        "message" => "Report updated successfully",
        "barangay" => $matched_barangay
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Server error"
    ]);
}