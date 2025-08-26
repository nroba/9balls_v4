<?php
// /settings.php  — セッション不使用・即動作版（CSRFはCookie + HMAC）
// 依存: /sys/db_connect.php（PDO $pdo）
//
// 500対策: セッションを廃止。エラーは logs/settings_error.log に強制出力。
// CSRF: 初回アクセス時に Set-Cookie: csrf_seed を配布し、HMACトークンを検証。

/* ===== 強制ログ ===== */
$DEBUG = (isset($_GET['debug']) && $_GET['debug'] === '1');
if ($DEBUG) { ini_set('display_errors','1'); ini_set('display_startup_errors','1'); }
error_reporting(E_ALL);
$logDir  = __DIR__ . '/logs';
$logFile = $logDir . '/settings_error.log';
if (!is_dir($logDir)) { @mkdir($logDir, 0775, true); }
ini_set('log_errors','1');
ini_set('error_log', $logFile);
set_error_handler(function($no,$str,$file,$line){ error_log("[PHP ERROR][$no] $str @ $file:$line"); return false; });
set_exception_handler(function($e){ error_log("[UNCAUGHT] ".$e->getMessage()."\n".$e->getTraceAsString()); });
register_shutdown_function(function(){ if ($e = error_get_last()) error_log("[SHUTDOWN] {$e['message']} @ {$e['file']}:{$e['line']}"); });

/* ===== 便利関数 ===== */
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/* ===== db_connect.php をロード ===== */
$paths = [
  __DIR__ . '/sys/db_connect.php',
  __DIR__ . '/../sys/db_connect.php',
  dirname(__DIR__) . '/sys/db_connect.php',
];
$found = false;
foreach ($paths as $p) { if (is_file($p)) { require_once $p; $found = true; break; } }
if (!$found) { http_response_code(500); echo "db_connect.php not found"; exit; }
if (!isset($pdo) || !($pdo instanceof PDO)) { http_response_code(500); echo "\$pdo missing"; exit; }
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

/* ===== 必要テーブル（存在しなければ作成）===== */
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS player_master (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(name)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $pdo->exec("CREATE TABLE IF NOT EXISTS shop_master (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(name)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $pdo->exec("CREATE TABLE IF NOT EXISTS rule_master (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(16) NULL,
    name VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(code), INDEX(name)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {
  error_log("DDL ERROR: ".$e->getMessage());
}

/* ===== CSRF: Cookie + HMAC ===== */
const CSRF_SECRET = 'change-me-32byte-min-secret-key'; // 任意の長い文字列に変更可（.envがあればそちらで）

if (empty($_COOKIE['csrf_seed'])) {
  $seed = bin2hex(random_bytes(16));
  // Cookie属性は必要に応じて調整（SameSite, Secure は運用に合わせる）
  setcookie('csrf_seed', $seed, [
    'expires'  => time() + 86400*180,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
  $_COOKIE['csrf_seed'] = $seed; // 以降の処理で使えるように
}
$csrf_seed = (string)($_COOKIE['csrf_seed'] ?? '');
function csrf_token(): string {
  return hash_hmac('sha256', $_COOKIE['csrf_seed'] ?? '', CSRF_SECRET);
}
function csrf_validate(string $token): bool {
  $calc = csrf_token();
  return hash_equals($calc, $token);
}

/* ===== フラッシュ（クエリ文字列で簡易実装） ===== */
function redirect_with_msg(string $url, string $type, string $msg): never {
  $q = http_build_query(['msg' => $msg, 'type' => $type]);
  header("Location: {$url}".(str_contains($url,'?')?'&':'?').$q);
  exit;
}
$flash_msg  = $_GET['msg']  ?? '';
$flash_type = $_GET['type'] ?? '';

/* ===== タブ ===== */
$tab = $_GET['tab'] ?? 'player';
if (!in_array($tab, ['player','shop','rule'], true)) $tab = 'player';

/* ===== POST処理 ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $entity = $_POST['entity'] ?? '';
  $action = $_POST['action'] ?? '';
  $token  = $_POST['csrf_token'] ?? '';

  if (!csrf_validate($token)) {
    redirect_with_msg("settings.php?tab={$tab}", 'danger', 'CSRFトークンが無効です。');
  }

  try {
    if ($entity === 'player') {
      if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') throw new Exception('プレイヤー名を入力してください。');
        $pdo->prepare("INSERT INTO player_master (name) VALUES (?)")->execute([$name]);
        redirect_with_msg("settings.php?tab=player", 'success', 'プレイヤーを追加しました。');
      } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($id<=0 || $name==='') throw new Exception('更新対象または名前が不正です。');
        $pdo->prepare("UPDATE player_master SET name=? WHERE id=?")->execute([$name, $id]);
        redirect_with_msg("settings.php?tab=player", 'success', 'プレイヤーを更新しました。');
      } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id<=0) throw new Exception('削除対象が不正です。');
        $pdo->prepare("DELETE FROM player_master WHERE id=?")->execute([$id]);
        redirect_with_msg("settings.php?tab=player", 'success', 'プレイヤーを削除しました。');
      }
    } elseif ($entity === 'shop') {
      if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') throw new Exception('店舗名を入力してください。');
        $pdo->prepare("INSERT INTO shop_master (name) VALUES (?)")->execute([$name]);
        redirect_with_msg("settings.php?tab=shop", 'success', '店舗を追加しました。');
      } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($id<=0 || $name==='') throw new Exception('更新対象または店舗名が不正です。');
        $pdo->prepare("UPDATE shop_master SET name=? WHERE id=?")->execute([$name, $id]);
        redirect_with_msg("settings.php?tab=shop", 'success', '店舗を更新しました。');
      } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id<=0) throw new Exception('削除対象が不正です。');
        $pdo->prepare("DELETE FROM shop_master WHERE id=?")->execute([$id]);
        redirect_with_msg("settings.php?tab=shop", 'success', '店舗を削除しました。');
      }
    } elseif ($entity === 'rule') {
      if ($action === 'create') {
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        if ($name === '') throw new Exception('ルール名を入力してください。');
        $pdo->prepare("INSERT INTO rule_master (code, name) VALUES (?,?)")->execute([$code!==''?$code:null, $name]);
        redirect_with_msg("settings.php?tab=rule", 'success', 'ルールを追加しました。');
      } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        if ($id<=0 || $name==='') throw new Exception('更新対象またはルール名が不正です。');
        $pdo->prepare("UPDATE rule_master SET code=?, name=? WHERE id=?")
            ->execute([$code!==''?$code:null, $name, $id]);
        redirect_with_msg("settings.php?tab=rule", 'success', 'ルールを更新しました。');
      } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id<=0) throw new Exception('削除対象が不正です。');
        $pdo->prepare("DELETE FROM rule_master WHERE id=?")->execute([$id]);
        redirect_with_msg("settings.php?tab=rule", 'success', 'ルールを削除しました。');
      }
    }
    // 不明なアクション
    redirect_with_msg("settings.php?tab={$tab}", 'danger', '不明な操作です。');

  } catch (Throwable $e) {
    error_log("POST ERROR: ".$e->getMessage());
    redirect_with_msg("settings.php?tab={$tab}", 'danger', 'エラー: '.$e->getMessage());
  }
}

/* ===== 一覧取得 ===== */
$players = $pdo->query("SELECT id, name, created_at FROM player_master ORDER BY name")->fetchAll();
$shops   = $pdo->query("SELECT id, name, created_at FROM shop_master ORDER BY name")->fetchAll();
$rules   = $pdo->query("SELECT id, IFNULL(code,'') AS code, name, created_at FROM rule_master ORDER BY id")->fetchAll();

/* ===== HTML ===== */
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

    <?php if ($flash_msg): ?>
      <div class="alert alert-<?= h($flash_type ?: 'info') ?>"><?= h($flash_msg) ?></div>
    <?php endif; ?>

    <ul class="nav nav-pills mb-3">
      <li class="nav-item"><a class="nav-link <?= $tab==='player'?'active':'' ?>" href="?tab=player">プレイヤー</a></li>
      <li class="nav-item"><a class="nav-link <?= $tab==='shop'  ?'active':'' ?>" href="?tab=shop">店舗</a></li>
      <li class="nav-item"><a class="nav-link <?= $tab==='rule'  ?'active':'' ?>" href="?tab=rule">ルール</a></li>
    </ul>

    <!-- プレイヤー -->
    <?php if ($tab==='player'): ?>
      <div class="card mb-4 shadow-sm">
        <div class="card-header fw-bold">プレイヤーの追加</div>
        <div class="card-body">
          <form method="post" class="row g-2">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="entity" value="player">
            <input type="hidden" name="action" value="create">
            <div class="col-12 col-md-8">
              <input type="text" name="name" class="form-control" placeholder="例）田中太郎" required>
            </div>
            <div class="col-12 col-md-4 d-grid"><button class="btn btn-primary">追加</button></div>
          </form>
        </div>
      </div>

      <div class="card shadow-sm">
        <div class="card-header fw-bold">プレイヤー一覧（<?= count($players) ?>件）</div>
        <div class="card-body table-responsive">
          <table class="table table-striped align-middle">
            <thead><tr><th style="width:90px">ID</th><th>名前</th><th style="width:160px">作成日</th><th style="width:220px">操作</th></tr></thead>
            <tbody>
              <?php foreach ($players as $p): ?>
                <tr>
                  <td><?= (int)$p['id'] ?></td>
                  <td>
                    <form method="post" class="d-flex gap-2">
                      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                      <input type="hidden" name="entity" value="player">
                      <input type="hidden" name="action" value="update">
                      <input type="hidden" name="id"     value="<?= (int)$p['id'] ?>">
                      <input type="text" name="name" class="form-control" value="<?= h($p['name']) ?>" required>
                      <button class="btn btn-sm btn-outline-primary">更新</button>
                    </form>
                  </td>
                  <td class="muted"><?= h($p['created_at']) ?></td>
                  <td>
                    <form method="post" onsubmit="return confirm('削除しますか？');">
                      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                      <input type="hidden" name="entity" value="player">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id"     value="<?= (int)$p['id'] ?>">
                      <button class="btn btn-sm btn-outline-danger">削除</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

    <!-- 店舗 -->
    <?php if ($tab==='shop'): ?>
      <div class="card mb-4 shadow-sm">
        <div class="card-header fw-bold">店舗の追加</div>
        <div class="card-body">
          <form method="post" class="row g-2">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="entity" value="shop">
            <input type="hidden" name="action" value="create">
            <div class="col-12 col-md-8">
              <input type="text" name="name" class="form-control" placeholder="例）バンバン" required>
            </div>
            <div class="col-12 col-md-4 d-grid"><button class="btn btn-primary">追加</button></div>
          </form>
        </div>
      </div>

      <div class="card shadow-sm">
        <div class="card-header fw-bold">店舗一覧（<?= count($shops) ?>件）</div>
        <div class="card-body table-responsive">
          <table class="table table-striped align-middle">
            <thead><tr><th style="width:90px">ID</th><th>店舗名（編集可）</th><th style="width:160px">作成日</th><th style="width:220px">操作</th></tr></thead>
            <tbody>
              <?php foreach ($shops as $s): ?>
                <tr>
                  <td><?= (int)$s['id'] ?></td>
                  <td>
                    <form method="post" class="d-flex gap-2">
                      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                      <input type="hidden" name="entity" value="shop">
                      <input type="hidden" name="action" value="update">
                      <input type="hidden" name="id"     value="<?= (int)$s['id'] ?>">
                      <input type="text" name="name" class="form-control" value="<?= h($s['name']) ?>" required>
                      <button class="btn btn-sm btn-outline-primary">更新</button>
                    </form>
                  </td>
                  <td class="muted"><?= h($s['created_at']) ?></td>
                  <td>
                    <form method="post" onsubmit="return confirm('削除しますか？');">
                      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                      <input type="hidden" name="entity" value="shop">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id"     value="<?= (int)$s['id'] ?>">
                      <button class="btn btn-sm btn-outline-danger">削除</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

    <!-- ルール -->
    <?php if ($tab==='rule'): ?>
      <div class="card mb-4 shadow-sm">
        <div class="card-header fw-bold">ルールの追加</div>
        <div class="card-body">
          <form method="post" class="row g-2">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="entity" value="rule">
            <input type="hidden" name="action" value="create">
            <div class="col-12 col-md-2"><input type="text" name="code" class="form-control" placeholder="例）A/B" maxlength="16"></div>
            <div class="col-12 col-md-6"><input type="text" name="name" class="form-control" placeholder="例）9ボール（Aルール）" required></div>
            <div class="col-12 col-md-4 d-grid"><button class="btn btn-primary">追加</button></div>
          </form>
        </div>
      </div>

      <div class="card shadow-sm">
        <div class="card-header fw-bold">ルール一覧（<?= count($rules) ?>件）</div>
        <div class="card-body table-responsive">
          <table class="table table-striped align-middle">
            <thead><tr><th style="width:90px">ID</th><th style="width:120px">コード</th><th>名称（編集可）</th><th style="width:160px">作成日</th><th style="width:220px">操作</th></tr></thead>
            <tbody>
              <?php foreach ($rules as $r): ?>
                <tr>
                  <td><?= (int)$r['id'] ?></td>
                  <td>
                    <form method="post" class="d-flex gap-2">
                      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                      <input type="hidden" name="entity" value="rule">
                      <input type="hidden" name="action" value="update">
                      <input type="hidden" name="id"     value="<?= (int)$r['id'] ?>">
                      <input type="text" name="code" class="form-control" value="<?= h($r['code']) ?>" maxlength="16" style="max-width:120px">
                      <input type="text" name="name" class="form-control" value="<?= h($r['name']) ?>" required>
                      <button class="btn btn-sm btn-outline-primary">更新</button>
                    </form>
                  </td>
                  <td class="muted"><?= h($r['created_at']) ?></td>
                  <td>
                    <form method="post" onsubmit="return confirm('削除しますか？');">
                      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                      <input type="hidden" name="entity" value="rule">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id"     value="<?= (int)$r['id'] ?>">
                      <button class="btn btn-sm btn-outline-danger">削除</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <div class="form-text mt-2">
            ※ Pocketmode の得点計算では、<code>code</code> が <strong>A</strong> の時はAルール（9番=2点・奇数=1点・×2あり）、それ以外はBルール（9番=2点・他=1点）として扱われます。
          </div>
        </div>
      </div>
    <?php endif; ?>

    <p class="text-muted small mt-4">
      ※ 削除時に外部参照制約（FOREIGN KEY）エラーが出る場合は、既存の試合データで使用中です。名称変更をご利用ください。
    </p>
  </div>
</body>
</html>
