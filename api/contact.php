<?php
/**
 * オリジナルパッケージLP お問い合わせフォームの送信処理（Xserver 用・PHP 7.3 で動くように書く）
 * 採用サイトの recruit/api/entry.php と同じ作り
 *
 * - 店舗（shop@）へお問い合わせ内容を通知
 * - お客様へ受付の自動返信
 * - 応答は JSON（{"ok":true} / {"ok":false,"error":"..."}）。フォーム側の fetch が受け取る
 */

// メールは UTF-8 で送る。'Japanese'（ISO-2022-JP）だと 髙・𠮷・①・㈱・絵文字・半角カナ・— が「?」に化ける
mb_language('uni');
mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Tokyo');
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
require __DIR__ . '/csv.inc.php';

const TO_ADDRESS   = 'shop@mame-kawamura.com';
const FROM_ADDRESS = 'shop@mame-kawamura.com';
const FROM_NAME    = '甘納豆かわむら';
const SHOP_TEL     = '076-282-7000';
const ALLOWED_ORIGINS = array('https://bulk.mame-kawamura.com');
const RATE_LIMIT_SEC  = 60; // 同じIPからの連続送信の間隔
// お客様への自動返信の上限（超えても受付・控え・shop@への通知は通常どおり。自動返信だけ止める）
const REPLY_MAX_PER_DAY   = 50; // 直近24時間の合計
const REPLY_MAX_PER_HOUR  = 15; // 直近1時間の合計
const REPLY_MAX_PER_EMAIL = 5;  // 同じメールアドレスへ、直近24時間で
// チェックボックス・ラジオは外から書き換えて送れるので、決まった値だけ通す
const ALLOWED_PRODUCTS = array('能登大納言', '石川県のだいず', '白花美人', '蓮の実', 'その他', 'まだ決めていない・詳しく聞きたい');
const ALLOWED_DATA = array('画像データ（JPEG・PNG・PDFなど）を送る', 'Illustratorデータ（.ai）で入稿する', 'まだ決まっていない・相談したい');
const ALLOWED_SPECS = array('通常バージョン（標準印刷エリア）', 'プレミアムバージョン（印刷エリア拡張）', 'まだ決めていない・詳しく聞きたい');
const ALLOWED_PURPOSES = array('企業の周年記念・お取引先への贈答品', '社内イベント・展示会のノベルティ', '組合・学会・定例会合でのお菓子', '内定者・新入社員へのウェルカムギフト', 'その他');

function respond($ok, $error = '', $status = 200) {
  http_response_code($status);
  echo json_encode($ok ? array('ok' => true) : array('ok' => false, 'error' => $error), JSON_UNESCAPED_UNICODE);
  exit;
}

// 1行項目：改行を除いて長さを制限（メールヘッダーへの差し込み対策も兼ねる）
function field($name, $max = 100) {
  $v = isset($_POST[$name]) ? (string) $_POST[$name] : '';
  $v = trim(preg_replace('/[\r\n\t\x00]+/u', ' ', $v));
  return mb_substr($v, 0, $max);
}

// 複数行項目
function textarea($name, $max = 1000) {
  $v = isset($_POST[$name]) ? (string) $_POST[$name] : '';
  $v = str_replace(array("\r\n", "\r", "\x00"), array("\n", "\n", ''), $v);
  return mb_substr(trim($v), 0, $max);
}

// 署名はサーバーの公開フォルダの外（~/mame-kawamura.com/bulk_private/mail_signature.txt）から読む。無ければ簡易署名
function signature() {
  $file = dirname(dirname(dirname(__DIR__))) . '/bulk_private/mail_signature.txt';
  if (is_readable($file)) {
    $sig = trim(str_replace(array("\r\n", "\r"), "\n", (string) file_get_contents($file)));
    if ($sig !== '') return $sig . "\n";
  }
  return "甘納豆かわむら\n"
    . "〒921-8031 石川県金沢市野町2丁目24-7\n"
    . "TEL：" . SHOP_TEL . "\n"
    . "MAIL：" . FROM_ADDRESS . "\n"
    . "https://bulk.mame-kawamura.com/\n";
}

// 問い合わせの控え：公開フォルダの外のCSVに1行ずつ追記する（メールを消しても記録が残るように）。
// Excel でそのまま開けるよう UTF-8（BOM付き）。書けなくてもお客様の送信は止めない
function save_entry($row) {
  $dir = dirname(dirname(dirname(__DIR__))) . '/bulk_private';
  $file = $dir . '/contacts.csv';
  if (!is_dir($dir) || !is_writable($dir) || (is_file($file) && !is_writable($file))) return false;
  $isNew = !is_file($file);
  $fp = @fopen($file, 'a');
  if (!$fp) return false;
  $ok = flock($fp, LOCK_EX);
  if ($ok) {
    if ($isNew || filesize($file) === 0) {
      fwrite($fp, "\xEF\xBB\xBF" . kw_csv_line(array_keys($row)));
    }
    $ok = fwrite($fp, kw_csv_line(array_map('kw_csv_guard', array_values($row)))) !== false;
    fflush($fp);
    flock($fp, LOCK_UN);
  }
  fclose($fp);
  if ($isNew) @chmod($file, 0600);
  return $ok;
}

// 自動返信を送ってよいか判定する。他人のアドレス宛てに大量に送らせる悪用への対策。
// 記録は bulk_private/autoreply_log.json（メールアドレスはハッシュ化して保存）
function reply_allowed($email, &$reason) {
  $file = dirname(dirname(dirname(__DIR__))) . '/bulk_private/autoreply_log.json';
  $fp = @fopen($file, 'c+');
  if (!$fp) { $reason = 'log'; return true; } // 記録できないときは送る（本物のお客様を優先）
  flock($fp, LOCK_EX);
  $log = json_decode(stream_get_contents($fp), true);
  if (!is_array($log)) $log = array();
  $sent = isset($log['sent']) && is_array($log['sent']) ? $log['sent'] : array();
  $now = time();
  $sent = array_values(array_filter($sent, function ($e) use ($now) { return $now - $e['t'] < 86400; }));
  $hash = hash('sha256', strtolower($email));
  $day = count($sent);
  $hour = count(array_filter($sent, function ($e) use ($now) { return $now - $e['t'] < 3600; }));
  $same = count(array_filter($sent, function ($e) use ($hash) { return $e['h'] === $hash; }));

  $reason = '';
  if ($day >= REPLY_MAX_PER_DAY) $reason = 'day';
  elseif ($hour >= REPLY_MAX_PER_HOUR) $reason = 'hour';
  elseif ($same >= REPLY_MAX_PER_EMAIL) $reason = 'email';

  $alert = false;
  if ($reason === '') {
    $sent[] = array('t' => $now, 'h' => $hash);
  } elseif ($reason !== 'email' && (!isset($log['alerted']) || $now - $log['alerted'] >= 86400)) {
    $log['alerted'] = $now; // 全体の上限に達したら、24時間に1回だけ shop@ に知らせる
    $alert = true;
  }
  $log['sent'] = $sent;
  ftruncate($fp, 0);
  rewind($fp);
  fwrite($fp, json_encode($log));
  fflush($fp);
  flock($fp, LOCK_UN);
  fclose($fp);
  @chmod($file, 0600);

  if ($alert) {
    @mb_send_mail(TO_ADDRESS, '【オリジナルパッケージ】自動返信が上限に達しました',
      "オリジナルパッケージLPのお客様への自動返信が上限（1日" . REPLY_MAX_PER_DAY . "通／1時間" . REPLY_MAX_PER_HOUR . "通）に達したため、\n"
      . "しばらく自動返信を止めています。お問い合わせの受付と、このアドレスへの通知は通常どおり続いています。\n\n"
      . "機械的な大量送信の可能性があります。通知メールの内容をご確認ください。\n",
      'From: ' . mb_encode_mimeheader(FROM_NAME) . ' <' . FROM_ADDRESS . '>', '-f' . FROM_ADDRESS);
  }
  return $reason === '';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(false, 'method', 405);

$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
if ($origin !== '' && !in_array($origin, ALLOWED_ORIGINS, true)) respond(false, 'origin', 403);

// ボット対策：見えない項目に入力があれば、送ったふりをして何もしない
if (field('website') !== '') respond(true);

// 連続送信の制限
$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
$lock = sys_get_temp_dir() . '/kw_bulk_contact_' . md5($ip);
if (is_file($lock) && time() - filemtime($lock) < RATE_LIMIT_SEC) respond(false, 'rate', 429);

$products = isset($_POST['products']) && is_array($_POST['products']) ? $_POST['products'] : array();
$products = array_values(array_intersect(ALLOWED_PRODUCTS, array_map('strval', $products)));

$d = array(
  'company'      => field('company', 100),
  'name'         => field('name', 60),
  'email'        => field('email', 200),
  'tel'          => field('tel', 30),
  'product_other'=> field('product_other', 200),
  'quantity'     => field('quantity', 100),
  'data'         => field('data', 50),
  'spec'         => field('spec', 50),
  'purpose'      => field('purpose', 50),
  'delivery'     => field('delivery', 50),
  'message'      => textarea('message', 2000),
  'utm_source'   => field('utm_source', 100),
  'utm_medium'   => field('utm_medium', 100),
  'utm_campaign' => field('utm_campaign', 100),
);

// 全角の電話番号・メールアドレス・数量を受け付けられるようにそろえる
$d['tel'] = str_replace(array('－', '−', '‐', '―', 'ー'), '-', mb_convert_kana($d['tel'], 'as'));
$d['email'] = preg_replace('/\s+/u', '', mb_convert_kana($d['email'], 'as'));
$d['quantity'] = mb_convert_kana($d['quantity'], 'as');

// フォーム側と同じ必須チェック
$telDigits = preg_replace('/\D/', '', $d['tel']);
if ($d['company'] === '' || $d['name'] === ''
  || !filter_var($d['email'], FILTER_VALIDATE_EMAIL)
  || ($d['tel'] !== '' && !preg_match('/^0\d{9,10}$/', $telDigits))
  || !$products
  || $d['quantity'] === ''
  || !in_array($d['data'], ALLOWED_DATA, true)
  || !in_array($d['spec'], ALLOWED_SPECS, true)
  || ($d['purpose'] !== '' && !in_array($d['purpose'], ALLOWED_PURPOSES, true))
  // 会社名・お名前は自動返信の宛名に入るので、URLのような文字列は受け付けない
  || preg_match('~(https?:|www\.|://|\.(com|net|jp|org|info|xyz|ru|cn)\b)~iu', $d['company'] . ' ' . $d['name'])) {
  respond(false, 'invalid', 400);
}

$dash = function ($v) { return $v !== '' ? $v : '（未入力）'; };
$productText = implode('、', $products);

$summary =
    "■ 会社名・団体名：" . $d['company'] . "\n"
  . "■ ご担当者：" . $d['name'] . "\n"
  . "■ メールアドレス：" . $d['email'] . "\n"
  . "■ 電話番号：" . $dash($d['tel']) . "\n"
  . "■ ご希望の商品：" . $productText . "\n"
  . "■ その他の商品：" . $dash($d['product_other']) . "\n"
  . "■ おおよその数量：" . $d['quantity'] . "\n"
  . "■ 入稿方法：" . $d['data'] . "\n"
  . "■ パッケージ仕様：" . $d['spec'] . "\n"
  . "■ ご利用用途：" . $dash($d['purpose']) . "\n"
  . "■ ご希望の納品日：" . $dash($d['delivery']) . "\n"
  . "■ ご不明点・ご要望：\n" . $dash($d['message']) . "\n";

$now = date('Y年n月j日 H:i');
// 受付ID：通知メールとCSVの行を突き合わせるための番号
$entryId = date('Ymd-His') . '-' . substr(md5(uniqid('', true)), 0, 4);

// メールより先に控えを保存する（通知メールが失敗してもお問い合わせの記録は残る）
$saved = save_entry(array(
  '受付ID' => $entryId, '受付日時' => date('Y-m-d H:i:s'),
  '会社名・団体名' => $d['company'], 'ご担当者' => $d['name'], 'メールアドレス' => $d['email'], '電話番号' => $d['tel'],
  'ご希望の商品' => $productText, 'その他の商品' => $d['product_other'], 'おおよその数量' => $d['quantity'],
  '入稿方法' => $d['data'], 'パッケージ仕様' => $d['spec'], 'ご利用用途' => $d['purpose'],
  'ご希望の納品日' => $d['delivery'], 'ご不明点・ご要望' => $d['message'],
  'utm_source' => $d['utm_source'], 'utm_medium' => $d['utm_medium'], 'utm_campaign' => $d['utm_campaign'],
  '送信元IP' => $ip,
));
$fromHeader = 'From: ' . mb_encode_mimeheader(FROM_NAME) . ' <' . FROM_ADDRESS . '>';
$envelope = '-f' . FROM_ADDRESS;

// 1) 店舗への通知（返信ボタンでお客様に返せるよう Reply-To をお客様に）
$adminSubject = '【オリジナルパッケージ】お問い合わせ｜' . $d['company'] . ' ' . $d['name'] . '様';
$adminBody =
    "オリジナルパッケージLPからお問い合わせがありました。\n"
  . "受付日時：" . $now . "\n"
  . "受付ID：" . $entryId . "\n"
  . ($saved ? '' : "※サーバーへの控え（contacts.csv）の保存に失敗しました。このメールを保管してください。\n")
  . "\n"
  . $summary . "\n"
  . "---------------------------------\n"
  . "流入元：utm_source=" . $dash($d['utm_source'])
  . " / utm_medium=" . $dash($d['utm_medium'])
  . " / utm_campaign=" . $dash($d['utm_campaign']) . "\n"
  . "送信元IP：" . $ip . "\n";
$adminHeaders = $fromHeader . "\r\n" . 'Reply-To: ' . $d['email'];

$replyReason = '';
$canReply = reply_allowed($d['email'], $replyReason);
if (!$canReply) {
  $why = array('day' => '1日の上限', 'hour' => '1時間の上限', 'email' => '同じアドレスへの上限');
  $adminBody = "※お客様への自動返信は" . $why[$replyReason] . "のため送っていません。必要に応じて手動でご返信ください。\n\n" . $adminBody;
}

if (!mb_send_mail(TO_ADDRESS, $adminSubject, $adminBody, $adminHeaders, $envelope)) {
  respond(false, 'mail', 500);
}
touch($lock);

// 2) お客様への自動返信（失敗してもお問い合わせ自体は受け付け済みなので ok を返す）
$replySubject = '甘納豆かわむら：オリジナルパッケージのお問い合わせを受け付けました';
$replyBody =
    $d['company'] . "\n"
  . $d['name'] . " 様\n\n"
  . "このたびは甘納豆かわむらのオリジナルパッケージに\n"
  . "お問い合わせいただき、誠にありがとうございます。\n\n"
  . "以下の内容でお問い合わせを受け付けました。\n"
  . "内容を確認のうえ、担当者より順次ご連絡いたします。\n"
  . "今しばらくお待ちください。\n\n"
  // お客様が自由に書ける欄は載せない（他人のアドレス宛てに任意の文章を送らせる悪用・誤送信時の情報漏れ対策）
  . "---------------------------------\n"
  . "■ ご希望の商品：" . $productText . "\n"
  . "■ 入稿方法：" . $d['data'] . "\n"
  . "■ パッケージ仕様：" . $d['spec'] . "\n"
  . "■ 受付番号：" . $entryId . "\n"
  . "---------------------------------\n\n"
  . "※このメールは自動でお送りしています。\n"
  . "　ご不明な点は、このメールへのご返信またはお電話にてお問い合わせください。\n"
  . "※お心当たりのない場合は、お手数ですがこのメールを破棄してください。\n\n"
  . signature();
if ($canReply) {
  @mb_send_mail($d['email'], $replySubject, $replyBody, $fromHeader . "\r\n" . 'Reply-To: ' . FROM_ADDRESS, $envelope);
}

respond(true);
