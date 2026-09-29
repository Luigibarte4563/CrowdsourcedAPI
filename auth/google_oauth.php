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
 *
 * The signature is checked against Google's trusted RS256 public keys; the
 * token is never trusted on decode alone. exp/nbf/iat are validated by
 * JWT::decode, and audience/issuer/required claims are validated below.
 */
function verifyGoogleIdToken($idToken) {
    if (!is_string($idToken) || substr_count($idToken, '.') !== 2) {
        return null;
    }

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
    } catch (Exception $e) {
        return null;
    }

    $claims = (array)$payload;

    $clientId = $_ENV['GOOGLE_CLIENT_ID'] ?? '';

    if ($clientId === '' || ($claims['aud'] ?? null) !== $clientId) {
        return null;
    }

    if (!isset($claims['azp']) || $claims['azp'] !== $clientId) {
        return null;
    }

    if (!in_array($claims['iss'] ?? null, [
        'accounts.google.com',
        'https://accounts.google.com'
    ], true)) {
        return null;
    }

    if (empty($claims['sub']) || empty($claims['email'])) {
        return null;
    }

    if (isset($claims['email_verified']) &&
        $claims['email_verified'] !== true &&
        $claims['email_verified'] !== 'true') {
        return null;
    }

    return $claims;
}
