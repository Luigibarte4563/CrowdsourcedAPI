<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

// A missing .env is still a hard failure above, which is the behaviour we want.
// A .env that exists but is only half filled in must not be one: on a fresh
// clone an undefined-index warning here would take down every endpoint (cors.php
// includes this file before it can emit a single header), which looks exactly
// like "CORS is not configured". Empty strings degrade the one feature that is
// missing credentials instead of taking the whole API down.
define('GOOGLE_CLIENT_ID', $_ENV['GOOGLE_CLIENT_ID'] ?? '');
define('GOOGLE_CLIENT_SECRET', $_ENV['GOOGLE_CLIENT_SECRET'] ?? '');
define('GOOGLE_REDIRECT_URI', $_ENV['GOOGLE_REDIRECT_URI'] ?? '');
define('FRONTEND_URL', $_ENV['FRONTEND_URL'] ?? '');
