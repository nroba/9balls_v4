<?php
// /tools/migrate_rule_id_to_int.php
// 目的: rule_master の主キーを数値IDに統一し、参照側(rule_id)も数値に移行
// 前提: /sys/db_connect.php で $pdo(PDO) が使える

header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors','1');
error_reporting(E_ALL);

require_once __DIR__ . '/../sys/db_connect.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function hasColumn(PDO $pdo, string $table, string $column): bool {
  $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
  $st->execute([$column]);
  return (bool)$st->fetch();
}
function getColumn(PDO $pdo, string $table, string $column) {
  $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
  $st->execute([$column]);
  return $st->fetch(); // ['Field','Type','Null','Key','Default','Extra']
}
function isIntType(string $type): bool {
  $t = strtolower($type);
  return (strpos($t,'int') !== false); // tinyint/int/bigint など
}
function execTry(PDO $pdo, string $sql, array $okErrnos = []) {
  try { $pdo->exec($sql); echo "OK  : $sql\n"; }
  catch (PDOException $e) {
    $errno = $e->errorInfo[1] ?? null;
    if (in_array($errno, $okErrnos, true)) {
      echo "SKIP: $sql  (errno=$errno)\n";
    } else { throw $e; }
  }
}

// 0) ルールテーブル存在確認
$st = $pdo->query("SHOW TABLES");
$tables = $st->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('rule_master', $tables, true)) {
  echo "FATAL: rule_master テーブルが見つかりません。\n";
  exit(1);
}

// 1) rule_master.id の型を確認
$col = getColumn($pdo, 'rule_master', 'id');
if (!$col) { echo "FATAL: rule_master.id が見つかりません。\n"; exit(1); }

echo "rule_master.id Type = {$col['Type']}, Key={$col['Key']}\n";
$alreadyInt = isIntType($col['Type']);

if ($alreadyInt) {
  echo "NOTE: rule_master.id は既に数値（移行不要）。参照側 rule_id の型を確認・必要なら修正します。\n";
} else {
  echo "STEP: rule_master.id が文字列 → 数値IDへ移行を開始します。\n";

  // 1-1) code 列を確保（旧 id を退避する先）
  if (!hasColumn($pdo,'rule_master','code')) {
    execTry($pdo, "ALTER TABLE `rule_master` ADD COLUMN `code` VARCHAR(16) NULL AFTER `id`", [1060]);
  }
  // 旧id→code へバックフィル（空のみ）
  execTry($pdo, "UPDATE `rule_master` SET `code` = `id` WHERE `code` IS NULL OR `code`=''", []);

  // 1-2) 数値ID列を追加（主キーにする前段）
  //     AUTO_INCREMENT にはインデックスが必要なので UNIQUE を合わせて作る
  execTry($pdo, "ALTER TABLE `rule_master` ADD COLUMN `id_num` INT NOT NULL AUTO_INCREMENT, ADD UNIQUE KEY `uniq_rule_id_num` (`id_num`)", []);

  // 1-3) 主キーの付け替え（旧PKを外し、新しい数値列を主キーに）
  if ($col['Key'] === 'PRI') {
    execTry($pdo, "ALTER TABLE `rule_master` DROP PRIMARY KEY", []);
  }
  execTry($pdo, "ALTER TABLE `rule_master` ADD PRIMARY KEY (`id_num`)", [1068]); // 1068: 既にPKがある場合

  // 1-4) 列名入れ替え（id_num→id、旧id→id_old に退避）
  // 旧idの型をそのまま保持するため、Typeを取得
  $oldIdType = $col['Type']; // 例: varchar(16)
  execTry($pdo, "ALTER TABLE `rule_master` CHANGE COLUMN `id` `id_old` $oldIdType NOT NULL", []);
  execTry($pdo, "ALTER TABLE `rule_master` CHANGE COLUMN `id_num` `id` INT NOT NULL", []);

  // 1-5) インデックス（任意）
  execTry($pdo, "CREATE INDEX `idx_rule_code` ON `rule_master` (`code`)", [1061]); // 1061: duplicate key name

  echo "STEP: rule_master の主キーは数値id に置換済み。旧idは id_old に退避しました。\n";
}

// 2) 参照側の rule_id を数値化
echo "STEP: 参照テーブルの rule_id を走査・移行します。\n";
$st = $pdo->query("SHOW TABLES");
$tables = $st->fetchAll(PDO::FETCH_COLUMN);

foreach ($tables as $t) {
  // 自分自身やツール系は除外
  if ($t === 'rule_master') continue;

  $c = getColumn($pdo, $t, 'rule_id');
  if (!$c) continue; // rule_id を持たないテーブルはスキップ

  $type = $c['Type'];
  $isInt = isIntType($type);

  echo " - $t.rule_id Type=$type\n";

  if ($isInt) {
    // INT型だが中身が旧idのままのケースは稀なので何もしない（必要なら個別に）
    // ここで意図的に JOIN UPDATE を入れることも可能：
    // execTry($pdo, "UPDATE `$t` x JOIN `rule_master` r ON CAST(x.rule_id AS CHAR) = r.id_old SET x.rule_id = r.id", []);
    continue;
  }

  // 文字列→数値へ移行: 新列 rule_id_int を作ってバックフィル→旧列を差し替え
  execTry($pdo, "ALTER TABLE `$t` ADD COLUMN `rule_id_int` INT NULL", [1060]);

  // 旧値(文字列) = rule_master.id_old（旧id）に基づき数値IDを埋める
  execTry($pdo,
    "UPDATE `$t` x JOIN `rule_master` r ON x.`rule_id` = r.`id_old` SET x.`rule_id_int` = r.`id`", []
  );
  // code でも参照していた可能性がある場合のフォールバック
  if (hasColumn($pdo,'rule_master','code')) {
    execTry($pdo,
      "UPDATE `$t` x JOIN `rule_master` r ON x.`rule_id` = r.`code` SET x.`rule_id_int` = r.`id` WHERE x.`rule_id_int` IS NULL", []
    );
  }

  // 未マップの件数を確認
  $nulls = (int)$pdo->query("SELECT COUNT(*) FROM `$t` WHERE `rule_id_int` IS NULL")->fetchColumn();
  echo "   -> mapped NULLs = $nulls\n";

  // 差し替え（NULLが残る場合は NOT NULL 制約を付けない）
  execTry($pdo, "ALTER TABLE `$t` DROP COLUMN `rule_id`", []);
  execTry($pdo, "ALTER TABLE `$t` CHANGE COLUMN `rule_id_int` `rule_id` INT".($nulls===0 ? " NOT NULL" : " NULL"), []);
}

// 3) 後片付け（任意）: 旧id列を残しておくか選択
//  検証後に問題無しを確認できたら、下の行をアンコメントして実行し直し可。
// execTry($pdo, "ALTER TABLE `rule_master` DROP COLUMN `id_old`", []);

echo "=== MIGRATION DONE ===\n";
echo "確認: SELECT id, code, name FROM rule_master ORDER BY id;\n";
echo "参照側: 主要テーブルの rule_id が INT になっているか確認してください。\n";
