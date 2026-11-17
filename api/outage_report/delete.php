<?php

header("Content-Type: application/json; charset=UTF-8");
header("Accept: application/json");

$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);

if (!is_array($data)) {
    if (!empty($_POST)) {
        $data = $_POST;
    } else {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Invalid JSON body"]);
        exit;
    }
}

$id = isset($data["id"]) ? (int)$data["id"] : 0;

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Valid report id is required"]);
    exit;
}

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../services/lookup.php';

try {
    $conn = getConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database connection failed"]);
    exit;
}

try {
    $user = getUserFromJWT();
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid token"]);
    exit;
}

if (!$user) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit;
}

$user_id = $user['id'];

// Make sure the report exists and belongs to this user before deciding what to do with it.
$stmt = $conn->prepare("
    SELECT id, status
    FROM outage_reports
    WHERE id = :id
    AND user_id = :user_id
    LIMIT 1
");
$stmt->execute([
    ":id" => $id,
    ":user_id" => $user_id
]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    http_response_code(404);
    echo json_encode(["success" => false, "message" => "Report not found."]);
    exit;
}

// A report that has already been verified / resolved / rejected can no longer be cancelled.
if (!in_array($report['status'], ['active', 'under_review'], true)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "This report has already been verified and cannot be deleted."
    ]);
    exit;
}

try {
    $stmt = $conn->prepare("
        UPDATE outage_reports
        SET
            status = :status,
            resolution_note = 'Cancelled by user'
        WHERE id = :id
        AND user_id = :user_id
    ");

    $stmt->execute([
        ":status" => 'rejected',
        ":id" => $id,
        ":user_id" => $user_id
    ]);

    echo json_encode([
        "success" => true,
        "message" => "Report cancelled successfully"
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database error"]);
}
