<?php

require_once __DIR__ . '/../../config/cors.php';

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../../auth/rbac.php';
require_once __DIR__ . '/../../auth/lineman_access.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* Managers only - this is the staff-side picker, not a staff directory. */
requireRole(requireAuthUser(), LINEMAN_ASSIGNMENT_MANAGER_ROLES);

try {
    /*
     * The only user listing in the API, and it is deliberately narrow: users holding
     * the `lineman` role, exposing name and email and nothing else. No password hash,
     * no google_id, no refresh_token, no other role's accounts.
     *
     * The role comes from a JOIN on `roles` rather than from the JWT, so somebody
     * demoted since signing in drops out of the picker they would otherwise still be
     * offered in. This is what fills the "Select Lineman" dropdown.
     */
    $stmt = $conn->prepare("
        SELECT
            u.id,
            u.first_name,
            u.middle_name,
            u.last_name,
            u.email
        FROM users u
        JOIN roles r ON r.id = u.role_id
        WHERE r.role_name = 'lineman'
        ORDER BY u.last_name ASC, u.first_name ASC, u.id ASC
    ");
    $stmt->execute();

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[] = [
            "id"    => (int)$row['id'],
            "name"  => trim($row['first_name'] . ' ' . ($row['middle_name'] ? $row['middle_name'] . ' ' : '') . $row['last_name']),
            "email" => $row['email']
        ];
    }

    echo json_encode([
        "success" => true,
        "message" => "Linemen fetched successfully",
        "count"   => count($rows),
        "data"    => $rows
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error"]);
}