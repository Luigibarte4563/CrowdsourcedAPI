<?php

function createNotification(
    PDO $conn,
    array $userIds,
    string $title,
    string $message,
    string $type = 'system',
    ?int $referenceId = null,
    ?string $sourceType = null,
    ?string $location = null   // ✅ FIX: ADD LOCATION PARAM
) {

    if (empty($userIds)) return false;

    $userIds = array_values(array_unique(array_map('intval', $userIds)));

    if (empty($userIds)) return false;

    /* INSERT */
    $insert = $conn->prepare("
        INSERT INTO notifications (
            user_id,
            title,
            message,
            type,
            maintenance_id,
            source_type,
            location
        ) VALUES (
            :user_id,
            :title,
            :message,
            :type,
            :maintenance_id,
            :source_type,
            :location
        )
    ");

    foreach ($userIds as $id) {

        $insert->execute([
            ":user_id" => $id,
            ":title" => $title,
            ":message" => $message,
            ":type" => $type,
            ":maintenance_id" => $referenceId,
            ":source_type" => $sourceType,
            ":location" => $location   // ✅ FIXED
        ]);
    }

    return true;
}