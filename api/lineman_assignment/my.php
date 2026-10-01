<?php

require_once __DIR__ . '/../../config/cors.php';

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../../auth/rbac.php';
require_once __DIR__ . '/../../auth/lineman_access.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/*
 * Lineman only.
 *
 * The identity is taken from the JWT and nothing else. There is deliberately no
 * ?lineman_id= parameter to read: a lineman asking for somebody else's assignments must
 * not be able to ask the question, rather than being refused the answer.
 */
$user = requireRole(requireAuthUser(), ['lineman']);

try {
    $stmt = $conn->prepare("
        SELECT la.id, la.barangay_id, b.barangay_name, la.status
        FROM lineman_assignments la
        JOIN barangays b ON b.id = la.barangay_id
        WHERE la.lineman_id = ?
          AND la.status = ?
        ORDER BY b.barangay_name ASC
    ");
    $stmt->execute([current_user_id($user), LINEMAN_ASSIGNMENT_ACTIVE]);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[] = [
            "id"            => (int)$row['id'],
            "barangay_id"   => (int)$row['barangay_id'],
            "barangay_name" => $row['barangay_name'],
            "status"        => $row['status']
        ];
    }

    echo json_encode([
        "success" => true,
        "message" => "Your assigned barangays",
        "count"   => count($rows),
        "data"    => $rows
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error"]);
}