<?php
/**
 * OpTrade — Auth API
 * POST actions: signup | login | logout
 */
require_once __DIR__ . '/db-S&P-case.php';

header('Content-Type: application/json');
session_start();

$action = $_POST['action'] ?? '';
$ip     = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

function json_die(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}
function json_ok(array $data = []): never {
    echo json_encode(array_merge(['ok' => true], $data));
    exit;
}

// ── CSRF token ────────────────────────────────────────────────────────────────
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
// Skip CSRF check for logout (it's safe), check for mutating actions
if ($action !== 'logout') {
    $token = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'], $token)) {
        json_die('Invalid CSRF token.', 403);
    }
}

// ── SIGNUP ────────────────────────────────────────────────────────────────────
if ($action === 'signup') {
    $email = trim(strtolower($_POST['email'] ?? ''));
    $pass  = $_POST['password'] ?? '';
    $pass2 = $_POST['password2'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_die('Invalid email address.');
    if (strlen($email) > 255)                        json_die('Email too long.');
    if (strlen($pass) < 8)                           json_die('Password must be at least 8 characters.');
    if ($pass !== $pass2)                            json_die('Passwords do not match.');

    $user_id = db_create_user($email, $pass);
    if ($user_id === false) json_die('That email is already registered. Please sign in.');

    $_SESSION['user_id'] = $user_id;
    $_SESSION['email']   = $email;
    json_ok(['redirect' => 'dashboard-S&P-case.php']);
}

// ── LOGIN ─────────────────────────────────────────────────────────────────────
if ($action === 'login') {
    $email = trim(strtolower($_POST['email'] ?? ''));
    $pass  = $_POST['password'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_die('Invalid email.');

    if (db_rate_limited($ip, $email)) {
        json_die('Too many failed attempts. Please wait ' . RATE_LIMIT_MIN . ' minutes.');
    }

    $user = db_find_user_by_email($email);
    if (!$user || !password_verify($pass, $user['password_hash'])) {
        db_record_attempt($ip, $email);
        json_die('Incorrect email or password.');
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['email']   = $user['email'];
    json_ok(['redirect' => 'dashboard-S&P-case.php']);
}

// ── LOGOUT ────────────────────────────────────────────────────────────────────
if ($action === 'logout') {
    session_destroy();
    header('Location: index-S&P-case.php');
    exit;
}

json_die('Unknown action.');
