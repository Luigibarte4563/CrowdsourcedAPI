<?php

require_once __DIR__ . '/../../config/cors.php';

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../../auth/rbac.php';
require_once __DIR__ . '/../../auth/issue_jwt.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$actor = requireRole(requireAuthUser(), ['admin']);

/*
 * Role management - the one write the platform never had, because roles were granted
 * by editing users.role_id by hand. Three rules keep it safe:
 *
 *   1. Only a role name from the users.role ENUM is accepted (400 otherwise).
 *   2. An admin cannot change their OWN role - the last admin demoting themselves
 *      would have no way back in short of SQL.
 *   3. The role travels inside the JWT, so the change reaches the target's API
 *      permissions at their next sign-in (me.php, which re-reads the database,
 *      shows it on their next page load). The success message says so.
 */

$input = json_decode(file_get_contents("php://input"), true);
$userId = (int)($input['user_id'] ?? 0);
$role = ($input['role'] ?? '');

if ($userId <= 0 || $role === '') {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "user_id and role are required"]);
    exit;
}

if (!in_array($role, ['user', 'lineman', 'electric_company', 'admin'], true)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Unknown role"]);
    exit;
}

if ($userId === (int)$actor['id']) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "You cannot change your own role"]);
    exit;
}

try {
    $userStmt = $conn->prepare("
        SELECT u.id, u.role
        FROM users u
        WHERE u.id = ? LIMIT 1
    ");
    $userStmt->execute([$userId]);
    $target = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$target) {
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "User not found"]);
        exit;
    }

    /* Idempotent no-op. */
    if ($role === $target['role']) {
        echo json_encode([
            "success" => true,
            "message" => "User already has that role",
            "data" => [
                "id"            => (int)$userId,
                "previous_role" => $target['role'],
                "role"          => $role,
            ],
        ]);
        exit;
    }

    /* updated_at is maintained by ON UPDATE CURRENT_TIMESTAMP. */
    $update = $conn->prepare("UPDATE users SET role = ? WHERE id = ?");
    $update->execute([$role, $userId]);

    echo json_encode([
        "success" => true,
        "message" => "Role updated. The new role takes effect for this user at their next sign-in.",
        "data" => [
            "id"            => (int)$userId,
            "previous_role" => $target['role'],
            "role"          => $role,
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error"]);
}
