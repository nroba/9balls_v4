<?php // /index.php (menu) ?>
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

  <!-- 分離したCSS -->
  <link href="assets/menu.css?v=20250826" rel="stylesheet">
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
      <a href="match_results/match_results.php" class="btn btn-info text-white menu-btn">
        <span class="menu-icon">📊</span>
        <span>日別まとめの閲覧</span>
      </a>

      <a href="pocketmode/index.php" class="btn btn-success text-white menu-btn">
        <span class="menu-icon">🎯</span>
        <span>Pocketmode（1ゲーム記録）</span>
      </a>

      <a href="daily/daily.php" class="btn btn-primary text-white menu-btn">
        <span class="menu-icon">📝</span>
        <span>日別登録フォーム</span>
      </a>

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
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.register('service-worker.js').catch(()=>{});
    }
  </script>
</body>
</html>
