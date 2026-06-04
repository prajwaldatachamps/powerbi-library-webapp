<?php
// api/google_callback.php

require_once __DIR__ . '/config.php';

unset($_SESSION['oauth_state']);

if (isset($_GET['error'])) {
    header('Location: ../login.html?error=google_denied');
    exit;
}

if (empty($_GET['code'])) {
    header('Location: ../login.html?error=no_code');
    exit;
}

$tokenUrl = 'https://oauth2.googleapis.com/token';

$tokenData = http_build_query([
    'code'          => $_GET['code'],
    'client_id'     => GOOGLE_CLIENT_ID,
    'client_secret' => GOOGLE_CLIENT_SECRET,
    'redirect_uri'  => GOOGLE_REDIRECT_URI,
    'grant_type'    => 'authorization_code',
]);

$context = stream_context_create([
    'http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => $tokenData,
        'timeout' => 15,
    ]
]);

$tokenResponse = @file_get_contents($tokenUrl, false, $context);

if (!$tokenResponse) {
    header('Location: ../login.html?error=token_exchange_failed');
    exit;
}

$tokenJson = json_decode($tokenResponse, true);

if (empty($tokenJson['access_token'])) {
    header('Location: ../login.html?error=no_access_token');
    exit;
}

$userInfoUrl = 'https://www.googleapis.com/oauth2/v2/userinfo';

$context2 = stream_context_create([
    'http' => [
        'method'  => 'GET',
        'header'  => "Authorization: Bearer " . $tokenJson['access_token'] . "\r\n",
        'timeout' => 15,
    ]
]);

$userResponse = @file_get_contents($userInfoUrl, false, $context2);

if (!$userResponse) {
    header('Location: ../login.html?error=userinfo_failed');
    exit;
}

$googleUser = json_decode($userResponse, true);

if (empty($googleUser['email']) || empty($googleUser['id'])) {
    header('Location: ../login.html?error=userinfo_failed');
    exit;
}

$googleEmail = strtolower(trim($googleUser['email']));
$googleId    = $googleUser['id'];
$googleName  = $googleUser['name'] ?? $googleEmail;
$googlePic   = $googleUser['picture'] ?? '';

$pdo = getPDO();

$stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1");
$stmt->execute([$googleEmail]);
$user = $stmt->fetch();

if (!$user) {
    session_unset();
    session_destroy();
    clear_auth_cookie();

    header('Location: ../login.html?error=not_authorized&email=' . urlencode($googleEmail));
    exit;
}

$pdo->prepare("UPDATE users SET google_id = ?, name = ? WHERE id = ?")
    ->execute([$googleId, $googleName, $user['id']]);

$_SESSION['user_id']      = $user['id'];
$_SESSION['user_name']    = $googleName;
$_SESSION['user_email']   = $googleEmail;
$_SESSION['user_role']    = $user['role'];
$_SESSION['user_picture'] = $googlePic;

set_auth_cookie([
    'id'      => $user['id'],
    'name'    => $googleName,
    'email'   => $googleEmail,
    'role'    => $user['role'],
    'picture' => $googlePic
]);

header('Location: ../index.html?login=success');
exit;
