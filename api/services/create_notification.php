<?php

function createNotification(
    PDO $conn,
    array $userIds,
    string $title,
    string $message,
    string $type = 'system',
    ?int $referenceId = null,
    ?string $sourceType = null,
    ?string $altMessage = null   // ✅ NEW: message for NOT affected users
) {

    if (empty($userIds)) {
        return false;
    }

    /* CLEAN IDS */
    $userIds = array_values(array_unique(array_map('intval', $userIds)));

    if (empty($userIds)) {
        return false;
    }

    /* GET LOCATION */
    $location = null;

    if ($referenceId) {

        $locStmt = $conn->prepare("
            SELECT affected_area
            FROM maintenance_schedules
            WHERE id = :id
            LIMIT 1
        ");

        $locStmt->execute([":id" => $referenceId]);
        $location = $locStmt->fetchColumn();
    }

    /* FILTER USERS */
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));

    $userStmt = $conn->prepare("
        SELECT id
        FROM users
        WHERE id IN ($placeholders)
        AND role != 'electric_company'
    ");

    $userStmt->execute($userIds);

    $filteredUsers = $userStmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($filteredUsers)) {
        return false;
    }

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
        )
        VALUES (
            :user_id,
            :title,
            :message,
            :type,
            :maintenance_id,
            :source_type,
            :location
        )
    ");

    foreach ($filteredUsers as $userId) {

        $finalMessage = $message;

        // fallback for outside-area users
        if ($altMessage) {
            $finalMessage = $altMessage;
        }

        $ok = $insert->execute([
            ":user_id"        => $userId,
            ":title"          => $title,
            ":message"        => $finalMessage,
            ":type"           => $type,
            ":maintenance_id" => $referenceId,
            ":source_type"    => $sourceType,
            ":location"       => $location
        ]);

        if (!$ok) {
            error_log("Notification insert failed: " . json_encode($insert->errorInfo()));
        }
    }

    return true;
}