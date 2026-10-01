<?php

require_once __DIR__ . '/../../config/cors.php';

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../../auth/rbac.php';
require_once __DIR__ . '/../../auth/lineman_access.php';

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
    /*
     * Deactivate, do not delete.
     *
     * The row is the record that this lineman covered this barangay and who put them
     * there, and outage_report_updates / outage_report_verifications reference the people
     * involved, not this pairing. Keeping the row means the audit trail and the
     * assigned_by attribution outlive the assignment, and it lets a later re-assignment
     * reactivate the same row instead of inventing a second one (see create.php).
     *
     * Filtering on `status = 'active'` makes the operation idempotent: removing an
     * already-removed assignment is a success, not a confusing 404.
     */
    $stmt = $conn->prepare("
        UPDATE lineman_assignments
        SET status = 'inactive', assigned_by = ?
        WHERE id = ? AND status = 'active'
    ");
    $stmt->execute([current_user_id($user), $id]);

    if ($stmt->rowCount() === 0) {
        /* Either it never existed, or it was already inactive - tell the two apart. */
        $existsStmt = $conn->prepare("SELECT id, status FROM lineman_assignments WHERE id = ? LIMIT 1");
        $existsStmt->execute([$id]);
        $existing = $existsStmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            http_response_code(404);
            echo json_encode(["success" => false, "message" => "Assignment not found"]);
            exit;
        }

        echo json_encode([
            "success" => true,
            "message" => "Assignment is already inactive",
            "id" => (int)$existing['id'],
            "status" => $existing['status']
        ]);
        exit;
    }

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