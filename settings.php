<?php
// /settings.php
require_once __DIR__ . '/sys/db_connect.php';

// --- POST処理（追加登録） ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = $_POST['type'] ?? '';
    $name = trim($_POST['name'] ?? '');
    $code = trim($_POST['code'] ?? '');

    if ($type && $name) {
        try {
            if ($type === 'player') {
                $stmt = $pdo->prepare("INSERT IGNORE INTO player_master (name) VALUES (:name)");
                $stmt->execute([':name' => $name]);
            } elseif ($type === 'shop') {
                $stmt = $pdo->prepare("INSERT IGNORE INTO shop_master (name) VALUES (:name)");
                $stmt->execute([':name' => $name]);
            } elseif ($type === 'rule') {
                $stmt = $pdo->prepare("INSERT INTO rule_master (code, name) VALUES (:code, :name)");
                $stmt->execute([':code' => $code, ':name' => $name]);
            }
        } catch (PDOException $e) {
            die("DBエラー: " . htmlspecialchars($e->getMessage()));
        }
    }
}

// --- 現在のマスタ一覧 ---
$players = $pdo->query("SELECT * FROM player_master ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$shops   = $pdo->query("SELECT * FROM shop_master ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$rules   = $pdo->query("SELECT * FROM rule_master ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>設定画面</title>
<link rel="stylesheet" href="/pocketmode/assets/pocketmode.css?v=<?=time()?>">
</head>
<body>
<div class="pm-header">
  <h1 class="pm-title">設定画面</h1>
  <a href="/index.php" class="pm-btn">← 戻る</a>
</div>

<div class="pm-settings">
  <h2>プレイヤー追加</h2>
  <form method="post">
    <input type="hidden" name="type" value="player">
    <input type="text" name="name" placeholder="プレイヤー名" required>
    <button type="submit" class="pm-btn pm-btn-primary">追加</button>
  </form>
  <ul>
    <?php foreach($players as $p): ?>
      <li><?=htmlspecialchars($p['name'])?></li>
    <?php endforeach; ?>
  </ul>
</div>

<div class="pm-settings">
  <h2>店舗追加</h2>
  <form method="post">
    <input type="hidden" name="type" value="shop">
    <input type="text" name="name" placeholder="店舗名" required>
    <button type="submit" class="pm-btn pm-btn-primary">追加</button>
  </form>
  <ul>
    <?php foreach($shops as $s): ?>
      <li><?=htmlspecialchars($s['name'])?></li>
    <?php endforeach; ?>
  </ul>
</div>

<div class="pm-settings">
  <h2>ルール追加</h2>
  <form method="post">
    <input type="hidden" name="type" value="rule">
    <input type="text" name="code" placeholder="コード (例: A/B)">
    <input type="text" name="name" placeholder="ルール名" required>
    <button type="submit" class="pm-btn pm-btn-primary">追加</button>
  </form>
  <ul>
    <?php foreach($rules as $r): ?>
      <li><?=htmlspecialchars($r['code'])?>：<?=htmlspecialchars($r['name'])?></li>
    <?php endforeach; ?>
  </ul>
</div>

</body>
</html>
