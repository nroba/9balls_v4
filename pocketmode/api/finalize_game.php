<?php
// /pocketmode/api/finalize_game.php — ハイブリッド保存（明細/ヘッダ自動判定）＋強制ログ
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
$DEBUG = (isset($_GET['debug']) && $_GET['debug']==='1');
if ($DEBUG){ ini_set('display_errors','1'); ini_set('display_startup_errors','1'); }

/* ログ初期化 */
$logCandidates = [
  dirname(__DIR__,2).'/logs/pocketmode_api.log',
  __DIR__.'/pocketmode_api.log',
  rtrim(sys_get_temp_dir(),'/').'/pocketmode_api.log',
];
$LOG=null; foreach($logCandidates as $p){ $d=dirname($p); if(is_dir($d)||@mkdir($d,0775,true)){ if(@file_put_contents($p,"[".date('c')."] bootstrap\n",FILE_APPEND)!==false){ $LOG=$p; break; } } }
function logf($m){ global $LOG; @error_log($m); if($LOG){ @file_put_contents($LOG,"[".date('c')."] $m\n",FILE_APPEND); } }
function respond($ok,$extra=[]){ global $LOG,$logCandidates; $extra['log_path']=$LOG?:'(unavailable)'; $extra['log_candidates']=$logCandidates; echo json_encode(array_merge(['success'=>$ok],$extra),JSON_UNESCAPED_UNICODE); exit; }

require_once __DIR__.'/../../sys/db_connect.php';
if(!isset($pdo)||!$pdo){ logf('no $pdo'); respond(false,['error'=>'DB接続に失敗しました']); }
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);

/* 入力 */
$raw=file_get_contents('php://input');
if($raw===''||$raw===false){ logf('empty body'); respond(false,['error'=>'空のリクエストです']); }
$data=json_decode($raw,true);
if(!is_array($data)){ logf('json decode failed: '.$raw); respond(false,['error'=>'JSONの形式が不正です']); }

function intOrNull($v){ $i=filter_var($v,FILTER_VALIDATE_INT); return ($i===false)?null:$i; }
function validDateYmd($s){ if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$s))return false; $dt=DateTime::createFromFormat('Y-m-d',$s); return $dt && $dt->format('Y-m-d')===$s; }

$game_id = isset($data['game_id']) ? substr(preg_replace('/[^A-Za-z0-9_\-]/','',(string)$data['game_id']),0,64) : bin2hex(random_bytes(8));
$date    = $data['date'] ?? date('Y-m-d'); if(!validDateYmd($date)) $date=date('Y-m-d');

$rule_id    = intOrNull($data['rule_id']    ?? null);
$shop_id    = intOrNull($data['shop_id']    ?? null);
$player1_id = intOrNull($data['player1_id'] ?? null);
$player2_id = intOrNull($data['player2_id'] ?? null);
$score1     = (int)(($data['score1'] ?? 0)==1?1:0);
$score2     = (int)(($data['score2'] ?? 0)==1?1:0);
$balls      = is_array($data['balls'] ?? null) ? $data['balls'] : null;

/* 必須チェック（UI側前提） */
$missing=[]; if(!$rule_id)$missing[]='ルール'; if(!$shop_id)$missing[]='店舗'; if(!$player1_id)$missing[]='プレイヤー1'; if(!$player2_id)$missing[]='プレイヤー2';
if($player1_id && $player2_id && $player1_id===$player2_id){ respond(false,['error'=>'同一プレイヤー同士は登録できません']); }
if($missing){ $msg='未選択: '.implode(' / ',$missing); logf($msg.' payload='.json_encode($data,JSON_UNESCAPED_UNICODE)); respond(false,['error'=>$msg]); }

/* ユーティリティ */
function tblExists(PDO $pdo,$t){ try{ return (bool)$pdo->query("SHOW TABLES LIKE ".$pdo->quote($t))->fetchColumn(); }catch(Throwable $e){ return false; } }
function listCols(PDO $pdo,$t){ try{$a=[]; foreach($pdo->query("SHOW COLUMNS FROM `$t`") as $r){ $a[strtolower($r['Field'])]=$r; } return $a; }catch(Throwable $e){ return []; } }
function has($cols,$c){ return array_key_exists(strtolower($c),$cols); }
function fetchName(PDO $pdo,$table,$id){
  if(!$id) return null;
  $map=['rule_master'=>'name','shop_master'=>'name','player_master'=>'name'];
  $pk='id'; $col=$map[$table]??'name';
  $st=$pdo->prepare("SELECT `$col` FROM `$table` WHERE `$pk`=? LIMIT 1");
  $st->execute([$id]); $v=$st->fetchColumn();
  return $v!==false ? $v : null;
}

/* テーブル準備（無ければ作成を試行：汎用） */
if(!tblExists($pdo,'match_detail')){
  try{
    $pdo->exec("CREATE TABLE `match_detail` (
      `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
      `game_id` VARCHAR(64) NULL,
      `date` DATE NULL,
      `rule_id` INT NULL, `rule` VARCHAR(255) NULL,
      `shop_id` INT NULL, `shop` VARCHAR(255) NULL,
      `player1_id` INT NULL, `player1` VARCHAR(255) NULL,
      `player2_id` INT NULL, `player2` VARCHAR(255) NULL,
      `ball_number` TINYINT NULL, `assigned` TINYINT NULL, `multiplier` TINYINT NULL,
      `score1` TINYINT NULL, `score2` TINYINT NULL,
      `ace1` TINYINT NULL, `ace2` TINYINT NULL,
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_game_ball (game_id, ball_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    logf('created match_detail (generic)');
  }catch(Throwable $e){ logf('create match_detail failed: '.$e->getMessage()); }
}

/* 実在カラム解析 */
$cols = listCols($pdo,'match_detail');
if(!$cols){ respond(false,['error'=>'match_detail にアクセスできません']); }

/* 列名マッピング決定 */
$dateCol = has($cols,'game_date') ? 'game_date' : (has($cols,'date') ? 'date' : null);
$use_game_id = has($cols,'game_id');
$p1_col = has($cols,'player1_id') ? 'player1_id' : (has($cols,'p1_id') ? 'p1_id' : (has($cols,'player1')?'player1':null));
$p2_col = has($cols,'player2_id') ? 'player2_id' : (has($cols,'p2_id') ? 'p2_id' : (has($cols,'player2')?'player2':null));
$rule_col_id = has($cols,'rule_id') ? 'rule_id' : null;
$rule_col_tx = (!$rule_col_id && has($cols,'rule')) ? 'rule' : null;
$shop_col_id = has($cols,'shop_id') ? 'shop_id' : null;
$shop_col_tx = (!$shop_col_id && has($cols,'shop')) ? 'shop' : null;
$scorePair = null; foreach([['score1','score2'],['p1_win','p2_win']] as $pair){ if(has($cols,$pair[0]) && has($cols,$pair[1])){ $scorePair=$pair; break; } }
$hasBall = has($cols,'ball_number'); // ← これが「明細モード」判定

/* 名称が必要ならマスタから取得 */
$rule_name   = ($rule_col_tx && !$rule_col_id) ? (fetchName($pdo,'rule_master',$rule_id) ?: (string)$rule_id) : null;
$shop_name   = ($shop_col_tx && !$shop_col_id) ? (fetchName($pdo,'shop_master',$shop_id) ?: (string)$shop_id) : null;
$player1Name = ($p1_col==='player1') ? (fetchName($pdo,'player_master',$player1_id) ?: (string)$player1_id) : null;
$player2Name = ($p2_col==='player2') ? (fetchName($pdo,'player_master',$player2_id) ?: (string)$player2_id) : null;

/* =============== 1) 明細モード（ball_number がある）: 9行INSERT =============== */
if ($hasBall && is_array($balls)) {
  try{
    $pdo->beginTransaction();
    if($use_game_id){
      $del=$pdo->prepare("DELETE FROM `match_detail` WHERE `game_id`=?");
      $del->execute([$game_id]);
    }
    $used=['mode'=>'detail','table'=>'match_detail','date_col'=>$dateCol,'score_cols'=>$scorePair];

    // 1本の動的INSERTを準備（存在する列だけ使う）
    $baseCols=[]; $params=[]; $ph=[];
    if($use_game_id){ $baseCols[]='game_id'; }
    if($dateCol){ $baseCols[]=$dateCol; }
    if($rule_col_id){ $baseCols[]=$rule_col_id; } elseif($rule_col_tx){ $baseCols[]=$rule_col_tx; }
    if($shop_col_id){ $baseCols[]=$shop_col_id; } elseif($shop_col_tx){ $baseCols[]=$shop_col_tx; }
    if($p1_col){ $baseCols[]=$p1_col; }
    if($p2_col){ $baseCols[]=$p2_col; }
    $baseCols[]='ball_number';
    if(has($cols,'assigned'))   $baseCols[]='assigned';
    if(has($cols,'multiplier')) $baseCols[]='multiplier';
    if($scorePair){ $baseCols[]=$scorePair[0]; $baseCols[]=$scorePair[1]; }
    if(has($cols,'ace1')) $baseCols[]='ace1';
    if(has($cols,'ace2')) $baseCols[]='ace2';

    $into = '`'.implode('`,`',$baseCols).'`';
    $sql = "INSERT INTO `match_detail` ($into) VALUES (".implode(',', array_fill(0,count($baseCols),'?')).")";
    $ins = $pdo->prepare($sql);

    for($i=1; $i<=9; $i++){
      $row=[];
      if($use_game_id) $row[]=$game_id;
      if($dateCol)     $row[]=$date;
      if($rule_col_id) $row[]=$rule_id; elseif($rule_col_tx) $row[]=$rule_name;
      if($shop_col_id) $row[]=$shop_id; elseif($shop_col_tx) $row[]=$shop_name;
      if($p1_col) $row[] = ($p1_col==='player1_id'||$p1_col==='p1_id') ? $player1_id : $player1Name;
      if($p2_col) $row[] = ($p2_col==='player2_id'||$p2_col==='p2_id') ? $player2_id : $player2Name;

      $row[] = $i; // ball_number

      $b = $balls[$i] ?? ['assigned'=>null,'multiplier'=>1];
      if(has($cols,'assigned'))   $row[] = (isset($b['assigned']) && ($b['assigned']===1||$b['assigned']===2)) ? (int)$b['assigned'] : null;
      if(has($cols,'multiplier')) $row[] = (isset($b['multiplier']) && (int)$b['multiplier']>0) ? (int)$b['multiplier'] : 1;

      if($scorePair){ $row[]=$score1; $row[]=$score2; }
      if(has($cols,'ace1')) $row[] = (int)($data['ace1'] ?? 0);
      if(has($cols,'ace2')) $row[] = (int)($data['ace2'] ?? 0);

      $ins->execute($row);
    }
    $pdo->commit();
    respond(true,['used'=>$used]);
  }catch(Throwable $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    logf('[DETAIL INSERT ERR] '.$e->getMessage().' payload='.json_encode($data,JSON_UNESCAPED_UNICODE));
    respond(false,['error'=>'保存エラー(明細): '.$e->getMessage()]);
  }
}

/* =============== 2) ヘッダモード（ball_number 無し）: 1行UPSERT =============== */
$insCols=[]; $ph=[]; $params=[]; $upd=[];
if($use_game_id){ $insCols[]='game_id'; $ph[]=':gid'; $params[':gid']=$game_id; $upd[]='game_id=VALUES(game_id)'; }
if($dateCol){ $insCols[]=$dateCol; $ph[]=':gdate'; $params[':gdate']=$date; $upd[]="$dateCol=VALUES($dateCol)"; }
if($rule_col_id){ $insCols[]='rule_id'; $ph[]=':rid'; $params[':rid']=$rule_id; $upd[]='rule_id=VALUES(rule_id)'; }
if($rule_col_tx){ $insCols[]='rule'; $ph[]=':rtx'; $params[':rtx']=$rule_name; $upd[]='rule=VALUES(rule)'; }
if($shop_col_id){ $insCols[]='shop_id'; $ph[]=':sid'; $params[':sid']=$shop_id; $upd[]='shop_id=VALUES(shop_id)'; }
if($shop_col_tx){ $insCols[]='shop'; $ph[]=':stx'; $params[':stx']=$shop_name; $upd[]='shop=VALUES(shop)'; }
if($p1_col){ $insCols[]=$p1_col; $ph[]=':p1'; $params[':p1']=($p1_col==='player1_id'||$p1_col==='p1_id')?$player1_id:$player1Name; $upd[]="$p1_col=VALUES($p1_col)"; }
if($p2_col){ $insCols[]=$p2_col; $ph[]=':p2'; $params[':p2']=($p2_col==='player2_id'||$p2_col==='p2_id')?$player2_id:$player2Name; $upd[]="$p2_col=VALUES($p2_col)"; }
if($scorePair){ $insCols[]=$scorePair[0]; $ph[]=':s1'; $params[':s1']=$score1; $upd[]="{$scorePair[0]}=VALUES({$scorePair[0]})";
                $insCols[]=$scorePair[1]; $ph[]=':s2'; $params[':s2']=$score2; $upd[]="{$scorePair[1]}=VALUES({$scorePair[1]})"; }

if(empty($insCols)){ respond(false,['error'=>'保存可能な列が見つかりません（スキーマ確認）']); }

$into='`'.implode('`,`',$insCols).'`'; $vals=implode(',',$ph);
$sql="INSERT INTO `match_detail` ($into) VALUES ($vals)"; if($use_game_id){ $sql.=" ON DUPLICATE KEY UPDATE ".implode(', ',$upd); }
try{
  $st=$pdo->prepare($sql); $st->execute($params);
  respond(true,['used'=>['mode'=>'header','table'=>'match_detail','date_col'=>$dateCol,'score_cols'=>$scorePair]]);
}catch(Throwable $e){
  logf('[HEADER INSERT ERR] '.$e->getMessage().' SQL='.$sql.' params='.json_encode($params));
  respond(false,['error'=>'保存エラー(ヘッダ): '.$e->getMessage()]);
}
