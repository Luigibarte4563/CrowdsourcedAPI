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
    /* If a timer_type is provided, use its defaults */
    if ($timer_type_name !== "") {
        $typeStmt = $conn->prepare("
            SELECT id, default_duration_hours, warning_hours_before
            FROM safety_timer_types WHERE timer_name = ? LIMIT 1
        ");
        $typeStmt->execute([$timer_type_name]);
        $timerType = $typeStmt->fetch(PDO::FETCH_ASSOC);

        if (!$timerType) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Unknown timer_type_name"]);
            exit;
        }

        $timer_type_id = (int)$timerType['id'];
        $durationHours = (float)$timerType['default_duration_hours'];
        $warningHours  = (float)$timerType['warning_hours_before'];
    } else {
        $durationHours = isset($data["duration_hours"]) ? (float)$data["duration_hours"] : null;
        $warningHours  = isset($data["warning_hours_before"]) ? (float)$data["warning_hours_before"] : 0;
        $timer_type_id = null;

        if ($durationHours === null || $durationHours <= 0) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "timer_type_name or duration_hours is required"]);
            exit;
        }

        /* generic default type if none matched */
        $defStmt = $conn->prepare("SELECT id FROM safety_timer_types WHERE timer_name = 'medication' LIMIT 1");
        $defStmt->execute();
        $defRow = $defStmt->fetch(PDO::FETCH_ASSOC);
        $timer_type_id = $defRow ? (int)$defRow['id'] : null;
    }

    if ($title === "") {
        $title = $timer_type_name !== "" ? $timer_type_name : "Safety timer";
    }

    $startedAt = isset($data["started_at"]) ? date("Y-m-d H:i:s", strtotime($data["started_at"])) : date("Y-m-d H:i:s");

    $expectedExpiration = date("Y-m-d H:i:s", strtotime("$startedAt +" . ($durationHours * 3600) . " seconds"));
    $warningAt = ($warningHours > 0)
        ? date("Y-m-d H:i:s", strtotime("$expectedExpiration -" . ($warningHours * 3600) . " seconds"))
        : null;

    $stmt = $conn->prepare("
        INSERT INTO safety_timers (
            user_id,
            timer_type_id,
            title,
            started_at,
            expected_expiration_at,
            warning_at,
            notes
        ) VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $user_id,
        $timer_type_id,
        $title,
        $startedAt,
        $expectedExpiration,
        $warningAt,
        $notes !== "" ? $notes : null
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Safety timer started",
        "timer_id" => (int)$conn->lastInsertId(),
        "started_at" => $startedAt,
        "warning_at" => $warningAt,
        "expected_expiration_at" => $expectedExpiration,
        "status" => "running"
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error"]);
}
