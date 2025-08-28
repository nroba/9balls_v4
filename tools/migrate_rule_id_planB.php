<?php
// /tools/migrate_rule_id_planB.php
// 目的: rule_master の主キーを INT に統一し、参照側 rule_id も数値化。FKに対応。
// 方式: 新テーブル rule_master_new を作り、旧 id を code に退避してコピー → 参照側を数値に更新 → 入れ替え。
// 出力: 画面(text/plain) と /tools/migrate_rule.log に進捗とエラーを全て記録。

header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors','1'); error_reporting(E_ALL);

$LOG = __DIR__ . '/migrate_rule.log';
@file_put_contents($LOG, "==== START ".date('c')." ====\n", FILE_APPEND);
function out($msg){ global $LOG; echo $msg."\n"; @file_put_contents($LOG, $msg."\n", FILE_APPEND); }

require_once __DIR__ . '/../sys/db_connect.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function q($s){ return '`'.str_replace('`','``',$s).'`'; }
function tryExec(PDO $pdo, string $sql, array $okErrnos=[]): bool {
  try { $pdo->exec($sql); out("OK  : $sql"); return true; }
  catch (PDOException $e) {
    $errno = $e->errorInfo[1] ?? null;
    $msg = "ERR : ($errno) ".$e->getMessage()." SQL=[".$sql."]";
    if (in_array($errno, $okErrnos, true)) { out("SKIP: ".$msg); return false; }
    out($msg);
    return false; // ここでは致命にせず続行（最後に確認）
  }
}
function colInfo(PDO $pdo, string $table, string $col) {
  $st = $pdo->prepare("SHOW COLUMNS FROM ".q($table)." LIKE ?");
  $st->execute([$col]);
  return $st->fetch();
}
function hasCol(PDO $pdo, string $table, string $col): bool { return (bool)colInfo($pdo,$table,$col); }
function isIntType($type): bool { $t=strtolower((string)$type); return strpos($t,'int')!==false; }
function listTables(PDO $pdo): array { return $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN); }
function createSQL(PDO $pdo, string $table): string {
  $row = $pdo->query("SHOW CREATE TABLE ".q($table))->fetch(PDO::FETCH_ASSOC);
  return $row['Create Table'] ?? $row['Create View'] ?? '';
}
function findFKsToRule(PDO $pdo): array {
  $out=[];
  foreach (listTables($pdo) as $t){
    if ($t==='rule_master') continue;
    $sql=createSQL($pdo,$t); if(!$sql) continue;
    $re='/CONSTRAINT\s+`?([^`\s]+)`?\s+FOREIGN KEY\s*\(([^)]+)\)\s+REFERENCES\s+`?rule_master`?\s*\(([^)]+)\)\s*([^,]*)/i';
    if(preg_match_all($re,$sql,$m,PREG_SET_ORDER)){
      foreach($m as $mm){
        $constraint=$mm[1];
        $cols=array_map(fn($c)=>trim(str_replace('`','',$c)), explode(',',$mm[2]));
        $refcols=array_map(fn($c)=>trim(str_replace('`','',$c)), explode(',',$mm[3]));
        $tail=strtoupper($mm[4]??'');
        $onDel=''; $onUpd='';
        if(preg_match('/ON\s+DELETE\s+(RESTRICT|CASCADE|SET NULL|NO ACTION)/i',$tail,$x)) $onDel=strtoupper($x[1]);
        if(preg_match('/ON\s+UPDATE\s+(RESTRICT|CASCADE|SET NULL|NO ACTION)/i',$tail,$y)) $onUpd=strtoupper($y[1]);
        $out[]=['table'=>$t,'name'=>$constraint,'cols'=>$cols,'refcols'=>$refcols,'on_delete'=>$onDel,'on_update'=>$onUpd];
      }
    }
  }
  return $out;
}

out("== PRECHECK ==");
$tables=listTables($pdo);
if(!in_array('rule_master',$tables,true)){ out("FATAL: rule_master がありません"); exit; }
$ci = colInfo($pdo,'rule_master','id');
out("rule_master.id Type={$ci['Type']} Key={$ci['Key']}");

$fkList=findFKsToRule($pdo);
if($fkList){
  out("FKs to rule_master:");
  foreach($fkList as $fk){ out(" - {$fk['table']}.(".implode(',',$fk['cols']).") -> rule_master(".implode(',',$fk['refcols']).") CONSTRAINT {$fk['name']}"); }
}else{
  out("No FKs to rule_master.");
}

out("== DROP FKs ==");
foreach($fkList as $fk){
  // 1091: can't DROP; check that it exists
  tryExec($pdo, "ALTER TABLE ".q($fk['table'])." DROP FOREIGN KEY ".q($fk['name']), [1091]);
}

out("== SET FOREIGN_KEY_CHECKS=0 ==");
tryExec($pdo,"SET FOREIGN_KEY_CHECKS=0");

out("== BUILD NEW rule_master ==");
$hasCreated = hasCol($pdo,'rule_master','created_at');
tryExec($pdo,"DROP TABLE IF EXISTS `rule_master_new`");
$defs = [
  "`id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY",
  "`code` VARCHAR(16) NULL",
  "`name` VARCHAR(255) NOT NULL"
];
if($hasCreated){ $defs[] = "`created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP"; }
$sqlCreate = "CREATE TABLE `rule_master_new` (" . implode(", ", $defs) . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
tryExec($pdo, $sqlCreate);

// 旧 id を code に退避しつつコピー
$sqlIns = "INSERT INTO `rule_master_new` (code,name".($hasCreated?",created_at":"").") ".
          "SELECT `id` AS code, `name`".($hasCreated?", `created_at`":"")." FROM `rule_master`";
tryExec($pdo, $sqlIns);

// 補助インデックス
tryExec($pdo, "CREATE INDEX `idx_rule_code` ON `rule_master_new` (`code`)", [1061]);

out("== UPDATE REFERENCING COLUMNS ==");
foreach($tables as $t){
  if($t==='rule_master' || $t==='rule_master_new') continue;
  // rule_id を持つなら対象
  $cinfo = colInfo($pdo,$t,'rule_id');
  if(!$cinfo) continue;
  $ctype = $cinfo['Type'];
  out(" * $t.rule_id Type=$ctype");
  if(isIntType($ctype)){
    out("   -> already INT, mapping by value may be unnecessary (skip)");
    continue;
  }
  // 文字列 → INT へ
  $tmp = 'rule_id_int';
  tryExec($pdo, "ALTER TABLE ".q($t)." ADD COLUMN ".q($tmp)." INT NULL", [1060]);
  // 旧 rule_id (文字列=A/B/...) を rule_master_new.code でマッピング
  tryExec($pdo,
    "UPDATE ".q($t)." x JOIN `rule_master_new` r ON x.`rule_id` = r.`code` ".
    "SET x.".q($tmp)." = r.`id`"
  );
  // 未マップ件数
  $nulls = (int)$pdo->query("SELECT COUNT(*) FROM ".q($t)." WHERE ".q($tmp)." IS NULL")->fetchColumn();
  out("   -> unmapped rows = $nulls");
  // 差し替え
  tryExec($pdo, "ALTER TABLE ".q($t)." DROP COLUMN `rule_id`");
  tryExec($pdo, "ALTER TABLE ".q($t)." CHANGE COLUMN ".q($tmp)." `rule_id` INT ".($nulls===0?"NOT NULL":"NULL"));
}

out("== SWAP TABLES ==");
tryExec($pdo, "DROP TABLE IF EXISTS `rule_master_old`");
tryExec($pdo, "RENAME TABLE `rule_master` TO `rule_master_old`, `rule_master_new` TO `rule_master`");
// 念のため code にインデックス再作成（rename で維持されるが保険）
tryExec($pdo, "CREATE INDEX `idx_rule_code` ON `rule_master` (`code`)", [1061]);

out("== RE-CREATE FKs ==");
foreach($fkList as $fk){
  if(count($fk['cols'])!==1 || count($fk['refcols'])!==1) { out("WARN: 複合FKは手動対応: {$fk['table']} ({$fk['name']})"); continue; }
  $sql = "ALTER TABLE ".q($fk['table'])." ADD CONSTRAINT ".q($fk['name'])." ".
         "FOREIGN KEY (".q($fk['cols'][0]).") REFERENCES `rule_master`(`id`)";
  if($fk['on_delete']) $sql .= " ON DELETE ".$fk['on_delete'];
  if($fk['on_update']) $sql .= " ON UPDATE ".$fk['on_update'];
  tryExec($pdo,$sql);
}

out("== SET FOREIGN_KEY_CHECKS=1 ==");
tryExec($pdo,"SET FOREIGN_KEY_CHECKS=1");

out("==== DONE ".date('c')." ====");
echo "確認: SELECT id, code, name FROM rule_master ORDER BY id;\n";
echo "ログ: " . $LOG . "\n";
