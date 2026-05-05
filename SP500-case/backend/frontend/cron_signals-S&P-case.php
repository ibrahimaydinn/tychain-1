<?php
/**
 * OpTrade — Signal Monitor / Cron Job
 *
 * Run this script periodically to check all tracked stocks and send email alerts.
 *
 * Usage (CLI):
 *   php cron_signals-S&P-case.php
 *
 * Cron example (every hour):
 *   0 * * * * cd /path/to/BIST_PROJECT/backend/frontend && php cron_signals-S&P-case.php >> /tmp/optrade_cron.log 2>&1
 *
 * Or trigger it from the dashboard → "Run Scan Now" button.
 */

define('CLI_MODE', php_sapi_name() === 'cli');

require_once __DIR__ . '/db-S&P-case.php';
require_once __DIR__ . '/email_helper-S&P-case.php';

function clog(string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    if (CLI_MODE) echo $line;
    // Could also write to a log file here
}

// ── Find Python (mirrors api.php — checks venv first) ────────────────────────
function find_python_exec(): string|null {
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
        $test = @shell_exec('command -v ' . escapeshellarg($c) . ' 2>/dev/null');
        if (!empty(trim($test ?? '')) || file_exists($c)) return $c;
    }
    return null;
}

// ── Run model + return parsed signal or null ──────────────────────────────────
function run_and_parse(string $ticker): array|null {
    $python = find_python_exec();
    if (!$python) return null;

    $script = realpath(__DIR__ . '/../train_model-S&P-case.py');
    if (!$script) return null;

    $sym = strtoupper($ticker);

    clog("  Running model for $ticker …");
    $cmd    = escapeshellcmd($python) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($sym) . ' 2>/dev/null';
    $output = shell_exec($cmd);
    if (!$output) { clog("  ✗ No output"); return null; }

    $start = strpos($output, '{');
    $end   = strrpos($output, '}');
    if ($start === false || $end === false) { clog("  ✗ No JSON"); return null; }

    $data = json_decode(substr($output, $start, $end - $start + 1), true);
    if (!is_array($data) || isset($data['error'])) {
        clog("  ✗ " . ($data['error'] ?? 'parse error'));
        return null;
    }

    $sig    = $data['signal'] ?? [];
    $action = $sig['action']  ?? 'HOLD';
    $colors = ['STRONG BUY'=>'#66BB6A','BUY'=>'#A5D6A7','HOLD'=>'#FFF176',
               'SELL'=>'#EF9A9A','STRONG SELL'=>'#EF5350'];
    $emojis = ['STRONG BUY'=>'🚀','BUY'=>'📈','HOLD'=>'⏸️','SELL'=>'📉','STRONG SELL'=>'🔴'];

    return [
        'ticker'        => $ticker,
        'action'        => $action,
        'strength'      => (int)($sig['strength'] ?? 0),
        'score'         => (int)($sig['score']    ?? 0),
        'last_price'    => round((float)($data['last_price']     ?? 0), 2),
        'next_price'    => round((float)($data['next_day_price'] ?? 0), 2),
        'pch'           => round((float)($data['price_change']   ?? 0), 2),
        'rsi'           => round((float)($sig['rsi']             ?? 0), 1),
        'trend'         => $sig['trend']     ?? '',
        'hmm_label'     => $sig['hmm_label'] ?? '',
        'summary'       => $sig['summary']   ?? '',
        'color'         => $colors[$action]  ?? '#78909C',
        'emoji'         => $emojis[$action]  ?? '📊',
    ];
}

// ── Main ──────────────────────────────────────────────────────────────────────
clog('=== OpTrade Signal Scan Start ===');

$tickers = db_all_unique_tickers();

if (empty($tickers)) {
    clog('No tracked stocks found. Add stocks on the dashboard first.');
    exit(0);
}

clog('Unique tickers to check: ' . implode(', ', $tickers));

$scanned = 0;
$skipped = 0;
$failed  = 0;
$alerts_sent = 0;

foreach ($tickers as $ticker) {
    clog("→ $ticker");

    // Skip if signal is still fresh (avoids hammering Yahoo Finance)
    if (db_signal_is_fresh($ticker)) {
        clog("  ✓ Signal is fresh — skipping");
        $skipped++;
        continue;
    }

    $parsed = run_and_parse($ticker);
    if (!$parsed) {
        clog("  ✗ Analysis failed for $ticker");
        $failed++;
        continue;
    }

    // Store signal in DB
    db_save_signal([
        'ticker'         => $ticker,
        'signal_type'    => $parsed['action'],
        'signal_strength'=> $parsed['strength'],
        'score'          => $parsed['score'],
        'last_price'     => $parsed['last_price'],
        'next_day_price' => $parsed['next_price'],
        'price_change'   => $parsed['pch'],
        'rsi'            => $parsed['rsi'],
        'trend'          => $parsed['trend'],
        'hmm_label'      => $parsed['hmm_label'],
        'summary'        => $parsed['summary'],
    ]);

    clog("  ✓ Signal: {$parsed['action']} ({$parsed['strength']}%) | Price: ${$parsed['last_price']}");

    // ── Email alert check ─────────────────────────────────────────────────────
    if ($parsed['strength'] >= ALERT_THRESHOLD) {
        clog("  🔔 Strength ≥ " . ALERT_THRESHOLD . "% — checking for users to alert");
        $users = db_users_tracking($ticker);
        clog("  Found " . count($users) . " user(s) tracking $ticker");

        foreach ($users as $u) {
            $uid = (int)$u['id'];

            // Deduplicate: skip if we already sent this signal type today
            if (db_already_notified($uid, $ticker, $parsed['action'])) {
                clog("  ↳ {$u['email']} — already notified today, skipping");
                continue;
            }

            clog("  ↳ Sending alert to {$u['email']} …");
            $result = email_signal_alert(
                $u['email'], $u['email'], $ticker,
                $parsed['action'], $parsed['strength'],
                $parsed['last_price'], $parsed['next_price'], $parsed['summary']
            );

            if ($result === true) {
                db_log_email($uid, $ticker, $parsed['action'], $parsed['strength']);
                $alerts_sent++;
                clog("  ↳ ✓ Email sent");
            } else {
                clog("  ↳ ✗ Email failed: $result");
            }
        }
    }

    $scanned++;
    // Brief pause to avoid hammering Yahoo Finance in rapid succession
    if (count($tickers) > 1) sleep(2);
}

clog("=== Scan Complete ===");
clog("Scanned: $scanned | Skipped (fresh): $skipped | Failed: $failed | Alerts sent: $alerts_sent");

// If called as web request (not CLI), output JSON
if (!CLI_MODE) {
    header('Content-Type: application/json');
    echo json_encode([
        'ok'          => true,
        'scanned'     => $scanned,
        'skipped'     => $skipped,
        'failed'      => $failed,
        'alerts_sent' => $alerts_sent,
    ]);
}
