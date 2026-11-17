<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../../auth/rbac.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$user_id = (int)requireAuthUser()['id'];

$data = json_decode(file_get_contents("php://input"), true);
if (!is_array($data)) {
    $data = $_POST;
}

$title           = trim($data["title"] ?? "");
$timer_type_name = trim($data["timer_type_name"] ?? "");
$notes           = trim($data["notes"] ?? "");

$durationHours = null;
$warningHours  = null;

try {
    /* Timer type and its defaults from backend constants */
    $timerType = getTimerType($timer_type_name);
    $timerDefaults = $timerType ? getTimerDefaults($timerType) : null;

    if ($timer_type_name !== "") {
        if (!$timerType) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Unknown timer_type_name"]);
            exit;
        }
        $durationHours = $timerDefaults['duration'];
        $warningHours  = $timerDefaults['warning'];
    } else {
        $durationHours = isset($data["duration_hours"]) ? (float)$data["duration_hours"] : null;
        $warningHours  = isset($data["warning_hours_before"]) ? (float)$data["warning_hours_before"] : 0;

        if ($durationHours === null || $durationHours <= 0) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "timer_type_name or duration_hours is required"]);
            exit;
        }
    }

    if ($title === "") {
        $title = $timer_type_name !== "" ? $timer_type_name : "Safety timer";
    }

    /*
     * All timer timestamps are computed by MySQL (NOW()) - never PHP date().
     * PHP's clock/timezone differs from the DB session timezone on this stack,
     * so rows written from PHP landed hours in the past and every new timer
     * looked expired the moment it was created.
     */
    $durationMinutes = (int) round($durationHours * 60);
    $warningMinutes  = (int) round($warningHours * 60);

    $stmt = $conn->prepare("
        INSERT INTO safety_timers (
            user_id,
            timer_type,
            title,
            started_at,
            expected_expiration_at,
            warning_at,
            notes
        ) VALUES (
            ?, ?, ?,
            NOW(),
            DATE_ADD(NOW(), INTERVAL ? MINUTE),
            IF(? > 0, DATE_SUB(DATE_ADD(NOW(), INTERVAL ? MINUTE), INTERVAL ? MINUTE), NULL),
            ?
        )
    ");
    $stmt->execute([
        $user_id,
        $timerType,
        $title,
        $durationMinutes,
        $warningMinutes,
        $durationMinutes,
        $warningMinutes,
        $notes !== "" ? $notes : null
    ]);

    $timerId = (int)$conn->lastInsertId();

    $rowStmt = $conn->prepare("
        SELECT started_at, expected_expiration_at, warning_at
        FROM safety_timers WHERE id = ?
    ");
    $rowStmt->execute([$timerId]);
    $row = $rowStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "message" => "Safety timer started",
        "timer_id" => $timerId,
        "started_at" => $row['started_at'],
        "warning_at" => $row['warning_at'],
        "expected_expiration_at" => $row['expected_expiration_at'],
        "status" => "running"
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error"]);
}
