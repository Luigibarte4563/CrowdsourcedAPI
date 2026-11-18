<?php

require_once __DIR__ . '/../../config/cors.php';

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../../auth/rbac.php';
require_once __DIR__ . '/../../auth/lineman_access.php';
require_once __DIR__ . '/../services/create_notification.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* Deactivation is recorded against the caller, so the row shows who withdrew it. */
$user = requireRole(requireAuthUser(), LINEMAN_ASSIGNMENT_MANAGER_ROLES);

$data = json_decode(file_get_contents("php://input"), true);
if (!is_array($data)) {
    $data = $_POST;
}
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid JSON input"]);
    exit;
}

$id = filter_var($data["id"] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "id must be a positive integer"]);
    exit;
}

try {
    /* Load the assignment details before deactivating so we can notify the lineman. */
    $assignmentStmt = $conn->prepare("
        SELECT la.lineman_id, la.barangay_id, b.barangay_name,
               u.first_name, u.middle_name, u.last_name
        FROM lineman_assignments la
        JOIN users u ON u.id = la.lineman_id
        LEFT JOIN barangays b ON b.id = la.barangay_id
        WHERE la.id = ?
        LIMIT 1
    ");
    $assignmentStmt->execute([$id]);
    $assignment = $assignmentStmt->fetch(PDO::FETCH_ASSOC);

    if (!$assignment) {
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Assignment not found"]);
        exit;
    }

    /* Deactivate, do not delete. */
    $stmt = $conn->prepare("
        UPDATE lineman_assignments
        SET status = 'inactive', assigned_by = ?
        WHERE id = ? AND status = 'active'
    ");
    $stmt->execute([current_user_id($user), $id]);

    if ($stmt->rowCount() === 0) {
        /* Either it never existed, or it was already inactive - tell the two apart. */
        if (!$assignment || $assignment['status'] !== 'active') {
            http_response_code(404);
            echo json_encode(["success" => false, "message" => "Assignment not found"]);
            exit;
        }

        echo json_encode([
            "success" => true,
            "message" => "Assignment is already inactive",
            "id" => (int)$assignment['id'],
            "status" => $assignment['status']
        ]);
        exit;
    }

    /* Notify the lineman that their assignment was removed. */
    $assignedByStmt = $conn->prepare("SELECT first_name, last_name FROM users WHERE id = ? LIMIT 1");
    $assignedByStmt->execute([current_user_id($user)]);
    $assignedByRow = $assignedByStmt->fetch(PDO::FETCH_ASSOC);
    $assignedByName = $assignedByRow
        ? trim(($assignedByRow['first_name'] ?? '') . ' ' . ($assignedByRow['last_name'] ?? ''))
        : 'Electric Company';
    $linemanFullName = trim(($assignment['first_name'] ?? '') . ' ' . (($assignment['middle_name'] ?? null) ? $assignment['middle_name'] . ' ' : '') . ($assignment['last_name'] ?? ''));

    createNotification(
        $conn,
        [(int)$assignment['lineman_id']],
        'Assignment Removed',
        "Hello {$linemanFullName}, your assignment to {$assignment['barangay_name']} has been removed by {$assignedByName}. You can no longer work on outage reports in that barangay.",
        'system'
    );

    echo json_encode([
        "success" => true,
        "message" => "Assignment deactivated",
        "id" => $id,
        "status" => "inactive"
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error"]);
}