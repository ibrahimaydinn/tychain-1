<?php
/**
 * OpTrade — User Dashboard
 * Shows tracked stocks, latest signals, and allows adding/removing stocks.
 */
require_once __DIR__ . '/db-S&P-case.php';
session_start();

// Auth guard
if (empty($_SESSION['user_id'])) {
    header('Location: auth-S&P-case.php');
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$user    = db_find_user_by_id($user_id);
$tracked = db_get_tracked($user_id);
$csrf    = $_SESSION['csrf'] ?? bin2hex(random_bytes(16));
$_SESSION['csrf'] = $csrf;

// Fetch latest signal for each tracked ticker
$signals = [];
foreach ($tracked as $t) {
    $signals[$t['ticker']] = db_get_signal($t['ticker']);
}

$SP500 = [
    'AAPL','MSFT','AMZN','NVDA','GOOGL','META','TSLA','JPM','LLY','V',
    'UNH','XOM','MA','HD','PG','COST','JNJ','MRK','ABBV','BAC',
    'AVGO','WMT','CVX','KO','NFLX','DIS','CSCO','INTC','AMD','CRM',
];

function signal_color(string $type): string {
    return match(true) {
        str_contains($type,'STRONG BUY')  => '#66BB6A',
        str_contains($type,'BUY')         => '#A5D6A7',
        str_contains($type,'HOLD')        => '#FFF176',
        str_contains($type,'STRONG SELL') => '#EF5350',
        str_contains($type,'SELL')        => '#EF9A9A',
        default                           => '#78909C',
    };
}
function signal_emoji(string $type): string {
    return match(true) {
        str_contains($type,'STRONG BUY')  => '🚀',
        str_contains($type,'BUY')         => '📈',
        str_contains($type,'HOLD')        => '⏸️',
        str_contains($type,'STRONG SELL') => '🔴',
        str_contains($type,'SELL')        => '📉',
        default                           => '❓',
    };
}
function time_ago(string $dt): string {
    $diff = time() - strtotime($dt);
    if ($diff < 60)     return 'Just now';
    if ($diff < 3600)   return floor($diff/60) . 'm ago';
    if ($diff < 86400)  return floor($diff/3600) . 'h ago';
    return floor($diff/86400) . 'd ago';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Dashboard — OpTrade</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#0D1B2A;color:#ECEFF1;font-family:'Segoe UI',system-ui,sans-serif;min-height:100vh}
/* nav */
nav{background:#111E2D;border-bottom:2px solid #1E3A5F;padding:0 24px;display:flex;
  align-items:center;height:58px;gap:16px}
.nav-brand{font-size:1.4rem;font-weight:800;
  background:linear-gradient(90deg,#29B6F6,#AB47BC);
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;
  text-decoration:none;margin-right:auto}
.nav-link{color:#78909C;text-decoration:none;font-size:.88rem;transition:color .2s}
.nav-link:hover{color:#29B6F6}
.nav-pill{background:#1A2B3C;border:1px solid #1E3A5F;color:#ECEFF1;
  padding:6px 14px;border-radius:20px;font-size:.83rem}
/* layout */
.container{max-width:1100px;margin:0 auto;padding:28px 20px}
/* page header */
.page-hdr{display:flex;align-items:center;justify-content:space-between;
  margin-bottom:28px;flex-wrap:wrap;gap:12px}
.page-hdr h1{font-size:1.6rem;font-weight:700}
.page-hdr p{color:#78909C;font-size:.9rem;margin-top:4px}
/* add-stock bar */
.add-bar{background:#1A2B3C;border:1px solid #1E3A5F;border-radius:12px;
  padding:20px 24px;margin-bottom:28px;display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.add-bar label{color:#90A4AE;font-size:.85rem;white-space:nowrap}
.add-bar select{background:#0D1B2A;border:1.5px solid #1E3A5F;color:#ECEFF1;
  padding:9px 14px;border-radius:8px;font-size:.93rem;font-family:inherit;min-width:160px}
.add-bar select:focus{outline:none;border-color:#29B6F6}
.btn{padding:9px 20px;border-radius:8px;border:none;cursor:pointer;
  font-family:inherit;font-size:.9rem;font-weight:600;transition:all .2s}
.btn-primary{background:#29B6F6;color:#0D1B2A}
.btn-primary:hover{background:#4FC3F7}
.btn-outline{background:transparent;border:1.5px solid #29B6F6;color:#29B6F6}
.btn-outline:hover{background:#29B6F614}
.btn-danger{background:transparent;border:1.5px solid #EF5350;color:#EF5350}
.btn-danger:hover{background:#EF535014}
.btn-sm{padding:6px 14px;font-size:.82rem}
.btn:disabled{opacity:.5;cursor:not-allowed}
/* alert banner */
.toast{position:fixed;top:70px;right:20px;z-index:999;background:#1A2B3C;
  border-radius:10px;padding:14px 20px;font-size:.9rem;min-width:260px;
  box-shadow:0 8px 32px #00000088;display:none;border-left:4px solid #29B6F6}
.toast.error{border-left-color:#EF5350}
.toast.success{border-left-color:#66BB6A}
/* stock grid */
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:20px}
/* stock card */
.stock-card{background:#1A2B3C;border:1px solid #1E3A5F;border-radius:12px;
  overflow:hidden;transition:border-color .2s}
.stock-card:hover{border-color:#29B6F644}
.card-top{padding:18px 20px 14px;border-bottom:1px solid #1E3A5F}
.card-ticker{font-size:1.4rem;font-weight:800;color:#ECEFF1}
.card-added{font-size:.75rem;color:#546E7A;margin-top:2px}
/* signal section */
.sig-section{padding:16px 20px}
.sig-badge{display:inline-flex;align-items:center;gap:7px;padding:7px 14px;
  border-radius:20px;font-size:.9rem;font-weight:700;margin-bottom:12px}
.sig-time{font-size:.75rem;color:#546E7A;margin-bottom:10px}
/* strength bar */
.str-row{display:flex;align-items:center;gap:10px;margin-bottom:12px}
.str-label{font-size:.75rem;color:#78909C;width:90px;flex-shrink:0}
.str-bar{flex:1;height:6px;background:#1E3A5F;border-radius:3px;overflow:hidden}
.str-fill{height:100%;border-radius:3px;transition:width .6s}
.str-pct{font-size:.8rem;font-weight:700;width:36px;text-align:right}
/* mini metrics */
.mini-metrics{display:flex;gap:12px;flex-wrap:wrap}
.mini-m{text-align:center}
.mini-m .mm-l{color:#546E7A;font-size:.7rem;text-transform:uppercase}
.mini-m .mm-v{font-size:.95rem;font-weight:700;color:#ECEFF1}
/* no-signal placeholder */
.no-signal{color:#546E7A;font-size:.88rem;text-align:center;padding:16px 0}
/* card actions */
.card-actions{padding:12px 20px;border-top:1px solid #1E3A5F;
  display:flex;gap:10px;justify-content:space-between;align-items:center}
/* empty state */
.empty-state{text-align:center;padding:60px 20px;color:#546E7A}
.empty-state .big{font-size:3rem;margin-bottom:12px}
.empty-state h3{font-size:1.2rem;color:#78909C;margin-bottom:8px}
/* run-cron banner */
.cron-bar{background:#1B3D2A;border:1px solid #66BB6A44;border-radius:10px;
  padding:14px 20px;margin-bottom:24px;display:flex;align-items:center;
  justify-content:space-between;gap:12px;flex-wrap:wrap}
.cron-bar p{font-size:.88rem;color:#A5D6A7}
/* alert threshold badge */
.threshold-badge{background:#1E3A5F;color:#29B6F6;padding:2px 9px;
  border-radius:10px;font-size:.75rem;font-weight:700}
/* spinner */
.spin{display:inline-block;width:14px;height:14px;border:2px solid #29B6F644;
  border-top-color:#29B6F6;border-radius:50%;animation:spin .7s linear infinite;
  vertical-align:middle;margin-right:5px}
@keyframes spin{to{transform:rotate(360deg)}}
</style>
</head>
<body>

<nav>
  <a class="nav-brand" href="index-S&P-case.php">📈 OpTrade</a>
  <a class="nav-link"  href="index-S&P-case.php">Analysis</a>
  <a class="nav-link"  href="dashboard-S&P-case.php" style="color:#29B6F6;">Dashboard</a>
  <span class="nav-pill">👤 <?= htmlspecialchars($user['email']) ?></span>
  <form method="post" action="api_auth-S&P-case.php" style="margin:0">
    <input type="hidden" name="action" value="logout">
    <button type="submit" class="btn btn-sm btn-outline">Sign Out</button>
  </form>
</nav>

<div class="toast" id="toast"></div>

<div class="container">

  <!-- Header -->
  <div class="page-hdr">
    <div>
      <h1>📊 My Dashboard</h1>
      <p>Track S&P 500 stocks and receive BUY/SELL email alerts automatically</p>
    </div>
    <div style="display:flex;gap:10px;align-items:center">
      <span style="color:#546E7A;font-size:.83rem">
        Alerts at <span class="threshold-badge">&gt;<?= ALERT_THRESHOLD ?>% strength</span>
      </span>
      <button class="btn btn-outline" onclick="runAllSignals()" id="btn-refresh-all">
        🔄 Refresh All Signals
      </button>
    </div>
  </div>

  <!-- Cron info banner -->
  <div class="cron-bar">
    <p>💡 <strong>Auto-alerts:</strong> run <code>php cron_signals-S&P-case.php</code> from the backend
       folder (or set a cron job) to auto-refresh signals and send emails in the background.</p>
    <button class="btn btn-sm btn-outline" style="border-color:#66BB6A;color:#66BB6A"
            onclick="triggerCron()">▶ Run Scan Now</button>
  </div>

  <!-- Add stock bar -->
  <div class="add-bar">
    <label>Add a stock to track:</label>
    <select id="add-select">
      <option value="">— choose a stock —</option>
      <?php foreach ($SP500 as $s): ?>
        <option value="<?= $s ?>" <?= array_search($s, array_column($tracked,'ticker')) !== false ? 'disabled style="color:#546E7A"' : '' ?>>
          <?= $s ?><?= array_search($s, array_column($tracked,'ticker')) !== false ? ' (tracked)' : '' ?>
        </option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-primary" onclick="addStock()">+ Add Stock</button>
    <span style="color:#546E7A;font-size:.82rem;margin-left:auto">
      <?= count($tracked) ?> / <?= MAX_TRACKED ?> stocks tracked
    </span>
  </div>

  <!-- Stock grid -->
  <?php if (empty($tracked)): ?>
  <div class="empty-state">
    <div class="big">📭</div>
    <h3>No stocks tracked yet</h3>
    <p>Add an S&P 500 stock above to start receiving AI signal alerts.</p>
  </div>
  <?php else: ?>
  <div class="grid" id="stock-grid">
    <?php foreach ($tracked as $t):
      $ticker = $t['ticker'];
      $sig    = $signals[$ticker] ?? null;
    ?>
    <div class="stock-card" id="card-<?= $ticker ?>">
      <div class="card-top">
        <div style="display:flex;justify-content:space-between;align-items:flex-start">
          <div>
            <div class="card-ticker"><?= $ticker ?></div>
            <div class="card-added">Tracked since <?= date('d M Y', strtotime($t['created_at'])) ?></div>
          </div>
          <a href="index-S&P-case.php?stock=<?= $ticker ?>" target="_blank"
             style="color:#29B6F6;font-size:.82rem;text-decoration:none" title="Open analysis">
            🔍 Analyse
          </a>
        </div>
      </div>

      <div class="sig-section" id="sig-<?= $ticker ?>">
        <?php if ($sig): ?>
          <?php
            $sc = signal_color($sig['signal_type']);
            $se = signal_emoji($sig['signal_type']);
          ?>
          <div class="sig-badge" style="background:<?= $sc ?>22;border:1.5px solid <?= $sc ?>;color:<?= $sc ?>">
            <?= $se ?> <?= htmlspecialchars($sig['signal_type']) ?>
          </div>
          <div class="sig-time">Last updated <?= time_ago($sig['checked_at']) ?></div>
          <!-- strength bar -->
          <div class="str-row">
            <span class="str-label">Strength</span>
            <div class="str-bar">
              <div class="str-fill" style="width:<?= $sig['signal_strength'] ?>%;background:<?= $sc ?>"></div>
            </div>
            <span class="str-pct" style="color:<?= $sc ?>"><?= $sig['signal_strength'] ?>%</span>
          </div>
          <!-- mini metrics -->
          <div class="mini-metrics">
            <?php if ($sig['last_price']): ?>
            <div class="mini-m">
              <div class="mm-l">Price</div>
              <div class="mm-v">$<?= number_format($sig['last_price'],2) ?></div>
            </div>
            <?php endif; ?>
            <?php if ($sig['price_change'] !== null): ?>
            <div class="mini-m">
              <div class="mm-l">Forecast</div>
              <div class="mm-v" style="color:<?= $sig['price_change']>=0?'#66BB6A':'#EF5350' ?>">
                <?= $sig['price_change']>=0?'+':'' ?><?= number_format($sig['price_change'],2) ?>%
              </div>
            </div>
            <?php endif; ?>
            <?php if ($sig['rsi']): ?>
            <div class="mini-m">
              <div class="mm-l">RSI</div>
              <div class="mm-v"><?= number_format($sig['rsi'],1) ?></div>
            </div>
            <?php endif; ?>
            <?php if ($sig['trend']): ?>
            <div class="mini-m">
              <div class="mm-l">Trend</div>
              <div class="mm-v" style="font-size:.78rem"><?= htmlspecialchars($sig['trend']) ?></div>
            </div>
            <?php endif; ?>
          </div>
          <?php if ($sig['signal_strength'] >= ALERT_THRESHOLD): ?>
          <div style="margin-top:10px;background:#1B3D2A;border-radius:6px;
                      padding:7px 12px;color:#A5D6A7;font-size:.8rem">
            🔔 Alert triggered — email sent to all trackers of this stock
          </div>
          <?php endif; ?>
        <?php else: ?>
          <div class="no-signal">
            No signal yet — click <strong>Refresh</strong> to run the AI analysis
          </div>
        <?php endif; ?>
      </div>

      <div class="card-actions">
        <button class="btn btn-sm btn-primary" onclick="refreshSignal('<?= $ticker ?>')"
                id="refresh-<?= $ticker ?>">
          🔄 Refresh Signal
        </button>
        <button class="btn btn-sm btn-danger" onclick="removeStock('<?= $ticker ?>')">
          🗑 Remove
        </button>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div><!-- /container -->

<script>
const CSRF = '<?= htmlspecialchars($csrf) ?>';

function showToast(msg, type='success') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast ' + type;
    t.style.display = 'block';
    clearTimeout(t._timer);
    t._timer = setTimeout(() => t.style.display='none', 4000);
}

async function apiTracked(params) {
    const fd = new FormData();
    fd.append('csrf', CSRF);
    for (const [k,v] of Object.entries(params)) fd.append(k,v);
    const r = await fetch('api_tracked-S&P-case.php', {method:'POST', body:fd});
    return r.json();
}

async function addStock() {
    const sel = document.getElementById('add-select');
    const ticker = sel.value;
    if (!ticker) { showToast('Please select a stock', 'error'); return; }
    const data = await apiTracked({action:'add', ticker});
    if (data.ok) { showToast(`${ticker} added! Reloading…`); setTimeout(()=>location.reload(),800); }
    else showToast(data.error, 'error');
}

async function removeStock(ticker) {
    if (!confirm(`Remove ${ticker} from your watchlist?`)) return;
    const data = await apiTracked({action:'remove', ticker});
    if (data.ok) {
        document.getElementById('card-' + ticker)?.remove();
        showToast(`${ticker} removed`);
    } else showToast(data.error,'error');
}

async function refreshSignal(ticker) {
    const btn = document.getElementById('refresh-' + ticker);
    const sec = document.getElementById('sig-' + ticker);
    btn.disabled = true;
    btn.innerHTML = '<span class="spin"></span>Analysing…';
    sec.innerHTML = '<div class="no-signal"><span class="spin"></span> Running AI model (2–4 min)…</div>';

    try {
        const data = await apiTracked({action:'refresh', ticker});
        if (data.ok && data.signal) {
            const s = data.signal;
            const sc = s.color;
            const stale = s.strength >= <?= ALERT_THRESHOLD ?>;
            sec.innerHTML = `
              <div class="sig-badge" style="background:${sc}22;border:1.5px solid ${sc};color:${sc}">
                ${s.emoji} ${s.action}
              </div>
              <div class="sig-time">Just updated</div>
              <div class="str-row">
                <span class="str-label">Strength</span>
                <div class="str-bar"><div class="str-fill" style="width:${s.strength}%;background:${sc}"></div></div>
                <span class="str-pct" style="color:${sc}">${s.strength}%</span>
              </div>
              <div class="mini-metrics">
                <div class="mini-m"><div class="mm-l">Price</div><div class="mm-v">$${s.last_price}</div></div>
                <div class="mini-m"><div class="mm-l">Forecast</div>
                  <div class="mm-v" style="color:${s.pch>=0?'#66BB6A':'#EF5350'}">
                    ${s.pch>=0?'+':''}${s.pch}%</div></div>
                <div class="mini-m"><div class="mm-l">RSI</div><div class="mm-v">${s.rsi}</div></div>
                <div class="mini-m"><div class="mm-l">Trend</div><div class="mm-v" style="font-size:.78rem">${s.trend}</div></div>
              </div>
              ${stale ? `<div style="margin-top:10px;background:#1B3D2A;border-radius:6px;
                padding:7px 12px;color:#A5D6A7;font-size:.8rem">
                🔔 Alert threshold reached — email sent to all trackers</div>` : ''}
            `;
            showToast(`${ticker}: ${s.action} (${s.strength}%)`);
        } else {
            sec.innerHTML = `<div class="no-signal" style="color:#EF9A9A">❌ ${data.error||'Analysis failed'}</div>`;
            showToast(data.error || 'Analysis failed', 'error');
        }
    } catch(e) {
        sec.innerHTML = `<div class="no-signal" style="color:#EF9A9A">❌ Request failed</div>`;
        showToast('Request failed', 'error');
    }
    btn.disabled = false;
    btn.innerHTML = '🔄 Refresh Signal';
}

async function runAllSignals() {
    const btn = document.getElementById('btn-refresh-all');
    btn.disabled = true;
    btn.innerHTML = '<span class="spin"></span>Running…';
    showToast('Running all signals… this may take a few minutes');
    const cards = document.querySelectorAll('.stock-card');
    for (const c of cards) {
        const ticker = c.id.replace('card-','');
        await refreshSignal(ticker);
    }
    btn.disabled = false;
    btn.innerHTML = '🔄 Refresh All Signals';
    showToast('All signals updated!');
}

async function triggerCron() {
    showToast('Running background scan…');
    const fd = new FormData();
    fd.append('csrf', CSRF);
    fd.append('action', 'cron');
    const r = await fetch('api_tracked-S&P-case.php', {method:'POST', body:fd});
    const d = await r.json();
    if (d.ok) showToast(`Scan complete: ${d.message}`);
    else showToast(d.error || 'Scan failed', 'error');
}
</script>
</body>
</html>
