<?php

require_once __DIR__ . '/../../auth/google_oauth.php';

$url = getGoogleAuthUrl();

header("Location: " . $url);
exit;
