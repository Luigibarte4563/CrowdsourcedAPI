<?php

require_once __DIR__ . '/../../config/cors.php';

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../../auth/rbac.php';
require_once __DIR__ . '/../../auth/lineman_access.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* Managers only. A lineman or a resident asking who covers what is answered 403. */
requireRole(requireAuthUser(), LINEMAN_ASSIGNMENT_MANAGER_ROLES);

/*
 * Optional filters. Each is validated before it reaches the query; an id that is not a
 * positive integer is a client bug, and silently ignoring it would return "everything"
 * in answer to a request that meant "one row".
 */
$linemanId = null;
if (isset($_GET['lineman_id']) && $_GET['lineman_id'] !== '') {
    $linemanId = filter_var($_GET['lineman_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($linemanId === false) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Invalid lineman_id"]);
        exit;
    }
}

$barangayId = null;
if (isset($_GET['barangay_id']) && $_GET['barangay_id'] !== '') {
    $barangayId = filter_var($_GET['barangay_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($barangayId === false) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Invalid barangay_id"]);
        exit;
    }
}

$status = null;
if (isset($_GET['status']) && $_GET['status'] !== '') {
    $status = strtolower(trim($_GET['status']));
    if (!in_array($status, LINEMAN_ASSIGNMENT_STATUSES, true)) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Invalid status"]);
        exit;
    }
}

/*
 * Reads the role from `roles` rather than trusting a JWT claim, so a lineman demoted
 * since signing in stops appearing in the picker they are assigned from.
 */
$sql = "
    SELECT
        la.id,
        la.lineman_id,
        lu.first_name,
        lu.middle_name,
        lu.last_name,
        lu.email,
        la.barangay_id,
        b.barangay_name,
        la.assigned_by,
        au.first_name AS assigned_by_first_name,
        au.middle_name AS assigned_by_middle_name,
        au.last_name  AS assigned_by_last_name,
        la.created_at,
        la.updated_at,
        la.status
    FROM lineman_assignments la
    JOIN users lu      ON lu.id = la.lineman_id
    JOIN roles lr      ON lr.id = lu.role_id
    JOIN barangays b   ON b.id  = la.barangay_id
    JOIN users au      ON au.id = la.assigned_by
    WHERE 1=1
";

$params = [];

if ($linemanId !== null) {
    $sql .= " AND la.lineman_id = :lineman_id";
    $params[':lineman_id'] = $linemanId;
}
if ($barangayId !== null) {
    $sql .= " AND la.barangay_id = :barangay_id";
    $params[':barangay_id'] = $barangayId;
}
if ($status !== null) {
    $sql .= " AND la.status = :status";
    $params[':status'] = $status;
}

/* Defensive: a row whose user is somehow no longer a lineman is not an assignment. */
$sql .= " AND lr.role_name = 'lineman'";

$sql .= " ORDER BY la.status ASC, la.created_at DESC, la.id DESC";

try {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[] = [
            "id"               => (int)$row['id'],
            "lineman_id"       => (int)$row['lineman_id'],
            "lineman_name"     => trim($row['first_name'] . ' ' . ($row['middle_name'] ? $row['middle_name'] . ' ' : '') . $row['last_name']),
            "lineman_email"    => $row['email'],
            "barangay_id"      => (int)$row['barangay_id'],
            "barangay_name"    => $row['barangay_name'],
            "assigned_by"      => (int)$row['assigned_by'],
            "assigned_by_name" => trim($row['assigned_by_first_name'] . ' ' . ($row['assigned_by_middle_name'] ? $row['assigned_by_middle_name'] . ' ' : '') . $row['assigned_by_last_name']),
            /* The column is created_at, matching every other table; the API calls it
               assigned_at because that is what the assignment date means to a reader. */
            "assigned_at"      => $row['created_at'],
            "updated_at"       => $row['updated_at'],
            "status"           => $row['status']
        ];
    }

    echo json_encode([
        "success" => true,
        "message" => "Lineman assignments fetched successfully",
        "count"   => count($rows),
        "data"    => $rows
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error"]);
}