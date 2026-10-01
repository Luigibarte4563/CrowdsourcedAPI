<?php

require_once __DIR__ . '/../../config/cors.php';

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../../auth/rbac.php';
require_once __DIR__ . '/../../auth/lineman_access.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* assigned_by is rewritten to this identity; a client-supplied value is ignored. */
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
$id = filter_var($data["id"] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "id must be a positive integer"]);
    exit;
}

/*
 * lineman_id, barangay_id and status are each optional: send only what changes. Falling
 * back to the stored value keeps this a partial update, matching maintenance/update.php,
 * so a status-only edit cannot accidentally blank the barangay.
 */
$linemanIdRaw  = array_key_exists("lineman_id", $data) ? $data["lineman_id"] : null;
$barangayIdRaw = array_key_exists("barangay_id", $data) ? $data["barangay_id"] : null;
$statusRaw     = array_key_exists("status", $data) ? $data["status"] : null;

$linemanId  = null;
$barangayId = null;
$status     = null;

if ($linemanIdRaw !== null) {
    $linemanId = filter_var($linemanIdRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($linemanId === false) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "lineman_id must be a positive integer"]);
        exit;
    }
}

if ($barangayIdRaw !== null) {
    $barangayId = filter_var($barangayIdRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($barangayId === false) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "barangay_id must be a positive integer"]);
        exit;
    }
}

if ($statusRaw !== null && $statusRaw !== '') {
    $status = strtolower(trim((string)$statusRaw));
    if (!in_array($status, LINEMAN_ASSIGNMENT_STATUSES, true)) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Invalid status"]);
        exit;
    }
}

/* =========================================
   LOAD CURRENT VALUES
   ========================================= */
$currentStmt = $conn->prepare("
    SELECT la.id, la.lineman_id, la.barangay_id, la.status
    FROM lineman_assignments la
    WHERE la.id = ?
    LIMIT 1
");
$currentStmt->execute([$id]);
$current = $currentStmt->fetch(PDO::FETCH_ASSOC);

if (!$current) {
    http_response_code(404);
    echo json_encode(["success" => false, "message" => "Assignment not found"]);
    exit;
}

$targetLinemanId  = $linemanId ?? (int)$current['lineman_id'];
$targetBarangayId = $barangayId ?? (int)$current['barangay_id'];
$targetStatus     = $status ?? $current['status'];

/* =========================================
   VALIDATE EVERY CHANGED VALUE
   ========================================= */

/* Same rule as create.php: the target's role is read from `roles`, never from a JWT or
   the client's word. Validated even when lineman_id was omitted, because a lineman may
   have been demoted since the row was written. */
$linemanStmt = $conn->prepare("
    SELECT u.id, u.first_name, u.middle_name, u.last_name, u.email, r.role_name
    FROM users u
    JOIN roles r ON r.id = u.role_id
    WHERE u.id = ?
    LIMIT 1
");
$linemanStmt->execute([$targetLinemanId]);
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
$barangayStmt->execute([$targetBarangayId]);
$barangay = $barangayStmt->fetch(PDO::FETCH_ASSOC);

if (!$barangay) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "The selected barangay does not exist"]);
    exit;
}

/* =========================================
   WRITE
   ========================================= */
$assignedBy = current_user_id($user);

try {
    /*
     * Moving a lineman onto a pair that already exists would violate UNIQUE(lineman_id,
     * barangay_id). Checked explicitly so the caller gets a 409 explaining the clash
     * rather than a bare 500 from the driver. The `id <> ?` guard lets an edit that
     * changes nothing pass through.
     */
    $clashStmt = $conn->prepare("
        SELECT id FROM lineman_assignments
        WHERE lineman_id = ? AND barangay_id = ? AND id <> ?
        LIMIT 1
    ");
    $clashStmt->execute([$targetLinemanId, $targetBarangayId, $id]);
    if ($clashStmt->fetch()) {
        http_response_code(409);
        echo json_encode([
            "success" => false,
            "message" => "That lineman is already assigned to the selected barangay"
        ]);
        exit;
    }

    $conn->prepare("
        UPDATE lineman_assignments
        SET lineman_id = ?, barangay_id = ?, status = ?, assigned_by = ?
        WHERE id = ?
    ")->execute([$targetLinemanId, $targetBarangayId, $targetStatus, $assignedBy, $id]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        http_response_code(409);
        echo json_encode([
            "success" => false,
            "message" => "That lineman is already assigned to the selected barangay"
        ]);
        exit;
    }

    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error"]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error"]);
    exit;
}

/* =========================================
   RESPONSE
   ========================================= */
$readStmt = $conn->prepare("
    SELECT la.created_at, la.updated_at,
           au.first_name AS ab_first, au.middle_name AS ab_middle, au.last_name AS ab_last
    FROM lineman_assignments la
    JOIN users au ON au.id = la.assigned_by
    WHERE la.id = ?
    LIMIT 1
");
$readStmt->execute([$id]);
$saved = $readStmt->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    "success" => true,
    "message" => "Assignment updated successfully",
    "data" => [
        "id"               => $id,
        "lineman_id"       => $targetLinemanId,
        "lineman_name"     => trim($lineman['first_name'] . ' ' . ($lineman['middle_name'] ? $lineman['middle_name'] . ' ' : '') . $lineman['last_name']),
        "lineman_email"    => $lineman['email'],
        "barangay_id"      => $targetBarangayId,
        "barangay_name"    => $barangay['barangay_name'],
        "assigned_by"      => $assignedBy,
        "assigned_by_name" => trim($saved['ab_first'] . ' ' . ($saved['ab_middle'] ? $saved['ab_middle'] . ' ' : '') . $saved['ab_last']),
        "assigned_at"      => $saved['created_at'],
        "updated_at"       => $saved['updated_at'],
        "status"           => $targetStatus
    ]
]);