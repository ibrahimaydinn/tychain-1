<?php
/**
 * OpTrade — Database layer
 *
 * Priority order for connection:
 *   1. Turso (free remote SQLite)  — when TURSO_URL env var is set   (HuggingFace)
 *   2. MySQL / PlanetScale         — when DB_HOST env var is set      (optional paid)
 *   3. SQLite file                 — fallback                         (localhost)
 */
require_once __DIR__ . '/config-S&P-case.php';

define('USE_TURSO', !empty(getenv('TURSO_URL')));
define('USE_MYSQL',  !USE_TURSO && !empty(getenv('DB_HOST')));

// ═══════════════════════════════════════════════════════════════════════════════
// Turso HTTP client — thin wrapper that mimics the PDO interface
// ═══════════════════════════════════════════════════════════════════════════════
class TursoStatement {
    private string $sql;
    private TursoDB $db;
    private array $rows = [];

    public function __construct(string $sql, TursoDB $db) {
        $this->sql = $sql;
        $this->db  = $db;
    }

    public function execute(array $params = []): bool {
        $this->rows = $this->db->_run($this->sql, $params);
        return true;
    }

    public function fetch(): array|false {
        return array_shift($this->rows) ?? false;
    }

    public function fetchAll(): array {
        return $this->rows;
    }

    public function fetchColumn(int $col = 0): mixed {
        $row = $this->fetch();
        if ($row === false) return false;
        return array_values($row)[$col] ?? false;
    }
}

class TursoDB {
    private string $url;
    private string $token;
    public  string $lastId = '0';

    public function __construct(string $url, string $token) {
        // Accept libsql:// or https:// — always use HTTPS for the HTTP API
        $this->url   = preg_replace('#^libsql://#', 'https://', rtrim($url, '/'));
        $this->token = $token;
    }

    public function prepare(string $sql): TursoStatement {
        return new TursoStatement($sql, $this);
    }

    public function exec(string $sql): void {
        $this->_run($sql, []);
    }

    /** Run a query and return it as a statement (for ->fetchAll() etc.) */
    public function query(string $sql): TursoStatement {
        $stmt = new TursoStatement($sql, $this);
        $stmt->execute([]);
        return $stmt;
    }

    public function lastInsertId(): string {
        return $this->lastId;
    }

    /** Internal: send one statement to Turso, return rows as assoc arrays. */
    public function _run(string $sql, array $params): array {
        $args = array_map(function ($v) {
            if ($v === null)       return ['type' => 'null'];
            if (is_int($v))        return ['type' => 'integer', 'value' => (string)$v];
            if (is_float($v))      return ['type' => 'float',   'value' => (string)$v];
            return                        ['type' => 'text',    'value' => (string)$v];
        }, array_values($params));

        $payload = json_encode([
            'requests' => [
                ['type' => 'execute', 'stmt' => ['sql' => $sql, 'args' => $args]],
                ['type' => 'close'],
            ]
        ]);

        $ch = curl_init($this->url . '/v2/pipeline');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->token,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        $raw   = curl_exec($ch);
        $cerr  = curl_error($ch);
        curl_close($ch);

        if ($cerr)       throw new \RuntimeException("Turso network error: $cerr");

        $data = json_decode($raw, true);
        if (empty($data['results'])) throw new \RuntimeException("Turso: unexpected response: $raw");

        $res = $data['results'][0];
        if ($res['type'] === 'error')
            throw new \RuntimeException("Turso SQL error: " . ($res['error']['message'] ?? $raw));

        $result = $res['response']['result'];
        $this->lastId = $result['last_insert_rowid'] ?? '0';

        // Convert columnar format → array of associative rows
        $cols = array_column($result['cols'], 'name');
        $rows = [];
        foreach ($result['rows'] as $row) {
            $assoc = [];
            foreach ($cols as $i => $col) {
                $assoc[$col] = $row[$i]['value'] ?? null;
            }
            $rows[] = $assoc;
        }
        return $rows;
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// Connection factory
// ═══════════════════════════════════════════════════════════════════════════════
function get_db(): PDO|TursoDB {
    static $db = null;
    if ($db !== null) return $db;

    if (USE_TURSO) {
        $db = new TursoDB(getenv('TURSO_URL'), getenv('TURSO_TOKEN'));
        _init_turso($db);
    } elseif (USE_MYSQL) {
        $host = getenv('DB_HOST');
        $name = getenv('DB_NAME') ?: 'optrade';
        $user = getenv('DB_USER');
        $pass = getenv('DB_PASS');
        $db   = new PDO(
            "mysql:host={$host};port=3306;dbname={$name};charset=utf8mb4",
            $user, $pass,
            [
                PDO::ATTR_ERRMODE                      => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE           => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_SSL_CA                 => '/etc/ssl/certs/ca-certificates.crt',
                PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
            ]
        );
        _init_mysql($db);
    } else {
        $db = new PDO('sqlite:' . DB_PATH);
        $db->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        _init_sqlite($db);
    }

    return $db;
}

// ── Schema: Turso (SQLite syntax, one statement per exec) ─────────────────────
function _init_turso(TursoDB $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id            INTEGER  PRIMARY KEY AUTOINCREMENT,
        email         TEXT     UNIQUE NOT NULL,
        password_hash TEXT     NOT NULL,
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_users_email ON users(email)");

    $db->exec("CREATE TABLE IF NOT EXISTS tracked_stocks (
        id         INTEGER  PRIMARY KEY AUTOINCREMENT,
        user_id    INTEGER  NOT NULL,
        ticker     TEXT     NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE(user_id, ticker)
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_ts_user   ON tracked_stocks(user_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_ts_ticker ON tracked_stocks(ticker)");

    $db->exec("CREATE TABLE IF NOT EXISTS signals (
        id              INTEGER  PRIMARY KEY AUTOINCREMENT,
        ticker          TEXT     NOT NULL,
        signal_type     TEXT     NOT NULL,
        signal_strength INTEGER  NOT NULL,
        score           INTEGER,
        last_price      REAL,
        next_day_price  REAL,
        price_change    REAL,
        rsi             REAL,
        trend           TEXT,
        hmm_label       TEXT,
        summary         TEXT,
        checked_at      DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_sig_ticker ON signals(ticker)");

    $db->exec("CREATE TABLE IF NOT EXISTS email_log (
        id              INTEGER  PRIMARY KEY AUTOINCREMENT,
        user_id         INTEGER  NOT NULL,
        ticker          TEXT     NOT NULL,
        signal_type     TEXT     NOT NULL,
        signal_strength INTEGER  NOT NULL,
        sent_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id         INTEGER  PRIMARY KEY AUTOINCREMENT,
        ip         TEXT     NOT NULL,
        email      TEXT     NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
}

// ── Schema: MySQL ─────────────────────────────────────────────────────────────
function _init_mysql(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id            INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
        email         VARCHAR(255) UNIQUE NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        created_at    DATETIME     DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_users_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tracked_stocks (
        id         INT         NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id    INT         NOT NULL,
        ticker     VARCHAR(20) NOT NULL,
        created_at DATETIME    DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_user_ticker (user_id, ticker),
        INDEX idx_ts_user (user_id), INDEX idx_ts_ticker (ticker),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS signals (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, ticker VARCHAR(20) NOT NULL,
        signal_type VARCHAR(10) NOT NULL, signal_strength INT NOT NULL,
        score INT, last_price DOUBLE, next_day_price DOUBLE, price_change DOUBLE,
        rsi DOUBLE, trend VARCHAR(20), hmm_label VARCHAR(20), summary TEXT,
        checked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_sig_ticker (ticker)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_log (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
        ticker VARCHAR(20) NOT NULL, signal_type VARCHAR(10) NOT NULL,
        signal_strength INT NOT NULL, sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, ip VARCHAR(45) NOT NULL,
        email VARCHAR(255) NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// ── Schema: SQLite ────────────────────────────────────────────────────────────
function _init_sqlite(PDO $pdo): void {
    $pdo->exec("PRAGMA journal_mode = WAL");
    $pdo->exec("PRAGMA foreign_keys = ON");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id            INTEGER  PRIMARY KEY AUTOINCREMENT,
            email         TEXT     UNIQUE NOT NULL,
            password_hash TEXT     NOT NULL,
            created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE INDEX IF NOT EXISTS idx_users_email ON users(email);
        CREATE TABLE IF NOT EXISTS tracked_stocks (
            id         INTEGER  PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER  NOT NULL,
            ticker     TEXT     NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE(user_id, ticker)
        );
        CREATE INDEX IF NOT EXISTS idx_ts_user   ON tracked_stocks(user_id);
        CREATE INDEX IF NOT EXISTS idx_ts_ticker ON tracked_stocks(ticker);
        CREATE TABLE IF NOT EXISTS signals (
            id INTEGER PRIMARY KEY AUTOINCREMENT, ticker TEXT NOT NULL,
            signal_type TEXT NOT NULL, signal_strength INTEGER NOT NULL,
            score INTEGER, last_price REAL, next_day_price REAL,
            price_change REAL, rsi REAL, trend TEXT, hmm_label TEXT,
            summary TEXT, checked_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE INDEX IF NOT EXISTS idx_sig_ticker ON signals(ticker);
        CREATE TABLE IF NOT EXISTS email_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
            ticker TEXT NOT NULL, signal_type TEXT NOT NULL,
            signal_strength INTEGER NOT NULL, sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT NOT NULL,
            email TEXT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");
}

// ═══════════════════════════════════════════════════════════════════════════════
// DB helper functions (work with PDO and TursoDB alike)
// ═══════════════════════════════════════════════════════════════════════════════

// ── Users ─────────────────────────────────────────────────────────────────────
function db_create_user(string $email, string $password): int|false {
    $db   = get_db();
    $hash = password_hash($password, PASSWORD_DEFAULT);
    try {
        $stmt = $db->prepare("INSERT INTO users (email, password_hash) VALUES (?, ?)");
        $stmt->execute([$email, $hash]);
        return (int) $db->lastInsertId();
    } catch (\Exception $e) {
        return false;
    }
}

function db_find_user_by_email(string $email): array|null {
    $stmt = get_db()->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function db_find_user_by_id(int $id): array|null {
    $stmt = get_db()->prepare("SELECT id, email, created_at FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// ── Tracked stocks ────────────────────────────────────────────────────────────
function db_add_tracked(int $user_id, string $ticker): bool {
    try {
        $sql  = USE_MYSQL
            ? "INSERT IGNORE INTO tracked_stocks (user_id, ticker) VALUES (?, ?)"
            : "INSERT OR IGNORE INTO tracked_stocks (user_id, ticker) VALUES (?, ?)";
        get_db()->prepare($sql)->execute([$user_id, strtoupper($ticker)]);
        return true;
    } catch (\Exception) { return false; }
}

function db_remove_tracked(int $user_id, string $ticker): void {
    get_db()->prepare("DELETE FROM tracked_stocks WHERE user_id = ? AND ticker = ?")
        ->execute([$user_id, strtoupper($ticker)]);
}

function db_get_tracked(int $user_id): array {
    $stmt = get_db()->prepare(
        "SELECT ticker, created_at FROM tracked_stocks WHERE user_id = ? ORDER BY created_at DESC"
    );
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}

function db_count_tracked(int $user_id): int {
    $stmt = get_db()->prepare("SELECT COUNT(*) FROM tracked_stocks WHERE user_id = ?");
    $stmt->execute([$user_id]);
    return (int) $stmt->fetchColumn();
}

function db_all_unique_tickers(): array {
    $rows = get_db()->query("SELECT DISTINCT ticker FROM tracked_stocks")->fetchAll();
    return array_column($rows, 'ticker');
}

function db_users_tracking(string $ticker): array {
    $stmt = get_db()->prepare("
        SELECT u.id, u.email
        FROM tracked_stocks ts
        JOIN users u ON u.id = ts.user_id
        WHERE ts.ticker = ?
    ");
    $stmt->execute([strtoupper($ticker)]);
    return $stmt->fetchAll();
}

// ── Signals ───────────────────────────────────────────────────────────────────
function db_save_signal(array $d): void {
    $db = get_db();
    $db->prepare("DELETE FROM signals WHERE ticker = ?")->execute([$d['ticker']]);
    $db->prepare("
        INSERT INTO signals
            (ticker, signal_type, signal_strength, score, last_price, next_day_price,
             price_change, rsi, trend, hmm_label, summary)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
    ")->execute([
        $d['ticker'], $d['signal_type'], $d['signal_strength'], $d['score'] ?? null,
        $d['last_price'] ?? null, $d['next_day_price'] ?? null,
        $d['price_change'] ?? null, $d['rsi'] ?? null,
        $d['trend'] ?? null, $d['hmm_label'] ?? null, $d['summary'] ?? null,
    ]);
}

function db_get_signal(string $ticker): array|null {
    $stmt = get_db()->prepare("SELECT * FROM signals WHERE ticker = ? LIMIT 1");
    $stmt->execute([strtoupper($ticker)]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function db_signal_is_fresh(string $ticker): bool {
    $sig = db_get_signal($ticker);
    if (!$sig) return false;
    return (time() - strtotime($sig['checked_at'])) / 60 < SIGNAL_CACHE_MIN;
}

// ── Email log ─────────────────────────────────────────────────────────────────
function db_already_notified(int $user_id, string $ticker, string $signal_type): bool {
    $cutoff = USE_MYSQL
        ? "DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        : "datetime('now', '-24 hours')";
    $stmt = get_db()->prepare("
        SELECT COUNT(*) FROM email_log
        WHERE user_id = ? AND ticker = ? AND signal_type = ?
          AND sent_at >= {$cutoff}
    ");
    $stmt->execute([$user_id, strtoupper($ticker), $signal_type]);
    return (int) $stmt->fetchColumn() > 0;
}

function db_log_email(int $user_id, string $ticker, string $signal_type, int $strength): void {
    get_db()->prepare(
        "INSERT INTO email_log (user_id, ticker, signal_type, signal_strength) VALUES (?,?,?,?)"
    )->execute([$user_id, strtoupper($ticker), $signal_type, $strength]);
}

// ── Rate limiting ─────────────────────────────────────────────────────────────
function db_rate_limited(string $ip, string $email): bool {
    $db     = get_db();
    $limit  = (int) RATE_LIMIT_MIN;
    $cutoff = USE_MYSQL
        ? "DATE_SUB(NOW(), INTERVAL {$limit} MINUTE)"
        : "datetime('now', '-{$limit} minutes')";

    $db->prepare("DELETE FROM login_attempts WHERE created_at < {$cutoff}")->execute();

    $stmt = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND email = ?");
    $stmt->execute([$ip, $email]);
    return (int) $stmt->fetchColumn() >= RATE_LIMIT_MAX;
}

function db_record_attempt(string $ip, string $email): void {
    get_db()->prepare("INSERT INTO login_attempts (ip, email) VALUES (?,?)")
        ->execute([$ip, $email]);
}
