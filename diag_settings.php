<?php
// /diag_settings.php  (text/plainで出す簡易診断)
header('Content-Type: text/plain; charset=utf-8');

// 1) PHP/拡張の状況
echo "=== DIAG SETTINGS START ===\n";
echo "PHP_VERSION: " . PHP_VERSION . "\n";
echo "EXT[pdo]: " . (extension_loaded('pdo') ? 'YES' : 'NO') . "\n";
echo "EXT[pdo_mysql]: " . (extension_loaded('pdo_mysql') ? 'YES' : 'NO') . "\n";

// 2) db_connect.php の候補を探索
$paths = [
  __DIR__ . '/sys/db_connect.php',
  __DIR__ . '/../sys/db_connect.php',
  dirname(__DIR__) . '/sys/db_connect.php',
];
$found = null;
foreach ($paths as $p) {
  echo "check: $p => " . (is_file($p) ? "FOUND" : "none") . "\n";
  if (!$found && is_file($p)) $found = $p;
}
if (!$found) {
  echo "FATAL: db_connect.php が見つかりません。上記いずれかの場所に設置してください。\n";
  exit(1);
}

// 3) 読み込み・$pdo検査・疎通
require_once $found;
echo "db_connect.php: $found (loaded)\n";

if (!isset($pdo) || !($pdo instanceof PDO)) {
  echo "FATAL: \$pdo が未定義 or PDOではありません。db_connect.php を見直してください。\n";
  exit(1);
}

try {
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $r = $pdo->query('SELECT 1')->fetchColumn();
  echo "DB SELECT 1 => $r (OK)\n";
} catch (Throwable $e) {
  echo "FATAL: DB疎通に失敗: " . $e->getMessage() . "\n";
  exit(1);
}

// 4) 必要テーブルの存在チェック＆試験作成
$ddl = [
  "CREATE TABLE IF NOT EXISTS player_master (
     id INT AUTO_INCREMENT PRIMARY KEY,
     name VARCHAR(255) NOT NULL,
     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
     INDEX(name)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
  "CREATE TABLE IF NOT EXISTS shop_master (
     id INT AUTO_INCREMENT PRIMARY KEY,
     name VARCHAR(255) NOT NULL,
     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
     INDEX(name)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
  "CREATE TABLE IF NOT EXISTS rule_master (
     id INT AUTO_INCREMENT PRIMARY KEY,
     code VARCHAR(16) NULL,
     name VARCHAR(255) NOT NULL,
     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
     INDEX(code), INDEX(name)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

foreach ($ddl as $sql) {
  try {
    $pdo->exec($sql);
    echo "DDL OK: " . substr($sql, 0, 40) . "...\n";
  } catch (Throwable $e) {
    echo "DDL ERROR: " . $e->getMessage() . "\n";
  }
}

echo "=== DIAG SETTINGS END ===\n";
