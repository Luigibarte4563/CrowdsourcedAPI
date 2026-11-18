<?php

require_once __DIR__ . '/../../config/cors.php';

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../../auth/rbac.php';
require_once __DIR__ . '/../../auth/lineman_access.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* Staff-only: electric_company / admin can view any lineman's activity;
   linemen can view their own. */
$user = requireAuthUser();
$isManager = hasRole($user, LINEMAN_ASSIGNMENT_MANAGER_ROLES);

$data = json_decode(file_get_contents("php://input"), true);
if (!is_array($data)) {
    $data = $_GET;
}

$linemanIdRaw = $data["lineman_id"] ?? ($isManager ? null : $user['id']);
$limit        = (int)($data["limit"] ?? 50);
$limit        = max(1, min($limit, 200));
$workFilter   = $data["work_type"] ?? 'all'; // 'all', 'done', 'pending'

if ($linemanIdRaw === null) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "lineman_id is required"]);
    exit;
}

$linemanId = filter_var($linemanIdRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($linemanId === false) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "lineman_id must be a positive integer"]);
    exit;
}

/* A lineman can only view their own activity; managers can view anyone's. */
if (!$isManager && (int)$user['id'] !== $linemanId) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "You can only view your own activity"]);
    exit;
}

/* Confirm the target is actually a lineman. */
$targetStmt = $conn->prepare("SELECT id, first_name, middle_name, last_name, email, role FROM users WHERE id = :id LIMIT 1");
$targetStmt->bindValue(':id', $linemanId, PDO::PARAM_INT);
$targetStmt->execute();
$target = $targetStmt->fetch(PDO::FETCH_ASSOC);

if (!$target) {
    http_response_code(404);
    echo json_encode(["success" => false, "message" => "User not found"]);
    exit;
}

if ($target['role'] !== 'lineman') {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "User is not a lineman"]);
    exit;
}

/* ============================================================
   1) VERIFICATIONS - reports this lineman has verified
   ============================================================ */
$verifications = [];
try {
    $sql = "
        SELECT
            v.id,
            v.outage_report_id,
            v.verification_status,
            v.notes,
            v.verified_at,
            o.report_key,
            o.location_name,
            o.status AS report_status,
            o.category,
            o.severity,
            b.barangay_name
        FROM outage_report_verifications v
        JOIN outage_reports o ON o.id = v.outage_report_id
        LEFT JOIN barangays b ON b.id = o.barangay_id
        WHERE v.verified_by = :lineman_id
    ";
    $params = [':lineman_id' => $linemanId];

    if ($workFilter === 'done') {
        $sql .= " AND o.status IN ('resolved', 'verified')";
    } elseif ($workFilter === 'pending') {
        $sql .= " AND o.status NOT IN ('resolved', 'verified')";
    }

    $sql .= " ORDER BY v.verified_at DESC LIMIT :limit";
    $params[':limit'] = $limit;

    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $isDone = in_array($row['report_status'], ['resolved', 'verified'], true);
        $verifications[] = [
            "type"             => "verification",
            "id"               => (int)$row['id'],
            "outage_report_id" => (int)$row['outage_report_id'],
            "report_key"       => $row['report_key'],
            "location_name"    => $row['location_name'],
            "barangay_name"    => $row['barangay_name'],
            "category"         => $row['category'],
            "severity"         => $row['severity'],
            "report_status"    => $row['report_status'],
            "verification"     => $row['verification_status'],
            "notes"            => $row['notes'],
            "performed_at"     => $row['verified_at'],
            "is_done"          => $isDone,
            "action"           => $row['verification_status'] === 'confirmed'
                ? "Verified outage report"
                : ($row['verification_status'] === 'false_report'
                    ? "Marked outage report as false"
                    : "Reviewed outage report"),
        ];
    }
} catch (Throwable $e) {
    $verifications = [];
}

/* ============================================================
   2) UPDATES - reports this lineman has updated (status/message changes)
   ============================================================ */
$updates = [];
try {
    $sql = "
        SELECT
            o.id,
            o.report_key,
            o.location_name,
            o.status,
            o.category,
            o.severity,
            o.update_message,
            o.updated_at,
            b.barangay_name
        FROM outage_reports o
        LEFT JOIN barangays b ON b.id = o.barangay_id
        WHERE o.updated_by = :lineman_id
          AND o.update_message IS NOT NULL AND o.update_message <> ''
    ";
    $params = [':lineman_id' => $linemanId];

    if ($workFilter === 'done') {
        $sql .= " AND o.status IN ('resolved', 'verified')";
    } elseif ($workFilter === 'pending') {
        $sql .= " AND o.status NOT IN ('resolved', 'verified')";
    }

    $sql .= " ORDER BY o.updated_at DESC LIMIT :limit";
    $params[':limit'] = $limit;

    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $isDone = in_array($row['status'], ['resolved', 'verified'], true);
        $updates[] = [
            "type"             => "update",
            "id"               => (int)$row['id'],
            "outage_report_id" => (int)$row['id'],
            "report_key"       => $row['report_key'],
            "location_name"    => $row['location_name'],
            "barangay_name"    => $row['barangay_name'],
            "category"         => $row['category'],
            "severity"         => $row['severity'],
            "report_status"    => $row['status'],
            "message"          => $row['update_message'],
            "performed_at"     => $row['updated_at'],
            "is_done"          => $isDone,
            "action"           => "Updated outage report",
        ];
    }
} catch (Throwable $e) {
    $updates = [];
}

/* Merge and sort all work activities by performed_at descending. */
$allActivities = array_merge($verifications, $updates);
usort($allActivities, function ($a, $b) {
    return strtotime($b['performed_at'] ?? '') <=> strtotime($a['performed_at'] ?? '');
});

$allActivities = array_slice($allActivities, 0, $limit);

/* Count done work (resolved or verified reports the lineman worked on) */
$doneCount = 0;
try {
    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT o.id) as done_count
        FROM outage_reports o
        LEFT JOIN outage_report_verifications v ON v.outage_report_id = o.id AND v.verified_by = :lineman_id
        WHERE (o.updated_by = :lineman_id OR v.verified_by = :lineman_id)
          AND o.status IN ('resolved', 'verified')
    ");
    $stmt->bindValue(':lineman_id', $linemanId, PDO::PARAM_INT);
    $stmt->execute();
    $doneRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $doneCount = (int)($doneRow['done_count'] ?? 0);
} catch (Throwable $e) {
    $doneCount = 0;
}

echo json_encode([
    "success" => true,
    "lineman" => [
        "id"       => (int)$target['id'],
        "name"     => trim(($target['first_name'] ?? '') . ' ' . ($target['middle_name'] ?? '') . ($target['last_name'] ?? '')),
        "email"    => $target['email'],
        "role"     => $target['role'],
    ],
    "summary" => [
        "total_activities" => count($allActivities),
        "total_done"       => $doneCount,
        "work_filter"      => $workFilter,
    ],
    "activities" => $allActivities,
]);
