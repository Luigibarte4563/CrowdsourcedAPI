<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../auth/jwt_auth.php';
require_once __DIR__ . '/../../auth/rbac.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$user = requireAuthUser();

if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "No valid image uploaded"]);
    exit;
}

$outageReportId = (int)($_POST['outage_report_id'] ?? 0);
if ($outageReportId <= 0) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "outage_report_id is required"]);
    exit;
}

/* =========================================
   SECURE FILE VALIDATION
========================================= */
$allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$allowedMime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$maxBytes = 5 * 1024 * 1024; // 5MB

$file = $_FILES['image'];

if ($file['size'] > $maxBytes) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Image too large (max 5MB)"]);
    exit;
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $allowedExt, true)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid file type"]);
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string)$finfo->file($file['tmp_name']);
if (!in_array($mime, $allowedMime, true)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid image content"]);
    exit;
}

/* =========================================
   MOVE FILE
========================================= */
$uploadDir = __DIR__ . '/../../uploads/outage/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0775, true);
}

$filename = 'or_' . $outageReportId . '_' . uniqid() . '.' . $ext;
$destination = $uploadDir . $filename;

if (!move_uploaded_file($file['tmp_name'], $destination)) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Failed to store image"]);
    exit;
}

$imageUrl = 'uploads/outage/' . $filename;

/* =========================================
   VERIFY USER OWNS THE REPORT (or staff)
========================================= */
$role = $user['role'] ?? '';
$isStaff = in_array($role, ['lineman', 'electric_company', 'admin'], true);

$ownsStmt = $conn->prepare("SELECT id FROM outage_reports WHERE id = ? AND user_id = ? LIMIT 1");
$ownsStmt->execute([$outageReportId, $user['id']]);
$owns = (bool)$ownsStmt->fetch();

if (!$owns && !$isStaff) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Forbidden"]);
    exit;
}

/* =========================================
   RECORD
========================================= */
$insert = $conn->prepare("
    INSERT INTO outage_report_images (outage_report_id, uploaded_by, image_url)
    VALUES (?, ?, ?)
");
$insert->execute([$outageReportId, $user['id'], $imageUrl]);

echo json_encode([
    "success" => true,
    "message" => "Image uploaded",
    "image_id" => (int)$conn->lastInsertId(),
    "image_url" => $imageUrl
]);
