<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/db_connect.php';

use Firebase\JWT\JWT;

/**
 * Issues the application's own JWT (as an httpOnly cookie) for a user id.
 * The token carries the user id and role name so existing authorization
 * checks ($user['role'] === 'electric_company', etc.) keep working.
 *
 * Returns the token string on success, or null on failure.
 */
function issueJWT($userId) {
    $conn = getConnection();

    $stmt = $conn->prepare("
        SELECT u.id, u.email, r.role_name AS role
        FROM users u
        JOIN roles r ON r.id = u.role_id
        WHERE u.id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || empty($user['email'])) {
        return null;
    }

    $issuedAt = time();
    $expire   = $issuedAt + 86400; // 24 hours

    $payload = [
        'iat'   => $issuedAt,
        'exp'   => $expire,
        'id'    => (int)$user['id'],
        'email' => $user['email'],
        'role'  => $user['role']
    ];

    $token = JWT::encode($payload, $_ENV['JWT_SECRET_KEY'], 'HS256');

    setcookie('jwt_token', $token, $expire, '/', '', false, true);

    return $token;
}

/**
 * Resolve a role id from a role name (lookup table driven).
 */
function getRoleIdByName($roleName) {
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT id FROM roles WHERE role_name = ? LIMIT 1");
    $stmt->execute([$roleName]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['id'] : null;
}

/**
 * Resolve a role name from a role id.
 */
function getRoleNameById($roleId) {
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT role_name FROM roles WHERE id = ? LIMIT 1");
    $stmt->execute([$roleId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row['role_name'] : null;
}

/**
 * Loads the full user row (with role name joined) from the database by id.
 * Returns null if not found.
 */
function getUserRecord($userId) {
    $conn = getConnection();
    $stmt = $conn->prepare("
        SELECT u.*, r.role_name AS role
        FROM users u
        JOIN roles r ON r.id = u.role_id
        WHERE u.id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
