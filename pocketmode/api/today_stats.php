<?php
// /pocketmode/api/today_stats.php
declare(strict_types=1);
mb_internal_encoding('UTF-8');
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../sys/db_connect.php';

/*
  出力:
  {
    "status":"ok",
    "date":"YYYY-MM-DD",
    "games": <ゲーム数>,
    "players":[
      {"id":1,"name":"...", "wins":3,"score":12,"balls":15,"breaks":2},
      ...
    ]
  }
  仕様:
  - ゲーム数: 指定日の game_id のユニーク数
  - 勝ち数  : 各 game_id で P1/P2 の合計点を比較し、多い方に +1（同点は加点なし）
  - 合計スコア: 各プレイヤーの得点合計（rule_score.point × 有効倍率）
  - 落とした玉: 各プレイヤーが assigned になった玉の件数
  - ブレイク数: 9番レコードで ace=1 のゲームを、その9番の assigned プレイヤーに +1
*/

try {
  $date = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])
        ? $_GET['date']
        : date('Y-m-d');

  // 該当日の全レコードを取得（results.php 相当のJOIN）
  $sql = "
  SELECT
    md.game_id, md.date,
    md.player1_id, pm1.name AS player1_name,
    md.player2_id, pm2.name AS player2_name,
    md.rule_id,
    COALESCE(rm.side_multiplier_enabled,0) AS side_multiplier_enabled,
    COALESCE(rm.side_multiplier_value,2)   AS side_multiplier_value,
    md.ball_number, md.assigned, md.multiplier, COALESCE(md.ace,0) AS ace,
    COALESCE(rs.point,1) AS base_point
  FROM match_detail md
  LEFT JOIN player_master pm1 ON pm1.id = md.player1_id
  LEFT JOIN player_master pm2 ON pm2.id = md.player2_id
  LEFT JOIN rule_master  rm   ON rm.id  = md.rule_id
  LEFT JOIN rule_score   rs   ON rs.rule_id = md.rule_id AND rs.ball_number = md.ball_number
  WHERE md.date = :date
  ORDER BY md.game_id, md.ball_number;
  ";

  $stmt = $pdo->prepare($sql);
  $stmt->execute([':date'=>$date]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  if (!$rows) {
    echo json_encode([
      'status'=>'ok','date'=>$date,'games'=>0,'players'=>[]
    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
  }

  // game_id 単位で集計
  $games = []; // game_id => meta + per-ball
  foreach ($rows as $r){
    $gid = $r['game_id'];
    if (!isset($games[$gid])){
      $games[$gid] = [
        'p1_id' => (int)$r['player1_id'], 'p1_name' => (string)$r['player1_name'],
        'p2_id' => (int)$r['player2_id'], 'p2_name' => (string)$r['player2_name'],
        'useMul'=> (int)$r['side_multiplier_enabled'] === 1,
        'mulVal'=> max(1, (int)$r['side_multiplier_value']),
        'p1_score'=>0, 'p2_score'=>0,
        'p1_balls'=>0, 'p2_balls'=>0,
        'p1_breaks'=>0, 'p2_breaks'=>0,
      ];
    }
    $g = &$games[$gid];

    $assigned  = is_null($r['assigned']) ? null : (int)$r['assigned']; // 1 or 2
    $base      = max(0, (int)($r['base_point'] ?? 1));
    $mul       = max(1, (int)($r['multiplier'] ?? 1));
    $isSide    = ($mul > 1);
    $effMul    = ($g['useMul'] && $isSide) ? $g['mulVal'] : 1;
    $point     = $base * $effMul;

    if ($assigned === 1){ $g['p1_score'] += $point; $g['p1_balls']++; }
    if ($assigned === 2){ $g['p2_score'] += $point; $g['p2_balls']++; }

    // ブレイクエース（9番の ace）
    if ((int)$r['ball_number'] === 9 && (int)$r['ace'] === 1){
      if     ($assigned === 1) $g['p1_breaks']++;
      elseif ($assigned === 2) $g['p2_breaks']++;
      // assigned がNULLでも、どちらにも付与しない（不明扱い）
    }
    unset($g);
  }

  // プレイヤー別に再集計
  $players = []; // pid => stats
  foreach ($games as $gid=>$g){
    // 勝敗
    $p1win = $g['p1_score'] > $g['p2_score'];
    $p2win = $g['p2_score'] > $g['p1_score'];

    // P1
    $pid = $g['p1_id'];
    if (!isset($players[$pid])){
      $players[$pid] = ['id'=>$pid, 'name'=>$g['p1_name'], 'wins'=>0,'score'=>0,'balls'=>0,'breaks'=>0];
    }
    $players[$pid]['wins']  += ($p1win ? 1 : 0);
    $players[$pid]['score'] += $g['p1_score'];
    $players[$pid]['balls'] += $g['p1_balls'];
    $players[$pid]['breaks']+= $g['p1_breaks'];

    // P2
    $pid = $g['p2_id'];
    if (!isset($players[$pid])){
      $players[$pid] = ['id'=>$pid, 'name'=>$g['p2_name'], 'wins'=>0,'score'=>0,'balls'=>0,'breaks'=>0];
    }
    $players[$pid]['wins']  += ($p2win ? 1 : 0);
    $players[$pid]['score'] += $g['p2_score'];
    $players[$pid]['balls'] += $g['p2_balls'];
    $players[$pid]['breaks']+= $g['p2_breaks'];
  }

  // 配列化（名前昇順）
  $playersArr = array_values($players);
  usort($playersArr, fn($a,$b)=> strcmp($a['name'],$b['name']));

  echo json_encode([
    'status'  => 'ok',
    'date'    => $date,
    'games'   => count($games),
    'players' => $playersArr
  ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    'status'=>'error',
    'message'=>$e->getMessage()
  ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
