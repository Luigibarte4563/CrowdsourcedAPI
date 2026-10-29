<?php

require_once __DIR__ . '/../../config/cors.php';

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../../auth/rbac.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$actor = requireRole(requireAuthUser(), ['admin']);

/*
 * Role management - the one write the platform never had, because roles were granted
 * by editing users.role_id by hand. Three rules keep it safe:
 *
 *   1. Only an integer role_id that exists in `roles` is accepted (400 otherwise).
 *   2. An admin cannot change their OWN role - the last admin demoting themselves
 *      would have no way back in short of SQL.
 *   3. The role travels inside the JWT, so the change reaches the target's API
 *      permissions at their next sign-in (me.php, which re-reads the database,
 *      shows it on their next page load). The success message says so.
 */

$input = json_decode(file_get_contents("php://input"), true);
$userId = (int)($input['user_id'] ?? 0);
$roleId = (int)($input['role_id'] ?? 0);

if ($userId <= 0 || $roleId <= 0) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "user_id and role_id are required"]);
    exit;
}

if ($userId === (int)$actor['id']) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "You cannot change your own role"]);
    exit;
}

try {
    $roleStmt = $conn->prepare("SELECT id, role_name FROM roles WHERE id = ? LIMIT 1");
    $roleStmt->execute([$roleId]);
    $role = $roleStmt->fetch(PDO::FETCH_ASSOC);

    if (!$role) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Unknown role"]);
        exit;
    }

    $userStmt = $conn->prepare("
        SELECT u.id, r.role_name AS role
        FROM users u
        JOIN roles r ON r.id = u.role_id
        WHERE u.id = ? LIMIT 1
    ");
    $userStmt->execute([$userId]);
    $target = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$target) {
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "User not found"]);
        exit;
    }

    /* Idempotent no-op, the same contract as delete.php on an inactive row. */
    if ($role['role_name'] === $target['role']) {
        echo json_encode([
            "success" => true,
            "message" => "User already has that role",
            "data" => [
                "id"            => (int)$userId,
                "previous_role" => $target['role'],
                "role"          => $role['role_name'],
                "role_id"       => (int)$roleId,
            ],
        ]);
        exit;
    }

    /* updated_at is maintained by ON UPDATE CURRENT_TIMESTAMP. */
    $update = $conn->prepare("UPDATE users SET role_id = ? WHERE id = ?");
    $update->execute([$roleId, $userId]);

    echo json_encode([
        "success" => true,
        "message" => "Role updated. The new role takes effect for this user at their next sign-in.",
        "data" => [
            "id"            => (int)$userId,
            "previous_role" => $target['role'],
            "role"          => $role['role_name'],
            "role_id"       => (int)$roleId,
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error"]);
}
