#!/usr/bin/env python3
"""Tychain — BIST30 Stock Forecasting (Flask web app)"""

import contextlib, json, os, secrets, sqlite3, subprocess, sys
from datetime import datetime, timedelta
from functools import wraps

from flask import (Flask, jsonify, redirect, render_template,
                   request, session, url_for)
from werkzeug.middleware.proxy_fix import ProxyFix
from werkzeug.security import check_password_hash, generate_password_hash
import pandas as pd

import db_config  # Turso (libSQL) in production, local SQLite in dev

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
SCRIPT   = os.path.join(BASE_DIR, 'backend', 'train_model.py')
PYTHON   = sys.executable
# Local-dev fallback path. In production TURSO_DATABASE_URL is set and this is unused.
DB_PATH  = os.environ.get('TYCHAIN_DB_PATH', os.path.join(BASE_DIR, 'tychain.db'))

ALERT_THRESHOLD  = 60          # Send alert when signal strength ≥ this %
ALERT_SIGNAL_TYPES = {         # Only these signal types trigger an email
    'BUY', 'STRONG BUY', 'SELL', 'STRONG SELL',
}
SIGNAL_CACHE_MIN = 60
MAX_TRACKED      = 20
RATE_LIMIT_MAX   = 5
RATE_LIMIT_MIN   = 15

BIST30_STOCKS = {
    'AKBNK':'AKBNK - Akbank',        'ASELS':'ASELS - Aselsan',
    'BIMAS':'BIMAS - BIM',           'DOHOL':'DOHOL - Dogan Holding',
    'EKGYO':'EKGYO - Emlak Konut',   'ENKAI':'ENKAI - Enka Insaat',
    'EREGL':'EREGL - Eregli Demir',  'FROTO':'FROTO - Ford Otosan',
    'GARAN':'GARAN - Garanti BBVA',  'GUBRF':'GUBRF - Gubre Fabrikalari',
    'HALKB':'HALKB - Halkbank',      'ISCTR':'ISCTR - Is Bankasi',
    'KCHOL':'KCHOL - Koc Holding',   'KOZAA':'KOZAA - Koza Anadolu',
    'KOZAL':'KOZAL - Koza Altin',    'KRDMD':'KRDMD - Kardemir',
    'MGROS':'MGROS - Migros',        'ODAS' :'ODAS - Odas Elektrik',
    'PETKM':'PETKM - Petkim',        'PGSUS':'PGSUS - Pegasus',
    'SAHOL':'SAHOL - Sabanci Holding','SASA':'SASA - SASA Polyester',
    'SISE' :'SISE - Sisecam',        'TAVHL':'TAVHL - TAV Havalimanlari',
    'TCELL':'TCELL - Turkcell',      'THYAO':'THYAO - Turkish Airlines',
    'TKFEN':'TKFEN - Tekfen Holding','TOASO':'TOASO - Tofas',
    'TUPRS':'TUPRS - Tupras',        'ULKER':'ULKER - Ulker Biskuvi',
    'VAKBN':'VAKBN - Vakifbank',     'VESTL':'VESTL - Vestel',
    'YKBNK':'YKBNK - Yapi Kredi',
}

def load_sp500_tickers():
    cache_file = os.path.join(BASE_DIR, 'sp500_tickers.json')
    if os.path.exists(cache_file):
        try:
            with open(cache_file, 'r') as f:
                return json.load(f)
        except:
            pass
    try:
        import requests
        import urllib3
        urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)
        html = requests.get('https://en.wikipedia.org/wiki/List_of_S%26P_500_companies', headers={'User-Agent': 'Mozilla/5.0'}, verify=False).text
        table = pd.read_html(html)[0]
        tickers = {str(row['Symbol']).replace('.', '-'): f"{str(row['Symbol']).replace('.', '-')} - {row['Security']}" for _, row in table.iterrows()}
        with open(cache_file, 'w') as f:
            json.dump(tickers, f)
        return tickers
    except Exception as e:
        print(f"Failed to fetch S&P 500: {e}")
        return {
            'AAPL': 'AAPL - Apple', 'MSFT': 'MSFT - Microsoft',
            'NVDA': 'NVDA - NVIDIA', 'GOOGL': 'GOOGL - Alphabet'
        }

SP500_STOCKS = load_sp500_tickers()
SP500_SECTORS = {'S&P 500': SP500_STOCKS} # Mock sector to keep index.html happy

STOCKS = {**BIST30_STOCKS, **SP500_STOCKS}

TICKER_BAR = [
    ('AAPL','+1.2%','up'),   ('THYAO','-0.5%','down'),('MSFT','+0.8%','up'),
    ('GARAN','+0.4%','up'),  ('NVDA','+2.1%','up'),   ('AKBNK','+1.5%','up'),
    ('AMZN','-0.2%','down'), ('FROTO','+1.8%','up'),  ('TSLA','-0.9%','down'),
    ('EREGL','+0.3%','up'),  ('GOOGL','+0.6%','up'),  ('SAHOL','-0.1%','down'),
]

SIGNAL_COLORS = {
    'STRONG BUY':'#66BB6A','BUY':'#A5D6A7','HOLD':'#FFF176',
    'SELL':'#EF9A9A','STRONG SELL':'#EF5350',
}
SIGNAL_EMOJIS = {
    'STRONG BUY':'🚀','BUY':'📈','HOLD':'⏸️','SELL':'📉','STRONG SELL':'🔴',
}

app = Flask(__name__)

# SECRET_KEY must be stable across workers and restarts. Without one,
# every gunicorn worker generates its own random key, which makes session
# cookies (and the CSRF token they hold) unreadable on cross-worker
# requests — surfacing as "Invalid Token" on signup/login.
_secret = os.environ.get('SECRET_KEY')
if not _secret:
    if db_config.using_turso():
        # Production (Turso configured) — fail loud rather than mint a
        # random per-worker key.
        raise RuntimeError(
            "SECRET_KEY environment variable is required in production. "
            "Generate one with: python -c \"import secrets; print(secrets.token_hex(32))\" "
            "and set it as a Hugging Face Space secret."
        )
    # Dev fallback — fine because Flask's dev server is single-process.
    _secret = secrets.token_hex(32)
    print("[app] WARNING: SECRET_KEY not set — using ephemeral key (dev only).")
app.secret_key = _secret

# Trust the Hugging Face reverse proxy (HTTPS → HTTP). x_proto=1 lets
# Flask see the original https scheme via X-Forwarded-Proto, which is
# required so that Secure-flagged cookies are issued.
app.wsgi_app = ProxyFix(app.wsgi_app, x_for=1, x_proto=1, x_host=1)

# Session cookie settings.
#
# In production we run inside a Hugging Face Spaces iframe whose origin
# (`*.hf.space`) is different from the surrounding huggingface.co page.
# Modern browsers treat that as a cross-site context and *will not* send
# `SameSite=Lax` cookies on requests originating in the iframe — which
# turns every POST into a fresh session and triggers our CSRF guard.
# `SameSite=None; Secure` is the only combination that works there.
#
# Locally (dev), we don't have HTTPS, so emitting `Secure` cookies would
# prevent the browser from storing them at all. Detect and downgrade.
_in_production = db_config.using_turso()
app.config.update(
    SESSION_COOKIE_HTTPONLY=True,
    SESSION_COOKIE_SAMESITE='None' if _in_production else 'Lax',
    SESSION_COOKIE_SECURE=_in_production,
    PERMANENT_SESSION_LIFETIME=timedelta(days=7),
)

# ── Database ───────────────────────────────────────────────────────────────────

@contextlib.contextmanager
def get_db():
    """Yield a DB connection. Routes through db_config so the same code
    works against Turso (production) and local SQLite (dev)."""
    conn = db_config.get_connection()
    try:
        yield conn
        conn.commit()
    except Exception:
        try:
            conn.rollback()
        except Exception:
            pass  # Some libsql modes don't support rollback after commit
        raise
    finally:
        conn.close()


# Schema split as discrete statements: required because libsql may not
# expose executescript(). Works identically against sqlite3.
_SCHEMA_STATEMENTS = [
    """CREATE TABLE IF NOT EXISTS users (
        id            INTEGER  PRIMARY KEY AUTOINCREMENT,
        email         TEXT     UNIQUE NOT NULL,
        password_hash TEXT     NOT NULL,
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
    )""",
    "CREATE INDEX IF NOT EXISTS idx_users_email ON users(email)",
    """CREATE TABLE IF NOT EXISTS tracked_stocks (
        id         INTEGER  PRIMARY KEY AUTOINCREMENT,
        user_id    INTEGER  NOT NULL,
        ticker     TEXT     NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE(user_id, ticker)
    )""",
    "CREATE INDEX IF NOT EXISTS idx_ts_user   ON tracked_stocks(user_id)",
    "CREATE INDEX IF NOT EXISTS idx_ts_ticker ON tracked_stocks(ticker)",
    """CREATE TABLE IF NOT EXISTS signals (
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
    )""",
    "CREATE INDEX IF NOT EXISTS idx_sig_ticker ON signals(ticker)",
    """CREATE TABLE IF NOT EXISTS email_log (
        id              INTEGER  PRIMARY KEY AUTOINCREMENT,
        user_id         INTEGER  NOT NULL,
        ticker          TEXT     NOT NULL,
        signal_type     TEXT     NOT NULL,
        signal_strength INTEGER  NOT NULL,
        sent_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )""",
    """CREATE TABLE IF NOT EXISTS login_attempts (
        id         INTEGER  PRIMARY KEY AUTOINCREMENT,
        ip         TEXT     NOT NULL,
        email      TEXT     NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )""",
    """CREATE TABLE IF NOT EXISTS performance_analytics (
        ticker TEXT PRIMARY KEY,
        market TEXT NOT NULL,
        price REAL,
        abs_1d REAL,
        pct_1d REAL,
        abs_1w REAL,
        pct_1w REAL,
        abs_1m REAL,
        pct_1m REAL,
        abs_1y REAL,
        pct_1y REAL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )""",
]


def init_db():
    """Create tables if missing. Safe to run repeatedly. Used by both
    local SQLite (with WAL mode) and Turso."""
    conn = db_config.get_connection()
    try:
        # WAL is a no-op on libsql remote, but speeds up local dev hugely.
        if not db_config.using_turso():
            try:
                conn.execute("PRAGMA journal_mode = WAL")
            except Exception:
                pass
        for stmt in _SCHEMA_STATEMENTS:
            conn.execute(stmt)
        conn.commit()
    finally:
        conn.close()

def db_create_user(email, password):
    try:
        with get_db() as conn:
            conn.execute(
                "INSERT INTO users (email, password_hash) VALUES (?, ?)",
                (email, generate_password_hash(password))
            )
            return conn.execute("SELECT last_insert_rowid()").fetchone()[0]
    except sqlite3.IntegrityError:
        return None
    except Exception as e:
        # libSQL surfaces UNIQUE violations as a generic error string;
        # fall through only for that specific case so we don't swallow real bugs.
        msg = str(e).lower()
        if 'unique' in msg or 'constraint' in msg:
            return None
        raise

def db_find_user_by_email(email):
    with get_db() as conn:
        cur = conn.execute("SELECT * FROM users WHERE email = ?", (email,))
        return db_config.dict_row(cur)

def db_find_user_by_id(user_id):
    with get_db() as conn:
        cur = conn.execute(
            "SELECT id, email, created_at FROM users WHERE id = ?", (user_id,)
        )
        return db_config.dict_row(cur)

def db_add_tracked(user_id, ticker):
    try:
        with get_db() as conn:
            conn.execute(
                "INSERT OR IGNORE INTO tracked_stocks (user_id, ticker) VALUES (?, ?)",
                (user_id, ticker.upper())
            )
        return True
    except Exception:
        return False

def db_remove_tracked(user_id, ticker):
    with get_db() as conn:
        conn.execute(
            "DELETE FROM tracked_stocks WHERE user_id = ? AND ticker = ?",
            (user_id, ticker.upper())
        )

def db_get_tracked(user_id):
    with get_db() as conn:
        cur = conn.execute(
            "SELECT ticker, created_at FROM tracked_stocks WHERE user_id = ? ORDER BY created_at DESC",
            (user_id,)
        )
        return db_config.dict_rows(cur)

def db_count_tracked(user_id):
    with get_db() as conn:
        return conn.execute(
            "SELECT COUNT(*) FROM tracked_stocks WHERE user_id = ?", (user_id,)
        ).fetchone()[0]

def db_save_signal(d):
    with get_db() as conn:
        conn.execute("DELETE FROM signals WHERE ticker = ?", (d['ticker'],))
        conn.execute("""
            INSERT INTO signals
                (ticker, signal_type, signal_strength, score, last_price,
                 next_day_price, price_change, rsi, trend, hmm_label, summary)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
        """, (
            d['ticker'], d['signal_type'], d['signal_strength'],
            d.get('score'), d.get('last_price'), d.get('next_day_price'),
            d.get('price_change'), d.get('rsi'), d.get('trend'),
            d.get('hmm_label'), d.get('summary'),
        ))

def db_get_signal(ticker):
    with get_db() as conn:
        cur = conn.execute(
            "SELECT * FROM signals WHERE ticker = ? LIMIT 1", (ticker.upper(),)
        )
        return db_config.dict_row(cur)

def db_signal_is_fresh(ticker):
    sig = db_get_signal(ticker)
    if not sig:
        return False
    try:
        checked = datetime.fromisoformat(sig['checked_at'])
        return (datetime.utcnow() - checked).total_seconds() / 60 < SIGNAL_CACHE_MIN
    except Exception:
        return False

def db_rate_limited(ip, email):
    with get_db() as conn:
        conn.execute(
            "DELETE FROM login_attempts WHERE created_at < datetime('now', ?)",
            (f'-{RATE_LIMIT_MIN} minutes',)
        )
        count = conn.execute(
            "SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND email = ?",
            (ip, email)
        ).fetchone()[0]
        return count >= RATE_LIMIT_MAX

def db_record_attempt(ip, email):
    with get_db() as conn:
        conn.execute(
            "INSERT INTO login_attempts (ip, email) VALUES (?, ?)", (ip, email)
        )

def db_all_unique_tickers():
    with get_db() as conn:
        rows = conn.execute("SELECT DISTINCT ticker FROM tracked_stocks").fetchall()
        return [r['ticker'] for r in rows]

def db_users_tracking(ticker):
    with get_db() as conn:
        cur = conn.execute("""
            SELECT u.id, u.email
            FROM tracked_stocks ts
            JOIN users u ON ts.user_id = u.id
            WHERE ts.ticker = ?
        """, (ticker.upper(),))
        return db_config.dict_rows(cur)

def db_already_notified(user_id, ticker, signal_type):
    with get_db() as conn:
        count = conn.execute("""
            SELECT COUNT(*) FROM email_log 
            WHERE user_id = ? AND ticker = ? AND signal_type = ? 
            AND date(sent_at) = date('now')
        """, (user_id, ticker.upper(), signal_type)).fetchone()[0]
        return count > 0

def db_log_email(user_id, ticker, signal_type, signal_strength):
    with get_db() as conn:
        conn.execute("""
            INSERT INTO email_log (user_id, ticker, signal_type, signal_strength)
            VALUES (?, ?, ?, ?)
        """, (user_id, ticker.upper(), signal_type, signal_strength))

# ── Jinja2 filters ─────────────────────────────────────────────────────────────

@app.template_filter('time_ago')
def time_ago(dt_str):
    try:
        diff = (datetime.utcnow() - datetime.fromisoformat(str(dt_str))).total_seconds()
        if diff < 60:    return 'Just now'
        if diff < 3600:  return f'{int(diff / 60)}m ago'
        if diff < 86400: return f'{int(diff / 3600)}h ago'
        return f'{int(diff / 86400)}d ago'
    except Exception:
        return str(dt_str)

@app.template_filter('format_date')
def format_date(dt_str):
    try:
        return datetime.fromisoformat(str(dt_str)).strftime('%d %b %Y')
    except Exception:
        return str(dt_str)

@app.template_filter('numfmt')
def numfmt(value, decimals=2):
    try:
        return f'{float(value):,.{decimals}f}'
    except (TypeError, ValueError):
        return '--'

@app.template_filter('signal_color')
def signal_color(signal_type):
    for k, v in SIGNAL_COLORS.items():
        if k in (signal_type or ''):
            return v
    return '#78909C'

@app.template_filter('signal_emoji')
def signal_emoji(signal_type):
    for k, v in SIGNAL_EMOJIS.items():
        if k in (signal_type or ''):
            return v
    return '❓'

# ── Helpers ────────────────────────────────────────────────────────────────────

def login_required(f):
    @wraps(f)
    def decorated(*args, **kwargs):
        if 'user_id' not in session:
            return redirect(url_for('auth'))
        return f(*args, **kwargs)
    return decorated

def get_csrf():
    if 'csrf' not in session:
        session['csrf'] = secrets.token_hex(16)
    return session['csrf']

def check_csrf(token):
    return secrets.compare_digest(token or '', session.get('csrf', ''))

def run_analysis(symbol):
    try:
        if symbol in BIST30_STOCKS:
            script_path = SCRIPT
            arg = symbol + '.IS'
        else:
            script_path = os.path.join(BASE_DIR, 'backend', 'train_model_sp500.py')
            arg = symbol

        result = subprocess.run(
            [PYTHON, script_path, arg],
            capture_output=True, text=True, timeout=360, cwd=BASE_DIR
        )
        output = result.stdout
        j0, j1 = output.find('{'), output.rfind('}')
        if j0 == -1 or j1 == -1:
            return {'error': f'No output from model. stderr: {result.stderr[-400:]}'}
        
        data = json.loads(output[j0:j1 + 1])
        data['currency'] = 'TRY' if symbol in BIST30_STOCKS else 'USD'
        data['currency_symbol'] = '₺' if symbol in BIST30_STOCKS else '$'
        return data
    except subprocess.TimeoutExpired:
        return {'error': 'Analysis timed out (>6 min). Please try again.'}
    except json.JSONDecodeError as e:
        return {'error': f'JSON parse error: {e}'}
    except Exception as e:
        return {'error': str(e)}

def persist_signal(symbol, data):
    db_save_signal({
        'ticker':          symbol,
        'signal_type':     data['signal']['action'],
        'signal_strength': data['signal']['strength'],
        'score':           data['signal'].get('score'),
        'last_price':      data.get('last_price'),
        'next_day_price':  data.get('next_day_price'),
        'price_change':    data.get('price_change'),
        'rsi':             data['signal'].get('rsi'),
        'trend':           data['signal'].get('trend'),
        'hmm_label':       data['signal'].get('hmm_label'),
        'summary':         data['signal'].get('summary'),
    })

# ── Routes ─────────────────────────────────────────────────────────────────────

@app.route('/')
def index():
    return render_template('index.html',
        logged_in=('user_id' in session),
        user_email=session.get('email', ''),
        csrf=get_csrf(),
        bist30_stocks=BIST30_STOCKS,
        sp500_stocks=SP500_STOCKS,
        sp500_sectors=SP500_SECTORS,
        ticker_bar=TICKER_BAR,
        stock_param=request.args.get('stock', ''),
    )

@app.route('/auth')
def auth():
    if 'user_id' in session:
        return redirect(url_for('dashboard'))
    return render_template('auth.html',
        tab=request.args.get('tab', 'login'),
        csrf=get_csrf(),
    )

@app.route('/dashboard')
@login_required
def dashboard():
    uid     = session['user_id']
    tracked = db_get_tracked(uid)
    
    tracked_bist30 = []
    tracked_sp500 = []
    signals_data = {}
    for t in tracked:
        sig = db_get_signal(t['ticker'])
        if t['ticker'] in BIST30_STOCKS:
            tracked_bist30.append(t)
            if sig:
                sig['currency'] = 'TRY'
                sig['currency_symbol'] = '₺'
        else:
            tracked_sp500.append(t)
            if sig:
                sig['currency'] = 'USD'
                sig['currency_symbol'] = '$'
        signals_data[t['ticker']] = sig

    return render_template('dashboard.html',
        user=db_find_user_by_id(uid),
        tracked=tracked,
        tracked_bist30=tracked_bist30,
        tracked_sp500=tracked_sp500,
        tracked_tickers={t['ticker'] for t in tracked},
        signals=signals_data,
        bist30_stocks=BIST30_STOCKS,
        sp500_stocks=SP500_STOCKS,
        sp500_sectors=SP500_SECTORS,
        csrf=get_csrf(),
        alert_threshold=ALERT_THRESHOLD,
        max_tracked=MAX_TRACKED,
    )

@app.route('/logout', methods=['GET', 'POST'])
def logout():
    session.clear()
    return redirect(url_for('index'))

@app.route('/market-summary')
def market_summary():
    with get_db() as conn:
        bist_gainers  = db_config.dict_rows(conn.execute("SELECT * FROM performance_analytics WHERE market='BIST30' ORDER BY pct_1d DESC LIMIT 10"))
        bist_losers   = db_config.dict_rows(conn.execute("SELECT * FROM performance_analytics WHERE market='BIST30' ORDER BY pct_1d ASC LIMIT 10"))
        sp500_gainers = db_config.dict_rows(conn.execute("SELECT * FROM performance_analytics WHERE market='SP500' ORDER BY pct_1d DESC LIMIT 10"))
        sp500_losers  = db_config.dict_rows(conn.execute("SELECT * FROM performance_analytics WHERE market='SP500' ORDER BY pct_1d ASC LIMIT 10"))

    return render_template('market-summary.html',
        logged_in=('user_id' in session),
        user_email=session.get('email', ''),
        bist_gainers=bist_gainers,
        bist_losers=bist_losers,
        sp500_gainers=sp500_gainers,
        sp500_losers=sp500_losers,
    )

@app.route('/performance')
def performance():
    with get_db() as conn:
        bist_perf  = db_config.dict_rows(conn.execute("SELECT * FROM performance_analytics WHERE market='BIST30' ORDER BY ticker ASC"))
        sp500_perf = db_config.dict_rows(conn.execute("SELECT * FROM performance_analytics WHERE market='SP500' ORDER BY ticker ASC"))

    return render_template('performance.html',
        logged_in=('user_id' in session),
        user_email=session.get('email', ''),
        bist_perf=bist_perf,
        sp500_perf=sp500_perf,
    )

# ── API ────────────────────────────────────────────────────────────────────────

@app.route('/api/analyze')
def api_analyze():
    symbol = request.args.get('symbol', '').upper()
    if not symbol or symbol not in STOCKS:
        return jsonify({'error': 'Invalid symbol'}), 400
    data = run_analysis(symbol)
    if 'error' not in data:
        persist_signal(symbol, data)
    return jsonify(data)

@app.route('/api/auth', methods=['POST'])
def api_auth_route():
    action = request.form.get('action')
    if not check_csrf(request.form.get('csrf')):
        # Almost always a session/cookie issue: SECRET_KEY mismatch across
        # workers, browser blocking cookies, or a stale form left open
        # across a server restart. Tell the user to refresh.
        return jsonify({'ok': False, 'error': 'Session expired — please refresh the page and try again.'}), 403

    if action == 'login':
        email = request.form.get('email', '').lower().strip()
        pw    = request.form.get('password', '')
        ip    = request.remote_addr
        if db_rate_limited(ip, email):
            return jsonify({'ok': False, 'error': 'Too many attempts. Try again later.'})
        user = db_find_user_by_email(email)
        if not user or not check_password_hash(user['password_hash'], pw):
            db_record_attempt(ip, email)
            return jsonify({'ok': False, 'error': 'Invalid email or password.'})
        session['user_id'] = user['id']
        session['email']   = user['email']
        return jsonify({'ok': True, 'redirect': url_for('dashboard')})

    if action == 'signup':
        email = request.form.get('email', '').lower().strip()
        pw    = request.form.get('password', '')
        pw2   = request.form.get('password2', '')
        if not email or not pw:
            return jsonify({'ok': False, 'error': 'Email and password are required.'})
        if len(pw) < 8:
            return jsonify({'ok': False, 'error': 'Password must be at least 8 characters.'})
        if pw != pw2:
            return jsonify({'ok': False, 'error': 'Passwords do not match.'})
        uid = db_create_user(email, pw)
        if uid is None:
            return jsonify({'ok': False, 'error': 'An account with this email already exists.'})
        session['user_id'] = uid
        session['email']   = email
        return jsonify({'ok': True, 'redirect': url_for('dashboard')})

    return jsonify({'ok': False, 'error': 'Unknown action'}), 400

@app.route('/api/tracked', methods=['POST'])
@login_required
def api_tracked():
    uid    = session['user_id']
    action = request.form.get('action')
    if not check_csrf(request.form.get('csrf')):
        # Almost always a session/cookie issue: SECRET_KEY mismatch across
        # workers, browser blocking cookies, or a stale form left open
        # across a server restart. Tell the user to refresh.
        return jsonify({'ok': False, 'error': 'Session expired — please refresh the page and try again.'}), 403

    if action == 'add':
        ticker = request.form.get('ticker', '').upper()
        if ticker not in STOCKS:
            return jsonify({'ok': False, 'error': 'Invalid ticker'})
        if db_count_tracked(uid) >= MAX_TRACKED:
            return jsonify({'ok': False, 'error': f'Max {MAX_TRACKED} stocks allowed'})
        db_add_tracked(uid, ticker)
        return jsonify({'ok': True})

    if action == 'remove':
        ticker = request.form.get('ticker', '').upper()
        db_remove_tracked(uid, ticker)
        return jsonify({'ok': True})

    if action == 'refresh':
        ticker = request.form.get('ticker', '').upper()
        if ticker not in STOCKS:
            return jsonify({'ok': False, 'error': 'Invalid ticker'})
        data = run_analysis(ticker)
        if 'error' in data:
            return jsonify({'ok': False, 'error': data['error']})
        persist_signal(ticker, data)
        act = data['signal']['action']
        return jsonify({
            'ok': True,
            'signal': {
                'action':     act,
                'strength':   data['signal']['strength'],
                'color':      SIGNAL_COLORS.get(act, '#78909C'),
                'emoji':      SIGNAL_EMOJIS.get(act, '❓'),
                'last_price': f"{data.get('last_price', 0):.2f}",
                'pch':        round(data.get('price_change', 0), 2),
                'rsi':        round(data['signal'].get('rsi', 0), 1),
                'trend':      data['signal'].get('trend', ''),
                'currency':   data.get('currency', 'TRY'),
                'currency_symbol': data.get('currency_symbol', '₺'),
            }
        })

    if action == 'cron':
        import cron_signals
        result = cron_signals.run_cron()
        return jsonify({
            'ok': True,
            'message': f"Scanned {result['scanned']}, Sent {result['alerts_sent']} alerts"
        })

    return jsonify({'ok': False, 'error': 'Unknown action'}), 400

# Initialize database tables unconditionally for Gunicorn
init_db()

if __name__ == '__main__':
    app.run(host='127.0.0.1', port=8080, debug=False)
