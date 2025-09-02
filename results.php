<?php
// /results.php
// - 日ごとの表示（デフォルト：最新1日分）＋ページネーション
// - Topbar：Menu／日次サマリー／前の日／次の日（縦位置を揃える）
// - 各ラウンド：登録時刻表示（H:i）
// - VSボタン：該当ゲームの詳細をモーダル表示（本ファイルにAJAX）
// - 日次サマリー：プレイヤー別の合計得点・勝ち数・落とし玉数・サイド数・各ボール(1-9)の落とし数(サイド数)
//   ※ サマリーの並び順は「当日P1として登場したプレイヤーを上段」→その後は名前順

declare(strict_types=1);
header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/sys/db_connect.php';

// ---------------------------------------------------------
// Helpers
// ---------------------------------------------------------
if (function_exists('mb_internal_encoding')) { mb_internal_encoding('UTF-8'); }

function v_asset(string $relPath): string {
  $p = __DIR__ . '/' . ltrim($relPath, '/');
  $t = @filemtime($p);
  return $t ? (string)$t : '1';
}
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function circled_num(int $n): string {
  static $map = [1=>'①',2=>'②',3=>'③',4=>'④',5=>'⑤',6=>'⑥',7=>'⑦',8=>'⑧',9=>'⑨'];
  return $map[$n] ?? (string)$n;
}

// ---------------------------------------------------------
// AJAX: ゲーム詳細（モーダル） ?ajax=1&date=...&gid=...
// ---------------------------------------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
  $date = isset($_GET['date']) ? trim((string)$_GET['date']) : '';
  $gid  = isset($_GET['gid'])  ? trim((string)$_GET['gid'])  : '';

  if ($date === '' || $gid === '') {
    http_response_code(400);
    echo '<div style="padding:12px;color:#b91c1c;">Invalid parameters.</div>';
    exit;
  }

  $sql = "
  SELECT
    md.date,
    md.game_id,
    md.player1_id, pm1.name AS player1_name,
    md.player2_id, pm2.name AS player2_name,
    md.shop_id,   sm.name  AS shop_name,
    md.rule_id,   rm.name  AS rule_name,
    COALESCE(rm.side_multiplier_enabled, 0) AS side_multiplier_enabled,
    COALESCE(rm.side_multiplier_value,   2) AS side_multiplier_value,
    md.ball_number,
    md.assigned,
    md.multiplier,
    COALESCE(rs.point, 1) AS base_point,
    COALESCE(md.ace,0) AS ace
  FROM match_detail md
  LEFT JOIN player_master pm1 ON pm1.id = md.player1_id
  LEFT JOIN player_master pm2 ON pm2.id = md.player2_id
  LEFT JOIN shop_master  sm  ON sm.id  = md.shop_id
  LEFT JOIN rule_master  rm  ON rm.id  = md.rule_id
  LEFT JOIN rule_score   rs  ON rs.rule_id = md.rule_id AND rs.ball_number = md.ball_number
  WHERE md.date = :d AND md.game_id = :g
  ORDER BY md.ball_number ASC;
  ";

  try {
    $st = $pdo->prepare($sql);
    $st->bindValue(':d', $date, PDO::PARAM_STR);
    $st->bindValue(':g', $gid,  PDO::PARAM_STR);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
      echo '<div style="padding:12px;">データが見つかりませんでした。</div>';
      exit;
    }
  } catch (Throwable $e) {
    http_response_code(500);
    echo '<div style="padding:12px;color:#b91c1c;">DB Error: '.h($e->getMessage()).'</div>';
    exit;
  }

  // 構築（1ゲーム分）
  $meta = [
    'game_id' => $rows[0]['game_id'] ?? $gid,
    'player1_name' => $rows[0]['player1_name'] ?? 'Player1',
    'player2_name' => $rows[0]['player2_name'] ?? 'Player2',
    'shop_name'    => $rows[0]['shop_name']    ?? '',
    'rule_name'    => $rows[0]['rule_name']    ?? '',
    'side_multiplier_enabled' => (int)($rows[0]['side_multiplier_enabled'] ?? 0),
    'side_multiplier_value'   => max(1, (int)($rows[0]['side_multiplier_value'] ?? 2)),
  ];
  $balls = array_fill(1, 9, ['assigned'=>null, 'multiplier'=>1, 'base_point'=>1]);
  $ace9  = 0;

  foreach ($rows as $r) {
    $bn = (int)$r['ball_number'];
    if ($bn>=1 && $bn<=9) {
      $balls[$bn] = [
        'assigned'   => is_null($r['assigned']) ? null : (int)$r['assigned'],
        'multiplier' => is_null($r['multiplier']) ? 1 : max(1, (int)$r['multiplier']),
        'base_point' => max(0, (int)$r['base_point']),
      ];
    }
    if ($bn === 9 && (int)$r['ace'] === 1) { $ace9 = 1; }
  }

  // スコア計算 & セル（非得点は無色）
  $p1score=0; $p2score=0;
  $useMul = ($meta['side_multiplier_enabled'] === 1);
  $mulVal = $meta['side_multiplier_value'];
  $p1Cells=[]; $p2Cells=[];
  for ($bn=1;$bn<=9;$bn++){
    $a   = $balls[$bn]['assigned'];
    $mul = $balls[$bn]['multiplier'];
    $base= $balls[$bn]['base_point'];
    $isSide = ($mul>1);
    $effMul = ($useMul && $isSide) ? $mulVal : 1;
    $point  = (int)$base * (int)$effMul;

    if     ($a===1) $p1score += $point;
    elseif ($a===2) $p2score += $point;

    $mark = $a ? circled_num($bn) : '・';
    $p1Cells[$bn] = ['text'=>($a===1?$mark:'・'),'owned'=>($a===1 && $point>0?($isSide?'p1-side':'p1'):'')];
    $p2Cells[$bn] = ['text'=>($a===2?$mark:'・'),'owned'=>($a===2 && $point>0?($isSide?'p2-side':'p2'):'')];
  }

  // HTML（親ページの CSS を利用）
  ?>
  <div class="res-game-card" style="margin:0;">
    <div class="res-board-meta">
      <span class="res-meta-id">Game: <?= h((string)$meta['game_id']) ?></span>
      <?php if ($meta['shop_name']!==''): ?><span>店：<?= h($meta['shop_name']) ?></span><?php endif; ?>
      <?php if ($meta['rule_name']!==''): ?><span>ルール：<?= h($meta['rule_name']) ?></span><?php endif; ?>
      <span style="margin-left:auto"><?= h($meta['player1_name']) ?> / <?= h($meta['player2_name']) ?></span>
    </div>
    <div class="res-board">
      <div class="res-board-spacer"></div>
      <?php for($bn=1;$bn<=9;$bn++): ?><div class="res-ball-head"><?= $bn ?></div><?php endfor; ?>
      <div class="res-score-head">得点</div>

      <div class="res-row-label"><?= h($meta['player1_name']) ?></div>
      <?php for($bn=1;$bn<=9;$bn++): ?>
        <div class="res-cell<?= $p1Cells[$bn]['owned'] ? '" data-owned="'.$p1Cells[$bn]['owned'].'"' : '"' ?> data-bn="<?= $bn ?>">
          <?= h($p1Cells[$bn]['text']) ?>
          <?php if ($bn===9 && $ace9 && $p1Cells[$bn]['owned']): ?><span class="res-ace">ACE</span><?php endif; ?>
        </div>
      <?php endfor; ?>
      <div class="res-score-cell" data-side="p1"><?= (int)$p1score ?></div>

      <div class="res-row-label"><?= h($meta['player2_name']) ?></div>
      <?php for($bn=1;$bn<=9;$bn++): ?>
        <div class="res-cell<?= $p2Cells[$bn]['owned'] ? '" data-owned="'.$p2Cells[$bn]['owned'].'"' : '"' ?> data-bn="<?= $bn ?>">
          <?= h($p2Cells[$bn]['text']) ?>
          <?php if ($bn===9 && $ace9 && $p2Cells[$bn]['owned']): ?><span class="res-ace">ACE</span><?php endif; ?>
        </div>
      <?php endfor; ?>
      <div class="res-score-cell" data-side="p2"><?= (int)$p2score ?></div>
    </div>
  </div>
  <?php
  exit;
}

// ---------------------------------------------------------
// AJAX: 日次サマリー（モーダル） ?stats=1&date=...
// ---------------------------------------------------------
if (isset($_GET['stats']) && $_GET['stats'] === '1') {
  $date = isset($_GET['date']) ? trim((string)$_GET['date']) : '';
  if ($date === '') {
    http_response_code(400);
    echo '<div style="padding:12px;color:#b91c1c;">Invalid parameters.</div>';
    exit;
  }

  $sql = "
  SELECT
    md.date,
    md.game_id,
    md.player1_id, pm1.name AS p1_name,
    md.player2_id, pm2.name AS p2_name,
    md.ball_number,
    md.assigned,
    md.multiplier,
    COALESCE(rs.point, 1) AS base_point,
    COALESCE(rm.side_multiplier_enabled, 0) AS side_enabled,
    COALESCE(rm.side_multiplier_value,   2) AS side_value
  FROM match_detail md
  LEFT JOIN player_master pm1 ON pm1.id = md.player1_id
  LEFT JOIN player_master pm2 ON pm2.id = md.player2_id
  LEFT JOIN rule_master  rm  ON rm.id  = md.rule_id
  LEFT JOIN rule_score   rs  ON rs.rule_id = md.rule_id AND rs.ball_number = md.ball_number
  WHERE md.date = :d
  ORDER BY md.game_id, md.ball_number;
  ";

  try {
    $st = $pdo->prepare($sql);
    $st->bindValue(':d', $date, PDO::PARAM_STR);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    http_response_code(500);
    echo '<div style="padding:12px;color:#b91c1c;">DB Error: '.h($e->getMessage()).'</div>';
    exit;
  }

  // 集計
  $players   = []; // [pid] => stats
  $gameMeta  = []; // [gid] => ['p1_id','p2_id','p1_name','p2_name']
  $gameScore = []; // [gid] => ['p1'=>0,'p2'=>0]
  foreach ($rows as $r) {
    $gid = (string)$r['game_id'];
    if (!isset($gameMeta[$gid])) {
      $gameMeta[$gid] = [
        'p1_id' => (int)$r['player1_id'],
        'p2_id' => (int)$r['player2_id'],
        'p1_name' => $r['p1_name'] ?? 'Player1',
        'p2_name' => $r['p2_name'] ?? 'Player2',
      ];
      $gameScore[$gid] = ['p1'=>0, 'p2'=>0];
    }

    $p1_id = $gameMeta[$gid]['p1_id'];
    $p2_id = $gameMeta[$gid]['p2_id'];
    $p1_nm = $gameMeta[$gid]['p1_name'];
    $p2_nm = $gameMeta[$gid]['p2_name'];

    if (!isset($players[$p1_id])) $players[$p1_id] = ['name'=>$p1_nm,'score'=>0,'wins'=>0,'drops'=>0,'sideDrops'=>0,'ball'=>array_fill(1,9,0),'ballSide'=>array_fill(1,9,0),'is_p1'=>true];
    // 既に存在していても is_p1 を true に（同日内にP1で出ていれば上段扱い）
    $players[$p1_id]['is_p1'] = true;

    if (!isset($players[$p2_id])) $players[$p2_id] = ['name'=>$p2_nm,'score'=>0,'wins'=>0,'drops'=>0,'sideDrops'=>0,'ball'=>array_fill(1,9,0),'ballSide'=>array_fill(1,9,0),'is_p1'=>false];

    $bn   = (int)$r['ball_number'];
    $asn  = is_null($r['assigned']) ? 0 : (int)$r['assigned']; // 0,1,2
    $mul  = is_null($r['multiplier']) ? 1 : max(1,(int)$r['multiplier']);
    $base = max(0,(int)$r['base_point']);
    $sideEnabled = (int)$r['side_enabled'] === 1;
    $sideVal     = max(1,(int)$r['side_value']);

    $isSide = ($mul > 1);
    $effMul = ($sideEnabled && $isSide) ? $sideVal : 1;
    $point  = (int)$base * (int)$effMul;

    if ($asn === 1) {
      $players[$p1_id]['score'] += $point;
      $players[$p1_id]['drops'] += 1;
      if ($isSide) $players[$p1_id]['sideDrops'] += 1;
      if ($bn >= 1 && $bn <= 9) {
        $players[$p1_id]['ball'][$bn] += 1;
        if ($isSide) $players[$p1_id]['ballSide'][$bn] += 1;
      }
      $gameScore[$gid]['p1'] += $point;
    } elseif ($asn === 2) {
      $players[$p2_id]['score'] += $point;
      $players[$p2_id]['drops'] += 1;
      if ($isSide) $players[$p2_id]['sideDrops'] += 1;
      if ($bn >= 1 && $bn <= 9) {
        $players[$p2_id]['ball'][$bn] += 1;
        if ($isSide) $players[$p2_id]['ballSide'][$bn] += 1;
      }
      $gameScore[$gid]['p2'] += $point;
    }
  }

  // 勝ち数（ゲーム単位）
  foreach ($gameMeta as $gid => $gm) {
    $p1 = $gm['p1_id']; $p2 = $gm['p2_id'];
    $s1 = $gameScore[$gid]['p1'] ?? 0;
    $s2 = $gameScore[$gid]['p2'] ?? 0;
    if ($s1 > $s2)      { $players[$p1]['wins'] = ($players[$p1]['wins'] ?? 0) + 1; }
    elseif ($s2 > $s1)  { $players[$p2]['wins'] = ($players[$p2]['wins'] ?? 0) + 1; }
  }

  // 並び順：is_p1 DESC（P1上段） → 名前昇順
  uasort($players, function($a,$b){
    if (($b['is_p1'] ?? false) !== ($a['is_p1'] ?? false)) {
      return ($b['is_p1'] ?? false) <=> ($a['is_p1'] ?? false);
    }
    return strcmp((string)$a['name'], (string)$b['name']);
  });

  // 出力
  ?>
  <div style="padding:6px 8px 10px;">
    <h3 style="margin:4px 0 8px;font-size:16px;">日次サマリー（<?= h($date) ?>）</h3>

    <div style="overflow:auto;">
      <table class="res-stat-table" style="border-collapse:collapse;width:100%;min-width:560px;">
        <thead>
          <tr>
            <th style="text-align:left;padding:6px;border-bottom:1px solid #e5e7eb;">プレイヤー</th>
            <th style="text-align:right;padding:6px;border-bottom:1px solid #e5e7eb;">合計得点</th>
            <th style="text-align:right;padding:6px;border-bottom:1px solid #e5e7eb;">勝ち数</th>
            <th style="text-align:right;padding:6px;border-bottom:1px solid #e5e7eb;">落とした玉数</th>
            <th style="text-align:right;padding:6px;border-bottom:1px solid #e5e7eb;">サイド数</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($players as $pid => $p): ?>
          <tr>
            <td style="padding:6px;border-bottom:1px solid #f1f5f9;">
              <?= $p['is_p1'] ? 'P1: ' : 'P2: ' ?><?= h($p['name']) ?>
            </td>
            <td style="padding:6px;border-bottom:1px solid #f1f5f9;text-align:right;"><?= (int)$p['score'] ?></td>
            <td style="padding:6px;border-bottom:1px solid #f1f5f9;text-align:right;"><?= (int)($p['wins'] ?? 0) ?></td>
            <td style="padding:6px;border-bottom:1px solid #f1f5f9;text-align:right;"><?= (int)$p['drops'] ?></td>
            <td style="padding:6px;border-bottom:1px solid #f1f5f9;text-align:right;"><?= (int)$p['sideDrops'] ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div style="height:8px;"></div>

    <div style="overflow:auto;">
      <table class="res-stat-table" style="border-collapse:collapse;width:100%;min-width:680px;">
        <thead>
          <tr>
            <th style="text-align:left;padding:6px;border-bottom:1px solid #e5e7eb;">プレイヤー</th>
            <?php for($bn=1;$bn<=9;$bn++): ?>
              <th style="text-align:center;padding:6px;border-bottom:1px solid #e5e7eb;"><?= $bn ?></th>
            <?php endfor; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($players as $pid => $p): ?>
            <tr>
              <td style="padding:6px;border-bottom:1px solid #f1f5f9;">
                <?= $p['is_p1'] ? 'P1: ' : 'P2: ' ?><?= h($p['name']) ?>
              </td>
              <?php for($bn=1;$bn<=9;$bn++):
                $n = (int)($p['ball'][$bn] ?? 0);
                $s = (int)($p['ballSide'][$bn] ?? 0);
              ?>
                <td style="padding:6px;border-bottom:1px solid #f1f5f9;text-align:center;">
                  <?= $n ?><?php if($s>0): ?> (<?= $s ?>)<?php endif; ?>
                </td>
              <?php endfor; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php
  exit;
}

// ---------------------------------------------------------
// 通常ページ：日ごと表示 + ページネーション
// ---------------------------------------------------------

// 1) 全日付一覧（DESC）を取得
$dates = [];
try {
  $rs = $pdo->query("SELECT DISTINCT date FROM match_detail ORDER BY date DESC;");
  $dates = $rs ? $rs->fetchAll(PDO::FETCH_COLUMN, 0) : [];
} catch (Throwable $e) {
  $dates = [];
}

if (!$dates) {
  $currentDate = null;
} else {
  $reqDate = $_GET['date'] ?? '';
  if ($reqDate !== '' && in_array($reqDate, $dates, true)) {
    $currentDate = $reqDate; // 形式は問わず DB に存在する値なら採用
  } else {
    $currentDate = $dates[0]; // デフォルト：最新の日
  }
}

// 2) 前後の日付を算出
$prevDate = $nextDate = null;
if ($currentDate) {
  $idx = array_search($currentDate, $dates, true);
  if ($idx !== false) {
    if (isset($dates[$idx+1])) $prevDate = $dates[$idx+1]; // 1つ古い日
    if ($idx > 0)             $nextDate = $dates[$idx-1];  // 1つ新しい日
  }
}

// 3) 当日のゲーム集計（一覧）＋登録時刻（first_time）
$games = [];
if ($currentDate) {
  $sql = "
  SELECT
    md.date                                        AS gdate,
    md.game_id                                     AS game_id,
    s.name                                         AS shop_name,
    r.name                                         AS rule_name,
    p1.name                                        AS p1_name,
    p2.name                                        AS p2_name,
    -- スコア（rule_score.point × 有効時のみ side_multiplier_value）
    SUM(CASE WHEN md.assigned = 1 THEN (COALESCE(rs.point,1) *
        CASE WHEN md.multiplier > 1 AND COALESCE(rm.side_multiplier_enabled,0)=1
          THEN COALESCE(rm.side_multiplier_value,2) ELSE 1 END) ELSE 0 END) AS p1_score,
    SUM(CASE WHEN md.assigned = 2 THEN (COALESCE(rs.point,1) *
        CASE WHEN md.multiplier > 1 AND COALESCE(rm.side_multiplier_enabled,0)=1
          THEN COALESCE(rm.side_multiplier_value,2) ELSE 1 END) ELSE 0 END) AS p2_score,
    MAX(CASE WHEN md.ball_number = 9 AND COALESCE(md.ace,0)=1 AND md.assigned=1 THEN 1 ELSE 0 END) AS ace_p1,
    MAX(CASE WHEN md.ball_number = 9 AND COALESCE(md.ace,0)=1 AND md.assigned=2 THEN 1 ELSE 0 END) AS ace_p2,
    MIN(COALESCE(md.created_at, CONCAT(md.date,' 00:00:00'))) AS first_time
  FROM match_detail md
  LEFT JOIN shop_master  s  ON s.id  = md.shop_id
  LEFT JOIN rule_master  r  ON r.id  = md.rule_id
  LEFT JOIN rule_master  rm ON rm.id = md.rule_id
  LEFT JOIN rule_score   rs ON rs.rule_id = md.rule_id AND rs.ball_number = md.ball_number
  LEFT JOIN player_master p1 ON p1.id = md.player1_id
  LEFT JOIN player_master p2 ON p2.id = md.player2_id
  WHERE md.date = :d
  GROUP BY md.date, md.game_id, s.name, r.name, p1.name, p2.name
  ORDER BY first_time ASC;
  ";
  try {
    $st = $pdo->prepare($sql);
    $st->execute([':d'=>$currentDate]);
    $games = $st->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    $games = [];
  }
}
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Results</title>
<link rel="stylesheet" href="/assets/results.css?v=<?= h(v_asset('assets/results.css')) ?>">

<style>
/* ===== モーダル（ページ専用の最小スタイル） ===== */
.res-modal-backdrop{ position:fixed; inset:0; background:rgba(0,0,0,.5); display:none; z-index:1000; }
.res-modal{
  position:fixed; z-index:1001; left:50%; top:50%; transform:translate(-50%,-50%);
  width:min(92vw, 980px); max-height:86vh; overflow:auto;
  background:#fff; border:1px solid #e5e7eb; border-radius:16px; box-shadow:0 10px 30px rgba(0,0,0,.25);
}
.res-modal-header{ display:flex; align-items:center; justify-content:space-between; padding:10px 12px; border-bottom:1px solid #eee; }
.res-modal-title{ font-weight:700; }
.res-modal-close{ appearance:none; border:0; background:#111827; color:#fff; height:32px; padding:0 10px; border-radius:999px; cursor:pointer; }
.res-modal-body{ padding:10px; overflow-x:auto; }

/* VS ボタン（カード中央） */
.res-vs-btn{
  display:inline-flex; align-items:center; justify-content:center; width:100%; height:100%;
  padding:0; background:#fafafa; border:1px dashed #cbd5e1; border-radius:8px; cursor:pointer; font-weight:700;
}

/* Topbar のボタン縦位置を揃える */
.res-topbar{ display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:8px; }
.res-actions{ display:flex; gap:8px; align-items:center; }
.res-pager{ display:flex; gap:8px; align-items:center; margin:0; }
.res-pager .res-btn[aria-disabled="true"]{ opacity:.5; pointer-events:none; }

/* —— モーダル内 9ボール表の収まり最適化（はみ出し防止） —— */
.res-modal .res-board{
  --res-label-col: 88px;
  --res-cell-size: 28px;
  --res-gap: 4px;
  --res-score-col: 52px;
}
@media (max-width: 600px){
  .res-modal .res-board{
    --res-label-col: 58px;
    --res-cell-size: 24px;
    --res-gap: 3px;
    --res-score-col: 40px;
  }
  .res-modal .res-row-label{ font-size:12px; padding-left:4px; }
  .res-modal .res-ball-head, .res-modal .res-score-head{ font-size:11px; }
  .res-modal .res-score-cell{ font-size:13px; }
}
@media (max-width: 380px){
  .res-modal .res-board{
    --res-label-col: 52px;
    --res-cell-size: 22px;
    --res-gap: 3px;
    --res-score-col: 38px;
  }
  .res-modal .res-row-label{ font-size:11.5px; }
}
</style>
</head>
<body>
<div class="res-container res-container--narrow">

  <!-- Topbar：左=Menu/日次サマリー、右=ページャ（縦位置揃え） -->
  <div class="res-topbar">
    <div class="res-actions">
      <a class="res-btn" href="/index.php" aria-label="Menuへ">Menu</a>
      <button class="res-btn" id="resStatsBtn" type="button">日次サマリー</button>
    </div>
    <div class="res-pager">
      <a class="res-btn" href="<?= $prevDate ? (h($_SERVER['PHP_SELF']).'?date='.h($prevDate)) : '#' ?>" aria-disabled="<?= $prevDate? 'false':'true' ?>">← 前の日</a>
      <a class="res-btn" href="<?= $nextDate ? (h($_SERVER['PHP_SELF']).'?date='.h($nextDate)) : '#' ?>" aria-disabled="<?= $nextDate? 'false':'true' ?>">次の日 →</a>
    </div>
  </div>

  <?php if (!$currentDate): ?>
    <p>データがありません。</p>
  <?php else: ?>
    <?php
      $total_rounds = count($games);
      $round = 1;
    ?>
    <section class="res-date-block">
      <div class="res-date-header">
        <div class="res-date-title"><?= h($currentDate) ?></div>
        <div class="res-date-sub">対戦数: <?= (int)$total_rounds ?></div>
      </div>

      <div class="res-cards">
        <?php foreach ($games as $g): ?>
          <?php
            $p1    = $g['p1_name'] ?? 'Player1';
            $p2    = $g['p2_name'] ?? 'Player2';
            $s1    = (int)($g['p1_score'] ?? 0);
            $s2    = (int)($g['p2_score'] ?? 0);
            $shop  = $g['shop_name'] ?: '';
            $rule  = $g['rule_name'] ?: '';
            $ace_p1= (int)($g['ace_p1'] ?? 0) === 1;
            $ace_p2= (int)($g['ace_p2'] ?? 0) === 1;
            $gid   = (string)$g['game_id'];
            $gdate = (string)$g['gdate'];
            $ftime = $g['first_time'] ?? null;
            $ftime_disp = $ftime ? date('H:i', strtotime($ftime)) : '';
          ?>
          <article class="res-card">
            <div class="res-card-head">
              <span class="res-round">Round-<?= $round ?></span>
              <div class="res-meta">
                <?php if ($rule !== ''): ?><div>Rule: <?= h($rule) ?></div><?php endif; ?>
                <?php if ($shop !== ''): ?><div>Shop: <?= h($shop) ?></div><?php endif; ?>
                <?php if ($ftime_disp !== ''): ?><div>登録: <?= h($ftime_disp) ?></div><?php endif; ?>
              </div>
            </div>

            <div class="res-players">
              <div class="res-player res-player--p1">
                <div class="res-name"><?= h($p1) ?></div>
                <div class="res-score"><?= $s1 ?></div>
                <?php if ($ace_p1): ?><div class="res-ace" title="ACE (9-Ball)">ACE</div><?php endif; ?>
              </div>

              <!-- VS ボタン：クリックでモーダル -->
              <div class="res-vs">
                <button class="res-vs-btn" data-date="<?= h($gdate) ?>" data-gid="<?= h($gid) ?>">VS</button>
              </div>

              <div class="res-player res-player--p2">
                <div class="res-name"><?= h($p2) ?></div>
                <div class="res-score"><?= $s2 ?></div>
                <?php if ($ace_p2): ?><div class="res-ace" title="ACE (9-Ball)">ACE</div><?php endif; ?>
              </div>
            </div>
          </article>
          <?php $round++; ?>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

</div>

<!-- モーダル（詳細・日次サマリー共用） -->
<div class="res-modal-backdrop" id="resModalBg"></div>
<div class="res-modal" id="resModal" style="display:none;">
  <div class="res-modal-header">
    <div class="res-modal-title" id="resModalTitle">モーダル</div>
    <button class="res-modal-close" id="resModalClose" type="button">閉じる</button>
  </div>
  <div class="res-modal-body" id="resModalBody">読み込み中...</div>
</div>

<script>
(() => {
  const $  = (s, r=document) => r.querySelector(s);
  const $$ = (s, r=document) => Array.from(r.querySelectorAll(s));

  const ENDPOINT     = '<?= h($_SERVER['PHP_SELF']) ?>'; // 取得先は常にこのスクリプト
  const CURRENT_DATE = '<?= h($currentDate ?? "") ?>';

  const modal     = $('#resModal');
  const modalBg   = $('#resModalBg');
  const modalBody = $('#resModalBody');
  const modalTit  = $('#resModalTitle');
  const closeBtn  = $('#resModalClose');

  function openModal(title) {
    if (title) modalTit.textContent = title;
    modal.style.display = 'block';
    modalBg.style.display = 'block';
    document.body.style.overflow = 'hidden';
  }
  function closeModal() {
    modal.style.display = 'none';
    modalBg.style.display = 'none';
    document.body.style.overflow = '';
    modalBody.innerHTML = '';
  }
  modalBg.addEventListener('click', closeModal);
  closeBtn.addEventListener('click', closeModal);
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeModal(); });

  // VSボタン → ゲーム詳細
  $$('.res-vs-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
      const date = btn.getAttribute('data-date');
      const gid  = btn.getAttribute('data-gid');
      if (!date || !gid) return;

      openModal('ゲーム詳細');
      modalBody.innerHTML = '<div style="padding:12px;">読み込み中...</div>';

      try {
        const qs  = new URLSearchParams({ ajax: '1', date, gid });
        const res = await fetch(ENDPOINT + '?' + qs.toString(), { method: 'GET', headers: { 'X-Requested-With': 'fetch' } });
        if (!res.ok) {
          const txt = await res.text();
          throw new Error('HTTP ' + res.status + ' ' + res.statusText + ' : ' + txt);
        }
        const html = await res.text();
        modalBody.innerHTML = html;
      } catch (err) {
        console.error(err);
        modalBody.innerHTML = '<div style="padding:12px;color:#b91c1c;">読み込みに失敗しました。<br><small>' +
          (err?.message || err) + '</small></div>';
      }
    });
  });

  // 日次サマリーボタン
  $('#resStatsBtn')?.addEventListener('click', async () => {
    if (!CURRENT_DATE) return;
    openModal('日次サマリー');
    modalBody.innerHTML = '<div style="padding:12px;">集計中...</div>';

    try {
      const qs  = new URLSearchParams({ stats: '1', date: CURRENT_DATE });
      const res = await fetch(ENDPOINT + '?' + qs.toString(), { method: 'GET', headers: { 'X-Requested-With': 'fetch' } });
      if (!res.ok) {
        const txt = await res.text();
        throw new Error('HTTP ' + res.status + ' ' + res.statusText + ' : ' + txt);
      }
      const html = await res.text();
      modalBody.innerHTML = html;
    } catch (err) {
      console.error(err);
      modalBody.innerHTML = '<div style="padding:12px;color:#b91c1c;">読み込みに失敗しました。<br><small>' +
        (err?.message || err) + '</small></div>';
    }
  });
})();
</script>
</body>
</html>
