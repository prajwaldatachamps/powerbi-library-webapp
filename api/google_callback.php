<?api
// api/google_callback.api

require_once __DIR__ . '/config.api';

// ── Validate state (CSRF protection) ─────────────────────
if (empty($_GET['state']) || empty($_SESSION['oauth_state']) || $_GET['state'] !== $_SESSION['oauth_state']) {
    unset($_SESSION['oauth_state']);
    header('Location: ../login.html?error=invalid_state');
    exit;
}
unset($_SESSION['oauth_state']);

// ── Google returned an error ──────────────────────────────
if (isset($_GET['error'])) {
    header('Location: ../login.html?error=google_denied');
    exit;
}

if (empty($_GET['code'])) {
    header('Location: ../login.html?error=no_code');
    exit;
}

// ── Exchange code for access token ───────────────────────
$tokenUrl  = 'https://oauth2.googleapis.com/token';
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

// ── Get user info from Google ─────────────────────────────
$userInfoUrl = 'https://www.googleapis.com/oauth2/v2/userinfo';
$context2    = stream_context_create([
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
$googleName  = $googleUser['name']    ?? $googleEmail;
$googlePic   = $googleUser['picture'] ?? '';

// ── Check if email is approved in users table ─────────────
$pdo  = getPDO();
$stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1");
$stmt->execute([$googleEmail]);
$user = $stmt->fetch();

if (!$user) {
    session_unset();
    session_destroy();
    header('Location: ../login.html?error=not_authorized&email=' . urlencode($googleEmail));
    exit;
}

// ── Update google_id and name on first login ──────────────
$pdo->prepare("UPDATE users SET google_id = ?, name = ? WHERE id = ?")
    ->execute([$googleId, $googleName, $user['id']]);

// ── Create server session ─────────────────────────────────
$_SESSION['user_id']      = $user['id'];
$_SESSION['user_name']    = $googleName;
$_SESSION['user_email']   = $googleEmail;
$_SESSION['user_role']    = $user['role'];
$_SESSION['user_picture'] = $googlePic;

// ── Set remember-me cookie (persists login for 25 days) ───
// Closing the browser tab/window will NOT log the user out.
// The cookie + DB token automatically restore the session on next visit.
set_remember_me($pdo, (int) $user['id']);

// ── Redirect to app ───────────────────────────────────────
header('Location: ../index.html?login=success');
exit;