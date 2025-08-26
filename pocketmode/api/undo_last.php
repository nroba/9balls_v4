<?php
// /pocketmode/api/undo_last.php
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$game_id = $input['game_id'] ?? null;
if(!$game_id){
    http_response_code(400);
    echo json_encode(['error' => 'bad request']);
    exit;
}

require_once __DIR__ . '/../../sys/db_connect.php';

try {
    // pocket_logから game_id の最大seqを削除
    $stmt = $pdo->prepare("DELETE FROM pocket_log WHERE game_id = ? ORDER BY seq DESC LIMIT 1");
    $stmt->execute([$game_id]);

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
