<?php
/**
 * 応募の控え（entries.csv）の書き方・読み方。entry.php から読み込む（kw_csv_parse は控えを読み出すとき用）
 *
 * PHP 7.3 の fputcsv / fgetcsv は \ を特別扱いするため、\" を含む文や \ で終わる文で
 * 区切りが崩れる（Excel・Googleスプレッドシートと読み方が食い違う、行が読めなくなる）。
 * そのため標準（RFC 4180：" は "" に重ねるだけ）どおりに自前で書き・読む。
 * このファイルは関数を定義するだけで、直接開かれても何も出力しない。
 */

// 先頭がこれらの文字のセルは Excel・スプレッドシートで式として動くので、' を付けて文字として扱わせる
function kw_csv_guard($v) {
  $v = (string) $v;
  return preg_match('/^[=+\-@\t\r]/u', $v) ? "'" . $v : $v;
}

function kw_csv_line($fields) {
  $out = array();
  foreach ($fields as $v) {
    $v = (string) $v;
    $out[] = preg_match('/[",\r\n]/', $v) ? '"' . str_replace('"', '""', $v) . '"' : $v;
  }
  return implode(',', $out) . "\n";
}

// 文字列全体を行の配列に分ける（"…" の中の改行・カンマはそのまま値として扱う）
function kw_csv_parse($text) {
  $text = preg_replace('/^\xEF\xBB\xBF/', '', (string) $text);
  $rows = array();
  $row = array();
  $field = '';
  $inQuote = false;
  $len = strlen($text);
  for ($i = 0; $i < $len; $i++) {
    $c = $text[$i];
    if ($inQuote) {
      if ($c === '"') {
        if ($i + 1 < $len && $text[$i + 1] === '"') { $field .= '"'; $i++; }
        else $inQuote = false;
      } else {
        $field .= $c;
      }
    } elseif ($c === '"') {
      $inQuote = true;
    } elseif ($c === ',') {
      $row[] = $field;
      $field = '';
    } elseif ($c === "\n" || $c === "\r") {
      if ($c === "\r" && $i + 1 < $len && $text[$i + 1] === "\n") $i++;
      $row[] = $field;
      $rows[] = $row;
      $row = array();
      $field = '';
    } else {
      $field .= $c;
    }
  }
  if ($field !== '' || $row) { $row[] = $field; $rows[] = $row; }
  return $rows;
}
