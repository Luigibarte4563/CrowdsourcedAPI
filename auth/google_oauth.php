<?php

require_once __DIR__ . '/../config/env.php';

use Firebase\JWT\JWT;
use Firebase\JWT\JWK;

/**
 * Builds the Google OAuth authorization URL (server-side requires a redirect).
 */
function getGoogleAuthUrl() {
    $clientId     = $_ENV['GOOGLE_CLIENT_ID'];
    $redirectUri  = $_ENV['GOOGLE_REDIRECT_URI'];

    $params = http_build_query([
        'client_id'     => $clientId,
        'redirect_uri'  => $redirectUri,
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'access_type'   => 'online',
        'prompt'        => 'select_account'
    ]);

    return 'https://accounts.google.com/o/oauth2/v2/auth?' . $params;
}

/**
 * Exchanges the authorization code for tokens using the Google token endpoint.
 * Returns the full token response array, or null on failure.
 */
function exchangeGoogleCode($code) {
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'code'          => $code,
        'client_id'     => $_ENV['GOOGLE_CLIENT_ID'],
        'client_secret' => $_ENV['GOOGLE_CLIENT_SECRET'],
        'redirect_uri'  => $_ENV['GOOGLE_REDIRECT_URI'],
        'grant_type'    => 'authorization_code'
    ]));

    $response = curl_exec($ch);
    curl_close($ch);

    if ($response === false) {
        return null;
    }

    $data = json_decode($response, true);
    return isset($data['id_token']) ? $data : null;
}

/**
 * Verifies a Google id_token against Google's published JWKS and returns the
 * decoded claims (email, sub, name, picture), or null if invalid.
 */
function verifyGoogleIdToken($idToken) {
    $jwksBody = @file_get_contents('https://www.googleapis.com/oauth2/v3/certs');
    if ($jwksBody === false) {
        return null;
    }

    $jwks = json_decode($jwksBody, true);
    if (!isset($jwks['keys'])) {
        return null;
    }

    $keys = JWK::parseKeySet($jwks);

    try {
        $payload = JWT::decode($idToken, $keys);

        if (($_ENV['GOOGLE_CLIENT_ID'] ?? '') && $payload->aud !== $_ENV['GOOGLE_CLIENT_ID']) {
            return null;
        }

        return (array)$payload;
    } catch (Exception $e) {
        return null;
    }
}
