<?php
// /pocketmode/api/finalize_game.php
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$in = json_decode(file_get_contents('php://input'), true);
$need = ['game_id','date','shop_id','rule_id','player1_id','player2_id','score1','score2'];
foreach($need as $k){
    if(!isset($in[$k]) || $in[$k]===''){
        http_response_code(400);
        echo json_encode(['error' => "missing: $k"]);
        exit;
    }
}

require_once __DIR__ . '/../../sys/db_connect.php';

try {
    // match_detail へ1レコード追加（勝者=1, 敗者=0）
    // 既存スキーマに合わせて列名を調整してください（例は id自動採番 / created_atデフォルト）
    $sql = "INSERT INTO match_detail
        (date, rule_id, shop_id, player1_id, player2_id, score1, score2, game_id)
        VALUES (?,?,?,?,?,?,?,?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $in['date'],
        (int)$in['rule_id'],
        (int)$in['shop_id'],
        (int)$in['player1_id'],
        (int)$in['player2_id'],
        (int)$in['score1'],
        (int)$in['score2'],
        $in['game_id'],
    ]);

    // 任意: イベント全体をJSONで保管したい場合は、別テーブルを用意するか、
    // match_detail に memo/text カラムがあるなら以下のように追記する実装に変更してください。
    // このサンプルでは安全のため何もしません。

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
