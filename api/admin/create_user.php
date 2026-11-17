<?php

require_once __DIR__ . '/../../config/cors.php';

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../../auth/rbac.php';
require_once __DIR__ . '/../../auth/issue_jwt.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

requireRole(requireAuthUser(), ['admin']);

/*
 * Admin user creation - the authenticated counterpart of auth/register.php, with the
 * one power registration deliberately refuses: choosing the role. register.php is
 * unauthenticated, so it ignores any role in the body (anybody could mint an admin);
 * here the caller already passed requireRole(['admin']), so the role_id is honoured -
 * validated against `roles`, defaulting to plain `user` when omitted.
 *
 * Validation mirrors register.php exactly: required fields, email filter, password of
 * at least 6 characters, duplicate email is a 409. Passwords are hashed with
 * PASSWORD_DEFAULT and auth_provider is 'local', so the account signs in through the
 * ordinary email + password form. No JWT is issued - the new user signs in themselves.
 */

$data = json_decode(file_get_contents("php://input"), true);
if (!is_array($data)) {
    $data = $_POST;
}

$first_name  = trim($data["first_name"] ?? "");
$middle_name = trim($data["middle_name"] ?? "");
$last_name   = trim($data["last_name"] ?? "");
$email       = strtolower(trim($data["email"] ?? ""));
$password    = $data["password"] ?? "";
$role        = ($data["role"] ?? 'user');

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
    echo json_encode(["success" => false, "message" => "Invalid email address"]);
    exit;
}

if (strlen($password) < 6) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Password must be at least 6 characters"]);
    exit;
}

try {
    if (!in_array($role, ['user', 'lineman', 'electric_company', 'admin'], true)) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Unknown role"]);
        exit;
    }

    $dupStmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $dupStmt->execute([$email]);
    if ($dupStmt->fetch()) {
        http_response_code(409);
        echo json_encode(["success" => false, "message" => "Email already registered"]);
        exit;
    }

    $insert = $conn->prepare("
        INSERT INTO users (
            first_name,
            middle_name,
            last_name,
            email,
            password,
            auth_provider,
            role
        ) VALUES (?, ?, ?, ?, ?, 'local', ?)
    ");
    $insert->execute([
        $first_name,
        $middle_name === "" ? null : $middle_name,
        $last_name,
        $email,
        password_hash($password, PASSWORD_DEFAULT),
        $role
    ]);

    http_response_code(201);
    echo json_encode([
        "success" => true,
        "message" => "User created",
        "data" => [
            "id"            => (int)$conn->lastInsertId(),
            "name"          => trim($first_name . ' ' . $last_name),
            "email"         => $email,
            "auth_provider" => 'local',
            "role"          => $role,
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error"]);
}
