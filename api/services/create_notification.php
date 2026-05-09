<?php

function createNotification(
    PDO $conn,
    array $userIds,
    string $title,
    string $message,
    string $type = 'system',
    ?int $referenceId = null,
    ?string $sourceType = null
) {

    if (empty($userIds)) {
        return false;
    }

    $sql = "
        INSERT INTO notifications (
            user_id,
            title,
            message,
            type,
            maintenance_id,
            source_type
        )
        VALUES (
            :user_id,
            :title,
            :message,
            :type,
            :maintenance_id,
            :source_type
        )
    ";

    $stmt = $conn->prepare($sql);

    foreach ($userIds as $userId) {

        $stmt->execute([
            ":user_id" => $userId,
            ":title" => $title,
            ":message" => $message,
            ":type" => $type,
            ":maintenance_id" => $referenceId,
            ":source_type" => $sourceType
        ]);
    }

    return true;
}