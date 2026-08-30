<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/issue_jwt.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* =========================================
   INPUT
========================================= */
$data = json_decode(file_get_contents("php://input"), true);

if (!is_array($data)) {
    $data = $_POST;
}

$email    = strtolower(trim($data["email"] ?? ""));
$password = $data["password"] ?? "";

if ($email === "" || $password === "") {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "email and password are required"
    ]);
    exit;
}

/* =========================================
   FETCH USER (local provider only)
========================================= */
try {
    $stmt = $conn->prepare("
        SELECT id, password, auth_provider
        FROM users
        WHERE email = ?
        LIMIT 1
    ");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || $user['auth_provider'] !== 'local' || empty($user['password'])) {
        http_response_code(401);
        echo json_encode([
            "success" => false,
            "message" => "Invalid credentials"
        ]);
        exit;
    }

    if (!password_verify($password, $user['password'])) {
        http_response_code(401);
        echo json_encode([
            "success" => false,
            "message" => "Invalid credentials"
        ]);
        exit;
    }

    $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")
        ->execute([$user['id']]);

    $token = issueJWT($user['id']);

    echo json_encode([
        "success" => true,
        "message" => "Login successful",
        "user_id" => (int)$user['id'],
        "token_issued" => $token !== null
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Login failed"]);
}
