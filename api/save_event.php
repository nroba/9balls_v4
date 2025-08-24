<?php
// /pocketmode/api/save_event.php
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$game_id = $input['game_id'] ?? null;
$event   = $input['event'] ?? null;

if(!$game_id || !$event){
    http_response_code(400);
    echo json_encode(['error' => 'bad request']);
    exit;
}

require_once __DIR__ . '/../../sys/db_connect.php';

try {
    // pocket_log テーブルを自動作成（存在しなければ）
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pocket_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            game_id VARCHAR(64) NOT NULL,
            seq INT NOT NULL,
            player TINYINT NOT NULL,
            ball TINYINT NULL,
            foul TINYINT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_game (game_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $stmt = $pdo->prepare("INSERT INTO pocket_log (game_id, seq, player, ball, foul) VALUES (?,?,?,?,?)");
    $stmt->execute([
        $game_id,
        (int)$event['seq'],
        (int)$event['player'],
        isset($event['ball']) ? $event['ball'] : null,
        (int)($event['foul'] ?? 0),
    ]);

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    // 失敗しても進行は継続できるようにするため 200で返すが、メッセージは返す
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
