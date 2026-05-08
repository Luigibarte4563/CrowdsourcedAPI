<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/env.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function getUserFromJWT() {

    if (!isset($_COOKIE['jwt_token'])) {
        return null;
    }

    try {

        $decoded = JWT::decode(
            $_COOKIE['jwt_token'],
            new Key($_ENV['JWT_SECRET_KEY'], 'HS256')
        );

        return (array) $decoded;

    } catch (Exception $e) {
        return null;
    }
}