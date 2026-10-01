<?php

require_once __DIR__ . '/../../config/cors.php';

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../../auth/rbac.php';
require_once __DIR__ . '/../../auth/lineman_access.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* assigned_by comes from the JWT identity below, never from the request body. */
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

/* =========================================
   INPUT
   ========================================= */
$linemanIdRaw  = $data["lineman_id"] ?? null;
$barangayIdRaw = $data["barangay_id"] ?? null;

$linemanId  = filter_var($linemanIdRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$barangayId = filter_var($barangayIdRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if ($linemanId === false) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "lineman_id must be a positive integer"]);
    exit;
}
if ($barangayId === false) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "barangay_id must be a positive integer"]);
    exit;
}

/* =========================================
   VALIDATION
   ========================================= */

/* The target must exist AND actually hold the `lineman` role. MariaDB cannot express
   "referenced user has role lineman" as a foreign key, so this is the only place that
   rule can live - it must never be skipped. A plain `user`, `electric_company` or
   `admin` id is rejected even though the id itself is valid. */
$linemanStmt = $conn->prepare("
    SELECT u.id, u.first_name, u.middle_name, u.last_name, u.email, r.role_name
    FROM users u
    JOIN roles r ON r.id = u.role_id
    WHERE u.id = ?
    LIMIT 1
");
$linemanStmt->execute([$linemanId]);
$lineman = $linemanStmt->fetch(PDO::FETCH_ASSOC);

if (!$lineman) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "The selected user does not exist"]);
    exit;
}

if ($lineman['role_name'] !== 'lineman') {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Only users with the lineman role can be assigned to a barangay"
    ]);
    exit;
}

$barangayStmt = $conn->prepare("SELECT id, barangay_name FROM barangays WHERE id = ? LIMIT 1");
$barangayStmt->execute([$barangayId]);
$barangay = $barangayStmt->fetch(PDO::FETCH_ASSOC);

if (!$barangay) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "The selected barangay does not exist"]);
    exit;
}

/* =========================================
   CREATE OR REACTIVATE
   ========================================= */
$assignedBy = current_user_id($user);

try {
    /*
     * The UNIQUE(lineman_id, barangay_id) key means a pair can only ever have one row.
     * An existing ACTIVE row is a genuine duplicate -> 409. An existing INACTIVE row is
     * the same relationship that was withdrawn, so it is reactivated in place: history
     * survives and no duplicate can appear.
     */
    $conn->beginTransaction();

    $existingStmt = $conn->prepare("
        SELECT id, status FROM lineman_assignments
        WHERE lineman_id = ? AND barangay_id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $existingStmt->execute([$linemanId, $barangayId]);
    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing && $existing['status'] === LINEMAN_ASSIGNMENT_ACTIVE) {
        $conn->rollBack();
        http_response_code(409);
        echo json_encode([
            "success" => false,
            "message" => "This lineman is already assigned to that barangay",
            "assignment_id" => (int)$existing['id']
        ]);
        exit;
    }

    if ($existing) {
        $assignmentId = (int)$existing['id'];
        $conn->prepare("
            UPDATE lineman_assignments
            SET status = 'active', assigned_by = ?
            WHERE id = ?
        ")->execute([$assignedBy, $assignmentId]);

        $reactivated = true;
    } else {
        $conn->prepare("
            INSERT INTO lineman_assignments (lineman_id, barangay_id, assigned_by, status)
            VALUES (?, ?, ?, 'active')
        ")->execute([$linemanId, $barangayId, $assignedBy]);

        $assignmentId = (int)$conn->lastInsertId();
        $reactivated = false;
    }

    $conn->commit();
} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    /* Belt and braces: a concurrent insert can win the race between the SELECT above
       and this INSERT, in which case the unique key is what stops the duplicate. */
    if ($e->getCode() === '23000') {
        http_response_code(409);
        echo json_encode([
            "success" => false,
            "message" => "This lineman is already assigned to that barangay"
        ]);
        exit;
    }

    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error"]);
    exit;
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error"]);
    exit;
}

/* =========================================
   RESPONSE
   ========================================= */
$readStmt = $conn->prepare("
    SELECT la.id, la.created_at, la.updated_at, la.status,
           au.first_name AS ab_first, au.middle_name AS ab_middle, au.last_name AS ab_last
    FROM lineman_assignments la
    JOIN users au ON au.id = la.assigned_by
    WHERE la.id = ?
    LIMIT 1
");
$readStmt->execute([$assignmentId]);
$saved = $readStmt->fetch(PDO::FETCH_ASSOC);

http_response_code(201);
echo json_encode([
    "success" => true,
    "message" => $reactivated ? "Assignment reactivated" : "Lineman assigned successfully",
    "data" => [
        "id"               => $assignmentId,
        "lineman_id"       => (int)$linemanId,
        "lineman_name"     => trim($lineman['first_name'] . ' ' . ($lineman['middle_name'] ? $lineman['middle_name'] . ' ' : '') . $lineman['last_name']),
        "lineman_email"    => $lineman['email'],
        "barangay_id"      => (int)$barangayId,
        "barangay_name"    => $barangay['barangay_name'],
        "assigned_by"      => $assignedBy,
        "assigned_by_name" => trim($saved['ab_first'] . ' ' . ($saved['ab_middle'] ? $saved['ab_middle'] . ' ' : '') . $saved['ab_last']),
        "assigned_at"      => $saved['created_at'],
        "updated_at"       => $saved['updated_at'],
        "status"           => $saved['status']
    ]
]);