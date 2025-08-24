<?php
// /pocketmode/index.php
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <title>Pocket Mode - 1ゲーム記録</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Bootstrap 5 CDN -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="./assets/pocketmode.css?v=20250824" rel="stylesheet">
</head>
<body class="bg-light">
  <div class="container py-3">
    <header class="mb-3">
      <h1 class="h3 fw-bold mb-0">Pocket Mode</h1>
      <div class="text-muted small">9ボールの1ゲームをリアルタイム記録 → 勝敗を保存</div>
    </header>

    <!-- ゲーム設定 -->
    <section class="card mb-3 shadow-sm">
      <div class="card-body">
        <div class="row g-2 align-items-end">
          <div class="col-6 col-md-3">
            <label class="form-label">日付</label>
            <input type="date" id="date" class="form-control" />
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label">ルール</label>
            <select id="rule_id" class="form-select"></select>
          </div>
          <div class="col-12 col-md-3">
            <label class="form-label">店舗</label>
            <select id="shop_id" class="form-select"></select>
          </div>
          <div class="col-6 col-md-1">
            <label class="form-label">先攻</label>
            <select id="first_player" class="form-select">
              <option value="1">P1</option>
              <option value="2">P2</option>
            </select>
          </div>
          <div class="col-12 col-md-2 d-grid">
            <button id="btnStart" class="btn btn-primary">ゲーム開始</button>
          </div>
        </div>
        <div class="row g-2 mt-2">
          <div class="col-12 col-md-6">
            <label class="form-label">プレイヤー1</label>
            <select id="player1_id" class="form-select"></select>
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label">プレイヤー2</label>
            <select id="player2_id" class="form-select"></select>
          </div>
        </div>
      </div>
    </section>

    <!-- 進行エリア -->
    <section id="playArea" class="card mb-3 shadow-sm d-none">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div>
            <div class="small text-muted">Game ID</div>
            <div class="fw-bold" id="gameId"></div>
          </div>
          <div class="text-end">
            <div class="small text-muted">現在の手番</div>
            <div class="fw-bold fs-5"><span id="activeLabel" class="badge bg-secondary">P1</span></div>
          </div>
        </div>

        <div class="row g-3">
          <div class="col-12 col-lg-6">
            <div class="card h-100">
              <div class="card-header py-2"><strong>スコア</strong></div>
              <div class="card-body d-flex justify-content-around align-items-center">
                <div class="pm-player">
                  <div class="pm-player-name" id="p1Name">Player 1</div>
                  <div class="pm-score" id="p1Score">0</div>
                  <button class="btn btn-outline-secondary btn-sm mt-2 toggle-active" data-player="1">手番にする</button>
                </div>
                <div class="display-6 text-muted">VS</div>
                <div class="pm-player">
                  <div class="pm-player-name" id="p2Name">Player 2</div>
                  <div class="pm-score" id="p2Score">0</div>
                  <button class="btn btn-outline-secondary btn-sm mt-2 toggle-active" data-player="2">手番にする</button>
                </div>
              </div>
            </div>
          </div>

          <div class="col-12 col-lg-6">
            <div class="card h-100">
              <div class="card-header py-2"><strong>操作</strong></div>
              <div class="card-body">
                <div class="mb-2">ボールをタップして「入った」記録を追加</div>
                <div class="pm-balls-grid" id="ballsGrid">
                  <!-- 1〜9 をJSで描画 -->
                </div>
                <div class="d-flex gap-2 mt-3">
                  <button id="btnFoul" class="btn btn-outline-danger">ファウル</button>
                  <button id="btnUndo" class="btn btn-outline-secondary">1手戻す</button>
                  <button id="btnReset" class="btn btn-outline-dark">リセット</button>
                  <button id="btnFinish" class="btn btn-success ms-auto">ゲーム終了・保存</button>
                </div>
                <div class="small text-muted mt-2">※9番を入れたプレイヤーを勝者として <code>match_detail</code> に 1/0 で保存します</div>
              </div>
            </div>
          </div>
        </div>

        <div class="card mt-3">
          <div class="card-header py-2"><strong>イベントログ（ローカル）</strong></div>
          <div class="card-body">
            <pre id="logView" class="pm-log"></pre>
          </div>
        </div>
      </div>
    </section>

    <footer class="text-center text-muted small pt-3">
      &copy; <?= date('Y') ?> 9balls Pocket Mode
    </footer>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="./assets/pocketmode.js?v=20250824"></script>
</body>
</html>
