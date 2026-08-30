<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../auth/google_oauth.php';
require_once __DIR__ . '/../../auth/issue_jwt.php';

$conn = getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* =========================================
   STATE / CODE
========================================= */
$code  = $_GET['code'] ?? null;
$error = $_GET['error'] ?? null;

if ($error) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Google sign-in was cancelled or failed: " . $error
    ]);
    exit;
}

if (!$code) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Missing authorization code"
    ]);
    exit;
}

/* =========================================
   EXCHANGE CODE FOR TOKENS
========================================= */
$tokens = exchangeGoogleCode($code);

if (!$tokens || empty($tokens['id_token'])) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "message" => "Failed to exchange Google authorization code"
    ]);
    exit;
}

/* =========================================
   VERIFY GOOGLE IDENTITY (signature + audience)
========================================= */
$claims = verifyGoogleIdToken($tokens['id_token']);

if (!$claims || empty($claims['sub']) || empty($claims['email'])) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "message" => "Google identity could not be verified"
    ]);
    exit;
}

$googleId  = $claims['sub'];
$email     = strtolower(trim($claims['email']));
$firstName = $claims['given_name'] ?? $claims['name'] ?? '';
$lastName  = $claims['family_name'] ?? '';
$picture   = $claims['picture'] ?? null;

if (empty($firstName) && empty($lastName) && !empty($claims['name'])) {
    $parts = preg_split('/\s+/', trim($claims['name']));
    $firstName = $parts[0] ?? '';
    $lastName  = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';
}

$firstName = trim($firstName);
$lastName  = trim($lastName);

try {
    /* =========================================
       FIND BY GOOGLE ID FIRST
    ========================================= */
    $byGoogle = $conn->prepare("
        SELECT id FROM users WHERE google_id = ? LIMIT 1
    ");
    $byGoogle->execute([$googleId]);
    $googleUser = $byGoogle->fetch(PDO::FETCH_ASSOC);

    if ($googleUser) {
        $userId = (int)$googleUser['id'];
        $conn->prepare("UPDATE users SET last_login = NOW(), picture = COALESCE(?, picture) WHERE id = ?")
            ->execute([$picture, $userId]);
    } else {
        /* =========================================
           FIND BY EMAIL (link, do not duplicate)
        ========================================= */
        $byEmail = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $byEmail->execute([$email]);
        $emailUser = $byEmail->fetch(PDO::FETCH_ASSOC);

        if ($emailUser) {
            $userId = (int)$emailUser['id'];
            $conn->prepare("
                UPDATE users
                SET google_id = ?, picture = COALESCE(?, picture), last_login = NOW()
                WHERE id = ?
            ")->execute([$googleId, $picture, $userId]);
        } else {
            /* =========================================
               CREATE NEW USER WITH THE "user" ROLE
            ========================================= */
            $roleId = getRoleIdByName('user');
            if (!$roleId) {
                http_response_code(500);
                echo json_encode(["success" => false, "message" => "Role configuration missing"]);
                exit;
            }

            $insert = $conn->prepare("
                INSERT INTO users (
                    google_id,
                    first_name,
                    last_name,
                    email,
                    picture,
                    auth_provider,
                    role_id
                ) VALUES (?, ?, ?, ?, ?, 'google', ?)
            ");
            $insert->execute([
                $googleId,
                $firstName,
                $lastName,
                $email,
                $picture,
                $roleId
            ]);

            $userId = (int)$conn->lastInsertId();
        }
    }

    $token = issueJWT($userId);

    echo json_encode([
        "success" => true,
        "message" => "Google sign-in successful",
        "user_id" => $userId,
        "token_issued" => $token !== null,
        "email" => $email,
        "name" => trim($firstName . ' ' . $lastName)
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Google sign-in failed"
    ]);
}
