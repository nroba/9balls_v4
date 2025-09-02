<?php
// /index.php — Top Menu aligned with results.css tone
function v(string $relPath): string {
  $p = __DIR__ . '/' . ltrim($relPath, '/');
  $t = @filemtime($p);
  return $t ? (string)$t : '1';
}
$year = date('Y');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>9Balls • Top Menu</title>
  <meta name="description" content="9Balls トップメニュー" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="/assets/style.css?v=<?= v('assets/style.css') ?>">
</head>
<body>
  <div class="wrap">
    <header class="site-header">
      <div class="brand">
        <img class="crest-img" src="/images/icon-main_roba.png" alt="9Balls" width="46" height="46">
        <div class="titles">
          <h1 class="heading">9Balls</h1>
          <p class="tagline">Billiards Match Manager</p>
        </div>
      </div>
    </header>

    <main>
      <section class="panel">
        <div class="panel-head">
          <div class="panel-title">Menu</div>
        </div>

        <nav class="menu">
          <!-- PocketMode -->
          <a class="btn-card" href="/pocketmode/index.php">
            <div class="btn-body">
              <div class="btn-leading" aria-hidden="true">🎯</div>
              <div class="btn-text">
                <div class="btn-title">PocketMode</div>
                <div class="btn-sub">1ゲームごとの記録画面</div>
              </div>
              <div class="btn-trail">›</div>
            </div>
          </a>

          <!-- Result (一覧) -->
          <a class="btn-card" href="/results.php">
            <div class="btn-body">
              <div class="btn-leading" aria-hidden="true">📊</div>
              <div class="btn-text">
                <div class="btn-title">Results</div>
                <div class="btn-sub">リザルト</div>
              </div>
              <div class="btn-trail">›</div>
            </div>
          </a>

          <!-- Result Detail（詳細） -->
          <a class="btn-card" href="/results_detail.php">
            <div class="btn-body">
              <div class="btn-leading" aria-hidden="true">🔎</div>
              <div class="btn-text">
                <div class="btn-title">Result Detail</div>
                <div class="btn-sub">リザルト（詳細）</div>
              </div>
              <div class="btn-trail">›</div>
            </div>
          </a>

          <!-- Settings -->
          <a class="btn-card" href="/settings.php">
            <div class="btn-body">
              <div class="btn-leading" aria-hidden="true">
                <img class="btn-icon" src="/images/btn_config.png" alt="">
              </div>
              <div class="btn-text">
                <div class="btn-title">Settings</div>
                <div class="btn-sub">ユーザー・店舗マスタなど</div>
              </div>
              <div class="btn-trail">›</div>
            </div>
          </a>
        </nav>
      </section>
    </main>

    <footer class="site-footer">
      <span>© <?= $year ?> Noraroba</span>
      <span class="sep">•</span>
    </footer>
  </div>
</body>
</html>
