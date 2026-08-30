<?php

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
        SELECT ul.id, b.barangay_name, ul.barangay_id, ul.address, ul.latitude, ul.longitude, ul.updated_at
        FROM user_locations ul
        LEFT JOIN barangays b ON b.id = ul.barangay_id
        WHERE ul.user_id = :user_id
        ORDER BY ul.is_primary DESC, ul.id DESC
        LIMIT 1
    ");
    $stmt->execute([":user_id" => $user_id]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$userData) {
        echo json_encode([
            "success" => true,
            "data" => [
                "location_name" => null,
                "address" => null,
                "barangay" => null,
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
            "updated_at" => $userData["updated_at"]
        ]
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database error"]);
}
