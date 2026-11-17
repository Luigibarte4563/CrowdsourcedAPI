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
/**
 * Valid role values, matching the `users.role` ENUM.
 */
const VALID_ROLES = ['user', 'lineman', 'electric_company', 'admin'];

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
        SELECT u.id, u.email, u.role
        FROM users u
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
 * Validate a role name against the users.role ENUM. Returns the name if valid, null otherwise.
 */
function validateRole($roleName) {
    return in_array($roleName, VALID_ROLES, true) ? $roleName : null;
}

/**
 * Loads the full user row from the database by id.
 * Returns null if not found. `role` is already a column on users (ENUM), no JOIN needed.
 */
function getUserRecord($userId) {
    $conn = getConnection();
    $stmt = $conn->prepare("
        SELECT u.*
        FROM users u
        WHERE u.id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
