<?php
// /pocketmode/api/masters.php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../sys/db_connect.php';

try {
    // プレイヤー
    $players = $pdo->query("SELECT id, name FROM player_master ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    // 店舗
    $shops   = $pdo->query("SELECT id, name FROM shop_master ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    // ルール（code列があれば表示で使用）
    $rules   = $pdo->query("SELECT id, IFNULL(code,'') AS code, name FROM rule_master ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'players' => $players,
        'shops'   => $shops,
        'rules'   => $rules,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
