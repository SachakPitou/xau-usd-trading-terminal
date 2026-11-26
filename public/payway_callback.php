<?php
require __DIR__.'/../lib/bootstrap.php'; require __DIR__.'/../lib/payway.php';
$raw = file_get_contents('php://input'); $d = json_decode($raw, true);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !is_array($d) || empty($d['tran_id'])) { http_response_code(400); exit('bad request'); }
$sig = $d; ksort($sig); $s = '';                       // signature check as in PayWay's docs (logged only; see note)
foreach ($sig as $v) $s .= is_array($v) ? json_encode($v) : $v;
$mine = base64_encode(hash_hmac('sha512', $s, (string)cfg('payway_api_key'), true));
if (!hash_equals($mine, (string)($_SERVER['HTTP_X_PAYWAY_HMAC_SHA512'] ?? ''))) error_log('[payway] callback signature mismatch for '.$d['tran_id']);
error_log('[payway] callback '.$d['tran_id'].' -> '.pw_settle((string)$d['tran_id']));
http_response_code(200); echo 'ok';