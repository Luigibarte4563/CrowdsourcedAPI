<?php

require_once __DIR__ . '/lookup.php';

/**
 * Creates notifications for the new normalized `notifications` schema.
 *
 * The old schema stored `type` as a string and had `source_type`/`location`.
 * The new schema uses a `notification_type_id` FK plus reference foreign keys
 * (outage_report_id, maintenance_id, flood_report_id, electrical_hazard_id,
 * safety_timer_id).
 *
 * Signature is kept compatible with existing callers. The `$location`
 * argument is accepted for backward compatibility but is no longer stored.
 */
function createNotification(
    PDO $conn,
    array $userIds,
    string $title,
    string $message,
    string $type = 'system',
    ?int $referenceId = null,
    ?string $sourceType = null,
    ?string $location = null
) {
    if (empty($userIds)) return false;

    $userIds = array_values(array_unique(array_map('intval', $userIds)));
    if (empty($userIds)) return false;

    /* Resolve notification type name (ENUM validated) */
    $type = getNotificationType($type);
    if (!$type) return false;

    /* Map source type to a reference FK column (whitelist only) */
    $refMap = [
        'outage'            => 'outage_report_id',
        'maintenance'       => 'maintenance_id',
        'flood'             => 'flood_report_id',
        'electrical_hazard' => 'electrical_hazard_id',
        'safety_timer'      => 'safety_timer_id'
    ];

    $refCol   = $refMap[$sourceType] ?? null;
    $refValue = ($refCol && $referenceId) ? (int)$referenceId : null;

    $colSql    = $refCol ? ", $refCol" : "";
    $valueSql  = $refCol ? ", :reference_id" : "";

    $insert = $conn->prepare("
        INSERT INTO notifications (
            user_id,
            notification_type,
            title,
            message,
            is_read
            $colSql
        ) VALUES (
            :user_id,
            :type,
            :title,
            :message,
            0
            $valueSql
        )
    ");

    foreach ($userIds as $id) {
        $params = [
            ":user_id"  => $id,
            ":type"     => $type,
            ":title"    => $title,
            ":message"  => $message
        ];
        if ($refCol) {
            $params[":reference_id"] = $refValue;
        }
        $insert->execute($params);
    }

    return true;
}
