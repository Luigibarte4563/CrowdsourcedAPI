<?php

require_once __DIR__ . '/env.php';

// Origins allowed to call this API from a browser.
//
// The default list covers a dev server running on the same machine only. If the
// frontend is reached from another host or port - a LAN IP such as
// http://192.168.1.20:5174, an https preview, a different port - set
// ALLOWED_ORIGINS in .env to a comma-separated list and the header will be sent
// for that origin instead.
//
// `Access-Control-Allow-Origin: *` is never valid here: the session is an
// HttpOnly `jwt_token` cookie and the client sends `credentials: 'include'`, so
// the response has to name one exact origin.
//
// FRONTEND_URL is deliberately NOT used below. It decides where the Google OAuth
// round trip sends the browser (api/auth/google_callback.php) and has no effect
// on these headers. ALLOWED_ORIGINS is the only knob for CORS.
$defaultOrigins = [
    'http://localhost:5174',
    'http://127.0.0.1:5174',
    'http://localhost:5173',
    'http://127.0.0.1:5173',
];

$allowedOrigins = $defaultOrigins;

if (!empty($_ENV['ALLOWED_ORIGINS'])) {
    $configured = array_filter(array_map('trim', explode(',', $_ENV['ALLOWED_ORIGINS'])));
    if ($configured) {
        // An explicit ALLOWED_ORIGINS replaces the defaults rather than adding to
        // them, so a deployed origin list is exactly what it says it is.
        $allowedOrigins = $configured;
    }
}

// Absent for same-origin requests (e.g. curl, or the Vite dev proxy), which needs
// no CORS headers at all.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    // The response now depends on Origin, so caches must key on it.
    header('Vary: Origin');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}
