// /pocketmode/assets/pocketmode.js
(() => {
  const qs = (s, el = document) => el.querySelector(s);
  const qsa = (s, el = document) => [...el.querySelectorAll(s)];

  const state = {
    gameId: null,
    active: 1, // 1 or 2
    seq: 0,
    events: [], // {seq, player, ball, foul, ts}
    players: {1: {id:null, name:'P1'}, 2:{id:null, name:'P2'}},
    score: {1:0, 2:0},
    masters: { players:[], shops:[], rules:[] }
  };

  // 初始化
  document.addEventListener('DOMContentLoaded', async () => {
    // 日付デフォルト
    qs('#date').value = new Date().toISOString().slice(0,10);

    // マスタ読込
    await loadMasters();

    // ハンドラ
    qs('#btnStart').addEventListener('click', onStart);
    qs('#btnFoul').addEventListener('click', onFoul);
    qs('#btnUndo').addEventListener('click', onUndo);
    qs('#btnReset').addEventListener('click', onReset);
    qs('#btnFinish').addEventListener('click', onFinish);
    qsa('.toggle-active').forEach(btn => {
      btn.addEventListener('click', () => {
        state.active = Number(btn.dataset.player);
        renderActive();
      });
    });

    // ボール描画
    const grid = qs('#ballsGrid');
    for (let i=1;i<=9;i++){
      const b = document.createElement('button');
      b.className = 'pm-ball btn';
      b.textContent = i;
      b.dataset.ball = String(i);
      b.addEventListener('click', () => onBall(i));
      grid.appendChild(b);
    }
    render();
  });

  async function loadMasters(){
    const res = await fetch('./api/masters.php');
    const data = await res.json();
    state.masters = data;

    const p1 = qs('#player1_id');
    const p2 = qs('#player2_id');
    const shop = qs('#shop_id');
    const rule = qs('#rule_id');

    const opt = (v, t) => {
      const o = document.createElement('option');
      o.value = v; o.textContent = t;
      return o;
    };

    p1.innerHTML = '<option value="">選択してください</option>';
    p2.innerHTML = '<option value="">選択してください</option>';
    data.players.forEach(r => {
      p1.appendChild(opt(r.id, r.name));
      p2.appendChild(opt(r.id, r.name));
    });

    shop.innerHTML = '<option value="">選択してください</option>';
    data.shops.forEach(r => shop.appendChild(opt(r.id, r.name)));

    rule.innerHTML = '<option value="">選択してください</option>';
    data.rules.forEach(r => {
      const label = r.code ? `${r.code}：${r.name}` : r.name;
      rule.appendChild(opt(r.id, label));
    });
  }

  function newGameId(){
    // YYYYMMDD-HHMMSS-rand3
    const d = new Date();
    const pad = n => String(n).padStart(2,'0');
    const id = [
      d.getFullYear(),
      pad(d.getMonth()+1),
      pad(d.getDate())
    ].join('') + '-' + [pad(d.getHours()), pad(d.getMinutes()), pad(d.getSeconds())].join('') + '-' + Math.floor(Math.random()*900+100);
    return id;
  }

  function onStart(){
    const date = qs('#date').value;
    const rule_id = qs('#rule_id').value;
    const shop_id = qs('#shop_id').value;
    const p1 = qs('#player1_id').value;
    const p2 = qs('#player2_id').value;

    if(!date || !rule_id || !shop_id || !p1 || !p2){
      alert('日付 / ルール / 店舗 / プレイヤー1 / プレイヤー2 を選択してください。');
      return;
    }
    state.gameId = newGameId();
    state.seq = 0;
    state.events = [];
    state.score = {1:0,2:0};
    state.players[1].id = Number(p1);
    state.players[2].id = Number(p2);
    state.players[1].name = qs('#player1_id').selectedOptions[0].textContent;
    state.players[2].name = qs('#player2_id').selectedOptions[0].textContent;
    state.active = Number(qs('#first_player').value);

    // UI
    qs('#gameId').textContent = state.gameId;
    qs('#p1Name').textContent = state.players[1].name;
    qs('#p2Name').textContent = state.players[2].name;
    qsa('.pm-ball').forEach(b=>b.classList.remove('disabled')); // 9番後は自動でdisable予定
    qs('#playArea').classList.remove('d-none');

    render();
  }

  function pushEvent({player, ball=null, foul=0}){
    state.seq += 1;
    const ev = { seq: state.seq, player, ball, foul, ts: Date.now() };
    state.events.push(ev);
    // リアルタイム保存（pocket_logへ）。失敗しても進行は続ける。
    fetch('./api/save_event.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ game_id: state.gameId, event: ev })
    }).catch(()=>{});
    return ev;
  }

  function onBall(ball){
    if(!state.gameId){ alert('先に「ゲーム開始」を押してください。'); return; }
    // すでに入ったボールは無効
    if(alreadyPocketed(ball)) return;

    const ev = pushEvent({player: state.active, ball, foul:0});
    // 9番でゲーム終了
    if(ball === 9){
      // 9番を入れたプレイヤーに+1
      state.score[state.active] += 1;
      // ボールを全て無効化
      qsa('.pm-ball').forEach(b=>b.classList.add('disabled'));
    }
    render();

    // 9番でなければ手番交代（好みで変更可）
    if(ball !== 9){
      toggleActive();
    }
  }

  function onFoul(){
    if(!state.gameId){ alert('先に「ゲーム開始」を押してください。'); return; }
    pushEvent({player: state.active, ball:null, foul:1});
    // ファウルで手番交代
    toggleActive();
    render();
  }

  function alreadyPocketed(ball){
    return state.events.some(e => e.ball === ball);
  }

  function toggleActive(){
    state.active = (state.active === 1 ? 2 : 1);
    renderActive();
  }

  function onUndo(){
    if(!state.gameId) return;
    const last = state.events.pop();
    if(last){
      state.seq = state.events.length ? state.events[state.events.length-1].seq : 0;
      // 直前が9番だったらスコアとボール無効を戻す
      if(last.ball === 9){
        state.score[last.player] = Math.max(0, state.score[last.player] - 1);
        qsa('.pm-ball').forEach(b=>b.classList.remove('disabled'));
      }
      // サーバにもUNDO通知（任意）
      fetch('./api/undo_last.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ game_id: state.gameId })
      }).catch(()=>{});
      render();
    }
  }

  function onReset(){
    if(confirm('このゲームのローカル記録を消去します。よろしいですか？')){
      state.seq = 0;
      state.events = [];
      state.score = {1:0,2:0};
      qsa('.pm-ball').forEach(b=>b.classList.remove('disabled'));
      render();
    }
  }

  async function onFinish(){
    if(!state.gameId){ alert('ゲームを開始してください'); return; }
    // 勝者判定：9番を入れたプレイヤーがいればその人。居なければ多く入れた方、同数なら先攻を勝ちにする(暫定)。
    const nine = [...state.events].reverse().find(e=>e.ball===9);
    let winP = nine ? nine.player : null;
    if(!winP){
      const count = {1:0,2:0};
      state.events.forEach(e => { if(e.ball) count[e.player] += 1; });
      if(count[1] > count[2]) winP = 1;
      else if(count[2] > count[1]) winP = 2;
      else winP = Number(qs('#first_player').value);
    }

    const payload = {
      game_id: state.gameId,
      date: qs('#date').value,
      shop_id: Number(qs('#shop_id').value),
      rule_id: Number(qs('#rule_id').value),
      player1_id: state.players[1].id,
      player2_id: state.players[2].id,
      score1: (winP===1 ? 1 : 0),
      score2: (winP===2 ? 1 : 0),
      // 参考用にイベント全体も送る（保存は任意）
      events: state.events
    };

    const res = await fetch('./api/finalize_game.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify(payload)
    });
    const data = await res.json();
    if(data.success){
      alert('保存しました。match_detailに1レコード追加されています。');
      // 次ゲーム準備：手番は入れ替え（任意）
      state.gameId = null;
      qs('#playArea').classList.add('d-none');
      render();
    }else{
      alert('保存に失敗しました: ' + (data.error || 'unknown'));
    }
  }

  function render(){
    // スコア表示（ここでは「9番を入れたら1勝」）
    qs('#p1Score').textContent = state.score[1];
    qs('#p2Score').textContent = state.score[2];

    // ログ表示
    qs('#logView').textContent = state.events
      .map(e => {
        const who = e.player===1 ? 'P1' : 'P2';
        if(e.foul) return `#${e.seq} ${who} FOUL`;
        if(e.ball) return `#${e.seq} ${who} POCKET ${e.ball}`;
        return `#${e.seq} ${who}`;
      })
      .join('\n');

    renderActive();
    // 入ったボールをグレーアウト
    qsa('.pm-ball').forEach(b => {
      const num = Number(b.dataset.ball);
      if(state.events.some(e=>e.ball===num)) b.classList.add('disabled');
    });
  }

  function renderActive(){
    qs('#activeLabel').textContent = (state.active===1 ? 'P1' : 'P2');
    qs('#activeLabel').classList.toggle('bg-primary', state.active===1);
    qs('#activeLabel').classList.toggle('bg-success', state.active===2);
  }
})();
