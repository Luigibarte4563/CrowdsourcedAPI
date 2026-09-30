<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../../auth/rbac.php';
require_once __DIR__ . '/../services/create_notification.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$user_id = (int)requireAuthUser()['id'];

try {
    $stmt = $conn->prepare("
        SELECT t.*, tt.timer_name, tt.default_duration_hours, tt.warning_hours_before, tt.description AS type_description
        FROM safety_timers t
        LEFT JOIN safety_timer_types tt ON tt.id = t.timer_type_id
        WHERE t.user_id = :user_id
        ORDER BY t.id DESC
    ");
    $stmt->execute([":user_id" => $user_id]);
    $timers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $alertInsert = $conn->prepare("
        INSERT IGNORE INTO safety_timer_alerts (safety_timer_id, alert_type, sent_at, is_sent)
        VALUES (?, ?, NOW(), 1)
    ");
    $alertCheck = $conn->prepare("
        SELECT id FROM safety_timer_alerts WHERE safety_timer_id = ? AND alert_type = ? AND is_sent = 1 LIMIT 1
    ");
    $statusUpdate = $conn->prepare("UPDATE safety_timers SET status = ? WHERE id = ? AND user_id = ?");

    foreach ($timers as &$timer) {
        $now = time();

        if ($timer['status'] === 'stopped') {
            $status = 'stopped';
        } elseif (strtotime($timer['expected_expiration_at']) <= $now) {
            $status = 'expired';
        } elseif (!empty($timer['warning_at']) && strtotime($timer['warning_at']) <= $now) {
            $status = 'warning';
        } else {
            $status = 'running';
        }

        $timer['status'] = $status;
        $statusUpdate->execute([$status, $timer['id'], $user_id]);

        /* Record one-time alert (warning / expired) */
        if ($status === 'warning' || $status === 'expired') {
            $alertCheck->execute([$timer['id'], $status]);
            if (!$alertCheck->fetch()) {
                $alertInsert->execute([$timer['id'], $status]);
                createNotification(
                    $conn,
                    [$user_id],
                    "Safety Timer: " . $timer['title'],
                    "Your safety timer \"" . $timer['title'] . "\" has reached its " . $status . " time."
                        . " This is a reminder only.",
                    "safety_timer",
                    (int)$timer['id'],
                    "safety_timer"
                );
            }
        }

        $timer['remaining_seconds'] =
            $status === 'stopped' ? 0 : max(0, strtotime($timer['expected_expiration_at']) - $now);
        $timer['timer_id'] = (int)$timer['id'];
    }
    unset($timer);

    echo json_encode([
        "success" => true,
        "count" => count($timers),
        "data" => $timers
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error"]);
}
