<?php
// /results_detail.php
// 日別の詳細グリッド（P1/P2 × 9ボール + 得点列）
// 追加：設定ボタン＆メニュー（表示切替）、Round⇄game_id 切替、非得点ボールは無色表示
// 500対策：mbstring存在チェック、配列初期化の厳密化、DB例外キャッチ

declare(strict_types=1);
header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/sys/db_connect.php';

// mbstring が無い環境での致命回避
if (function_exists('mb_internal_encoding')) {
  mb_internal_encoding('UTF-8');
}

// アセット版管理（?v=）
function v_asset(string $relPath): string {
  $p = __DIR__ . '/' . ltrim($relPath, '/');
  $t = @filemtime($p);
  return $t ? (string)$t : '1';
}

// 丸数字（1〜9）
function res_circled_num(int $n): string {
  static $map = [1=>'①',2=>'②',3=>'③',4=>'④',5=>'⑤',6=>'⑥',7=>'⑦',8=>'⑧',9=>'⑨'];
  return $map[$n] ?? (string)$n;
}

// データ取得（ACE対応 + 例外安全）
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
ORDER BY md.date DESC, md.game_id, md.ball_number;
";

$rows = [];
$dbError = null;
try {
  $stmt = $pdo->query($sql);
  if ($stmt !== false) {
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  }
} catch (Throwable $e) {
  $dbError = $e->getMessage();
}

// 構造化：$byDate[date][game_id] = ['meta'=>.., 'balls'=>.., 'ace9'=>0/1]
$byDate = [];
foreach ($rows as $r) {
  $date = $r['date'];
  $gid  = $r['game_id'];

  // 次元を必ず初期化（未初期化アクセスの致命回避）
  if (!isset($byDate[$date])) {
    $byDate[$date] = [];
  }
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
      'ace9'  => 0,
    ];
  }

  $bn = (int)$r['ball_number'];
  if ($bn >= 1 && $bn <= 9) {
    $byDate[$date][$gid]['balls'][$bn] = [
      'assigned'   => is_null($r['assigned']) ? null : (int)$r['assigned'],       // 1=P1, 2=P2
      'multiplier' => is_null($r['multiplier']) ? 1 : max(1, (int)$r['multiplier']),
      'base_point' => max(0, (int)$r['base_point']),
    ];
  }
  if ($bn === 9 && (int)$r['ace'] === 1) {
    $byDate[$date][$gid]['ace9'] = 1;
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
<style>
/* 設定カードの簡易レイアウト（既存 .res-card を流用） */
.res-settings .row{display:flex;flex-wrap:wrap;gap:12px;align-items:center}
.res-settings label{display:inline-flex;gap:6px;align-items:center;font-size:14px}
.res-settings .title{font-weight:700;margin-bottom:6px}
.res-topbar{justify-content:space-between}
.res-icon{height:20px;width:20px;display:inline-block;vertical-align:-4px}
</style>
</head>
<body>
  <div class="res-container">
    <!-- Topbar：Menu / 設定 -->
    <div class="res-topbar">
      <a class="res-btn" href="/index.php" aria-label="Menuへ">Menu</a>
      <button class="res-btn" id="res-cfg-toggle" type="button" aria-label="設定">
        <img class="res-icon" src="/images/btn_config.png" alt=""> 設定
      </button>
    </div>

    <!-- 設定メニュー（トグル表示） -->
    <section class="res-card res-settings" id="res-settings" style="display:none;">
      <div class="title">表示設定</div>
      <div class="row" style="margin-bottom:2px">
        <span style="font-size:13px;color:#666;">ゲームの回数表示：</span>
        <label><input type="radio" name="roundMode" value="round">Round（Round-1,2,...）</label>
        <label><input type="radio" name="roundMode" value="game_id">game_id（Game: XXXXX）</label>
      </div>
    </section>

    <h1 style="font-size:20px;margin:12px 0 14px;">日別戦績</h1>

    <?php
      // デバッグ用（?debug=1 で例外メッセージを表示）
      if (isset($_GET['debug']) && $_GET['debug'] === '1' && $dbError) {
        echo '<pre style="color:#b91c1c;background:#fee2e2;padding:8px;border-radius:8px;white-space:pre-wrap">';
        echo 'DB Error: ' . htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8');
        echo '</pre>';
      }
    ?>

    <?php if (empty($byDate)): ?>
      <p style="color:#666;">データがありません。</p>
    <?php else: ?>
      <?php foreach ($byDate as $date => $games): ?>
        <?php $roundNo = 1; $totalRounds = count($games); ?>
        <section class="res-date-block">
          <div class="res-date-header">
            <div class="res-date-title"><?= htmlspecialchars($date, ENT_QUOTES, 'UTF-8') ?></div>
            <div class="res-date-sub">Rounds: <?= (int)$totalRounds ?></div>
          </div>

          <?php foreach ($games as $gid => $game): ?>
            <?php
              $m     = $game['meta'];
              $balls = $game['balls'];

              $p1score = 0; $p2score = 0;
              $useMul = ((int)$m['side_multiplier_enabled'] === 1);
              $mulVal = max(1, (int)$m['side_multiplier_value']);

              $p1Cells = []; $p2Cells = [];
              for ($bn = 1; $bn <= 9; $bn++) {
                $a    = $balls[$bn]['assigned']   ?? null;
                $mul  = $balls[$bn]['multiplier'] ?? 1;
                $base = $balls[$bn]['base_point'] ?? 1;

                $isSide       = ($mul > 1);
                $effectiveMul = ($useMul && $isSide) ? $mulVal : 1;
                $point        = (int)$base * (int)$effectiveMul; // ルール上の得点（0なら非得点）

                // スコア加算（point=0 は加算されない）
                if     ($a === 1) $p1score += $point;
                elseif ($a === 2) $p2score += $point;

                $mark = $a ? res_circled_num($bn) : '・';

                // 非得点（point=0）は塗色なし（無色）。得点時のみオーナ色を付与
                $ownedP1 = ($a === 1 && $point > 0) ? ($isSide ? 'p1-side' : 'p1') : '';
                $ownedP2 = ($a === 2 && $point > 0) ? ($isSide ? 'p2-side' : 'p2') : '';

                $p1Cells[$bn] = [
                  'text'  => ($a === 1) ? $mark : '・',
                  'owned' => $ownedP1
                ];
                $p2Cells[$bn] = [
                  'text'  => ($a === 2) ? $mark : '・',
                  'owned' => $ownedP2
                ];
              }
              $hasAce = (int)($game['ace9'] ?? 0) === 1;
            ?>
            <article class="res-game-card">
              <div class="res-board-meta">
                <!-- Round / game_id 切替用（両方出してJSで表示制御） -->
                <span class="res-meta-id" data-roundmode>
                  <span class="mode--round">Round-<?= $roundNo ?></span>
                  <span class="mode--gid" style="display:none;">Game: <?= htmlspecialchars($m['game_id'], ENT_QUOTES, 'UTF-8') ?></span>
                </span>
                <?php if (!empty($m['shop_name'])): ?>
                  <span>店：<?= htmlspecialchars($m['shop_name'], ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
                <?php if (!empty($m['rule_name'])): ?>
                  <span>ルール：<?= htmlspecialchars($m['rule_name'], ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
                <!-- 幅節約のため P1/P2 表記は削除して「名前のみ」を表示 -->
                <span style="margin-left:auto"><?= htmlspecialchars($m['player1_name'], ENT_QUOTES, 'UTF-8') ?> / <?= htmlspecialchars($m['player2_name'], ENT_QUOTES, 'UTF-8') ?></span>
              </div>

              <div class="res-board">
                <div class="res-board-spacer"></div>
                <?php for ($bn = 1; $bn <= 9; $bn++): ?>
                  <div class="res-ball-head"><?= $bn ?></div>
                <?php endfor; ?>
                <div class="res-score-head">得点</div>

                <!-- 上段：P1（ラベルは名前のみ） -->
                <div class="res-row-label"><?= htmlspecialchars($m['player1_name'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php for ($bn = 1; $bn <= 9; $bn++): ?>
                  <div class="res-cell<?= $p1Cells[$bn]['owned'] ? '" data-owned="'.$p1Cells[$bn]['owned'].'"' : '"' ?> data-bn="<?= $bn ?>">
                    <?= htmlspecialchars($p1Cells[$bn]['text'], ENT_QUOTES, 'UTF-8') ?>
                    <?php if ($bn===9 && $hasAce && $p1Cells[$bn]['owned']): ?><span class="res-ace">ACE</span><?php endif; ?>
                  </div>
                <?php endfor; ?>
                <div class="res-score-cell" data-side="p1"><?= (int)$p1score ?></div>

                <!-- 下段：P2（ラベルは名前のみ） -->
                <div class="res-row-label"><?= htmlspecialchars($m['player2_name'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php for ($bn = 1; $bn <= 9; $bn++): ?>
                  <div class="res-cell<?= $p2Cells[$bn]['owned'] ? '" data-owned="'.$p2Cells[$bn]['owned'].'"' : '"' ?> data-bn="<?= $bn ?>">
                    <?= htmlspecialchars($p2Cells[$bn]['text'], ENT_QUOTES, 'UTF-8') ?>
                    <?php if ($bn===9 && $hasAce && $p2Cells[$bn]['owned']): ?><span class="res-ace">ACE</span><?php endif; ?>
                  </div>
                <?php endfor; ?>
                <div class="res-score-cell" data-side="p2"><?= (int)$p2score ?></div>
              </div>
            </article>
            <?php $roundNo++; ?>
          <?php endforeach; ?>
        </section>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

<script>
(() => {
  const settingsEl = document.getElementById('res-settings');
  const toggleBtn  = document.getElementById('res-cfg-toggle');
  const OPEN_KEY   = 'resultsDetail_cfgOpen';
  const MODE_KEY   = 'resultsDetail_roundMode';

  // 設定メニューの開閉
  function setOpen(open){
    if (!settingsEl) return;
    settingsEl.style.display = open ? 'block' : 'none';
    try { localStorage.setItem(OPEN_KEY, open ? '1' : '0'); } catch(e){}
  }
  toggleBtn?.addEventListener('click', () => {
    const open = settingsEl && settingsEl.style.display !== 'block';
    setOpen(!!open);
  });
  // 前回状態を復元
  try { setOpen(localStorage.getItem(OPEN_KEY) === '1'); } catch(e){}

  // Round / game_id 表示切替
  function applyRoundMode(mode){
    document.querySelectorAll('[data-roundmode]').forEach(n => {
      const r = n.querySelector('.mode--round');
      const g = n.querySelector('.mode--gid');
      if (mode === 'game_id') {
        if (r) r.style.display = 'none';
        if (g) g.style.display = '';
      } else {
        if (r) r.style.display = '';
        if (g) g.style.display = 'none';
      }
    });
  }
  // 保存値の復元と監視
  const radios = document.querySelectorAll('input[name="roundMode"]');
  let saved = 'round';
  try { saved = localStorage.getItem(MODE_KEY) || 'round'; } catch(e){}
  applyRoundMode(saved);
  radios.forEach(r => {
    r.checked = (r.value === saved);
    r.addEventListener('change', () => {
      const mode = r.value;
      try { localStorage.setItem(MODE_KEY, mode); } catch(e){}
      applyRoundMode(mode);
    });
  });
})();
</script>
</body>
</html>
