<?php
// /settings.php
// マスタ登録画面（プレイヤー・店舗）単一ファイル版
// 前提: /sys/db_connect.php で $pdo (PDO, ERRMODE_EXCEPTION 推奨) が用意される

declare(strict_types=1);
session_start();

// 一時的なデバッグ（不具合時のみON。動作確認後は必ずOFFに）
/*
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
*/

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function v(string $relPath): string {
  $p = __DIR__ . '/' . ltrim($relPath, '/');
  $t = @filemtime($p);
  return $t ? (string)$t : '1';
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$CSRF = $_SESSION['csrf_token'];

require_once __DIR__ . '/sys/db_connect.php'; // $pdo が定義される想定

// テーブル名
$TBL_PLAYER = 'player_master';
$TBL_SHOP   = 'shop_master';

// 入力ユーティリティ
function postStr(string $key): string {
  return isset($_POST[$key]) ? trim((string)$_POST[$key]) : '';
}
function verifyCsrf(): void {
  if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    http_response_code(400);
    exit('Invalid CSRF token');
  }
}
function normalizeName(string $name): string {
  $name = preg_replace('/[ \x{3000}]+/u', ' ', $name);
  return $name ?? '';
}

// トースト表示用メッセージ
$flash = ['ok'=>[], 'err'=>[]];

$action = $_POST['action'] ?? '';
$tab    = $_POST['tab']    ?? ($_GET['tab'] ?? 'player');
$now    = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');

try {
  if ($action !== '') {
    verifyCsrf();
  }

  if ($action === 'add_player') {
    $name = normalizeName(postStr('name'));
    if ($name === '') throw new Exception('プレイヤー名を入力してください。');
    if (mb_strlen($name) > 50) throw new Exception('プレイヤー名は50文字以内で入力してください。');

    $sql = "SELECT id FROM {$TBL_PLAYER} WHERE name = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$name]);
    if ($stmt->fetch()) throw new Exception('同名のプレイヤーが既に存在します。');

    $sql = "
