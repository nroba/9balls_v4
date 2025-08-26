<?php
ini_set('display_errors','1');
ini_set('display_startup_errors','1');
error_reporting(E_ALL);

$paths = [
  __DIR__ . '/sys/db_connect.php',
  __DIR__ . '/../sys/db_connect.php',
  dirname(__DIR__) . '/sys/db_connect.php',
];
$found = false;
foreach ($paths as $p) { if (is_file($p)) { require_once $p; $found = true; break; } }
if (!$found) { exit("db_connect.php not found\n"); }

if (!isset($pdo) || !($pdo instanceof PDO)) { exit("\$pdo not defined or not PDO\n"); }

try {
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $r = $pdo->query('SELECT 1')->fetchColumn();
  echo "DB OK: SELECT 1 = {$r}\n";
} catch (Throwable $e) {
  echo "DB NG: " . $e->getMessage() . "\n";
}
