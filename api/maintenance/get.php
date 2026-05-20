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

    if (!$date || !$start || !$end) {
        return "upcoming";
    }

    $startDT = new DateTime("$date $start");
    $endDT = new DateTime("$date $end");

    if ($now > $endDT) return "completed";
    if ($now >= $startDT && $now <= $endDT) return "ongoing";
    return "upcoming";
}

/* =========================================
   NORMALIZE STATUS
========================================= */
function normalizeStatus($dbStatus, $autoStatus)
{
    $dbStatus = strtolower(trim($dbStatus));

    if (in_array($dbStatus, ["upcoming", "ongoing", "completed", "cancelled"])) {
        return $dbStatus;
    }

    return $autoStatus;
}

try {

    $user = getUserFromJWT();

    if (!$user) {
        throw new Exception("Unauthorized");
    }

    /* =========================================
       FETCH ALL MAINTENANCE
    ========================================= */
    $sql = "
        SELECT 
            ms.id,
            ms.created_by,
            u.name AS company_name,
            ms.radius,
            ms.maintenance_date,
            ms.start_time,
            ms.end_time,
            ms.description,
            ms.affected_barangays,
            ms.status,
            ms.created_at,
            ms.updated_at
        FROM maintenance_schedules ms
        LEFT JOIN users u 
            ON u.id = ms.created_by
        WHERE u.role = 'electric_company'
        ORDER BY ms.id DESC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $data = [];

    foreach ($rows as $row) {

        /* =========================================
           COMPUTE STATUS
        ========================================= */
        $autoStatus = computeStatus(
            $row['maintenance_date'],
            $row['start_time'],
            $row['end_time']
        );

        $finalStatus = normalizeStatus($row['status'], $autoStatus);

        /* =========================================
           AUTO SYNC DB STATUS (SAFE)
        ========================================= */
        if ($row['status'] !== "completed" && $row['status'] !== "cancelled") {

            if ($row['status'] !== $autoStatus) {

                $update = $conn->prepare("
                    UPDATE maintenance_schedules
                    SET status = :status
                    WHERE id = :id
                ");

                $update->execute([
                    ":status" => $autoStatus,
                    ":id" => $row['id']
                ]);
            }
        }

        /* =========================================
           BARANGAY FORMAT FIX
        ========================================= */
        $barangays = json_decode($row["affected_barangays"], true);

        if (!is_array($barangays)) {
            $barangays = [];
        }

        /* =========================================
           FINAL RESPONSE ITEM
        ========================================= */
        $data[] = [
            "id" => (int)$row["id"],
            "company_name" => $row["company_name"],
            "radius" => (int)$row["radius"],

            "maintenance_date" => $row["maintenance_date"],
            "start_time" => $row["start_time"],
            "end_time" => $row["end_time"],

            "description" => $row["description"],
            "affected_barangays" => $barangays,

            "status" => $finalStatus,

            "created_at" => $row["created_at"],
            "updated_at" => $row["updated_at"]
        ];
    }

    /* =========================================
       RESPONSE
    ========================================= */
    echo json_encode([
        "success" => true,
        "count" => count($data),
        "data" => $data
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}