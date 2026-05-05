<?php
require_once __DIR__ . '/db-S&P-case.php';
session_start();
$_logged_in   = !empty($_SESSION['user_id']);
$_user_email  = $_SESSION['email'] ?? '';
$_csrf        = $_SESSION['csrf'] ?? bin2hex(random_bytes(16));
$_SESSION['csrf'] = $_csrf;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>OpTrade — S&P 500 Financial Forecasting</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{
  --bg:#060d1a;--bg2:#0b1629;--card:rgba(255,255,255,0.04);--card-border:rgba(255,255,255,0.08);
  --blue:#29b6f6;--blue2:#0288d1;--purple:#7c3aed;--green:#22c55e;--red:#ef4444;
  --orange:#f59e0b;--white:#f1f5f9;--muted:#64748b;--muted2:#94a3b8;--glow:rgba(41,182,246,0.15);
}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--white);min-height:100vh;overflow-x:hidden}
::-webkit-scrollbar{width:6px}::-webkit-scrollbar-track{background:var(--bg2)}::-webkit-scrollbar-thumb{background:#1e3a5f;border-radius:3px}

/* NAV */
nav{position:sticky;top:0;z-index:100;display:flex;align-items:center;justify-content:space-between;padding:1rem 2rem;background:rgba(6,13,26,0.9);backdrop-filter:blur(20px);border-bottom:1px solid var(--card-border)}
.nav-logo{display:flex;align-items:center;gap:.6rem;font-size:1.3rem;font-weight:800}
.nav-logo .icon{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,var(--blue),var(--purple));display:flex;align-items:center;justify-content:center;font-size:.9rem}
.nav-links{display:flex;gap:1.5rem}.nav-links a{color:var(--muted2);text-decoration:none;font-size:.85rem;font-weight:500;transition:.2s}.nav-links a:hover{color:var(--white)}
.nav-badge{background:linear-gradient(135deg,var(--blue),var(--purple));padding:.3rem .8rem;border-radius:20px;font-size:.75rem;font-weight:600}

/* TICKER */
.ticker-bar{background:var(--bg2);border-bottom:1px solid var(--card-border);overflow:hidden;height:36px;display:flex;align-items:center}
.ticker-inner{display:flex;gap:3rem;white-space:nowrap;animation:ticker 30s linear infinite}
@keyframes ticker{0%{transform:translateX(0)}100%{transform:translateX(-50%)}}
.ticker-item{font-size:.78rem;font-weight:500;display:flex;align-items:center;gap:.4rem}
.up{color:var(--green)}.down{color:var(--red)}.tick-label{color:var(--muted2)}

/* HERO */
.hero{text-align:center;padding:4rem 1.5rem 2rem;background:radial-gradient(ellipse 80% 50% at 50% -10%,rgba(41,182,246,.12),transparent)}
.hero h1{font-size:clamp(1.8rem,4vw,3rem);font-weight:800;letter-spacing:-.03em;line-height:1.15}
.hero h1 span{background:linear-gradient(135deg,var(--blue),var(--purple));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.hero p{color:var(--muted2);margin:.8rem auto 0;max-width:520px;font-size:.95rem;line-height:1.6}

/* SELECTOR */
.selector-wrap{max-width:600px;margin:2.5rem auto 1rem;padding:0 1rem}
.selector-card{background:var(--card);border:1px solid var(--card-border);border-radius:16px;padding:1.5rem 1.8rem;backdrop-filter:blur(12px)}
.selector-card label{display:block;font-size:.8rem;font-weight:600;color:var(--muted2);letter-spacing:.06em;text-transform:uppercase;margin-bottom:.6rem}
.select-row{display:flex;gap:.8rem}
.select-row select{flex:1;background:rgba(255,255,255,0.06);border:1px solid var(--card-border);color:var(--white);padding:.7rem 1rem;border-radius:10px;font-size:.9rem;font-family:'Inter',sans-serif;cursor:pointer;outline:none;transition:.2s}
.select-row select:focus{border-color:var(--blue);box-shadow:0 0 0 3px var(--glow)}
.select-row select option{background:#0d1b2a;color:var(--white)}
.btn-analyze{display:flex;align-items:center;gap:.5rem;background:linear-gradient(135deg,var(--blue),var(--blue2));border:none;color:#fff;padding:.7rem 1.5rem;border-radius:10px;font-size:.9rem;font-weight:600;font-family:'Inter',sans-serif;cursor:pointer;transition:.2s;white-space:nowrap}
.btn-analyze:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(41,182,246,.35)}.btn-analyze:disabled{opacity:.5;cursor:not-allowed;transform:none}

/* LOADING */
.loading-overlay{display:none;position:fixed;inset:0;background:rgba(6,13,26,.92);backdrop-filter:blur(8px);z-index:999;flex-direction:column;align-items:center;justify-content:center;gap:1.5rem}
.loading-overlay.active{display:flex}
.spinner{width:60px;height:60px;border-radius:50%;border:3px solid rgba(41,182,246,.2);border-top-color:var(--blue);animation:spin .9s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.loading-steps{display:flex;flex-direction:column;gap:.5rem;text-align:center}
.loading-title{font-size:1.1rem;color:var(--white);font-weight:600;margin-bottom:.3rem}
.loading-steps span{font-size:.82rem;color:var(--muted);transition:.3s;padding:.2rem 0}
.loading-steps span.active-step{color:var(--blue);font-weight:600}

/* RESULTS */
#results{display:none;max-width:1100px;margin:0 auto;padding:2rem 1.5rem 4rem}
#results.visible{display:block;animation:fadeUp .5s ease}
@keyframes fadeUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
.section-title{font-size:.75rem;font-weight:700;color:var(--blue);letter-spacing:.1em;text-transform:uppercase;margin-bottom:1rem;display:flex;align-items:center;gap:.4rem}

/* ── SIGNAL BANNER ── */
.signal-banner{border-radius:20px;padding:2rem;margin-bottom:1.5rem;border:1px solid;position:relative;overflow:hidden}
.signal-banner::before{content:'';position:absolute;top:-80px;right:-80px;width:260px;height:260px;border-radius:50%;opacity:.08}
.signal-banner.sig-strong-buy  {background:rgba(34,197,94,.07);border-color:rgba(34,197,94,.35)}
.signal-banner.sig-strong-buy::before{background:var(--green)}
.signal-banner.sig-buy         {background:rgba(34,197,94,.05);border-color:rgba(34,197,94,.25)}
.signal-banner.sig-buy::before {background:var(--green)}
.signal-banner.sig-hold        {background:rgba(245,158,11,.05);border-color:rgba(245,158,11,.25)}
.signal-banner.sig-hold::before{background:var(--orange)}
.signal-banner.sig-sell        {background:rgba(239,68,68,.05);border-color:rgba(239,68,68,.25)}
.signal-banner.sig-sell::before{background:var(--red)}
.signal-banner.sig-strong-sell {background:rgba(239,68,68,.08);border-color:rgba(239,68,68,.35)}
.signal-banner.sig-strong-sell::before{background:var(--red)}

.signal-top{display:flex;align-items:center;gap:1.2rem;flex-wrap:wrap;margin-bottom:1.2rem}
.signal-icon-wrap{width:60px;height:60px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.5rem;flex-shrink:0}
.sig-strong-buy .signal-icon-wrap,.sig-buy .signal-icon-wrap{background:rgba(34,197,94,.2);color:var(--green)}
.sig-hold .signal-icon-wrap{background:rgba(245,158,11,.2);color:var(--orange)}
.sig-sell .signal-icon-wrap,.sig-strong-sell .signal-icon-wrap{background:rgba(239,68,68,.2);color:var(--red)}
.signal-label-group .signal-action{font-size:2rem;font-weight:800;letter-spacing:-.02em}
.sig-strong-buy .signal-action,.sig-buy .signal-action{color:var(--green)}
.sig-hold .signal-action{color:var(--orange)}
.sig-sell .signal-action,.sig-strong-sell .signal-action{color:var(--red)}
.signal-label-group .signal-stock{font-size:.9rem;color:var(--muted2);margin-top:.1rem}
.signal-strength-wrap{flex:1;min-width:200px}
.signal-strength-wrap .str-label{font-size:.72rem;color:var(--muted2);margin-bottom:.4rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em}
.strength-bar-bg{height:8px;background:rgba(255,255,255,0.08);border-radius:8px;overflow:hidden}
.strength-bar-fill{height:100%;border-radius:8px;transition:width .8s ease}
.sig-strong-buy .strength-bar-fill,.sig-buy .strength-bar-fill{background:linear-gradient(90deg,#16a34a,var(--green))}
.sig-hold .strength-bar-fill{background:linear-gradient(90deg,#b45309,var(--orange))}
.sig-sell .strength-bar-fill,.sig-strong-sell .strength-bar-fill{background:linear-gradient(90deg,#b91c1c,var(--red))}

.signal-summary{font-size:.9rem;color:var(--muted2);line-height:1.7;margin-bottom:1.5rem;background:rgba(255,255,255,.03);border-radius:10px;padding:.9rem 1rem}

/* Key levels */
.levels-row{display:grid;grid-template-columns:repeat(3,1fr);gap:1rem}
.level-box{background:rgba(255,255,255,.04);border:1px solid var(--card-border);border-radius:12px;padding:1rem 1.2rem}
.level-box .lv-label{font-size:.7rem;color:var(--muted2);font-weight:600;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.4rem}
.level-box .lv-value{font-size:1.2rem;font-weight:700}
.level-box .lv-hint{font-size:.7rem;color:var(--muted);margin-top:.3rem}
.lv-entry{color:var(--blue)}.lv-target{color:var(--green)}.lv-stop{color:var(--red)}

/* CONTEXT CARDS */
.context-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;margin-bottom:1.5rem}
.ctx-card{background:var(--card);border:1px solid var(--card-border);border-radius:14px;padding:1.2rem 1.4rem}
.ctx-card .ctx-icon{font-size:1.4rem;margin-bottom:.6rem}
.ctx-card .ctx-label{font-size:.7rem;color:var(--muted2);font-weight:600;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.3rem}
.ctx-card .ctx-value{font-size:1.1rem;font-weight:700;margin-bottom:.4rem}
.ctx-card .ctx-desc{font-size:.75rem;color:var(--muted2);line-height:1.55}
.ctx-bullish{color:var(--green)}.ctx-bearish{color:var(--red)}.ctx-neutral{color:var(--orange)}

/* PREDICTION */
.pred-card{background:linear-gradient(135deg,rgba(41,182,246,.1),rgba(124,58,237,.1));border:1px solid rgba(41,182,246,.25);border-radius:20px;padding:2rem 2.5rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1.5rem;margin-bottom:1.5rem;position:relative;overflow:hidden}
.pred-card::before{content:'';position:absolute;top:-60px;right:-60px;width:200px;height:200px;border-radius:50%;background:radial-gradient(circle,rgba(41,182,246,.12),transparent)}
.pred-main .label{font-size:.8rem;color:var(--muted2);font-weight:500;margin-bottom:.3rem}
.pred-main .price{font-size:2.8rem;font-weight:800;background:linear-gradient(135deg,var(--blue),#a78bfa);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.pred-main .subtext{font-size:.82rem;color:var(--muted2);margin-top:.3rem}
.pred-change{text-align:right}
.pred-change .badge{display:inline-flex;align-items:center;gap:.4rem;padding:.5rem 1rem;border-radius:30px;font-size:1rem;font-weight:700}
.badge.up{background:rgba(34,197,94,.15);color:var(--green);border:1px solid rgba(34,197,94,.3)}
.badge.down{background:rgba(239,68,68,.15);color:var(--red);border:1px solid rgba(239,68,68,.3)}
.pred-meta{font-size:.78rem;color:var(--muted);margin-top:.4rem}

/* METRICS */
.metrics-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin-bottom:1.5rem}
.metric-card{background:var(--card);border:1px solid var(--card-border);border-radius:14px;padding:1.2rem 1.4rem;position:relative;overflow:hidden;transition:.2s}
.metric-card:hover{border-color:rgba(41,182,246,.3);transform:translateY(-2px)}
.metric-card .m-icon{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:.85rem;margin-bottom:.8rem}
.metric-card .m-label{font-size:.75rem;color:var(--muted2);font-weight:500;margin-bottom:.3rem}
.metric-card .m-value{font-size:1.5rem;font-weight:700;color:var(--white)}
.metric-card .m-desc{font-size:.7rem;color:var(--muted);margin-top:.2rem;line-height:1.4}
.metric-card .m-bar{position:absolute;bottom:0;left:0;height:3px;border-radius:0 0 14px 14px}
.oos-note{font-size:.72rem;color:var(--muted);background:rgba(34,197,94,.06);border:1px solid rgba(34,197,94,.15);border-radius:8px;padding:.5rem .9rem;margin-bottom:1rem;display:flex;align-items:center;gap:.5rem}

/* TRADING */
.trading-card{background:var(--card);border:1px solid var(--card-border);border-radius:16px;padding:1.5rem;margin-bottom:1.5rem;display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1.2rem}
.t-stat .t-label{font-size:.72rem;color:var(--muted2);font-weight:500;margin-bottom:.3rem}
.t-stat .t-value{font-size:1.25rem;font-weight:700}
.profit-pos{color:var(--green)}.profit-neg{color:var(--red)}

/* CHARTS */
.charts-grid{display:grid;grid-template-columns:1fr;gap:1.2rem}
.chart-card{background:var(--card);border:1px solid var(--card-border);border-radius:16px;padding:1.2rem;overflow:hidden}
.chart-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:.6rem}
.chart-title{font-size:.9rem;font-weight:600;color:var(--white)}
.chart-badge{font-size:.7rem;font-weight:600;padding:.25rem .7rem;border-radius:20px;background:rgba(41,182,246,.12);color:var(--blue);border:1px solid rgba(41,182,246,.2)}
.chart-desc{font-size:.75rem;color:var(--muted2);margin-bottom:.8rem;line-height:1.5}
.chart-card img{width:100%;border-radius:10px;display:block}

/* ERROR */
.error-card{background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);border-radius:14px;padding:1.2rem 1.5rem;color:#fca5a5;font-size:.88rem;display:flex;align-items:flex-start;gap:.8rem}
.disclaimer{max-width:700px;margin:1.5rem auto 0;padding:0 1.5rem;font-size:.72rem;color:var(--muted);line-height:1.6;text-align:center}
@media(max-width:640px){nav{padding:.8rem 1rem}.nav-links{display:none}.hero{padding:2.5rem 1rem 1.5rem}.pred-card{flex-direction:column}.pred-change{text-align:left}.levels-row{grid-template-columns:1fr}}
</style>
</head>
<body>

<nav>
  <div class="nav-logo">
    <div class="icon"><i class="fa-solid fa-chart-line"></i></div>
    OpTrade
  </div>
  <div class="nav-links">
    <a href="index-S&P-case.php" style="color:var(--white)">Analysis</a>
    <?php if ($_logged_in): ?>
      <a href="dashboard-S&P-case.php">Dashboard</a>
    <?php endif; ?>
  </div>
  <?php if ($_logged_in): ?>
    <div style="display:flex;align-items:center;gap:10px">
      <span style="color:var(--muted2);font-size:.8rem">👤 <?= htmlspecialchars($_user_email) ?></span>
      <form method="post" action="api_auth-S&P-case.php" style="margin:0">
        <input type="hidden" name="action" value="logout">
        <button type="submit" style="background:transparent;border:1px solid #1e3a5f;color:var(--muted2);
          padding:.3rem .9rem;border-radius:8px;cursor:pointer;font-size:.78rem;font-family:inherit">
          Sign Out
        </button>
      </form>
    </div>
  <?php else: ?>
    <div style="display:flex;align-items:center;gap:10px">
      <a href="auth-S&P-case.php" style="color:var(--muted2);text-decoration:none;font-size:.82rem">Sign In</a>
      <a href="auth-S&P-case.php?tab=signup" class="nav-badge" style="text-decoration:none">Get Started</a>
    </div>
  <?php endif; ?>
</nav>

<div class="ticker-bar">
  <div class="ticker-inner" id="ticker">
    <?php
    $tickers=[['AAPL','+1.2%','up'],['MSFT','+0.8%','up'],['AMZN','-0.5%','down'],
              ['NVDA','+2.1%','up'],['GOOGL','+0.3%','up'],['META','-0.2%','down'],
              ['TSLA','+1.5%','up'],['JPM','+0.6%','up'],['LLY','-0.9%','down'],
              ['V','+1.8%','up'],['UNH','+0.4%','up'],['XOM','-0.1%','down']];
    for($r=0;$r<2;$r++){foreach($tickers as[$s,$p,$d]){echo"<div class='ticker-item'><span class='tick-label'>$s</span><span class='$d'>$p</span></div>";}}
    ?>
  </div>
</div>

<section class="hero">
  <h1>Smart Forecasting for<br><span>S&P 500 Stocks</span></h1>
  <p>Powered by LSTM deep learning, Hidden Markov Models, and Monte Carlo simulation
     to deliver price predictions.</p>
</section>

<div class="selector-wrap">
  <div class="selector-card">
    <label>Select an S&P 500 Stock</label>
    <div class="select-row">
      <select id="stockSelect">
        <option value="">-- Choose a stock --</option>
        <?php
        $stocks=[
          'AAPL'=>'AAPL - Apple Inc.','MSFT'=>'MSFT - Microsoft','AMZN'=>'AMZN - Amazon',
          'NVDA'=>'NVDA - NVIDIA','GOOGL'=>'GOOGL - Alphabet (Google)','META'=>'META - Meta Platforms',
          'TSLA'=>'TSLA - Tesla','JPM'=>'JPM - JPMorgan Chase','LLY'=>'LLY - Eli Lilly',
          'V'=>'V - Visa','UNH'=>'UNH - UnitedHealth','XOM'=>'XOM - ExxonMobil',
          'MA'=>'MA - Mastercard','HD'=>'HD - Home Depot','PG'=>'PG - Procter & Gamble',
          'COST'=>'COST - Costco','JNJ'=>'JNJ - Johnson & Johnson','MRK'=>'MRK - Merck',
          'ABBV'=>'ABBV - AbbVie','BAC'=>'BAC - Bank of America','AVGO'=>'AVGO - Broadcom',
          'WMT'=>'WMT - Walmart','CVX'=>'CVX - Chevron','KO'=>'KO - Coca-Cola',
          'NFLX'=>'NFLX - Netflix','DIS'=>'DIS - Disney','CSCO'=>'CSCO - Cisco',
          'INTC'=>'INTC - Intel','AMD'=>'AMD - AMD','CRM'=>'CRM - Salesforce'];
        foreach($stocks as $sym=>$name){echo"<option value='$sym'>$name</option>";}
        ?>
      </select>
      <button class="btn-analyze" id="analyzeBtn" onclick="runAnalysis()">
        <i class="fa-solid fa-bolt"></i> Analyze
      </button>
    </div>
    <p style="font-size:.72rem;color:var(--muted);margin-top:.8rem">
      <i class="fa-solid fa-clock" style="margin-right:.3rem"></i>
      Analysis takes 2–5 minutes — the model trains fresh using real market data
    </p>
  </div>
</div>

<div class="loading-overlay" id="loadingOverlay">
  <div class="spinner"></div>
  <div class="loading-steps">
    <div class="loading-title">Running Analysis...</div>
    <span id="s1">Downloading market data</span>
    <span id="s2">Computing technical indicators (RSI, EMA)</span>
    <span id="s3">Running Monte Carlo simulation</span>
    <span id="s4">Fitting Hidden Markov Model (market regimes)</span>
    <span id="s5">Training LSTM neural network (80/20 split)</span>
    <span id="s6">Generating predictions, signals and charts</span>
  </div>
</div>

<div id="results">

  <div id="errorBox" class="error-card" style="display:none;margin-bottom:1.5rem">
    <i class="fa-solid fa-circle-exclamation" style="margin-top:.1rem;flex-shrink:0"></i>
    <span id="errorMsg"></span>
  </div>

  <!-- ── SIGNAL BANNER ── -->
  <p class="section-title"><i class="fa-solid fa-signal"></i> AI Trading Signal</p>
  <div class="signal-banner" id="signalBanner">
    <div class="signal-top">
      <div class="signal-icon-wrap" id="signalIconWrap">
        <i class="fa-solid fa-circle-question" id="signalIcon"></i>
      </div>
      <div class="signal-label-group">
        <div class="signal-action" id="signalAction">--</div>
        <div class="signal-stock" id="signalStock"></div>
      </div>
      <div class="signal-strength-wrap">
        <div class="str-label">Signal Strength</div>
        <div class="strength-bar-bg">
          <div class="strength-bar-fill" id="strengthBar" style="width:0%"></div>
        </div>
        <div style="font-size:.7rem;color:var(--muted);margin-top:.3rem" id="strengthLabel"></div>
      </div>
    </div>
    <div class="signal-summary" id="signalSummary"></div>
    <div class="levels-row">
      <div class="level-box">
        <div class="lv-label"><i class="fa-solid fa-crosshairs" style="margin-right:.3rem"></i>Entry Zone</div>
        <div class="lv-value lv-entry" id="levelEntry">--</div>
        <div class="lv-hint">Suggested price range to buy</div>
      </div>
      <div class="level-box">
        <div class="lv-label"><i class="fa-solid fa-bullseye" style="margin-right:.3rem"></i>Target Price</div>
        <div class="lv-value lv-target" id="levelTarget">--</div>
        <div class="lv-hint">Model's next-day price objective</div>
      </div>
      <div class="level-box">
        <div class="lv-label"><i class="fa-solid fa-shield-halved" style="margin-right:.3rem"></i>Stop Loss</div>
        <div class="lv-value lv-stop" id="levelStop">--</div>
        <div class="lv-hint">Exit here to limit losses (~5%)</div>
      </div>
    </div>
  </div>

  <!-- ── MARKET CONTEXT ── -->
  <p class="section-title" style="margin-top:1.5rem"><i class="fa-solid fa-magnifying-glass-chart"></i> What the Indicators Say</p>
  <div class="context-grid">
    <div class="ctx-card">
      <div class="ctx-icon">📊</div>
      <div class="ctx-label">RSI Momentum</div>
      <div class="ctx-value" id="ctxRsiValue">--</div>
      <div class="ctx-desc" id="ctxRsiDesc">--</div>
    </div>
    <div class="ctx-card">
      <div class="ctx-icon">📈</div>
      <div class="ctx-label">Price Trend (EMA)</div>
      <div class="ctx-value" id="ctxTrendValue">--</div>
      <div class="ctx-desc" id="ctxTrendDesc">--</div>
    </div>
    <div class="ctx-card">
      <div class="ctx-icon">🧠</div>
      <div class="ctx-label">Market Regime (HMM)</div>
      <div class="ctx-value" id="ctxHmmValue">--</div>
      <div class="ctx-desc" id="ctxHmmDesc">--</div>
    </div>
  </div>

  <!-- ── NEXT-DAY ESTIMATE ── -->
  <p class="section-title" style="margin-top:1.5rem"><i class="fa-solid fa-crosshairs"></i> Next-Day Price Estimate</p>
  <div class="pred-card">
    <div class="pred-main">
      <div class="label">Estimated Closing Price Tomorrow</div>
      <div class="price" id="predPrice">--</div>
      <div class="subtext">Generated by LSTM + HMM + Monte Carlo model trained on real market data.</div>
    </div>
    <div class="pred-change">
      <div class="badge up" id="changeBadge">--</div>
      <div class="pred-meta" id="predMeta"></div>
    </div>
  </div>

  <!-- ── MODEL ACCURACY ── -->
  <p class="section-title" style="margin-top:1.5rem"><i class="fa-solid fa-chart-bar"></i> How Accurate Is the Model?</p>
  <div class="oos-note">
    <i class="fa-solid fa-flask-vial" style="color:var(--green)"></i>
    These metrics are <strong style="color:var(--green)">out-of-sample</strong> — calculated on the 20% of data the model never saw during training. Lower numbers = better accuracy.
  </div>
  <div class="metrics-grid">
    <div class="metric-card">
      <div class="m-icon" style="background:rgba(41,182,246,.12);color:var(--blue)"><i class="fa-solid fa-ruler"></i></div>
      <div class="m-label">MAE — Average Error</div>
      <div class="m-value" id="mMAE">--</div>
      <div class="m-desc">On average, the model's price prediction is off by this many USD. Lower is better.</div>
      <div class="m-bar" style="background:linear-gradient(90deg,var(--blue),transparent);width:70%"></div>
    </div>
    <div class="metric-card">
      <div class="m-icon" style="background:rgba(124,58,237,.12);color:#a78bfa"><i class="fa-solid fa-calculator"></i></div>
      <div class="m-label">MSE — Squared Error</div>
      <div class="m-value" id="mMSE">--</div>
      <div class="m-desc">Penalises large errors more heavily than small ones. A lower number is better.</div>
      <div class="m-bar" style="background:linear-gradient(90deg,#a78bfa,transparent);width:55%"></div>
    </div>
    <div class="metric-card">
      <div class="m-icon" style="background:rgba(245,158,11,.12);color:var(--orange)"><i class="fa-solid fa-arrows-up-down"></i></div>
      <div class="m-label">RMSE — Error in USD</div>
      <div class="m-value" id="mRMSE">--</div>
      <div class="m-desc">The most intuitive error metric — the typical prediction error in USD. Lower is better.</div>
      <div class="m-bar" style="background:linear-gradient(90deg,var(--orange),transparent);width:60%"></div>
    </div>
    <div class="metric-card">
      <div class="m-icon" style="background:rgba(34,197,94,.12);color:var(--green)"><i class="fa-solid fa-percent"></i></div>
      <div class="m-label">MAPE — Error in %</div>
      <div class="m-value" id="mMAPE">--</div>
      <div class="m-desc">Average error as a percentage of the actual price. Under 5% is considered good.</div>
      <div class="m-bar" style="background:linear-gradient(90deg,var(--green),transparent);width:80%"></div>
    </div>
  </div>

  <!-- ── BACKTEST ── -->
  <p class="section-title" style="margin-top:1.5rem"><i class="fa-solid fa-sack-dollar"></i> Simulated Trading Results</p>
  <div class="oos-note">
    <i class="fa-solid fa-flask-vial" style="color:var(--green)"></i>
    This backtest runs only on <strong style="color:var(--green)">out-of-sample data</strong> (the 20% test period). Starting with 100,000 USD — buying when the model predicts a rise, selling when it predicts a fall.
  </div>
  <div class="trading-card">
    <div class="t-stat"><div class="t-label">Starting Capital</div><div class="t-value">100,000 USD</div></div>
    <div class="t-stat"><div class="t-label">Final Value</div><div class="t-value" id="tFinal">--</div></div>
    <div class="t-stat"><div class="t-label">Total Profit / Loss</div><div class="t-value" id="tProfit">--</div></div>
    <div class="t-stat"><div class="t-label">Return</div><div class="t-value" id="tReturn">--</div></div>
    <div class="t-stat"><div class="t-label">Trades Made</div><div class="t-value" id="tTrades">--</div></div>
    <div class="t-stat"><div class="t-label">Win Rate</div><div class="t-value" id="tSuccess">--</div></div>
  </div>

  <!-- ── CHARTS ── -->
  <p class="section-title" style="margin-top:1.5rem"><i class="fa-solid fa-chart-line"></i> Forecast Charts</p>
  <div class="charts-grid">
    <div class="chart-card">
      <div class="chart-header">
        <span class="chart-title">Last 100 Days — Actual vs Predicted</span>
        <span class="chart-badge">LSTM Forecast</span>
      </div>
      <p class="chart-desc">Blue = real closing price. Orange = what the model predicted. The shaded green area is the out-of-sample test zone — data the model never trained on.</p>
      <img id="chart100" src="" alt="Last 100 days">
    </div>
    <div class="chart-card">
      <div class="chart-header">
        <span class="chart-title">Full History — Actual vs Predicted</span>
        <span class="chart-badge">2020 – Today</span>
      </div>
      <p class="chart-desc">Full price history with model predictions overlaid. The dotted purple line marks where training ended and out-of-sample testing began.</p>
      <img id="chartFull" src="" alt="Full range">
    </div>
    <div class="chart-card">
      <div class="chart-header">
        <span class="chart-title">Trading Strategy Backtest</span>
        <span class="chart-badge">Out-of-Sample Only</span>
      </div>
      <p class="chart-desc">Green triangles (▲) = model said BUY. Red triangles (▼) = model said SELL. Shows where the simulated trades were placed on the actual price chart.</p>
      <img id="chartTrade" src="" alt="Trading strategy">
    </div>
  </div>

</div>

<p class="disclaimer">
  ⚠️ This tool is for educational purposes only and does not constitute financial advice.
  AI predictions can be wrong — always do your own research and never invest more than you can afford to lose.
  Past backtest performance does not guarantee future returns.
</p><br>

<script>
const steps=['s1','s2','s3','s4','s5','s6'];
const delays=[1000,3000,6000,10000,15000,55000];
let stepTimer=null,stepIdx=0;
function startSteps(){
  stepIdx=0;steps.forEach(id=>document.getElementById(id).classList.remove('active-step'));
  function tick(){if(stepIdx<steps.length){steps.forEach(id=>document.getElementById(id).classList.remove('active-step'));document.getElementById(steps[stepIdx]).classList.add('active-step');stepIdx++;stepTimer=setTimeout(tick,delays[stepIdx-1]||8000);}}
  tick();
}
function stopSteps(){if(stepTimer)clearTimeout(stepTimer);steps.forEach(id=>document.getElementById(id).classList.remove('active-step'));}
function fmt(n,d=2){return Number(n).toLocaleString('en',{minimumFractionDigits:d,maximumFractionDigits:d});}

function setSignalClass(banner,action){
  const map={'STRONG BUY':'sig-strong-buy','BUY':'sig-buy','HOLD':'sig-hold','SELL':'sig-sell','STRONG SELL':'sig-strong-sell'};
  banner.className='signal-banner '+(map[action]||'sig-hold');
}
function signalIcon(action){
  if(action==='STRONG BUY'||action==='BUY') return 'fa-arrow-trend-up';
  if(action==='HOLD') return 'fa-minus';
  return 'fa-arrow-trend-down';
}

function runAnalysis(){
  const sym=document.getElementById('stockSelect').value;
  if(!sym){alert('Please select a stock first.');return;}
  document.getElementById('results').classList.remove('visible');
  document.getElementById('results').style.display='none';
  document.getElementById('errorBox').style.display='none';
  document.getElementById('loadingOverlay').classList.add('active');
  document.getElementById('analyzeBtn').disabled=true;
  startSteps();

  fetch('api-S&P-case.php?symbol='+encodeURIComponent(sym))
    .then(r=>r.json())
    .then(data=>{
      stopSteps();
      document.getElementById('loadingOverlay').classList.remove('active');
      document.getElementById('analyzeBtn').disabled=false;

      if(data.error){
        document.getElementById('errorMsg').textContent=data.error+(data.raw?' | '+data.raw:'');
        document.getElementById('errorBox').style.display='flex';
        document.getElementById('results').style.display='block';
        document.getElementById('results').classList.add('visible');
        return;
      }

      // ── Signal banner
      const sig=data.signal;
      const banner=document.getElementById('signalBanner');
      setSignalClass(banner,sig.action);
      document.getElementById('signalIcon').className='fa-solid '+signalIcon(sig.action);
      document.getElementById('signalAction').textContent=sig.action;
      document.getElementById('signalStock').textContent=data.symbol+' · '+data.last_date;
      document.getElementById('strengthBar').style.width=sig.strength+'%';
      document.getElementById('strengthLabel').textContent='Score: '+sig.score+' / 6  ('+sig.strength+'%)';
      document.getElementById('signalSummary').textContent=sig.summary;
      document.getElementById('levelEntry').textContent='$'+fmt(sig.entry_low)+' – $'+fmt(sig.entry_high);
      document.getElementById('levelTarget').textContent='$'+fmt(sig.target);
      document.getElementById('levelStop').textContent='$'+fmt(sig.stop_loss);

      // ── Context cards
      const rsiClass=sig.rsi>70?'ctx-bearish':sig.rsi<30?'ctx-bullish':'ctx-neutral';
      document.getElementById('ctxRsiValue').textContent=sig.rsi_label;
      document.getElementById('ctxRsiValue').className='ctx-value '+rsiClass;
      document.getElementById('ctxRsiDesc').textContent=sig.rsi_desc;
      const trendClass=sig.trend==='Uptrend'?'ctx-bullish':'ctx-bearish';
      document.getElementById('ctxTrendValue').textContent=sig.trend;
      document.getElementById('ctxTrendValue').className='ctx-value '+trendClass;
      document.getElementById('ctxTrendDesc').textContent=sig.trend_desc;
      const hmmClass=sig.hmm_label==='Bull Regime'?'ctx-bullish':sig.hmm_label==='Bear Regime'?'ctx-bearish':'ctx-neutral';
      document.getElementById('ctxHmmValue').textContent=sig.hmm_label;
      document.getElementById('ctxHmmValue').className='ctx-value '+hmmClass;
      document.getElementById('ctxHmmDesc').textContent=sig.hmm_desc;

      // ── Price estimate
      document.getElementById('predPrice').textContent='$'+fmt(data.next_day_price);
      const chg=data.price_change;
      const badge=document.getElementById('changeBadge');
      badge.className='badge '+(chg>=0?'up':'down');
      badge.innerHTML='<i class="fa-solid fa-arrow-'+(chg>=0?'trend-up':'trend-down')+'"></i> '+(chg>=0?'+':'')+fmt(chg)+'%';
      document.getElementById('predMeta').textContent='Last known: $'+fmt(data.last_price)+' on '+data.last_date;

      // ── Metrics
      document.getElementById('mMAE').textContent='$'+fmt(data.metrics.mae,4);
      document.getElementById('mMSE').textContent=fmt(data.metrics.mse,4);
      document.getElementById('mRMSE').textContent='$'+fmt(data.metrics.rmse,4);
      document.getElementById('mMAPE').textContent=fmt(data.metrics.mape,2)+'%';

      // ── Trading
      const t=data.trading;const sign=t.total_profit>=0;
      document.getElementById('tFinal').textContent='$'+fmt(t.final_value);
      document.getElementById('tProfit').innerHTML='<span class="'+(sign?'profit-pos':'profit-neg')+'">'+(sign?'+':'')+fmt(t.total_profit)+' USD</span>';
      document.getElementById('tReturn').innerHTML='<span class="'+(sign?'profit-pos':'profit-neg')+'">'+(sign?'+':'')+fmt(t.profit_pct)+'%</span>';
      document.getElementById('tTrades').textContent=t.num_trades+' trades over '+t.test_days+' days';
      document.getElementById('tSuccess').innerHTML='<span class="'+(t.success_rate>=50?'profit-pos':'profit-neg')+'">'+fmt(t.success_rate,1)+'%</span>';

      // ── Charts
      document.getElementById('chart100').src='data:image/png;base64,'+data.charts.last_100_days;
      document.getElementById('chartFull').src='data:image/png;base64,'+data.charts.full_range;
      document.getElementById('chartTrade').src='data:image/png;base64,'+data.charts.trading_strategy;

      document.getElementById('results').style.display='block';
      setTimeout(()=>{
        document.getElementById('results').classList.add('visible');
        document.getElementById('results').scrollIntoView({behavior:'smooth',block:'start'});
      },50);
    })
    .catch(err=>{
      stopSteps();
      document.getElementById('loadingOverlay').classList.remove('active');
      document.getElementById('analyzeBtn').disabled=false;
      document.getElementById('errorMsg').textContent='Request failed: '+err.message;
      document.getElementById('errorBox').style.display='flex';
      document.getElementById('results').style.display='block';
      document.getElementById('results').classList.add('visible');
    });
}
document.getElementById('stockSelect').addEventListener('keydown',e=>{if(e.key==='Enter')runAnalysis();});
</script>
</body>
</html>
