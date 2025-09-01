<?php
// /results.php
// 日別カード一覧：日付ごとに Round-1..、ACEバッジ、説明行なし、スマホ最適化（CSSは /assets/results.css に統合）

declare(strict_types=1);
header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/sys/db_connect.php';

// アセット版管理 (?v=)
function v_asset(string $relPath): string {
  $p = __DIR__ . '/' . ltrim($relPath, '/');
  $t = @filemtime($p);
  return $t ? (string)$t : '1';
}

// 集計：1ゲーム=game_id 単位に P1/P2スコア、9番ACE有無/どちらがACEか
$sql = <<<SQL
SELECT
  md.date                                        AS gdate,
  md.game_id                                     AS game_id,
  md.shop_id,
  s.name                                         AS shop_name,
  md.rule_id,
  r.name                                         AS rule_name,
  md.player1_id,
  p1.name                                        AS p1_name,
  md.player2_id,
  p2.name                                        AS p2_name,
  SUM(CASE WHEN md.assigned = 1 THEN COALESCE(md.multiplier,1) ELSE 0 END) AS p1_score,
  SUM(CASE WHEN md.assigned = 2 THEN COALESCE(md.multiplier,1) ELSE 0 END) AS p2_score,
  MAX(CASE WHEN md.ball_number = 9 AND COALESCE(md.ace,0)=1 THEN 1 ELSE 0 END)           AS has_ace9,
  MAX(CASE WHEN md.ball_number = 9 AND COALESCE(md.ace,0)=1 AND md.assigned=1 THEN 1 ELSE 0 END) AS ace_p1,
  MAX(CASE WHEN md.ball_number = 9 AND COALESCE(md.ace,0)=1 AND md.assigned=2 THEN 1 ELSE 0 END) AS ace_p2,
  MIN(COALESCE(md.created_at, CONCAT(md.date,' 00:00:00'))) AS first_time
FROM match_detail md
LEFT JOIN shop_master  s  ON s.id  = md.shop_id
LEFT JOIN rule_master  r  ON r.id  = md.rule_id
LEFT JOIN player_master p1 ON p1.id = md.player1_id
LEFT JOIN player_master p2 ON p2.id = md.player2_id
GROUP BY md.date, md.game_id, md.shop_id, s.name, md.rule_id, r.name, md.player1_id, p1.name, md.player2_id, p2.name
ORDER BY md.date DESC, first_time ASC
SQL;

$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 日付ごとにまとめ
$grouped = [];
foreach ($rows as $row) {
  $d = $row['gdate'];
  if (!isset($grouped[$d])) $grouped[$d] = [];
  $grouped[$d][] = $row;
}
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Results</title>
<link rel="stylesheet" href="/assets/results.css?v=<?= htmlspecialchars(v_asset('assets/results.css'), ENT_QUOTES, 'UTF-8') ?>">
</head>
<body>
<div class="container">

<?php if (empty($grouped)): ?>
  <p>データがありません。</p>
<?php else: ?>
  <?php foreach ($grouped as $date => $games): ?>
    <?php
      $total_rounds = count($games);
      $round = 1;
      // first_time で安定表示
      usort($games, fn($a,$b)=>strcmp($a['first_time']??'', $b['first_time']??''));
    ?>
    <section class="date-section">
      <div class="date-header">
        <div><?= htmlspecialchars($date, ENT_QUOTES, 'UTF-8') ?></div>
        <div class="date-sub">対戦数: <?= (int)$total_rounds ?></div>
      </div>

      <div class="cards">
        <?php foreach ($games as $g): ?>
          <?php
            $p1  = $g['p1_name'] ?? 'Player1';
            $p2  = $g['p2_name'] ?? 'Player2';
            $s1  = (int)($g['p1_score'] ?? 0);
            $s2  = (int)($g['p2_score'] ?? 0);
            $shop = $g['shop_name'] ?: '';
            $rule = $g['rule_name'] ?: '';
            $ace_p1 = (int)($g['ace_p1'] ?? 0) === 1;
            $ace_p2 = (int)($g['ace_p2'] ?? 0) === 1;
          ?>
          <article class="card">
            <div class="card-head">
              <span class="round-tag">Round-<?= $round ?></span>
              <div class="meta">
                <?php if ($rule !== ''): ?><div>Rule: <?= htmlspecialchars($rule, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                <?php if ($shop !== ''): ?><div>Shop: <?= htmlspecialchars($shop, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
              </div>
            </div>

            <div class="players">
              <div class="player p1">
                <div class="name"><?= htmlspecialchars($p1, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="score"><?= $s1 ?></div>
                <?php if ($ace_p1): ?><div class="ace-badge" title="ACE (9-Ball)">ACE</div><?php endif; ?>
              </div>

              <div class="vs"><span>VS</span></div>

              <div class="player p2">
                <div class="name"><?= htmlspecialchars($p2, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="score"><?= $s2 ?></div>
                <?php if ($ace_p2): ?><div class="ace-badge" title="ACE (9-Ball)">ACE</div><?php endif; ?>
              </div>
            </div>
          </article>
          <?php $round++; ?>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endforeach; ?>
<?php endif; ?>

</div>
</body>
</html>
