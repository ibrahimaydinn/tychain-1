<?php
/**
 * OpTrade — Email helper
 * Sends via SMTP (SSL) if configured, falls back to PHP mail().
 * No external libraries required.
 */
require_once __DIR__ . '/config-S&P-case.php';

/**
 * Send an HTML email.
 * Returns true on success, false (or error string) on failure.
 */
function send_email(string $to_email, string $to_name, string $subject, string $html_body): bool|string {
    if (SMTP_HOST !== '') {
        return _smtp_send($to_email, $to_name, $subject, $html_body);
    }
    return _mail_send($to_email, $to_name, $subject, $html_body);
}

// ── BUY/SELL Alert email templates ───────────────────────────────────────────
function email_signal_alert(string $to_email, string $to_name, string $ticker,
                             string $signal_type, int $strength, float $price,
                             float $next_price, string $summary): bool|string {
    $color    = in_array($signal_type, ['STRONG BUY','BUY']) ? '#66BB6A' : '#EF5350';
    $emoji    = in_array($signal_type, ['STRONG BUY','BUY']) ? '📈' : '📉';
    $pch      = round((($next_price - $price) / $price) * 100, 2);
    $pch_sign = $pch >= 0 ? '+' : '';
    $subject  = "{$emoji} {$signal_type} Alert – {$ticker} | OpTrade";

    $body = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8">
<style>
  body{font-family:'Segoe UI',Arial,sans-serif;background:#0D1B2A;color:#ECEFF1;margin:0;padding:0;}
  .wrap{max-width:580px;margin:40px auto;background:#1A2B3C;border-radius:12px;overflow:hidden;}
  .hdr{background:linear-gradient(135deg,#0D1B2A,#1A2B3C);padding:28px 32px;border-bottom:3px solid {$color};}
  .hdr h1{margin:0;font-size:1.5rem;color:#ECEFF1;}
  .hdr p{margin:6px 0 0;color:#78909C;font-size:0.88rem;}
  .body{padding:28px 32px;}
  .signal-badge{display:inline-block;background:{$color}22;border:2px solid {$color};
    color:{$color};padding:10px 22px;border-radius:30px;font-size:1.3rem;
    font-weight:800;letter-spacing:.05em;margin-bottom:20px;}
  .row{display:flex;justify-content:space-between;padding:10px 0;
    border-bottom:1px solid #1E3A5F;}
  .row:last-child{border-bottom:none;}
  .row .lbl{color:#78909C;font-size:.85rem;}
  .row .val{color:#ECEFF1;font-weight:600;}
  .summary{background:#0D1B2A;border-left:4px solid #29B6F6;border-radius:0 8px 8px 0;
    padding:14px 18px;margin:20px 0;color:#CFD8DC;font-size:.9rem;line-height:1.6;}
  .footer{padding:18px 32px;background:#0D1B2A;font-size:.78rem;color:#546E7A;
    border-top:1px solid #1E3A5F;}
  .btn{display:inline-block;background:#29B6F6;color:#0D1B2A;text-decoration:none;
    padding:12px 28px;border-radius:8px;font-weight:700;margin-top:16px;}
</style>
</head>
<body>
<div class="wrap">
  <div class="hdr">
    <h1>{$emoji} OpTrade Signal Alert</h1>
    <p>AI-powered S&P 500 signal for {$ticker}</p>
  </div>
  <div class="body">
    <div class="signal-badge">{$signal_type}</div>
    <div class="row"><span class="lbl">Stock</span><span class="val">{$ticker}</span></div>
    <div class="row"><span class="lbl">Signal Strength</span><span class="val" style="color:{$color};">{$strength}%</span></div>
    <div class="row"><span class="lbl">Current Price</span><span class="val">\${$price}</span></div>
    <div class="row"><span class="lbl">Forecast (next day)</span><span class="val">\${$next_price} <small style="color:{$color};">({$pch_sign}{$pch}%)</small></span></div>
    <div class="summary">{$summary}</div>
    <a class="btn" href="APP_URL_PLACEHOLDER">View Full Analysis</a>
    <p style="color:#546E7A;font-size:.8rem;margin-top:20px;">
      You're receiving this because you track {$ticker} on OpTrade.
    </p>
  </div>
  <div class="footer">
    ⚠️ <strong>Disclaimer:</strong> This is an educational AI tool, not financial advice.
    Forecasts can be wrong. Always do your own research before investing.<br><br>
    OpTrade · <a href="APP_URL_PLACEHOLDER/unsubscribe.php?email={$to_email}" style="color:#29B6F6;">Unsubscribe</a>
  </div>
</div>
</body>
</html>
HTML;

    $body = str_replace('APP_URL_PLACEHOLDER', APP_URL, $body);
    return send_email($to_email, $to_name, $subject, $body);
}

// ── SMTP (SSL, no STARTTLS — works with Gmail port 465) ──────────────────────
function _smtp_send(string $to, string $to_name, string $subject, string $html): bool|string {
    $host = SMTP_HOST;
    $port = SMTP_PORT;
    $user = SMTP_USER;
    $pass = SMTP_PASS;

    $errno = 0; $errstr = '';
    $sock = @fsockopen($host, $port, $errno, $errstr, 15);
    if (!$sock) return "SMTP connect failed: $errstr ($errno)";

    $read = fn() => fgets($sock, 512);
    $send = function(string $cmd) use ($sock) {
        fwrite($sock, $cmd . "\r\n");
    };

    // handshake
    $r = $read();
    if (!str_starts_with($r, '220')) { fclose($sock); return "SMTP banner: $r"; }

    $send("EHLO localhost");
    while (true) { $r = $read(); if ($r[3] !== '-') break; }

    // AUTH LOGIN
    $send("AUTH LOGIN");
    $read();                                        // 334 Username:
    $send(base64_encode($user));
    $read();                                        // 334 Password:
    $send(base64_encode($pass));
    $r = $read();
    if (!str_starts_with($r, '235')) { fclose($sock); return "SMTP auth failed: $r"; }

    $boundary = 'b' . md5(uniqid());
    $msg_id   = '<' . time() . '.' . rand() . '@optrade>';
    $date     = date('r');
    $from_enc = '=?UTF-8?B?' . base64_encode(MAIL_NAME) . '?=';
    $to_enc   = '=?UTF-8?B?' . base64_encode($to_name)  . '?=';
    $sub_enc  = '=?UTF-8?B?' . base64_encode($subject)  . '?=';

    $headers = implode("\r\n", [
        "Date: $date",
        "Message-ID: $msg_id",
        "From: $from_enc <" . MAIL_FROM . ">",
        "To: $to_enc <$to>",
        "Subject: $sub_enc",
        "MIME-Version: 1.0",
        "Content-Type: multipart/alternative; boundary=\"$boundary\"",
    ]);

    $msg = "--$boundary\r\n"
         . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
         . strip_tags($html) . "\r\n\r\n"
         . "--$boundary\r\n"
         . "Content-Type: text/html; charset=UTF-8\r\n\r\n"
         . $html . "\r\n\r\n"
         . "--$boundary--\r\n";

    $send("MAIL FROM: <" . MAIL_FROM . ">");
    $read();
    $send("RCPT TO: <$to>");
    $read();
    $send("DATA");
    $read();
    $send($headers . "\r\n\r\n" . $msg . "\r\n.");
    $r = $read();
    $send("QUIT");
    fclose($sock);

    if (!str_starts_with($r, '250')) return "SMTP DATA rejected: $r";
    return true;
}

// ── PHP mail() fallback ───────────────────────────────────────────────────────
function _mail_send(string $to, string $to_name, string $subject, string $html): bool|string {
    $headers  = "From: " . MAIL_NAME . " <" . MAIL_FROM . ">\r\n";
    $headers .= "Reply-To: " . MAIL_FROM . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $ok = mail("$to_name <$to>", $subject, $html, $headers);
    return $ok ? true : "mail() returned false — check server sendmail config";
}
