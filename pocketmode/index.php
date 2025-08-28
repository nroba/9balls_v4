<?php
// /pocketmode/index.php — CSS/JS を “絶対パス＋?v=更新時刻” で読み込む
function v_local(string $relPath): string {
  $p = __DIR__ . '/' . ltrim($relPath, '/');   // 実ファイルパス
  $t = @filemtime($p);
  return $t ? (string)$t : '1';                 // 失敗時は '1'
}
?>
<!doctype html>
<html lang="ja">
<head>
    <?php
  // SWの実体は /pocketmode/service-worker.js
  $SW_FILE = __DIR__ . '/service-worker.js';
  $sw_v = is_file($SW_FILE) ? filemtime($SW_FILE) : time();

  // 既に CSS/JS も filemtime() で ?v= を付けている前提ならそのままでOK
?>
<script>
  if ('serviceWorker' in navigator) {
    // 相対パスで登録 → scope は /pocketmode/ 配下に自動設定
    navigator.serviceWorker.register('service-worker.js?v=<?= $sw_v ?>', { scope: './' })
      .then(reg => console.log('[SW] registered:', reg.scope))
      .catch(err => console.warn('[SW] register failed:', err));
  }
</script>

  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Pocketmode | 9Balls</title>

  <!-- フォールバック最小CSS（外部CSSが読めない時も縦積みを防ぐ） -->
  <style>
    :root { --gap:12px; }
    body { margin:0; font-family:system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans JP", "Hiragino Kaku Gothic ProN", "Meiryo", sans-serif; background:#f7f7f9; color:#111; }
    .pm-header { display:flex; justify-content:space-between; align-items:center; padding:12px 16px; background:#fff; border-bottom:1px solid #e5e7eb; position:sticky; top:0; }
    .pm-title { margin:0; font-size:18px; font-weight:700; }
    .pm-settings-btn { padding:8px 12px; border-radius:10px; border:1px solid #d1d5db; background:#fff; cursor:pointer; }
    .pm-settings { background:#fff; padding:12px 16px; border-bottom:1px solid #e5e7eb; }
    .pm-setting-row { display:grid; grid-template-columns:88px 1fr; align-items:center; gap:10px; padding:6px 0; }
    .pm-scoreboard { display:grid; grid-template-columns:1fr 1fr; gap:var(--gap); padding:12px 16px; }
    .pm-player { display:flex; flex-direction:column; align-items:center; padding:8px 0; background:#fff; border:1px solid #e5e7eb; border-radius:12px; }
    .pm-player-name { font-size:14px; opacity:.8; }
    .pm-score { font-size:40px; font-weight:800; line-height:1; }
    .pm-grid { display:grid; grid-template-columns:repeat(3, minmax(86px, 1fr)); gap:var(--gap); padding:8px 16px 16px; }
    .ball-wrapper { position:relative; opacity:.5; transition:opacity .2s; }
    .ball { width:86px; height:86px; object-fit:contain; user-select:none; -webkit-user-drag:none; }
    .ball-multiplier { position:absolute; right:-6px; top:-6px; padding:2px 6px; font-weight:700; background:#0d6efd; color:#fff; border-radius:12px; display:none; }
    .pm-actions { display:flex; gap:12px; justify-content:center; padding:0 16px 24px; }
    .pm-btn { padding:12px 16px; border-radius:10px; border:1px solid #d1d5db; background:#fff; cursor:pointer; }
    .pm-btn-primary { background:#0d6efd; border-color:#0d6efd; color:#fff; }
    .pm-btn-outline { background:#fff; color:#111; }
    .pm-post { display:flex; gap:12px; justify-content:center; padding:8px 16px 24px; }
    .pm-popup { position:fixed; left:50%; top:50%; transform:translate(-50%,-50%); background:rgba(0,0,0,.85); color:#fff; padding:10px 16px; border-radius:12px; z-index:9999; }
  </style>

  <!-- 本来のCSS（絶対パス＋?v=） -->
  <link rel="stylesheet" href="/pocketmode/assets/pocketmode.css?v=<?= v_local('assets/pocketmode.css') ?>">
  <link rel="icon" href="/images/icon-192.png">
  <meta name="theme-color" content="#0d6efd">
</head>
<body>

  <!-- ヘッダー -->
  <header class="pm-header">
    <h1 class="pm-title">Pocketmode</h1>
    <button class="pm-settings-btn" type="button" onclick="toggleSettings()">⚙</button>
  </header>

  <!-- 試合設定 -->
  <section class="pm-settings" id="gameSettings" style="display:none">
    <div class="pm-setting-row">
      <label for="dateInput">日付</label>
      <input id="dateInput" type="date" value="">
    </div>

    <div class="pm-setting-row">
      <label for="ruleSelect">ルール</label>
      <select id="ruleSelect"></select>
    </div>

    <div class="pm-setting-row">
      <label for="shop">店舗</label>
      <select id="shop"></select>
    </div>

    <div class="pm-setting-row">
      <label for="player1">プレイヤー1</label>
      <select id="player1"></select>
    </div>

    <div class="pm-setting-row">
      <label for="player2">プレイヤー2</label>
      <select id="player2"></select>
    </div>
  </section>

  <!-- スコア -->
  <section class="pm-scoreboard">
    <div class="pm-player">
      <div class="pm-player-name" id="label1">Player 1</div>
      <div class="pm-score" id="score1">0</div>
    </div>
    <div class="pm-player">
      <div class="pm-player-name" id="label2">Player 2</div>
      <div class="pm-score" id="score2">0</div>
    </div>
  </section>

  <!-- ボール -->
  <section class="pm-grid" id="ballGrid"></section>

  <!-- 操作 -->
  <section class="pm-actions">
    <button id="resetBtn" class="pm-btn pm-btn-outline" type="button">リセット</button>
    <button id="registBtn" class="pm-btn pm-btn-primary" type="button">登録</button>
  </section>

  <!-- 登録後の操作 -->
  <section id="postRegistActions" class="pm-post" style="display:none">
    <button class="pm-btn pm-btn-outline" type="button" onclick="hideActions()">閉じる</button>
  </section>

  <!-- 中央ポップアップ -->
  <div id="popup" class="pm-popup" style="display:none"></div>

  <!-- 振る舞い（絶対パス＋?v=） -->
  <script src="/pocketmode/assets/pocketmode.js?v=<?= v_local('assets/pocketmode.js') ?>"></script>

  <script>
    // 日付の初期値（ページ読み込み時）
    (function initDate(){
      const el = document.getElementById('dateInput');
      if (el && !el.value) {
        const d = new Date();
        const z = (n)=>String(n).padStart(2,'0');
        el.value = `${d.getFullYear()}-${z(d.getMonth()+1)}-${z(d.getDate())}`;
      }
    })();

    // PWA（任意）
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.register('/service-worker.js').catch(()=>{});
    }
  </script>

  <noscript>このページを利用するには JavaScript を有効にしてください。</noscript>
</body>
</html>
