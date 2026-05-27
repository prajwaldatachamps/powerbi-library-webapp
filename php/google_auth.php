<?php
// php/google_auth.php
// Redirects the user to Google's OAuth consent screen

require_once __DIR__ . '/config.php';

// Generate and store a random state value to prevent CSRF
$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state'] = $state;

$params = http_build_query([
    'client_id'     => GOOGLE_CLIENT_ID,
    'redirect_uri'  => GOOGLE_REDIRECT_URI,
    'response_type' => 'code',
    'scope'         => 'openid email profile',
    'access_type'   => 'online',
    'state'         => $state,
    'prompt'        => 'select_account', // always show account picker
]);

header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
exit;