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
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>9Balls メニュー</title>

  <!-- PWA（任意） -->
  <link rel="manifest" href="manifest.json">
  <link rel="icon" href="images/icon-192.png">
  <meta name="theme-color" content="#0d6efd">

  <!-- Bootstrap -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

  <!-- メニューCSSに ?v=更新時刻 を付与 -->
  <link href="assets/menu.css?v=<?= v('assets/menu.css') ?>" rel="stylesheet">
</head>
<body>
  <main class="menu-card">
    <header class="mb-4">
      <div class="brand">
        <img src="images/icon-192.png" alt="9Balls">
        <div>
          <h1 class="h4 mb-0">9Balls メニュー</h1>
          <p class="lead mb-0">スコア記録と集計をすばやく。</p>
        </div>
      </div>
    </header>

    <section class="menu-grid mb-3">
      <!-- 日別まとめ（一覧/集計） -->
      <a href="match_results/match_results.php" class="btn btn-info text-white menu-btn">
        <span class="menu-icon">📊</span>
        <span>日別まとめの閲覧</span>
      </a>

      <!-- Pocketmode（自動で ?v= を付与） -->
      <a href="pocketmode/index.php?v=<?= $POCKET_VER ?>" class="btn btn-success text-white menu-btn">
        <span class="menu-icon">🎯</span>
        <span>Pocketmode（1ゲーム記録）</span>
      </a>

      <!-- 日別登録 -->
      <a href="daily/daily.php" class="btn btn-primary text-white menu-btn">
        <span class="menu-icon">📝</span>
        <span>日別登録フォーム</span>
      </a>

      <!-- マスタ設定：settings.php か admin/masters.php を運用に合わせて -->
      <a href="settings.php" class="btn btn-secondary text-white menu-btn">
        <span class="menu-icon">⚙</span>
        <span>各種マスタ設定</span>
      </a>
    </section>

    <footer class="d-flex justify-content-between align-items-center mt-2">
      <small class="footer-note">© <?= date('Y') ?> 9balls</small>
      <small class="footer-note">v<?= htmlspecialchars($_GET['v'] ?? 'menu') ?></small>
    </footer>
  </main>

  <script>
    // PWA用（存在する時だけ登録）
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.register('service-worker.js').catch(()=>{});
    }
  </script>
</body>
</html>
