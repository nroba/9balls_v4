<?php
// /index.php（自動版）— filemtimeで ?v= を自動付与
// v(): 単一ファイルの更新時刻、v_multi(): 複数ファイルの最大更新時刻
function v(string $relPath): string {
  $p = __DIR__ . '/' . ltrim($relPath, '/');
  $t = @filemtime($p);
  return $t ? (string)$t : '1';
}
function v_multi(array $paths): string {
  $max = 0;
  foreach ($paths as $rel) {
    $t = @filemtime(__DIR__ . '/' . ltrim($rel, '/'));
    if ($t && $t > $max) $max = $t;
  }
  return $max ? (string)$max : '1';
}

// Pocketmode一式（index/css/js）の最新版時刻をリンクに付与
$POCKET_VER = v_multi([
  'pocketmode/index.php',
  'pocketmode/assets/pocketmode.css',
  'pocketmode/assets/pocketmode.js',
]);

$menuCssVer = v('assets/menu.css');
$year = date('Y');
$menuVerText = htmlspecialchars($_GET['v'] ?? 'menu', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <title>9Balls メニュー</title>
  <meta name="description" content="9Balls メニュー | スコア記録と集計をすばやく。">
  <meta name="theme-color" content="#0d6efd">

  <!-- Favicon / アイコン（サイト・ホーム画面共通で流用可能なPNGを配置） -->
  <link rel="icon" href="/images/icon-192.png" sizes="192x192">
  <link rel="icon" href="/images/icon-512.png" sizes="512x512">
  <link rel="apple-touch-icon" href="/images/icon-180.png" sizes="180x180">

  <!-- PWA（ルートスコープ） -->
  <link rel="manifest" href="/manifest.json">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="9balls">

  <!-- Bootstrap（CSSのみ） -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

  <!-- メニューCSSに ?v=更新時刻 を付与 -->
  <link href="/assets/menu.css?v=<?= $menuCssVer ?>" rel="stylesheet">
  <style>
    /* 念のためのベース（menu.cssがあれば最小限） */
    body { background: #0f1320; color: #e9eef7; }
    .menu-card {
      max-width: 720px; margin: 4rem auto; background: #12182a;
      border-radius: 16px; padding: 1.75rem; box-shadow: 0 10px 28px rgba(0,0,0,.35);
    }
    .brand { display:flex; gap:1rem; align-items:center; }
    .brand img { width:56px; height:56px; border-radius: 12px; }
    .menu-grid { display:grid; gap: .75rem; grid-template-columns: 1fr; }
    @media (min-width: 560px){ .menu-grid { grid-template-columns: 1fr 1fr; } }
    .menu-btn { display:flex; gap:.5rem; align-items:center; justify-content:center; padding:1.1rem; border-radius: .9rem; font-weight:600; }
    .menu-icon { font-size: 1.2rem; }
    .footer-note { color:#9fb0d6; }
  </style>
</head>
<body>
  <main class="menu-card">
    <header class="mb-4">
      <div class="brand">
        <img src="/images/icon-192.png" alt="9Balls">
        <div>
          <h1 class="h4 mb-0">9Balls メニュー</h1>
          <p class="lead mb-0">スコア記録と集計をすばやく。</p>
        </div>
      </div>
    </header>

    <section class="menu-grid mb-3">
      <!-- 日別まとめ（一覧/集計） -->
      <a href="/match_results/match_results.php" class="btn btn-info text-white menu-btn">
        <span class="menu-icon">📊</span>
        <span>日別まとめの閲覧</span>
      </a>

      <!-- Pocketmode（自動で ?v= を付与） -->
      <a href="/pocketmode/index.php?v=<?= $POCKET_VER ?>" class="btn btn-success text-white menu-btn">
        <span class="menu-icon">🎯</span>
        <span>Pocketmode（1ゲーム記録）</span>
      </a>

      <!-- 日別登録 -->
      <a href="/daily/daily.php" class="btn btn-primary text-white menu-btn">
        <span class="menu-icon">📝</span>
        <span>日別登録フォーム</span>
      </a>

      <!-- マスタ設定：settings.php か admin/masters.php を運用に合わせて -->
      <a href="/settings.php" class="btn btn-secondary text-white menu-btn">
        <span class="menu-icon">⚙</span>
        <span>各種マスタ設定</span>
      </a>
    </section>

    <footer class="d-flex justify-content-between align-items-center mt-2">
      <small class="footer-note">© <?= $year ?> 9balls</small>
      <small class="footer-note">v<?= $menuVerText ?></small>
    </footer>
  </main>

  <!-- PWA用（存在する時だけ登録）。スコープをサイト全体にするため絶対パス推奨 -->
  <script>
    if ('serviceWorker' in navigator) {
      window.addEventListener('load', () => {
        navigator.serviceWorker.register('/service-worker.js').catch(()=>{});
      });
    }
  </script>
</body>
</html>
