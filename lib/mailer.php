<?php
if (defined('MAILER_LOADED')) return; define('MAILER_LOADED', true);
// Mail helper. Priority: mail_debug (log only) > SMTP (if smtp_host is set) > PHP mail().
function send_mail(string $to, string $subject, string $body, ?string $html = null): bool {
  $to = str_replace(["\r", "\n"], '', $to);
  if (cfg('mail_debug')) { error_log("[mail] to=$to | $subject\n$body"); return true; }
  if (cfg('smtp_host')) return smtp_send($to, $subject, $body, $html);
  [$ct, $mb] = build_mime($body, $html);
  return @mail($to, '=?UTF-8?B?'.base64_encode($subject).'?=', $mb, 'From: "XAU/USD Terminal" <'.cfg('mail_from').">\r\nMIME-Version: 1.0\r\n".$ct);
}

function build_mime(string $text, ?string $html): array {
  $text = str_replace(["\r\n", "\r"], "\n", $text);
  $b64 = fn($s) => chunk_split(base64_encode($s), 76, "\r\n");
  if (!$html) return ["Content-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: base64", $b64($text)];
  $bd = '=_'.bin2hex(random_bytes(12));
  return ['Content-Type: multipart/alternative; boundary="'.$bd.'"',
    "--$bd\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".$b64($text)
   ."--$bd\r\nContent-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".$b64($html)."--$bd--\r\n"];
}

function smtp_send(string $to, string $subject, string $body, ?string $html = null): bool {
  $host = (string)cfg('smtp_host'); $port = (int)(cfg('smtp_port') ?: 587); $from = (string)cfg('mail_from');
  $fp = @stream_socket_client("tcp://$host:$port", $en, $es, 15);
  if (!$fp) { error_log("[smtp] connect failed: $es"); return false; }
  stream_set_timeout($fp, 15);
  $rd = function () use ($fp) { $r = ''; while (($l = fgets($fp, 515)) !== false) { $r .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; } return $r; };
  $cmd = function (string $c, string $ok) use ($fp, $rd) {
    fwrite($fp, $c."\r\n"); $r = $rd();
    if (strpos($r, $ok) !== 0) throw new RuntimeException(trim($r));
  };
  try {
    $r = $rd(); if (strpos($r, '220') !== 0) throw new RuntimeException(trim($r));
    $me = gethostname() ?: 'localhost';
    $cmd("EHLO $me", '250'); $cmd('STARTTLS', '220');
    if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('TLS failed');
    $cmd("EHLO $me", '250');
    $cmd('AUTH LOGIN', '334'); $cmd(base64_encode((string)cfg('smtp_user')), '334'); $cmd(base64_encode((string)cfg('smtp_pass')), '235');
    $cmd("MAIL FROM:<$from>", '250'); $cmd("RCPT TO:<$to>", '250'); $cmd('DATA', '354');
    [$ct, $mb] = build_mime($body, $html);
    $hdr = 'From: "XAU/USD Terminal" <'.$from.">\r\nTo: <$to>\r\nSubject: =?UTF-8?B?".base64_encode($subject)."?=\r\nDate: ".date('r')
         ."\r\nMessage-ID: <".bin2hex(random_bytes(8)).'@'.$me.">\r\nMIME-Version: 1.0\r\n"
         .(cfg('mail_reply_to') ? 'Reply-To: '.str_replace(["\r", "\n"], '', (string)cfg('mail_reply_to'))."\r\n" : '').$ct;
    $cmd($hdr."\r\n\r\n".$mb.'.', '250');
    fwrite($fp, "QUIT\r\n"); return true;
  } catch (Throwable $e) { error_log('[smtp] '.$e->getMessage()); return false; }
  finally { fclose($fp); }
}

function app_url(): string {
  if (cfg('app_url')) return rtrim((string)cfg('app_url'), '/');
  return (!empty($_SERVER['HTTPS']) ? 'https' : 'http').'://'.($_SERVER['HTTP_HOST'] ?? 'localhost');
}

/** Branded HTML email with one button. Inline styles + tables so it renders in Gmail/Outlook/Apple Mail. */
function email_template(string $heading, string $intro, string $btn, string $url, string $note): string {
  $e = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
  $site = app_url(); $siteName = $e(preg_replace('#^https?://#', '', $site)); $contact = $e((string)(cfg('mail_reply_to') ?: cfg('mail_from')));
  $heading = $e($heading); $intro = $e($intro); $btn = $e($btn); $u = $e($url); $note = $e($note);
  return <<<HTML
<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f3f4f6">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 12px"><tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:12px;overflow:hidden;font-family:-apple-system,'Segoe UI',Roboto,Arial,sans-serif">
<tr><td style="background:#0c0e12;padding:20px 28px;font-size:18px;font-weight:700;color:#ffffff">XAU<span style="color:#f0b429">/</span>USD Terminal</td></tr>
<tr><td style="padding:28px">
<h1 style="margin:0 0 12px;font-size:22px;line-height:1.3;color:#111827">$heading</h1>
<p style="margin:0 0 22px;font-size:15px;line-height:1.6;color:#374151">$intro</p>
<table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="background:#f0b429;border-radius:8px"><a href="$u" style="display:inline-block;padding:13px 26px;font-size:15px;font-weight:700;color:#1a1300;text-decoration:none">$btn</a></td></tr></table>
<p style="margin:22px 0 6px;font-size:13px;color:#6b7280">Button not working? Copy this link into your browser:</p>
<p style="margin:0 0 22px;font-size:12px;word-break:break-all"><a href="$u" style="color:#b45309">$u</a></p>
<p style="margin:0;font-size:13px;line-height:1.6;color:#6b7280">$note</p>
</td></tr>
<tr><td style="padding:16px 28px;background:#f9fafb;font-size:12px;line-height:1.6;color:#9ca3af">Trading involves risk. Signals are not financial advice.<br>Questions? Write to <a href="mailto:$contact" style="color:#6b7280">$contact</a> &middot; <a href="$site" style="color:#6b7280">$siteName</a></td></tr>
</table></td></tr></table></body></html>
HTML;
}

function send_action_mail(string $to, string $subject, string $heading, string $intro, string $btn, string $url, string $note): bool {
  $text = "$heading\n\n$intro\n\n$btn:\n$url\n\n$note\n\n- XAU/USD Terminal";
  return send_mail($to, $subject, $text, email_template($heading, $intro, $btn, $url, $note));
}

function send_verification(int $uid, string $email): void {
  $token = bin2hex(random_bytes(32));
  db()->prepare('DELETE FROM email_tokens WHERE user_id=?')->execute([$uid]);
  db()->prepare('INSERT INTO email_tokens(user_id,token_hash,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 24 HOUR))')
     ->execute([$uid, hash('sha256', $token)]);
  send_action_mail($email, 'Confirm your email - XAU/USD Terminal', 'Confirm your email',
    'Welcome! Confirm your email address to activate your account. The link is valid for 24 hours.',
    'Confirm email', app_url().'/verify.php?token='.$token, "If you didn't create this account, you can ignore this email.");
}