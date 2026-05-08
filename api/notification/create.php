<?php

header("Content-Type: application/json");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* =========================================
   JWT AUTH
========================================= */
$user = getUserFromJWT();

if (!$user) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized (invalid token)"
    ]);
    exit;
}

$user_id = $user["id"] ?? null;
$role = $user["role"] ?? null;

if (!$user_id) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "message" => "Invalid token data"
    ]);
    exit;
}

/* =========================================
   OPTIONAL ROLE SECURITY
   (adjust if needed)
========================================= */
if (!in_array($role, ["electric_company", "admin"])) {
    http_response_code(403);
    echo json_encode([
        "success" => false,
        "message" => "Forbidden: only electric_company or admin allowed"
    ]);
    exit;
}

/* =========================================
   GET electric_company.id (IMPORTANT FIX)
========================================= */
$stmt = $conn->prepare("
    SELECT id 
    FROM electric_companies 
    WHERE user_id = :user_id
    LIMIT 1
");

$stmt->execute([":user_id" => $user_id]);
$company = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$company) {
    http_response_code(404);
    echo json_encode([
        "success" => false,
        "message" => "Electric company profile not found"
    ]);
    exit;
}

$electric_company_id = $company["id"];

/* =========================================
   INPUT
========================================= */
$data = json_decode(file_get_contents("php://input"), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Invalid JSON"
    ]);
    exit;
}

$notifications = $data["notifications"] ?? null;

/* =========================================
   VALIDATION
========================================= */
if (!$notifications && !isset($data["user_id"], $data["title"], $data["message"])) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Missing required fields"
    ]);
    exit;
}

/* =========================================
   INSERT
========================================= */
try {

    $conn->beginTransaction();

    $stmt = $conn->prepare("
        INSERT INTO notifications 
        (user_id, title, message, type)
        VALUES (:user_id, :title, :message, :type)
    ");

    /* ================================
       BATCH INSERT
    ================================ */
    if ($notifications) {

        foreach ($notifications as $n) {

            $stmt->execute([
                ":user_id" => $n["user_id"],
                ":title"   => $n["title"],
                ":message" => $n["message"],
                ":type"    => $n["type"] ?? "maintenance"
            ]);
        }

    } 
    /* ================================
       SINGLE INSERT
    ================================ */
    else {

        $stmt->execute([
            ":user_id" => $data["user_id"],
            ":title"   => $data["title"],
            ":message" => $data["message"],
            ":type"    => $data["type"] ?? "maintenance"
        ]);
    }

    $conn->commit();

    echo json_encode([
        "success" => true,
        "message" => "Notification(s) created",
        "company_id" => $electric_company_id
    ]);

} catch (PDOException $e) {

    $conn->rollBack();

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => $e->getMessage() // IMPORTANT for debugging
    ]);
}