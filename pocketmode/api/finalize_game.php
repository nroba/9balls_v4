<?php
// /pocketmode/api/finalize_game.php
declare(strict_types=1);
mb_internal_encoding('UTF-8');

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../sys/db_connect.php';

try {
    $raw = file_get_contents('php://input');
    if ($raw === false) throw new RuntimeException('no input');
    $req = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

    // 必須パラメータ取得
    $game_id     = (string)($req['game_id'] ?? '');
    $date        = (string)($req['date'] ?? '');
    $rule_id     = (int)($req['rule_id'] ?? 0);
    $shop_id     = (int)($req['shop_id'] ?? 0);
    $player1_id  = (int)($req['player1_id'] ?? 0);
    $player2_id  = (int)($req['player2_id'] ?? 0);
    $score1      = (int)($req['score1'] ?? 0); // 使っていないなら無視してOK
    $score2      = (int)($req['score2'] ?? 0); // 使っていないなら無視してOK
    $balls       = (array)($req['balls'] ?? []);
    $ace         = (int)($req['ace'] ?? 0); // ★ 追加：ブレイクエース（1/0）

    if ($game_id === '' || $date === '' || $rule_id<=0 || $shop_id<=0 || $player1_id<=0 || $player2_id<=0) {
        throw new InvalidArgumentException('missing params');
    }

    // balls の正規化
    for ($i=1;$i<=9;$i++){
        if (!isset($balls[$i]) || !is_array($balls[$i])) $balls[$i] = ['assigned'=>null, 'multiplier'=>1];
        $a = $balls[$i]['assigned'];
        $m = $balls[$i]['multiplier'];
        $a = (is_null($a) || $a==='' ? null : (int)$a);
        $m = (int)$m; if ($m<1) $m=1;
        $balls[$i] = ['assigned'=>$a, 'multiplier'=>$m];
    }

    $pdo->beginTransaction();

    // 1ゲーム＝9レコードを挿入（9番のみ ace を反映）
    $sql = "
      INSERT INTO match_detail
        (game_id, date, rule_id, shop_id, player1_id, player2_id,
         ball_number, assigned, multiplier, ace, created_at)
      VALUES
        (:game_id, :date, :rule_id, :shop_id, :p1, :p2,
         :bn, :assigned, :multiplier, :ace, NOW())
    ";
    $stmt = $pdo->prepare($sql);

    for ($bn=1;$bn<=9;$bn++){
        $a = $balls[$bn]['assigned'];
        $m = $balls[$bn]['multiplier'];
        $aceFlag = ($bn===9) ? ($ace ? 1 : 0) : 0; // ★ 9番のみ

        $stmt->execute([
            ':game_id'    => $game_id,
            ':date'       => $date,
            ':rule_id'    => $rule_id,
            ':shop_id'    => $shop_id,
            ':p1'         => $player1_id,
            ':p2'         => $player2_id,
            ':bn'         => $bn,
            ':assigned'   => $a,
            ':multiplier' => $m,
            ':ace'        => $aceFlag,
        ]);
    }

    // （任意）スコアサマリや日別集計テーブルを持っている場合はここで更新

    $pdo->commit();
    echo json_encode(['success'=>true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $e2) {} }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
