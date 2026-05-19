<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* =========================================
   AUTO STATUS FUNCTION
========================================= */
function computeStatus($date, $start, $end)
{
    $now = new DateTime();
    $startDT = new DateTime("$date $start");
    $endDT = new DateTime("$date $end");

    if ($now > $endDT) return "done";
    if ($now >= $startDT && $now <= $endDT) return "ongoing";
    return "pending";
}

try {

    $user = getUserFromJWT();

    if (!$user) {
        throw new Exception("Unauthorized");
    }

    $sql = "
        SELECT 
            ms.id,
            ms.electric_company_id,
            ec.company_name,
            ms.radius,
            ms.maintenance_date,
            ms.start_time,
            ms.end_time,
            ms.description,
            ms.affected_barangays,
            ms.status AS db_status
        FROM maintenance_schedules ms
        LEFT JOIN electric_companies ec 
            ON ec.id = ms.electric_company_id
        ORDER BY ms.id DESC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $data = [];

    foreach ($rows as $row) {

        $autoStatus = computeStatus(
            $row['maintenance_date'],
            $row['start_time'],
            $row['end_time']
        );

        /* OPTIONAL: sync DB so it permanently updates */
        $update = $conn->prepare("
            UPDATE maintenance_schedules
            SET status = :status
            WHERE id = :id
        ");
        $update->execute([
            ":status" => $autoStatus,
            ":id" => $row['id']
        ]);

        $data[] = [
            "id" => $row["id"],
            "company_name" => $row["company_name"],
            "radius" => (int)$row["radius"],
            "maintenance_date" => $row["maintenance_date"],
            "start_time" => $row["start_time"],
            "end_time" => $row["end_time"],
            "description" => $row["description"],
            "affected_barangays" => json_decode($row["affected_barangays"], true),
            "status" => $autoStatus
        ];
    }

    echo json_encode([
        "success" => true,
        "data" => $data
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}