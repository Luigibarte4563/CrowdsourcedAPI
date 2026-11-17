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
    /*
     * Status and remaining time are computed by MySQL (NOW()) - never PHP time().
     * PHP's clock/timezone differs from the DB session timezone on this stack, so
     * comparing stored timestamps against PHP time() mislabelled timers.
     */
    $stmt = $conn->prepare("
        SELECT t.*,
            CASE
                WHEN t.status = 'stopped' THEN 'stopped'
                WHEN t.expected_expiration_at <= NOW() THEN 'expired'
                WHEN t.warning_at IS NOT NULL AND t.warning_at <= NOW() THEN 'warning'
                ELSE 'running'
            END AS computed_status,
            GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), t.expected_expiration_at)) AS remaining_seconds
        FROM safety_timers t
        WHERE t.user_id = :user_id
        ORDER BY t.id DESC
    ");
    $stmt->execute([":user_id" => $user_id]);
    $timers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $statusUpdate = $conn->prepare("UPDATE safety_timers SET status = ? WHERE id = ? AND user_id = ?");

    foreach ($timers as &$timer) {
        $status = $timer['computed_status'];
        unset($timer['computed_status']);

        $timer['status'] = $status;
        $statusUpdate->execute([$status, $timer['id'], $user_id]);

        /* Record one-time alert (warning / expired) using sent_at columns on the timer */
        if ($status === 'warning' || $status === 'expired') {
            $sentCol = $status === 'warning' ? 'warning_sent_at' : 'expired_sent_at';
            $alreadySent = $timer[$sentCol] !== null;
            if (!$alreadySent) {
                $conn->prepare("UPDATE safety_timers SET $sentCol = NOW() WHERE id = ? AND user_id = ?")
                    ->execute([$timer['id'], $user_id]);
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
            $status === 'stopped' ? 0 : (int) $timer['remaining_seconds'];
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
