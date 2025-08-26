<?php

/* ===== settings.php 強制デバッグブート ===== */
$__DEBUG = (isset($_GET['debug']) && $_GET['debug'] === '1');

if ($__DEBUG) {
  ini_set('display_errors','1');
  ini_set('display_startup_errors','1');
}
error_reporting(E_ALL);

// ログ出力先をプロジェクト直下 logs/ に固定
$__logDir = __DIR__ . '/logs';
$__logFile = $__logDir . '/settings_error.log';
if (!is_dir($__logDir)) { @mkdir($__logDir, 0775, true); }

ini_set('log_errors', '1');
ini_set('error_log', $__logFile);

// 致命的エラーも拾う
set_error_handler(function($no,$str,$file,$line){
  error_log("[PHP ERROR][$no] $str @ $file:$line");
  return false; // 既定の処理も継続
});
set_exception_handler(function($e){
  error_log("[UNCAUGHT] " . $e->getMessage() . "\n" . $e->getTraceAsString());
});
register_shutdown_function(function(){
  $e = error_get_last();
  if ($e) {
    error_log("[SHUTDOWN] {$e['message']} @ {$e['file']}:{$e['line']}");
  }
});

// db_connect.php のパスゆらぎ吸収
$__paths = [
  __DIR__ . '/sys/db_connect.php',
  __DIR__ . '/../sys/db_connect.php',
  dirname(__DIR__) . '/sys/db_connect.php',
];
$__found = false;
foreach ($__paths as $__p) { if (is_file($__p)) { require_once $__p; $__found = true; break; } }
if (!$__found) {
  error_log("[BOOT] db_connect.php not found. tried: " . implode(', ', $__paths));
  http_response_code(500);
  if ($__DEBUG) { echo "<pre>db_connect.php not found</pre>"; }
  exit;
}
/* ===== /強制デバッグブート ここまで ===== */



// /settings.php
// 依存: /sys/db_connect.php（PDO $pdo）
// 備考: 既存テーブルが無い場合は CREATE TABLE IF NOT EXISTS で最低限を自動作成

declare(strict_types=1);
session_start();

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// CSRF
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf_token = $_SESSION['csrf_token'];

// DB接続
require_once __DIR__ . '/sys/db_connect.php';

// --- 初期テーブル（存在しなければ作成） ---
try {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS player_master (
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(255) NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX(name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS shop_master (
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(255) NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX(name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS rule_master (
      id INT AUTO_INCREMENT PRIMARY KEY,
      code VARCHAR(16) NULL,
      name VARCHAR(255) NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX(code),
      INDEX(name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
} catch (Throwable $e) {
  $init_error = $e->getMessage();
}

// --- フラッシュメッセージ ---
function set_flash(string $type, string $msg): void {
  $_SESSION['flash'] = ['type'=>$type, 'msg'=>$msg];
}
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// --- ハンドラ ---
$tab = $_GET['tab'] ?? 'player'; // 'player' | 'shop' | 'rule'

// POST処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $entity = $_POST['entity'] ?? '';
  $action = $_POST['action'] ?? '';
  $token  = $_POST['csrf_token'] ?? '';

  if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    set_flash('danger', 'CSRFトークンが無効です。再度操作してください。');
    header('Location: settings.php?tab=' . urlencode($tab));
    exit;
  }

  try {
    if ($entity === 'player') {
      if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') throw new Exception('プレイヤー名を入力してください。');
        $stmt = $pdo->prepare("INSERT INTO player_master (name) VALUES (?)");
        $stmt->execute([$name]);
        set_flash('success', 'プレイヤーを追加しました。');
      } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($id <= 0 || $name === '') throw new Exception('更新対象または名前が不正です。');
        $stmt = $pdo->prepare("UPDATE player_master SET name=? WHERE id=?");
        $stmt->execute([$name, $id]);
        set_flash('success', 'プレイヤーを更新しました。');
      } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) throw new Exception('削除対象が不正です。');
        $stmt = $pdo->prepare("DELETE FROM player_master WHERE id=?");
        $stmt->execute([$id]);
        set_flash('success', 'プレイヤーを削除しました。');
      }
      $tab = 'player';

    } elseif ($entity === 'shop') {
      if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') throw new Exception('店舗名を入力してください。');
        $stmt = $pdo->prepare("INSERT INTO shop_master (name) VALUES (?)");
        $stmt->execute([$name]);
        set_flash('success', '店舗を追加しました。');
      } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($id <= 0 || $name === '') throw new Exception('更新対象または店舗名が不正です。');
        $stmt = $pdo->prepare("UPDATE shop_master SET name=? WHERE id=?");
        $stmt->execute([$name, $id]);
        set_flash('success', '店舗を更新しました。');
      } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) throw new Exception('削除対象が不正です。');
        $stmt = $pdo->prepare("DELETE FROM shop_master WHERE id=?");
        $stmt->execute([$id]);
        set_flash('success', '店舗を削除しました。');
      }
      $tab = 'shop';

    } elseif ($entity === 'rule') {
      if ($action === 'create') {
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        if ($name === '') throw new Exception('ルール名を入力してください。');
        $stmt = $pdo->prepare("INSERT INTO rule_master (code, name) VALUES (?,?)");
        $stmt->execute([$code !== '' ? $code : null, $name]);
        set_flash('success', 'ルールを追加しました。');
      } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        if ($id <= 0 || $name === '') throw new Exception('更新対象またはルール名が不正です。');
        $stmt = $pdo->prepare("UPDATE rule_master SET code=?, name=? WHERE id=?");
        $stmt->execute([$code !== '' ? $code : null, $name, $id]);
        set_flash('success', 'ルールを更新しました。');
      } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) throw new Exception('削除対象が不正です。');
        $stmt = $pdo->prepare("DELETE FROM rule_master WHERE id=?");
        $stmt->execute([$id]);
        set_flash('success', 'ルールを削除しました。');
      }
      $tab = 'rule';
    }

  } catch (Throwable $e) {
    // 外部参照制約など
    set_flash('danger', 'エラー: ' . $e->getMessage());
  }

  header('Location: settings.php?tab=' . urlencode($tab));
  exit;
}

// --- データ取得 ---
$players = $pdo->query("SELECT id, name, created_at FROM player_master ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$shops   = $pdo->query("SELECT id, name, created_at FROM shop_master ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$rules   = $pdo->query("SELECT id, IFNULL(code,'') AS code, name, created_at FROM rule_master ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <title>各種マスタ設定</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Bootstrap -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .container-narrow{ max-width:1100px; }
    .table td, .table th{ vertical-align: middle; }
    .muted{ color:#6c757d; }
  </style>
</head>
<body class="bg-light">
  <div class="container container-narrow py-4">
    <header class="mb-3 d-flex align-items-center justify-content-between">
      <h1 class="h4 fw-bold mb-0">各種マスタ設定</h1>
      <a class="btn btn-outline-secondary btn-sm" href="index.php">← メニュー</a>
    </header>

    <?php if (isset($init_error)): ?>
      <div class="alert alert-warning">初期化でエラーが発生しました: <?= h($init_error) ?></div>
    <?php endif; ?>

    <?php if ($flash): ?>
      <div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['msg']) ?></div>
    <?php endif; ?>

    <ul class="nav nav-pills mb-3">
      <li class="nav-item"><a class="nav-link <?= $tab==='player'?'active':'' ?>" href="?tab=player">プレイヤー</a></li>
      <li class="nav-item"><a class="nav-link <?= $tab==='shop'  ?'active':'' ?>" href="?tab=shop">店舗</a></li>
      <li class="nav-item"><a class="nav-link <?= $tab==='rule'  ?'active':'' ?>" href="?tab=rule">ルール</a></li>
    </ul>

    <!-- プレイヤー -->
    <?php if ($tab === 'player'): ?>
      <div class="card mb-4 shadow-sm">
        <div class="card-header fw-bold">プレイヤーの追加</div>
        <div class="card-body">
          <form method="post" class="row g-2">
            <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
            <input type="hidden" name="entity" value="player">
            <input type="hidden" name="action" value="create">
            <div class="col-12 col-md-8">
              <input type="text" name="name" class="form-control" placeholder="例）田中太郎" required>
            </div>
            <div class="col-12 col-md-4 d-grid">
              <button class="btn btn-primary">追加</button>
            </div>
          </form>
        </div>
      </div>

      <div class="card shadow-sm">
        <div class="card-header fw-bold">プレイヤー一覧（<?= count($players) ?>件）</div>
        <div class="card-body table-responsive">
          <table class="table table-striped align-middle">
            <thead><tr><th style="width:90px">ID</th><th>名前</th><th style="width:160px">作成日</th><th style="width:160px">操作</th></tr></thead>
            <tbody>
              <?php foreach ($players as $p): ?>
                <tr>
                  <td><?= (int)$p['id'] ?></td>
                  <td><?= h($p['name']) ?></td>
                  <td class="muted"><?= h($p['created_at']) ?></td>
                  <td>
                    <!-- 編集モーダル起動 -->
                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editPlayer-<?= (int)$p['id'] ?>">編集</button>
                    <!-- 削除 -->
                    <form method="post" style="display:inline" onsubmit="return confirm('削除しますか？');">
                      <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                      <input type="hidden" name="entity" value="player">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id"     value="<?= (int)$p['id'] ?>">
                      <button class="btn btn-sm btn-outline-danger">削除</button>
                    </form>
                  </td>
                </tr>

                <!-- 編集モーダル -->
                <div class="modal fade" id="editPlayer-<?= (int)$p['id'] ?>" tabindex="-1" aria-hidden="true">
                  <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                      <div class="modal-header">
                        <h5 class="modal-title">プレイヤー編集 (ID: <?= (int)$p['id'] ?>)</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                      </div>
                      <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                        <input type="hidden" name="entity" value="player">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id"     value="<?= (int)$p['id'] ?>">
                        <div class="modal-body">
                          <div class="mb-2">
                            <label class="form-label">名前</label>
                            <input type="text" name="name" class="form-control" value="<?= h($p['name']) ?>" required>
                          </div>
                        </div>
                        <div class="modal-footer">
                          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">閉じる</button>
                          <button class="btn btn-primary">更新</button>
                        </div>
                      </form>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

    <!-- 店舗 -->
    <?php if ($tab === 'shop'): ?>
      <div class="card mb-4 shadow-sm">
        <div class="card-header fw-bold">店舗の追加</div>
        <div class="card-body">
          <form method="post" class="row g-2">
            <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
            <input type="hidden" name="entity" value="shop">
            <input type="hidden" name="action" value="create">
            <div class="col-12 col-md-8">
              <input type="text" name="name" class="form-control" placeholder="例）バンバン" required>
            </div>
            <div class="col-12 col-md-4 d-grid">
              <button class="btn btn-primary">追加</button>
            </div>
          </form>
        </div>
      </div>

      <div class="card shadow-sm">
        <div class="card-header fw-bold">店舗一覧（<?= count($shops) ?>件）</div>
        <div class="card-body table-responsive">
          <table class="table table-striped align-middle">
            <thead><tr><th style="width:90px">ID</th><th>店舗名</th><th style="width:160px">作成日</th><th style="width:160px">操作</th></tr></thead>
            <tbody>
              <?php foreach ($shops as $s): ?>
                <tr>
                  <td><?= (int)$s['id'] ?></td>
                  <td><?= h($s['name']) ?></td>
                  <td class="muted"><?= h($s['created_at']) ?></td>
                  <td>
                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editShop-<?= (int)$s['id'] ?>">編集</button>
                    <form method="post" style="display:inline" onsubmit="return confirm('削除しますか？');">
                      <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                      <input type="hidden" name="entity" value="shop">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id"     value="<?= (int)$s['id'] ?>">
                      <button class="btn btn-sm btn-outline-danger">削除</button>
                    </form>
                  </td>
                </tr>

                <div class="modal fade" id="editShop-<?= (int)$s['id'] ?>" tabindex="-1" aria-hidden="true">
                  <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                      <div class="modal-header">
                        <h5 class="modal-title">店舗編集 (ID: <?= (int)$s['id'] ?>)</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                      </div>
                      <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                        <input type="hidden" name="entity" value="shop">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id"     value="<?= (int)$s['id'] ?>">
                        <div class="modal-body">
                          <div class="mb-2">
                            <label class="form-label">店舗名</label>
                            <input type="text" name="name" class="form-control" value="<?= h($s['name']) ?>" required>
                          </div>
                        </div>
                        <div class="modal-footer">
                          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">閉じる</button>
                          <button class="btn btn-primary">更新</button>
                        </div>
                      </form>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

    <!-- ルール -->
    <?php if ($tab === 'rule'): ?>
      <div class="card mb-4 shadow-sm">
        <div class="card-header fw-bold">ルールの追加</div>
        <div class="card-body">
          <form method="post" class="row g-2">
            <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
            <input type="hidden" name="entity" value="rule">
            <input type="hidden" name="action" value="create">
            <div class="col-12 col-md-2">
              <input type="text" name="code" class="form-control" placeholder="例）A/B" maxlength="16">
            </div>
            <div class="col-12 col-md-6">
              <input type="text" name="name" class="form-control" placeholder="例）9ボール（Aルール）" required>
            </div>
            <div class="col-12 col-md-4 d-grid">
              <button class="btn btn-primary">追加</button>
            </div>
          </form>
        </div>
      </div>

      <div class="card shadow-sm">
        <div class="card-header fw-bold">ルール一覧（<?= count($rules) ?>件）</div>
        <div class="card-body table-responsive">
          <table class="table table-striped align-middle">
            <thead><tr><th style="width:90px">ID</th><th style="width:120px">コード</th><th>名称</th><th style="width:160px">作成日</th><th style="width:160px">操作</th></tr></thead>
            <tbody>
              <?php foreach ($rules as $r): ?>
                <tr>
                  <td><?= (int)$r['id'] ?></td>
                  <td><span class="badge bg-secondary"><?= h($r['code']) ?: '—' ?></span></td>
                  <td><?= h($r['name']) ?></td>
                  <td class="muted"><?= h($r['created_at']) ?></td>
                  <td>
                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editRule-<?= (int)$r['id'] ?>">編集</button>
                    <form method="post" style="display:inline" onsubmit="return confirm('削除しますか？');">
                      <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                      <input type="hidden" name="entity" value="rule">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id"     value="<?= (int)$r['id'] ?>">
                      <button class="btn btn-sm btn-outline-danger">削除</button>
                    </form>
                  </td>
                </tr>

                <div class="modal fade" id="editRule-<?= (int)$r['id'] ?>" tabindex="-1" aria-hidden="true">
                  <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                      <div class="modal-header">
                        <h5 class="modal-title">ルール編集 (ID: <?= (int)$r['id'] ?>)</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                      </div>
                      <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                        <input type="hidden" name="entity" value="rule">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id"     value="<?= (int)$r['id'] ?>">
                        <div class="modal-body">
                          <div class="row g-2">
                            <div class="col-12 col-md-3">
                              <label class="form-label">コード</label>
                              <input type="text" name="code" class="form-control" value="<?= h($r['code']) ?>" maxlength="16">
                            </div>
                            <div class="col-12 col-md-9">
                              <label class="form-label">名称</label>
                              <input type="text" name="name" class="form-control" value="<?= h($r['name']) ?>" required>
                            </div>
                          </div>
                          <div class="form-text mt-2">
                            ※ Pocketmodeの得点計算では、<code>code</code> が <strong>A</strong> の時はAルール（9番=2点・奇数=1点・×2あり）、それ以外はBルール（9番=2点・他=1点）として扱われます。
                          </div>
                        </div>
                        <div class="modal-footer">
                          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">閉じる</button>
                          <button class="btn btn-primary">更新</button>
                        </div>
                      </form>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

    <p class="text-muted small mt-4">
      ※ 削除時に「外部参照制約（FOREIGN KEY）」エラーになる場合、既存の試合データで使用中の可能性があります。名称変更のご利用を検討ください。
    </p>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
