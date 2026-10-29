<?php

require_once __DIR__ . '/../../config/cors.php';

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../../auth/rbac.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* The admin user directory is the one allow-list in the API with a single entry. */
requireRole(requireAuthUser(), ['admin']);

try {
    $conditions = [];
    $params = [];

    /* Optional filters: ?role=<role_name> and ?q=<name or email substring>. */
    $role = trim($_GET['role'] ?? '');
    if ($role !== '') {
        $conditions[] = "r.role_name = :role";
        $params[':role'] = $role;
    }

    $q = trim($_GET['q'] ?? '');
    if ($q !== '') {
        $conditions[] = "(u.first_name LIKE :q OR u.last_name LIKE :q OR u.email LIKE :q)";
        $params[':q'] = '%' . $q . '%';
    }

    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

    /*
     * The full user directory, widened from lineman_assignment/linemen.php to every
     * role. Same discipline: name, email, role, provider and the two timestamps the
     * directory shows - no password hash, no google_id, no refresh_token, ever.
     * The role comes from the JOIN, so a change made here is reflected on the next
     * read rather than only at the target's next sign-in.
     */
    $stmt = $conn->prepare("
        SELECT
            u.id,
            u.first_name,
            u.last_name,
            u.email,
            u.auth_provider,
            u.role_id,
            r.role_name,
            u.last_login,
            u.created_at
        FROM users u
        JOIN roles r ON r.id = u.role_id
        $where
        ORDER BY u.created_at DESC, u.id DESC
    ");
    $stmt->execute($params);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[] = [
            "id"            => (int)$row['id'],
            "name"          => trim($row['first_name'] . ' ' . $row['last_name']),
            "email"         => $row['email'],
            "auth_provider" => $row['auth_provider'],
            "role_id"       => (int)$row['role_id'],
            "role"          => $row['role_name'],
            "last_login"    => $row['last_login'],
            "created_at"    => $row['created_at'],
        ];
    }

    echo json_encode([
        "success" => true,
        "message" => "Users fetched successfully",
        "count"   => count($rows),
        "data"    => $rows
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error"]);
}
