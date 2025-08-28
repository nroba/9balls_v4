<?php
// /tools/migrate_rule_id_to_int_fk_safe.php
// 目的: rule_master の主キーを数値に統一し、参照側 rule_id も数値化。外部キーに対応。
// 前提: /sys/db_connect.php が $pdo(PDO) を提供。MySQL 5.7 / INFORMATION_SCHEMA 不使用。

header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../sys/db_connect.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function q($s){ return '`'.str_replace('`','``',$s).'`'; }

function hasColumn(PDO $pdo, string $table, string $column): bool {
  $st = $pdo->prepare("SHOW COLUMNS FROM ".q($table)." LIKE ?");
  $st->execute([$column]);
  return (bool)$st->fetch();
}
function getColumn(PDO $pdo, string $table, string $column) {
  $st = $pdo->prepare("SHOW COLUMNS FROM ".q($table)." LIKE ?");
  $st->execute([$column]);
  return $st->fetch(); // ['Field','Type','Null','Key','Default','Extra']
}
function isIntType(string $type): bool {
  $t = strtolower($type);
  return (strpos($t,'int') !== false);
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
function listTables(PDO $pdo): array {
  return $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
}
function getCreateTable(PDO $pdo, string $table): string {
  $row = $pdo->query("SHOW CREATE TABLE ".q($table))->fetch(PDO::FETCH_ASSOC);
  return $row['Create Table'] ?? $row['Create View'] ?? '';
}

/**
 * 参照側テーブルにある、rule_master を参照する外部キーを抽出
 * 戻り: array of [
 *   'table' => テーブル名,
 *   'constraint' => 制約名,
 *   'cols' => ['rule_id', ...]  // 複数列FKは想定外だが配列対応
 *   'ref_table' => 'rule_master',
 *   'ref_cols' => ['id'],
 *   'on_delete' => 'CASCADE|RESTRICT|SET NULL|NO ACTION|',
 *   'on_update' => 同上
 * ]
 */
function findFKsReferencingRuleMaster(PDO $pdo): array {
  $out = [];
  foreach (listTables($pdo) as $t) {
    if ($t === 'rule_master') continue;
    $sql = getCreateTable($pdo, $t);
    if (!$sql) continue;

    // CONSTRAINT `fk_name` FOREIGN KEY (`col1`,...) REFERENCES `rule_master` (`id`,...) ON DELETE ... ON UPDATE ...
    $re = '/CONSTRAINT\s+`?([^`\s]+)`?\s+FOREIGN KEY\s*\(([^)]+)\)\s+REFERENCES\s+`?rule_master`?\s*\(([^)]+)\)\s*([^,]*)/i';
    if (preg_match_all($re, $sql, $m, PREG_SET_ORDER)) {
      foreach ($m as $mm) {
        $constraint = $mm[1];
        $cols = array_map(function($c){ return trim(str_replace('`','',$c)); }, explode(',', $mm[2]));
        $ref_cols = array_map(function($c){ return trim(str_replace('`','',$c)); }, explode(',', $mm[3]));
        $tail = strtoupper($mm[4] ?? '');
        $on_delete = '';
        $on_update = '';
        if (preg_match('/ON\s+DELETE\s+(RESTRICT|CASCADE|SET NULL|NO ACTION)/i', $tail, $x)) $on_delete = strtoupper($x[1]);
        if (preg_match('/ON\s+UPDATE\s+(RESTRICT|CASCADE|SET NULL|NO ACTION)/i', $tail, $y)) $on_update = strtoupper($y[1]);

        $out[] = [
          'table' => $t,
          'constraint' => $constraint,
          'cols' => $cols,
          'ref_table' => 'rule_master',
          'ref_cols' => $ref_cols,
          'on_delete' => $on_delete,
          'on_update' => $on_update,
        ];
      }
    }
  }
  return $out;
}

echo "=== MIGRATE (FK-safe) START ===\n";

// 0) 前提チェック
$tables = listTables($pdo);
if (!in_array('rule_master', $tables, true)) {
  echo "FATAL: rule_master テーブルが見つかりません。\n";
  exit(1);
}

// 1) rule_master の id 型を確認
$col = getColumn($pdo, 'rule_master', 'id');
if (!$col) { echo "FATAL: rule_master.id がありません。\n"; exit(1); }
echo "rule_master.id: Type={$col['Type']} Key={$col['Key']} Extra={$col['Extra']}\n";

$fkList = findFKsReferencingRuleMaster($pdo);
if ($fkList) {
  echo "--- Dropping FKs referencing rule_master ---\n";
  foreach ($fkList as $fk) {
    $sql = "ALTER TABLE ".q($fk['table'])." DROP FOREIGN KEY ".q($fk['constraint']);
    execTry($pdo, $sql, []);
  }
} else {
  echo "No FKs referencing rule_master.\n";
}

// 念のためFKチェックを緩める（セッション限定）
execTry($pdo, "SET FOREIGN_KEY_CHECKS=0", []);

// 2) rule_master を数値IDへ
if (isIntType($col['Type'])) {
  echo "NOTE: rule_master.id は既に整数型。次の工程へ進みます。\n";
} else {
  echo "--- Converting rule_master.id to INT PK ---\n";
  // 2-1) code 列を確保し、旧idを退避
  if (!hasColumn($pdo, 'rule_master', 'code')) {
    execTry($pdo, "ALTER TABLE `rule_master` ADD COLUMN `code` VARCHAR(16) NULL AFTER `id`", [1060]);
  }
  execTry($pdo, "UPDATE `rule_master` SET `code`=`id` WHERE `code` IS NULL OR `code`=''", []);

  // 2-2) 新しい数値列 id_num をAUTO_INCR
