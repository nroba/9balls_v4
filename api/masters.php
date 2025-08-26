<?php
// /pocketmode/api/masters.php  安全版
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../sys/db_connect.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// INFORMATION_SCHEMA を使わずに列の有無を確認
function hasColumn(PDO $pdo, string $table, string $column): bool {
  $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
  $st->execute([$column]);
  return (bool)$st->fetch();
}
$hasRuleCode = hasColumn($pdo, 'rule_master', 'code');

try {
  $players = $pdo->query("SELECT id, name FROM player_master ORDER BY name")->fetchAll();
} catch (Throwable $e) { $players = []; }

try {
  $shops   = $pdo->query("SELECT id, name FROM shop_master ORDER BY name")->fetchAll();
} catch (Throwable $e) { $shops = []; }

try {
  $rules   = $pdo->query("
    SELECT id, ".($hasRuleCode ? "IFNULL(code,'')" : "''")." AS code, name
    FROM rule_master ORDER BY id
  ")->fetchAll();
} catch (Throwable $e) { $rules = []; }

echo json_encode(['players'=>$players,'shops'=>$shops,'rules'=>$rules], JSON_UNESCAPED_UNICODE);
