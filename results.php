<?php
// /results.php
// 日別の戦績表示（1対戦ごとに上下2段：P1/P2、玉9グリッド＋右端に得点）
// 表示：通常色＝P1青/P2橙、サイド（multiplier>1）は濃色で表示
// 得点ロジック：rule_score.point × （サイド倍率を使うルールなら side_multiplier_value、使わないなら1）
// 依存：/sys/db_connect.php が $pdo (PDO) を提供

declare(strict_types=1);
mb_internal_encoding('UTF-8');

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/sys/db_connect.php';

// アセット更新検知（?v=timestamp）
function v_asset(string $relPath): string {
  $p = __DIR__ . '/' . ltrim($relPath, '/');
  $t = @filemtime($p);
  return $t ? (string)$t : '1';
}

// 丸数字（1〜9）
function circled_num(int $n): string {
  static $map = [1=>'①',2=>'②',3=>'③',4=>'④',5=>'⑤',6=>'⑥',7=>'⑦',8=>'⑧',9=>'⑨'];
  return $map[$n] ?? (string)$n;
}

// データ取得（date → game_id → ball_number）
// - ルール名、サイド倍率使用可否/倍率値（rule_master）
// - 玉ごとの基点（rule_score.point）
// - multiplier は「2=サイド」を想定（Aで有効、Bは見た目だけ or 常に1）
$sql = "
SELECT
  md.date,
  md.game_id,
  md.player1_id,
  pm1.name AS player1_name,
  md.player2_id,
  pm2.name AS player2_name,
  md.shop_id,
  sm.name  AS shop_name,
  md.rule_id,
  rm.name  AS rule_name,
  COALESCE(rm.side_multiplier_enabled, 0) AS side_multiplier_enabled,
  COALESCE(rm.side_multiplier_value,   2) AS side_multiplier_value,
  md.ball_number,
  md.assigned,
  md.multiplier,
  COALESCE(rs.point, 1) AS base_point
FROM match_detail md
LEFT JOIN player_master pm1 ON pm1.id = md.player1_id
LEFT JOIN player_master pm2 ON pm2.id = md.player2_id
LEFT JOIN shop_master  sm  ON sm.id  = md.shop_id
LEFT JOIN rule_master  rm  ON rm.id  = md.rule_id
LEFT JOIN rule_score   rs  ON rs.rule_id = md.rule_id AND rs.ball_number = md.ball_number
ORDER BY md.date DESC, md.game_id, md.ball_number;
";

$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 構造化
// $byDate[date][game_id] = [
//   'meta' => [...],
//   'balls' => [1 => ['assigned'=>?, 'multiplier'=>?, 'base_point'=>?], ... 9=>...]
// ]
$byDate = [];
foreach ($rows as $r) {
  $date = $r['date'];
  $gid  = $r['game_id'];

  if (!isset($byDate[$date][$gid])) {
    $byDate[$date][$gid] = [
      'meta' => [
        'game_id'                 => $gid,
        'player1_id'              => (int)$r['player1_id'],
        'player1_name'            => $r['player1_name'] ?? 'Player1',
        'player2_id'              => (int)$r['player2_id'],
        'player2_name'            => $r['player2_name'] ?? 'Player2',
        'shop_name'               => $r['shop_name'] ?? '',
        'rule_id'                 => (int)$r['rule_id'],
        'rule_name'               => $r['rule_name'] ?? '',
        'side_multiplier_enabled' => (int)$r['side_multiplier_enabled'],
        'side_multiplier_value'   => max(1, (int)$r['side_multiplier_value']),
      ],
      'balls' => array_fill(1, 9, ['assigned'=>null, 'multiplier'=>1, 'base_point'=>1]),
    ];
  }

  $bn = (int)$r['ball_number'];
  if ($bn >= 1 && $bn <= 9) {
    $byDate[$date][$gid]['balls'][$bn] = [
      'assigned'   => is_null($r['assigned']) ? null : (int)$r['assigned'],              // 1: P1, 2: P2
      'multiplier' => is_null($r['multiplier']) ? 1 : max(1, (int)$r['multiplier']),     // 2: サイド
      'base_point' => max(0, (int)$r['base_point']),                                      // 基点（0も許容）
    ];
  }
}

?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>日別戦績 / 9balls</title>
<link rel="stylesheet" href="/assets/results.css?v=<?= htmlspecialchars(v_asset('assets/results.css'), ENT_QUOTES, 'UTF-8') ?>">
</head>
<body>
  <div class="res-container">
    <h1 class="res-h1">日別戦績</h1>

    <?php if (empty($byDate)): ?>
      <p class="res-empty">データがありません。</p>
    <?php else: ?>
      <?php foreach ($byDate as $date => $games): ?>
        <section class="res-date-block">
          <div class="res-date-title"><?= htmlspecialchars($date, ENT_QUOTES, 'UTF-8') ?></div>

          <?php foreach ($games as $gid => $game): ?>
            <?php
              $m = $game['meta'];
              $balls = $game['balls'];

              $p1Cells = [];
              $p2Cells = [];
              $p1Score = 0;
              $p2Score = 0;

              $useMul = ((int)$m['side_multiplier_enabled'] === 1);
              $mulVal = max(1, (int)$m['side_multiplier_value']);

              for ($bn = 1; $bn <= 9; $bn++) {
                $a    = $balls[$bn]['assigned']   ?? null;
                $mul  = $balls[$bn]['multiplier'] ?? 1;
                $base = $balls[$bn]['base_point'] ?? 1;

                // 見た目上の「サイド」判定（multiplier>1）
                $isSide = ($mul > 1);
                // 得点に効く倍率（Aは有効、Bは常に1）
                $effectiveMul = ($useMul && $isSide) ? $mulVal : 1;
                $point = (int)$base * (int)$effectiveMul;

                if ($a === 1) $p1Score += $point;
                if ($a === 2) $p2Score += $point;

                $mark = $a ? circled_num($bn) : '・';

                // P1セル
                $p1Cells[$bn] = [
                  'text'  => ($a === 1) ? $mark : '・',
                  'owned' => ($a === 1) ? ($isSide ? 'p1-side' : 'p1') : ''
                ];
                // P2セル
                $p2Cells[$bn] = [
                  'text'  => ($a === 2) ? $mark : '・',
                  'owned' => ($a === 2) ? ($isSide ? 'p2-side' : 'p2') : ''
                ];
              }
            ?>
            <article class="res-game-card">
              <div class="res-meta">
                <span class="gid">Game: <?= htmlspecialchars((string)$m['game_id'], ENT_QUOTES, 'UTF-8') ?></span>
                <?php if (!empty($m['shop_name'])): ?>
                  <span>店：<?= htmlspecialchars($m['shop_name'], ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
                <?php if (!empty($m['rule_name'])): ?>
                  <span>ルール：<?= htmlspecialchars($m['rule_name'], ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
              </div>

              <div class="res-board">
                <div class="res-spacer"></div>
                <?php for ($bn = 1; $bn <= 9; $bn++): ?>
                  <div class="ball-head"><?= $bn ?></div>
                <?php endfor; ?>
                <div class="score-head">得点</div>

                <!-- 上段：プレイヤー1 -->
                <div class="row-label">P1：<?= htmlspecialchars($m['player1_name'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php for ($bn = 1; $bn <= 9; $bn++): ?>
                  <div class="cell"<?= $p1Cells[$bn]['owned'] ? ' data-owned="'.$p1Cells[$bn]['owned'].'"' : '' ?>>
                    <?= htmlspecialchars($p1Cells[$bn]['text'], ENT_QUOTES, 'UTF-8') ?>
                  </div>
                <?php endfor; ?>
                <div class="score-cell" data-side="p1"><?= (int)$p1Score ?></div>

                <!-- 下段：プレイヤー2 -->
                <div class="row-label">P2：<?= htmlspecialchars($m['player2_name'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php for ($bn = 1; $bn <= 9; $bn++): ?>
                  <div class="cell"<?= $p2Cells[$bn]['owned'] ? ' data-owned="'.$p2Cells[$bn]['owned'].'"' : '' ?>>
                    <?= htmlspecialchars($p2Cells[$bn]['text'], ENT_QUOTES, 'UTF-8') ?>
                  </div>
                <?php endfor; ?>
                <div class="score-cell" data-side="p2"><?= (int)$p2Score ?></div>
              </div>

              <div class="res-legend">
                <span class="badge">青：P1（通常）</span>
                <span class="badge">橙：P2（通常）</span>
                <span class="badge strong">濃色＝サイド（multiplier&gt;1）</span>
                <span class="badge">得点＝ rule_score.point × （サイド倍率を使うルールなら倍率）</span>
              </div>
            </article>
          <?php endforeach; ?>
        </section>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</body>
</html>
