<?php
set_time_limit(480);   // 8 min — enough for model + faster now with cache
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$symbol = isset($_GET['symbol']) ? $_GET['symbol'] : 'AAPL';
$symbol = preg_replace('/[^A-Z0-9\.\-]/', '', strtoupper(trim($symbol)));
if (empty($symbol)) {
    echo json_encode(['error' => 'No symbol provided']);
    exit;
}

// Find the correct Python interpreter (prefer project venv first)
$project_root = realpath(__DIR__ . '/../..');
$python_candidates = [
    $project_root . '/venv/bin/python3',
    $project_root . '/.venv/bin/python3',
    '/Library/Frameworks/Python.framework/Versions/3.11/bin/python3.11',
    '/opt/homebrew/bin/python3.11',
    '/usr/local/bin/python3.11',
    '/opt/homebrew/bin/python3',
    '/usr/local/bin/python3',
    'python3.11',
    'python3',
];

$python = null;
foreach ($python_candidates as $p) {
    $test = shell_exec('command -v ' . escapeshellarg($p) . ' 2>/dev/null');
    if (!empty(trim($test ?? '')) || file_exists($p)) {
        $python = $p;
        break;
    }
}

if (!$python) {
    echo json_encode(['error' => 'Python interpreter not found']);
    exit;
}

$script = realpath(__DIR__ . '/../train_model-S&P-case.py');
if (!$script || !file_exists($script)) {
    echo json_encode(['error' => 'train_model-S&P-case.py not found at: ' . dirname(__DIR__)]);
    exit;
}

$cmd    = escapeshellcmd($python) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($symbol) . ' 2>&1';
$output = shell_exec($cmd);

if ($output === null || $output === '') {
    echo json_encode(['error' => 'Model produced no output. Check Python setup.']);
    exit;
}

// Find JSON in output (skip any warning lines before it)
$json_start = strpos($output, '{');
if ($json_start === false) {
    echo json_encode(['error' => 'Model output is not JSON', 'raw' => substr($output, 0, 500)]);
    exit;
}

$json_str = substr($output, $json_start);
$data     = json_decode($json_str, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(['error' => 'JSON parse failed: ' . json_last_error_msg(),
                      'raw'   => substr($json_str, 0, 300)]);
    exit;
}

echo json_encode($data);
