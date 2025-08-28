<?php
// /tools/probe_tools.php
header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL); ini_set('display_errors','1');

$paths = [
  __DIR__ . '/probe.txt',                                  // /tools/ に書けるか
  rtrim(sys_get_temp_dir(), '/').'/probe_'.time().'.log',  // /tmp に書けるか
];
foreach ($paths as $p) {
  $ok = @file_put_contents($p, date('c')." alive\n", FILE_APPEND);
  echo ($ok!==false ? "OK  " : "NG  ").$p."\n";
}

echo "ALIVE\n";
