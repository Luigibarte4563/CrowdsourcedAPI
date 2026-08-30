<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../../auth/issue_jwt.php';

$conn = getConnection();

$user = getUserFromJWT();

if (!$user || !isset($user['id'])) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized"
    ]);
    exit;
}

$record = getUserRecord($user['id']);

if (!$record) {
    http_response_code(404);
    echo json_encode([
        "success" => false,
        "message" => "User not found"
    ]);
    exit;
}

echo json_encode([
    "success" => true,
    "data" => [
        "id"            => (int)$record['id'],
        "google_id"     => $record['google_id'],
        "first_name"    => $record['first_name'],
        "middle_name"   => $record['middle_name'],
        "last_name"     => $record['last_name'],
        "email"         => $record['email'],
        "picture"       => $record['picture'],
        "auth_provider" => $record['auth_provider'],
        "role"          => $record['role'],
        "role_id"       => (int)$record['role_id'],
        "created_at"    => $record['created_at']
    ]
]);
