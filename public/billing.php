<?php
require __DIR__.'/../lib/bootstrap.php'; require __DIR__.'/../lib/payway.php'; require __DIR__.'/../lib/site.php';
require_login(); $u = user(); $msg = ''; $ok = false;
// Settle the payment we were sent back for, plus any unfinished ones from the last 7 days (covers a missed callback).
$ids = db()->prepare("SELECT tran_id FROM payments WHERE user_id=? AND status='pending' AND created_at>DATE_SUB(NOW(), INTERVAL 7 DAY)"); $ids->execute([$u['id']]);
foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $t) if (pw_settle($t) === 'paid' && ($_GET['tran'] ?? '') === $t) $ok = true;
if (!empty($_GET['tran']) && !$ok) { $own = db()->prepare('SELECT status FROM payments WHERE tran_id=? AND user_id=?'); $own->execute([$_GET['tran'], $u['id']]);
  $st = $own->fetchColumn(); if ($st === 'paid') $ok = true; elseif ($st) $msg = 'We have not received confirmation yet. It can take a minute. Refresh this page shortly.'; }
$end = pw_current_end((int)$u['id']); $active = $end && strtotime($end) > time();
$form = (!$active || isset($_GET['renew'])) && cfg('payway_merchant_id') ? pw_new_payment($u) : null;
$price = pw_amount(); $days = (int)(cfg('plan_days') ?: 30);
site_header('Billing', ''); ?>
<section class="pg"><div class="wrap" style="max-width:520px">
  <span class="badge">Billing</span><h1 style="font-size:30px">Full access plan</h1>
  <?php if ($ok): ?><div class="warn" style="border-color:#2ed39b"><h3 style="color:#2ed39b">Payment received ✓</h3><p>Thank you! Your access is active.</p></div><?php elseif ($msg): ?><div class="warn"><p><?= h($msg) ?></p></div><?php endif; ?>
  <div class="plan pop" style="margin-top:18px"><h3>XAU/USD Terminal</h3><div class="tag">Everything unlocked</div>
    <div class="pr">$<?= h($price) ?><small> / <?= $days ?> days</small></div>
    <ul><li>Live scalping signals with reasoning</li><li>AI review &amp; market analysis</li><li>Performance, journal, risk tools</li><li>Telegram &amp; email alerts</li></ul>
    <?php if ($active): ?><p class="mu">Active until <b><?= h(substr($end, 0, 10)) ?></b>.</p><a class="btn" href="billing.php?renew=1">Add another <?= $days ?> days</a> <a class="btn pri" href="dashboard.php">Open dashboard</a>
    <?php elseif ($form): ?>
      <form method="POST" target="aba_webservice" id="aba_merchant_request" action="<?= h(pw_base()) ?>/api/payment-gateway/v1/payments/purchase">
        <?php foreach ($form as $k => $v): ?><input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>"><?php endforeach; ?>
        <button class="btn pri lg2" style="width:100%" type="submit">Pay $<?= h($price) ?> with ABA PayWay</button>
      </form>
      <p class="mu" style="font-size:12px;margin:10px 0 0">ABA Pay, KHQR and cards. Payments are handled by ABA PayWay; we never see your card details.<?= cfg('payway_sandbox') ? ' <b>SANDBOX MODE: no real money.</b>' : '' ?></p>
    <?php else: ?><p class="mu">Payments are not configured yet.</p><?php endif; ?>
  </div>
</div></section>
<?php if ($form && !$active): ?>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://checkout.payway.com.kh/plugins/checkout2-0.js"></script>
<script>
document.getElementById('aba_merchant_request').addEventListener('submit', function (e) {
  if (window.AbaPayway && typeof AbaPayway.checkout === 'function') { e.preventDefault(); AbaPayway.checkout(); }
  else { this.target = '_self'; }   // plugin missing: normal form post to PayWay's hosted checkout page
});
</script>
<?php endif; site_footer();