<?php
// /pocketmode/api/masters.php
declare(strict_types=1);
mb_internal_encoding('UTF-8');

header('Content-Type: application/json; charset=UTF-8');
// header('Access-Control-Allow-Origin: *'); // 必要ならCORSを有効化

require_once __DIR__ . '/../../sys/db_connect.php'; // $pdo (PDO) を提供

/**
 * 情報スキーマからカラムの有無を確認
 */
function hasColumn(PDO $pdo, string $table, string $column): bool {
    $sql = "
        SELECT COUNT(*) AS cnt
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = :table
          AND COLUMN_NAME  = :column
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':table' => $table, ':column' => $column]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return isset($row['cnt']) && (int)$row['cnt'] > 0;
}

/**
 * シンプルな SELECT 実行（例外を投げる）
 */
function fetchAllAssoc(PDO $pdo, string $sql, array $params = []): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

try {
    // ---- rules ----
    $orderByRules = hasColumn($pdo, 'rule_master', 'sort_order')
        ? 'ORDER BY sort_order, id'
        : 'ORDER BY id';

    $rules = fetchAllAssoc($pdo, "
        SELECT
            id,
            code,
            name,
            /* 並び用の列は返したいなら以下をアンコメント
            sort_order,
            */
            side_multiplier_enabled,
            side_multiplier_value
        FROM rule_master
        {$orderByRules};
    ");

    // 型の後処理（JSONで数値になるように）
    foreach ($rules as &$r) {
        $r['id'] = (int)$r['id'];
        $r['side_multiplier_enabled'] = isset($r['side_multiplier_enabled']) ? (int)$r['side_multiplier_enabled'] : 0;
        $r['side_multiplier_value']   = isset($r['side_multiplier_value'])   ? (int)$r['side_multiplier_value']   : 1;
        // if (isset($r['sort_order'])) $r['sort_order'] = (int)$r['sort_order'];
    }
    unset($r);

    // ---- shops ----
    // 並びは name, id にしています（必要なら sort_order に差し替え）
    $shops = fetchAllAssoc($pdo, "
        SELECT id, name
        FROM shop_master
        ORDER BY name ASC, id ASC
    ");
    foreach ($shops as &$s) {
        $s['id'] = (int)$s['id'];
    }
    unset($s);

    // ---- players ----
    $players = fetchAllAssoc($pdo, "
        SELECT id, name
        FROM player_master
        ORDER BY name ASC, id ASC
    ");
    foreach ($players as &$p) {
        $p['id'] = (int)$p['id'];
    }
    unset($p);

    // ---- response ----
    $resp = [
        'status'  => 'ok',
        'rules'   => $rules,
        'shops'   => $shops,
        'players' => $players,
        'timestamp' => date('c'), // ISO8601
    ];

    echo json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'masters load failed',
        'detail'  => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
