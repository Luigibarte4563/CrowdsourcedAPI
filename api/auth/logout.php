<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/../../config/env.php';

/* Clear the application JWT cookie */
if (isset($_COOKIE['jwt_token'])) {
    setcookie('jwt_token', '', time() - 3600, '/', '', false, true);
}

echo json_encode([
    "success" => true,
    "message" => "Logged out"
]);
