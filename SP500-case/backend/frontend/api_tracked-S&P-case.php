<?php
/**
 * OpTrade — Tracked-stocks API
 * POST actions: add | remove | refresh | cron
 */
require_once __DIR__ . '/db-S&P-case.php';
require_once __DIR__ . '/email_helper-S&P-case.php';

header('Content-Type: application/json');
session_start();

// Auth guard
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'Not authenticated']);
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$action  = $_POST['action'] ?? '';

// CSRF
$token = $_POST['csrf'] ?? '';
if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Invalid CSRF token']);
    exit;
}

function jok(array $d=[]): never  { echo json_encode(array_merge(['ok'=>true],$d)); exit; }
function jerr(string $m): never   { echo json_encode(['ok'=>false,'error'=>$m]); exit; }

// ── Find Python (mirrors api.php — checks venv first) ────────────────────────
function find_python(): string|null {
    $project_root = realpath(__DIR__ . '/../..');
    $candidates = [
        $project_root . '/venv/bin/python3',
        $project_root . '/.venv/bin/python3',
        '/Library/Frameworks/Python.framework/Versions/3.11/bin/python3.11',
        '/opt/homebrew/bin/python3.11',
        '/usr/local/bin/python3.11',
        '/opt/homebrew/opt/python@3.12/bin/python3.12',
        '/opt/homebrew/opt/python@3.11/bin/python3.11',
        '/opt/homebrew/bin/python3',
        '/usr/local/bin/python3',
        'python3.11',
        'python3',
        'python',
    ];
    foreach ($candidates as $c) {
        $test = shell_exec('command -v ' . escapeshellarg($c) . ' 2>/dev/null');
        if (!empty(trim($test ?? '')) || file_exists($c)) return $c;
    }
    return null;
}

// ── Run Python model for one ticker, returns parsed array or null ─────────────
function run_model(string $ticker): array|null {
    $python = find_python();
    if (!$python) return null;

    $script = realpath(__DIR__ . '/../train_model-S&P-case.py');
    if (!$script) return null;

    $sym = strtoupper($ticker);

    $cmd    = escapeshellcmd($python) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($sym) . ' 2>&1';
    $output = shell_exec($cmd);
    if (!$output) return null;

    $start = strpos($output, '{');
    $end   = strrpos($output, '}');
    if ($start === false || $end === false) return null;

    $data = json_decode(substr($output, $start, $end - $start + 1), true);
    return (is_array($data) && !isset($data['error'])) ? $data : null;
}

// ── Parse model output → flat signal array ────────────────────────────────────
function parse_signal(array $data): array {
    $sig     = $data['signal']   ?? [];
    $action  = $sig['action']    ?? 'HOLD';
    $colors  = [
        'STRONG BUY'=>'#66BB6A','BUY'=>'#A5D6A7','HOLD'=>'#FFF176',
        'SELL'=>'#EF9A9A','STRONG SELL'=>'#EF5350',
    ];
    $emojis  = [
        'STRONG BUY'=>'🚀','BUY'=>'📈','HOLD'=>'⏸️','SELL'=>'📉','STRONG SELL'=>'🔴',
    ];
    return [
        'action'      => $action,
        'strength'    => (int)($sig['strength'] ?? 0),
        'score'       => (int)($sig['score']    ?? 0),
        'last_price'  => round((float)($data['last_price']   ?? 0), 2),
        'next_price'  => round((float)($data['next_day_price'] ?? 0), 2),
        'pch'         => round((float)($data['price_change'] ?? 0), 2),
        'rsi'         => round((float)($sig['rsi']           ?? 0), 1),
        'trend'       => $sig['trend']     ?? '',
        'hmm_label'   => $sig['hmm_label'] ?? '',
        'summary'     => $sig['summary']   ?? '',
        'color'       => $colors[$action]  ?? '#78909C',
        'emoji'       => $emojis[$action]  ?? '📊',
    ];
}

// ── Trigger email alert for a ticker if threshold exceeded ────────────────────
function maybe_send_alerts(string $ticker, array $parsed): void {
    if ($parsed['strength'] < ALERT_THRESHOLD) return;
    $users = db_users_tracking($ticker);
    foreach ($users as $u) {
        if (db_already_notified((int)$u['id'], $ticker, $parsed['action'])) continue;
        $result = email_signal_alert(
            $u['email'], $u['email'], $ticker,
            $parsed['action'], $parsed['strength'],
            $parsed['last_price'], $parsed['next_price'], $parsed['summary']
        );
        db_log_email((int)$u['id'], $ticker, $parsed['action'], $parsed['strength']);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// ADD
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'add') {
    $ticker = strtoupper(trim($_POST['ticker'] ?? ''));
    if (!$ticker) jerr('No ticker provided');
    if (db_count_tracked($user_id) >= MAX_TRACKED) jerr('Max tracked stocks reached (' . MAX_TRACKED . ')');
    $ok = db_add_tracked($user_id, $ticker);
    $ok ? jok(['ticker'=>$ticker]) : jerr('Could not add stock (already tracked?)');
}

// ─────────────────────────────────────────────────────────────────────────────
// REMOVE
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'remove') {
    $ticker = strtoupper(trim($_POST['ticker'] ?? ''));
    if (!$ticker) jerr('No ticker provided');
    db_remove_tracked($user_id, $ticker);
    jok();
}

// ─────────────────────────────────────────────────────────────────────────────
// REFRESH — run Python for one ticker, store result, trigger email if needed
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'refresh') {
    $ticker = strtoupper(trim($_POST['ticker'] ?? ''));
    if (!$ticker) jerr('No ticker provided');

    $data = run_model($ticker);
    if (!$data) jerr("Could not run analysis for $ticker. Make sure Python and dependencies are installed.");

    $parsed = parse_signal($data);

    // Save to DB (replaces previous signal for this ticker)
    db_save_signal([
        'ticker'        => $ticker,
        'signal_type'   => $parsed['action'],
        'signal_strength'=> $parsed['strength'],
        'score'         => $parsed['score'],
        'last_price'    => $parsed['last_price'],
        'next_day_price'=> $parsed['next_price'],
        'price_change'  => $parsed['pch'],
        'rsi'           => $parsed['rsi'],
        'trend'         => $parsed['trend'],
        'hmm_label'     => $parsed['hmm_label'],
        'summary'       => $parsed['summary'],
    ]);

    // Send emails if signal is strong enough
    maybe_send_alerts($ticker, $parsed);

    jok(['signal' => $parsed]);
}

// ─────────────────────────────────────────────────────────────────────────────
// CRON — refresh all tracked stocks for all users (background scan)
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'cron') {
    $tickers = db_all_unique_tickers();
    $done = 0; $alerts = 0;
    foreach ($tickers as $ticker) {
        // Skip if signal is still fresh
        if (db_signal_is_fresh($ticker)) continue;

        $data = run_model($ticker);
        if (!$data) continue;

        $parsed = parse_signal($data);
        db_save_signal([
            'ticker'        => $ticker,
            'signal_type'   => $parsed['action'],
            'signal_strength'=> $parsed['strength'],
            'score'         => $parsed['score'],
            'last_price'    => $parsed['last_price'],
            'next_day_price'=> $parsed['next_price'],
            'price_change'  => $parsed['pch'],
            'rsi'           => $parsed['rsi'],
            'trend'         => $parsed['trend'],
            'hmm_label'     => $parsed['hmm_label'],
            'summary'       => $parsed['summary'],
        ]);

        if ($parsed['strength'] >= ALERT_THRESHOLD) {
            $users = db_users_tracking($ticker);
            foreach ($users as $u) {
                if (db_already_notified((int)$u['id'], $ticker, $parsed['action'])) continue;
                email_signal_alert(
                    $u['email'], $u['email'], $ticker,
                    $parsed['action'], $parsed['strength'],
                    $parsed['last_price'], $parsed['next_price'], $parsed['summary']
                );
                db_log_email((int)$u['id'], $ticker, $parsed['action'], $parsed['strength']);
                $alerts++;
            }
        }
        $done++;
    }
    jok(['message' => "Scanned {$done} stock(s), sent {$alerts} alert(s)"]);
}

jerr('Unknown action');
