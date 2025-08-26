<?php // /pocketmode/index.php ?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Pocketed! (Pocket Mode)</title>
  <link rel="stylesheet" href="./assets/pocketmode.css?v=20250824">
</head>
<body>
  <h1>Pocketed!</h1>

  <!-- スコア表示 -->
  <div class="scoreboard">
    <div id="label1">Player 1</div>
    <div id="score1">0</div>
    <div id="label2">Player 2</div>
    <div id="score2">0</div>
  </div>

  <!-- 設定表示切り替え -->
  <div class="text-center my-2">
    <button onclick="toggleSettings()" class="btn btn-outline-secondary">⚙ 設定</button>
  </div>

  <!-- 設定エリア（初期表示ON） -->
  <div id="gameSettings" class="settings-panel">
    <div class="player-select" style="margin-bottom:.6em">
      <label>日付：</label>
      <input id="dateInput" type="date" value="<?= date('Y-m-d') ?>">
    </div>

    <div class="rule-select">
      <label>ルール選択：</label>
      <!-- masters.php から動的に上書き（rule_master の id/code/name） -->
      <select id="ruleSelect">
        <option value="">読み込み中…</option>
      </select>
    </div>

    <div class="player-select">
      <label>店舗名：</label>
      <select id="shop"></select>
    </div>

    <div class="player-select">
      <label>プレイヤー1：</label>
      <select id="player1"></select>
    </div>

    <div class="player-select">
      <label>プレイヤー2：</label>
      <select id="player2"></select>
    </div>
  </div>

  <!-- ボール -->
  <div class="ball-grid" id="ballGrid"></div>

  <!-- ボタン -->
  <div class="button-row">
    <img id="resetBtn"  src="../images/btn_erase.png"  alt="リセット" class="icon-button">
    <img id="registBtn" src="../images/btn_regist.png" alt="登録"   class="icon-button">
  </div>

  <!-- ポップアップ -->
  <div id="popup" class="popup"></div>

  <!-- 登録後アクション -->
  <div id="postRegistActions" class="popup post-regist-box" style="display: none;">
    <p class="text-center mb-3">✅ 登録が完了しました！</p>
    <div class="btn-group-responsive">
      <button onclick="resetAll(); hideActions();" class="btn btn-success btn-lg">🎱 次のゲーム</button>
      <a href="../daily/daily.php" class="btn btn-outline-dark btn-lg">📅 日別まとめへ</a>
    </div>
  </div>

  <script src="./assets/pocketmode.js?v=20250824"></script>
</body>
</html>
