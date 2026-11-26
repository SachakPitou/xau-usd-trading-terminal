<?php
require_once __DIR__.'/mailer.php';   // for app_url()
function pw_base(): string { return cfg('payway_sandbox') ? 'https://checkout-sandbox.payway.com.kh' : 'https://checkout.payway.com.kh'; }
function pw_hash(array $parts): string { return base64_encode(hash_hmac('sha512', implode('', $parts), (string)cfg('payway_api_key'), true)); }
function pw_amount(): string { return number_format((float)(cfg('plan_price') ?: 15), 2, '.', ''); }

/** Creates a pending payment and returns the signed form fields for PayWay checkout. */
function pw_new_payment(array $u): array {
  $tran = date('ymdHis').str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);   // 18 digits (max 20)
  $amount = pw_amount(); $mid = (string)cfg('payway_merchant_id'); $req = gmdate('YmdHis');
  db()->prepare('INSERT INTO payments(tran_id,user_id,amount,currency) VALUES(?,?,?,?)')->execute([$tran, $u['id'], $amount, 'USD']);
  $items = base64_encode(json_encode([['name'=>'XAU/USD Terminal - '.(int)(cfg('plan_days') ?: 30).' days full access', 'quantity'=>1, 'price'=>(float)$amount]]));
  $first = substr(preg_replace('/[^A-Za-z0-9]/', '', explode('@', $u['email'])[0]) ?: 'Member', 0, 20);
  $ret = base64_encode(app_url().'/payway_callback.php'); $cont = app_url().'/billing.php?tran='.$tran;
  $f = ['req_time'=>$req, 'merchant_id'=>$mid, 'tran_id'=>$tran, 'amount'=>$amount, 'items'=>$items, 'firstname'=>$first,
        'email'=>$u['email'], 'type'=>'purchase', 'return_url'=>$ret, 'continue_success_url'=>$cont, 'currency'=>'USD'];
  // PayWay hash order: req_time merchant_id tran_id amount items shipping ctid pwt firstname lastname email phone type
  //                    payment_option return_url cancel_url continue_success_url return_deeplink currency custom_fields return_params
  $f['hash'] = pw_hash([$req, $mid, $tran, $amount, $items, '', '', '', $first, '', $u['email'], '', 'purchase', '', $ret, '', $cont, '', 'USD', '', '']);
  $f['view_type'] = 'hosted_view';   // not part of the signed hash string; asks PayWay for its full hosted checkout page
  return $f;
}

/** Asks PayWay directly for the status of a transaction (only works for 7 days after creation). */
function pw_check(string $tran): ?array {
  $req = gmdate('YmdHis'); $mid = (string)cfg('payway_merchant_id');
  $ch = curl_init(pw_base().'/api/payment-gateway/v1/payments/check-transaction-2');
  curl_setopt_array($ch, [CURLOPT_POST=>1, CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>15, CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
    CURLOPT_POSTFIELDS=>json_encode(['req_time'=>$req, 'merchant_id'=>$mid, 'tran_id'=>$tran, 'hash'=>pw_hash([$req, $mid, $tran])])]);
  $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  $j = json_decode((string)$r, true);
  if (!is_array($j)) { error_log("[payway] bad check response (HTTP $code)"); return null; }
  return $j;
}

/** Confirms a payment with PayWay and, once, extends the user's access. Returns 'paid' | 'pending' | 'unknown'. */
function pw_settle(string $tran): string {
  $s = db()->prepare('SELECT * FROM payments WHERE tran_id=?'); $s->execute([$tran]); $p = $s->fetch();
  if (!$p) return 'unknown';
  if ($p['status'] === 'paid') return 'paid';
  $j = pw_check($tran); $d = $j['data'] ?? null;
  if (!$d || (string)($j['status']['code'] ?? '') !== '00') return 'pending';
  if ((int)($d['payment_status_code'] ?? -1) === 0 && ($d['payment_currency'] ?? '') === $p['currency'] && (float)($d['payment_amount'] ?? 0) + 0.001 >= (float)$p['amount']) {
    $u = db()->prepare("UPDATE payments SET status='paid', apv=?, paid_at=NOW() WHERE tran_id=? AND status='pending'");
    $u->execute([(string)($d['apv'] ?? ''), $tran]);
    if ($u->rowCount() === 1) pw_extend((int)$p['user_id'], $tran);   // only the first confirmation extends access
    return 'paid';
  }
  return 'pending';
}

function pw_extend(int $uid, string $ref): void {
  $days = (int)(cfg('plan_days') ?: 30);
  $s = db()->prepare("SELECT id FROM subscriptions WHERE user_id=? AND provider='aba' ORDER BY id DESC LIMIT 1"); $s->execute([$uid]);
  if ($id = $s->fetchColumn())
    db()->prepare("UPDATE subscriptions SET status='active', provider_ref=?, current_period_end=DATE_ADD(GREATEST(NOW(), COALESCE(current_period_end, NOW())), INTERVAL $days DAY) WHERE id=?")->execute([$ref, $id]);
  else
    db()->prepare("INSERT INTO subscriptions(user_id,provider,provider_ref,status,current_period_end) VALUES(?,'aba',?,'active',DATE_ADD(NOW(), INTERVAL $days DAY))")->execute([$uid, $ref]);
}

function pw_current_end(int $uid): ?string {
  $s = db()->prepare("SELECT current_period_end FROM subscriptions WHERE user_id=? AND status='active' ORDER BY current_period_end DESC LIMIT 1"); $s->execute([$uid]);
  return $s->fetchColumn() ?: null;
}