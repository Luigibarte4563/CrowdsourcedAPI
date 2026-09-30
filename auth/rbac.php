<?php

/**
 * Lightweight role-based access helpers.
 *
 * The JWT is issued only by this server (auth/issue_jwt.php), so the "role"
 * claim inside it is server-controlled and cannot be chosen by the frontend.
 * These helpers centralize role checks used by the endpoints.
 */

/**
 * Whether the authenticated user (from the decoded JWT payload) has one of
 * the given role names.
 */
function hasRole($user, array $allowedRoles) {
    if (!$user || !isset($user['role'])) {
        return false;
    }
    return in_array($user['role'], $allowedRoles, true);
}

/**
 * Build the standard 403 response and exit.
 */
function denyAccess($message = "Forbidden") {
    http_response_code(403);
    echo json_encode([
        "success" => false,
        "message" => $message
    ]);
    exit;
}

/**
 * Require an authenticated user. Returns the user payload or exits 401.
 */
function requireAuthUser() {
    $user = getUserFromJWT();
    if (!$user || !isset($user['id'])) {
        http_response_code(401);
        echo json_encode([
            "success" => false,
            "message" => "Unauthorized"
        ]);
        exit;
    }
    return $user;
}

/**
 * Require that the authenticated user has one of the listed roles.
 * Returns the user payload or exits 403.
 */
function requireRole($user, array $allowedRoles) {
    if (!hasRole($user, $allowedRoles)) {
        denyAccess();
    }
    return $user;
}
