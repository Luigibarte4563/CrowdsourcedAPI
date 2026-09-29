<?php

/**
 * Google OAuth 2.0 redirect endpoint.
 *
 * Flow: google.php -> Google -> this file -> verify identity -> MySQL user ->
 * issueJWT() -> React (/auth/google-callback).
 *
 * This endpoint never returns JSON to the browser: the caller is a top-level
 * browser navigation, so every outcome ends in a redirect to the frontend.
 */

require_once __DIR__ . '/../../auth/google_oauth.php';
require_once __DIR__ . '/../../auth/issue_jwt.php';

$frontendUrl = rtrim($_ENV['FRONTEND_URL'] ?? '', '/');

/**
 * Redirects back to the React app. Only a short, non-sensitive error code is
 * ever sent to the frontend; details stay in the server log.
 */
function redirectToFrontend(string $base, array $params = []): void
{
    if ($params) {
        $base .= '?' . http_build_query($params);
    }

    header('Location: ' . $base, true, 302);
    exit;
}

function failToFrontend(string $code): void
{
    error_log('[google_callback] failed: ' . $code);

    redirectToFrontend(
        rtrim($_ENV['FRONTEND_URL'] ?? '', '/') . '/login',
        ['error' => $code]
    );
}

/* =========================================
   INPUT / ERROR HANDLING
   ========================================= */
if (isset($_GET['error'])) {
    // access_denied, consent_denied, etc. Map to a generic safe code.
    failToFrontend('google_auth_denied');
}

$code = $_GET['code'] ?? '';

if (!is_string($code) || trim($code) === '') {
    failToFrontend('google_missing_code');
}

/* =========================================
   EXCHANGE CODE FOR TOKENS
   ========================================= */
try {
    $tokens = exchangeGoogleCode($code);
} catch (Throwable $e) {
    error_log('[google_callback] token exchange error: ' . $e->getMessage());
    $tokens = null;
}

if (!$tokens || empty($tokens['id_token'])) {
    failToFrontend('google_exchange_failed');
}

/* =========================================
   VERIFY GOOGLE IDENTITY
   ========================================= */
try {
    $claims = verifyGoogleIdToken($tokens['id_token']);
} catch (Throwable $e) {
    error_log('[google_callback] id_token verification error: ' . $e->getMessage());
    $claims = null;
}

if (!$claims || empty($claims['sub']) || empty($claims['email'])) {
    failToFrontend('google_invalid_token');
}

$googleId = trim((string)$claims['sub']);
$email    = strtolower(trim((string)$claims['email']));

$firstName = trim((string)($claims['given_name'] ?? ''));
$lastName  = trim((string)($claims['family_name'] ?? ''));
$picture   = trim((string)($claims['picture'] ?? ''));

// users.first_name / users.last_name are NOT NULL.
if ($firstName === '' && $lastName === '') {
    $firstName = strtok($email, '@');
}
if ($firstName === '') {
    $firstName = 'User';
}
if ($lastName === '') {
    $lastName = $firstName;
}

/* =========================================
   FIND OR CREATE THE USER
   ========================================= */
try {
    $conn = getConnection();

    // 1) Already linked to a Google account.
    $stmt = $conn->prepare("
        SELECT id FROM users
        WHERE google_id = ?
        LIMIT 1
    ");
    $stmt->execute([$googleId]);
    $userId = $stmt->fetchColumn();

    if ($userId) {
        $conn->prepare("
            UPDATE users
            SET picture = ?, last_login = NOW()
            WHERE id = ?
        ")->execute([$picture !== '' ? $picture : null, $userId]);
    } else {
        // 2) Same email, different provider -> link the Google identity.
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $userId = $stmt->fetchColumn();

        if ($userId) {
            // Only google_id is written. auth_provider is intentionally left
            // untouched: login.php only allows password sign-in while it stays
            // 'local', so flipping it to 'google' here would silently disable
            // email/password login for an account that already had one.
            $conn->prepare("
                UPDATE users
                SET google_id = ?, picture = ?, last_login = NOW()
                WHERE id = ?
            ")->execute([$googleId, $picture !== '' ? $picture : null, $userId]);
        } else {
            // 3) Brand new user -> default role.
            $roleId = getRoleIdByName('user');

            if (!$roleId) {
                failToFrontend('google_role_missing');
            }

            $conn->prepare("
                INSERT INTO users (
                    google_id,
                    first_name,
                    last_name,
                    email,
                    password,
                    picture,
                    auth_provider,
                    role_id,
                    last_login
                ) VALUES (?, ?, ?, ?, NULL, ?, 'google', ?, NOW())
            ")->execute([
                $googleId,
                $firstName,
                $lastName,
                $email,
                $picture !== '' ? $picture : null,
                $roleId
            ]);

            $userId = (int)$conn->lastInsertId();
        }
    }
} catch (Throwable $e) {
    error_log('[google_callback] database error: ' . $e->getMessage());
    failToFrontend('google_db_error');
}

/* =========================================
   ISSUE THE EXISTING APPLICATION JWT
   ========================================= */
try {
    $jwt = issueJWT((int)$userId);
} catch (Throwable $e) {
    error_log('[google_callback] jwt error: ' . $e->getMessage());
    $jwt = null;
}

if (!$jwt) {
    failToFrontend('google_jwt_failed');
}

/* =========================================
   BACK TO REACT
   ========================================= */
redirectToFrontend($frontendUrl . '/auth/google-callback', ['token' => $jwt]);
