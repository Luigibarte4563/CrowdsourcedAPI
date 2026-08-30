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

$first_name  = trim($data["first_name"]  ?? "");
$middle_name = trim($data["middle_name"] ?? "");
$last_name   = trim($data["last_name"]   ?? "");
$email       = strtolower(trim($data["email"] ?? ""));
$password    = $data["password"] ?? "";

/* =========================================
   VALIDATION
========================================= */
if ($first_name === "" || $last_name === "" || $email === "" || $password === "") {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "first_name, last_name, email and password are required"
    ]);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Invalid email address"
    ]);
    exit;
}

if (strlen($password) < 6) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Password must be at least 6 characters"
    ]);
    exit;
}

/* =========================================
   DUPLICATE CHECK
========================================= */
try {
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode([
            "success" => false,
            "message" => "Email already registered"
        ]);
        exit;
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database error"]);
    exit;
}

/* =========================================
   NEW USERS ALWAYS RECEIVE THE "user" ROLE.
   Never accept a role from the frontend.
========================================= */
$roleId = getRoleIdByName('user');
if (!$roleId) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Role configuration missing"]);
    exit;
}

$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

try {
    $insert = $conn->prepare("
        INSERT INTO users (
            first_name,
            middle_name,
            last_name,
            email,
            password,
            auth_provider,
            role_id
        ) VALUES (?, ?, ?, ?, ?, 'local', ?)
    ");

    $insert->execute([
        $first_name,
        $middle_name === "" ? null : $middle_name,
        $last_name,
        $email,
        $hashedPassword,
        $roleId
    ]);

    $userId = (int)$conn->lastInsertId();

    $token = issueJWT($userId);

    echo json_encode([
        "success" => true,
        "message" => "Registration successful",
        "user_id" => $userId,
        "token_issued" => $token !== null
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Registration failed"]);
}
