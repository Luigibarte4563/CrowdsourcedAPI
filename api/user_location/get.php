<?php

require_once __DIR__ . '/../../config/cors.php';

header("Content-Type: application/json");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';

$conn = getConnection();

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

try {
    $stmt = $conn->prepare("
        SELECT u.barangay_id, u.address, u.latitude, u.longitude, u.location_updated_at,
               b.barangay_name
        FROM users u
        LEFT JOIN barangays b ON b.id = u.barangay_id
        WHERE u.id = :user_id
        LIMIT 1
    ");
    $stmt->execute([":user_id" => $user_id]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$userData || $userData["latitude"] === null || $userData["longitude"] === null) {
        echo json_encode([
            "success" => true,
            "data" => [
                "location_name" => null,
                "address" => null,
                "barangay" => null,
                "barangay_id" => null,
                "latitude" => null,
                "longitude" => null,
                "updated_at" => null
            ]
        ]);
        exit;
    }

    echo json_encode([
        "success" => true,
        "data" => [
            "location_name" => $userData["address"],
            "address" => $userData["address"],
            "barangay" => $userData["barangay_name"],
            "barangay_id" => $userData["barangay_id"] !== null ? (int)$userData["barangay_id"] : null,
            "latitude" => $userData["latitude"],
            "longitude" => $userData["longitude"],
            "updated_at" => $userData["location_updated_at"]
        ]
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database error"]);
}
