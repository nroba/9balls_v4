/* Pocketmode（横スワイプ固定＋登録＋マスタ読込＋ACE＋当日成績モーダル）
   - ルールA/B 共通で「Side」バッジ表示
   - ルールA: 倍率 1⇔2（倍率2の時に「Side」）
   - ルールB: 倍率は使わず isSide をトグル（スコアは等倍）
   - 9番右に「ACE」（画像は他ボールと同じ見た目で配置）
   - 画面下： [Menu] [Reset] [Score] [Regist] を等幅で並べる（Menuは下線無し）
   - 「Score」→ 当日の対戦成績をモーダルで表示（列は Player / Win / Score / Pocketed）
   - ★ 追加：トップ帯・ボトムボタンを少し濃いグレーに／右上設定ボタンを画像化
*/
(() => {
  "use strict";

  // ====== 定数・要素 ======
  const MENU_URL     = "/index.php";
  const API_MASTERS  = "/pocketmode/api/masters.php";
  const API_FINALIZE = "/pocketmode/api/finalize_game.php";
  const API_TODAY    = "/pocketmode/api/today_stats.php";

  const grid        = document.getElementById("ballGrid");
  const popup       = document.getElementById("popup");
  const resetBtn    = document.getElementById("resetBtn");
  const registBtn   = document.getElementById("registBtn");
  const ruleSel     = document.getElementById("ruleSelect");
  const p1Sel       = document.getElementById("player1");
  const p2Sel       = document.getElementById("player2");
  const shopSel     = document.getElementById("shop");
  const dateInput   = document.getElementById("dateInput");
  const settingsBtn = document.getElementById("settingsBtn");
  const settingsBox = document.getElementById("gameSettings");
  const titleEl     = document.querySelector(".pm-title");

  if (!grid) { console.warn("ballGrid が見つかりません"); return; }

  // ====== テーマ色（“背景より少し濃いグレー”）
  const GRAY_SOFT = "#f3f4f6"; // トップ帯（プレイヤー名＋スコア）用
  const GRAY_BTN  = "#e5e7eb"; // ボトム4ボタン用
  const GRAY_BR   = "#d1d5db"; // 枠線
  const TXT_DARK  = "#111827";

  // ====== ユーティリティ ======
  const z2 = (n)=> String(n).padStart(2,"0");
  const todayYmd = ()=>{
    const d=new Date(); const mm=z2(d.getMonth()+1), dd=z2(d.getDate());
    return `${d.getFullYear()}-${mm}-${dd}`;
  };

  function playSoundOverlap(src){ try{ new Audio(src).play(); }catch(e){} }
  function showPopup(text, ms=1000){
    if (!popup) return;
    popup.textContent = text; popup.style.display = "block";
    setTimeout(()=> popup.style.display = "none", ms);
  }
  function updateLabels(){
    const l1 = document.getElementById("label1");
    const l2 = document.getElementById("label2");
    if (l1) l1.textContent = p1Sel?.selectedOptions?.[0]?.textContent || "Player 1";
    if (l2) l2.textContent = p2Sel?.selectedOptions?.[0]?.textContent || "Player 2";
  }

  // 右上設定ボタンを画像化（現行サイズ維持）
  function paintSettingsButton(){
    if (!settingsBtn) return;
    // 現行の見かけサイズを取得
    const h = settingsBtn.offsetHeight || 24;
    const w = settingsBtn.offsetWidth  || h;
    // 中身を画像に差し替え
    settingsBtn.textContent = "";
    const img = document.createElement("img");
    img.src = "/images/btn_config.png";
    img.alt = "設定";
    // 高さ基準で合わせる（横は自動）
    img.style.height = `${h}px`;
    img.style.width  = "auto";
    img.style.display = "block";
    // 余白を詰め、見た目サイズは維持
    settingsBtn.style.padding = "0";
    settingsBtn.style.width   = `${w}px`;
    settingsBtn.style.height  = `${h}px`;
    settingsBtn.appendChild(img);
  }

  // ルール判定 & タイトル反映
  function getRuleCode(){
    const opt = ruleSel?.selectedOptions?.[0];
    const codeByData = opt?.dataset?.code?.trim();
    if (codeByData) return codeByData.toUpperCase();
    const t = (opt?.textContent || "").toUpperCase();
    if (t.includes("A")) return "A";
    if (t.includes("B")) return "B";
    return "";
  }
  function isRuleA(){ return getRuleCode() === "A"; }

  function updateRuleUI(){
    const code = getRuleCode() || "?";
    if (titleEl){ titleEl.textContent = `Pocketmode — ルール${code}`; }
    const aMode = isRuleA();
    for (let i=1;i<=9;i++){
      const st = ballState[i]; if (!st) continue;
      if (aMode){
        if (st.isSide && st.multiplier !== 2) st.multiplier = 2; // B→A
      } else {
        if (st.multiplier === 2) st.isSide = true;               // A→B
        st.multiplier = 1;
      }
      updateBadge(i);
    }
  }

  // ====== マスタ取得＆初期選択 ======
  function makeOpt(id, name, code){
    const o = document.createElement("option");
    o.value = String(id);
    o.dataset.id = String(id);
    if (code) o.dataset.code = String(code);
    o.textContent = code ? `${code}：${name}` : name;
    return o;
  }
  function ensureFirstNonEmpty(sel){
    if (!sel) return;
    if (sel.value) return;
    for (let i=0;i<sel.options.length;i++){
      if (sel.options[i].value !== "") { sel.selectedIndex = i; break; }
    }
  }
  function playersCount(sel){ return sel ? Array.from(sel.options).filter(o=>o.value!=="").length : 0; }
  function chooseTwoDistinctPlayers(){
    if (!p1Sel || !p2Sel) return;
    const opts = Array.from(p1Sel.options).filter(o=>o.value!=="");
    if (opts.length >= 2){
      p1Sel.value = opts[0].value;
      p2Sel.value = opts[1].value;
    } else {
      ensureFirstNonEmpty(p1Sel);
      ensureFirstNonEmpty(p2Sel);
    }
  }
  function autoAvoidSamePlayers(changedSel){
    if (!p1Sel || !p2Sel) return;
    if (p1Sel.value && p2Sel.value && p1Sel.value === p2Sel.value){
      const target = (changedSel === p1Sel) ? p2Sel : p1Sel;
      const opts = Array.from(target.options).filter(o=>o.value!=="");
      if (opts.length >= 2){
        for (let i=0;i<opts.length;i++){
          const tryVal = opts[i].value;
          if (tryVal !== (changedSel === p1Sel ? p1Sel.value : p2Sel.value)){
            target.value = tryVal; break;
          }
        }
      }
    }
    updateLabels();
  }
  async function loadMasters(){
    try{
      const res = await fetch(API_MASTERS, { cache:"no-store" });
      const data = await res.json();

      if (Array.isArray(data.rules) && ruleSel){
        ruleSel.innerHTML = "";
        data.rules.forEach(r => ruleSel.appendChild(makeOpt(r.id, r.name, r.code)));
      }
      if (Array.isArray(data.shops) && shopSel){
        shopSel.innerHTML = "";
        data.shops.forEach(s => shopSel.appendChild(makeOpt(s.id, s.name)));
      }
      if (Array.isArray(data.players) && p1Sel && p2Sel){
        p1Sel.innerHTML = ""; p2Sel.innerHTML = "";
        data.players.forEach(p => {
          p1Sel.appendChild(makeOpt(p.id, p.name));
          p2Sel.appendChild(makeOpt(p.id, p.name));
        });
      }

      ensureFirstNonEmpty(ruleSel);
      ensureFirstNonEmpty(shopSel);
      chooseTwoDistinctPlayers();

      updateLabels(); updateRuleUI();
    }catch(e){
      console.warn("masters取得失敗:", e);
      ensureFirstNonEmpty(ruleSel);
      ensureFirstNonEmpty(shopSel);
      ensureFirstNonEmpty(p1Sel);
      ensureFirstNonEmpty(p2Sel);
      updateLabels(); updateRuleUI();
    }
  }

  // ====== 状態＆スコア ======
  const ballState = {}; // { [i]: { swiped, assigned: 1|2|null, multiplier: 1|2, isSide: boolean, wrapper } }
  let score1=0, score2=0;

  // ★ ACE 状態
  let breakAce = false;

  // バッジ更新（A/B 共通で「Side」表記）
  function updateBadge(num){
    const label = document.getElementById(`multi${num}`);
    const st = ballState[num];
    if (!label || !st) return;

    if (isRuleA()){
      const show = (st.multiplier === 2) || !!st.isSide;
      if (show){
        label.textContent = "Side";
        label.style.display = "block";
        label.classList.remove("bounce"); void label.offsetWidth; label.classList.add("bounce");
      } else {
        label.style.display = "none"; label.classList.remove("bounce");
      }
    } else {
      if (st.isSide){
        label.textContent = "Side";
        label.style.display = "block";
        label.classList.remove("bounce"); void label.offsetWidth; label.classList.add("bounce");
      } else {
        label.style.display = "none"; label.classList.remove("bounce");
      }
    }
  }

  function updateScoreDisplay(){
    const s1 = document.getElementById("score1");
    const s2 = document.getElementById("score2");
    if (s1) s1.textContent = score1;
    if (s2) s2.textContent = score2;
  }
  function recalcScores(){
    score1 = 0; score2 = 0;
    const aRule = isRuleA();
    for (let j=1;j<=9;j++){
      const st = ballState[j];
      if (!st?.swiped || !st.assigned) continue;
      let point = 0;
      if (aRule){
        if (j===9) point=2; else if (j%2===1) point=1;
        point *= st.multiplier;   // Aのみ倍率有効
      } else {
        point = (j===9) ? 2 : 1;  // Bでは常に等倍
      }
      if (st.assigned===1) score1 += point;
      if (st.assigned===2) score2 += point;
    }
    updateScoreDisplay();
  }

  // ====== グリッド生成（Aceを他玉と同じ見た目で配置） ======
  function buildGrid(){
    grid.innerHTML="";
    for (let i=1;i<=9;i++){
      const wrap = document.createElement("div");
      wrap.className = "ball-wrapper";
      wrap.style.opacity = "0.5";

      const img = document.createElement("img");
      img.className = "ball";
      img.src = `/images/ball${i}.png`;
      img.alt = `Ball ${i}`;
      img.setAttribute("draggable","false");

      const label = document.createElement("div");
      label.className = "ball-multiplier";
      label.id = `multi${i}`;
      label.textContent = "";
      label.style.display = "none";

      wrap.appendChild(img); wrap.appendChild(label);
      grid.appendChild(wrap);

      ballState[i] = { swiped:false, assigned:null, multiplier:1, isSide:false, wrapper:wrap };
      attachSwipeHandlers(wrap, i);
    }

    // === 9番の右に ACE（見た目は他玉と同一レイアウト） ===
    const aceWrap = document.createElement("div");
    aceWrap.className = "ball-wrapper";
    aceWrap.style.opacity = breakAce ? "1" : "0.5";
    aceWrap.id = "aceWrap";

    const aceImg = document.createElement("img");
    aceImg.className = "ball";
    aceImg.src = "/images/ball_ace.png";
    aceImg.alt = "Break Ace";
    aceImg.setAttribute("draggable","false");

    aceWrap.appendChild(aceImg);
    grid.appendChild(aceWrap);

    aceWrap.addEventListener("click", ()=>{
      breakAce = !breakAce;
      aceWrap.style.opacity = breakAce ? "1" : "0.5";
      showPopup(breakAce ? "ブレイクエース" : "解除");
    });
  }

  // ====== スワイプ（左右固定） ======
  function attachSwipeHandlers(el, n){
    const TH = 30;
    let startX = null;

    const onStart = (x)=>{ startX = x; };
    const onEnd   = (x)=>{
      if (startX==null) return;
      const dx = x - startX; startX = null;

      const st = ballState[n], prev = st.assigned;
      if (!st.swiped){
        if (dx < -TH){ st.assigned=1; st.swiped=true; restartAnimation(el,"roll-left");  playSoundOverlap("sounds/swipe.mp3"); }
        else if (dx > TH){ st.assigned=2; st.swiped=true; restartAnimation(el,"roll-right"); playSoundOverlap("sounds/swipe.mp3"); }
        else { return; }
      } else {
        if ((prev===1 && dx>TH) || (prev===2 && dx<-TH)){
          st.assigned=null; st.swiped=false;
          el.classList.remove("roll-left","roll-right");
          el.style.opacity="0.5";
          playSoundOverlap("sounds/cancel.mp3");
        }
      }
      recalcScores(); updateBadge(n);
    };

    el.addEventListener("touchstart",(e)=> onStart(e.touches[0].clientX), {passive:true});
    el.addEventListener("touchend",  (e)=> onEnd(e.changedTouches[0].clientX));
    el.addEventListener("mousedown", (e)=> onStart(e.clientX));
    el.addEventListener("mouseup",   (e)=> onEnd(e.clientX));

    // タップで「Side」切替
    el.addEventListener("click", ()=>{
      const st = ballState[n];
      if (!st.swiped) return;

      if (!isRuleA()){
        st.isSide = !st.isSide;                         // B
        showPopup(st.isSide ? "サイド" : "通常");
        playSoundOverlap("sounds/side.mp3");
        updateBadge(n);
        return;
      }
      st.multiplier = (st.multiplier===1)?2:1;          // A
      st.isSide = (st.multiplier === 2);
      updateBadge(n);
      showPopup(st.multiplier===2 ? "サイド（得点×2）" : "コーナー（得点×1）");
      playSoundOverlap("sounds/side.mp3");
      recalcScores();
    });
  }

  function restartAnimation(el, cls){
    el.classList.remove("roll-left","roll-right");
    void el.offsetWidth; // reflow
    el.classList.add(cls);
    el.style.opacity = "1";
  }

  // ====== トップ帯を“少し濃いグレー”で塗る ======
  function paintTopBand(){
    const l1 = document.getElementById("label1");
    const l2 = document.getElementById("label2");
    const s1 = document.getElementById("score1");
    const s2 = document.getElementById("score2");

    // 共通祖先を優先的に塗る
    const ca = (a,b)=>{
      if (!a || !b) return null;
      const set = new Set();
      let x=a; while(x){ set.add(x); x=x.parentElement; }
      let y=b; while(y){ if (set.has(y)) return y; y=y.parentElement; }
      return null;
    };
    let target =
      ca(l1,l2) || ca(s1,s2) ||
      document.querySelector(".scoreboard") ||
      document.querySelector(".pm-top") ||
      document.getElementById("topArea") ||
      document.querySelector(".pm-header") ||
      (l1 && l1.parentElement) || (s1 && s1.parentElement);

    if (!target) return;

    Object.assign(target.style, {
      background: GRAY_SOFT,
      border: `1px solid ${GRAY_BR}`,
      borderRadius: "12px",
      padding: "8px 10px",
      color: TXT_DARK
    });
  }

  // ====== ボタン行（[Menu][Reset][Score][Regist]） ======
  function ensureBottomButtons(){
    // 共通親
    const host = (resetBtn && registBtn && resetBtn.parentElement === registBtn.parentElement)
      ? resetBtn.parentElement
      : (resetBtn?.parentElement || registBtn?.parentElement || null);
    if (!host) return;

    // 既存のリセット／登録ボタンの表記を英字へ
    if (resetBtn)  resetBtn.textContent  = "Reset";
    if (registBtn) registBtn.textContent = "Regist";

    // 1) Menuボタン（左端）
    let menuBtn = document.getElementById("menuBtn");
    if (!menuBtn){
      menuBtn = document.createElement("a");
      menuBtn.id = "menuBtn";
      menuBtn.href = MENU_URL;
      menuBtn.textContent = "Menu";
      // 既存ボタンに合わせる & 下線無し
      menuBtn.className = resetBtn?.className || "pm-btn";
      menuBtn.style.textDecoration = "none";
      host.insertBefore(menuBtn, resetBtn || host.firstChild);
    } else {
      menuBtn.href = MENU_URL;
      menuBtn.textContent = "Menu";
      menuBtn.style.textDecoration = "none";
    }

    // 2) Scoreボタン（リセットと登録の間）
    let scoreBtn = document.getElementById("scoreBtn");
    if (!scoreBtn){
      scoreBtn = document.createElement("button");
      scoreBtn.id = "scoreBtn";
      scoreBtn.type = "button";
      scoreBtn.textContent = "Score";
      scoreBtn.className = registBtn?.className || resetBtn?.className || "pm-btn";
      host.insertBefore(scoreBtn, registBtn || null);
      scoreBtn.addEventListener("click", openTodayModal);
    } else {
      scoreBtn.textContent = "Score";
    }

    // 3) 等幅にする（4等分）＋“少し濃いグレー”に塗る
    try{
      host.style.display = "grid";
      host.style.gridTemplateColumns = "repeat(4, 1fr)";
      host.style.gap = host.style.gap || "8px";

      [menuBtn, resetBtn, scoreBtn, registBtn].forEach(el=>{
        if (!el) return;
        el.style.width = "100%";
        el.style.background = GRAY_BTN;
        el.style.border = `1px solid ${GRAY_BR}`;
        el.style.color = TXT_DARK;
      });
    }catch(e){}
  }

  // ====== 当日の対戦成績モーダル（列名：Player / Win / Score / Pocketed） ======
  function buildModalSkeleton(){
    if (document.getElementById("scoreModalOverlay")) return;
    const overlay = document.createElement("div");
    overlay.id = "scoreModalOverlay";
    Object.assign(overlay.style, {
      position:"fixed", inset:"0", background:"rgba(0,0,0,.45)", display:"none",
      zIndex:"1000", alignItems:"center", justifyContent:"center", padding:"16px"
    });

    const modal = document.createElement("div");
    modal.id = "scoreModal";
    Object.assign(modal.style, {
      background:"#fff", borderRadius:"12px", maxWidth:"720px", width:"100%",
      boxShadow:"0 20px 50px rgba(0,0,0,.2)", overflow:"hidden"
    });

    const head = document.createElement("div");
    Object.assign(head.style, {
      display:"flex", alignItems:"center", justifyContent:"space-between",
      padding:"12px 16px", borderBottom:"1px solid #eee", background:"#fafafa"
    });
    const title = document.createElement("div");
    title.id = "scoreModalTitle";
    title.textContent = "当日の対戦成績";
    Object.assign(title.style, {fontWeight:"700"});
    const closeBtn = document.createElement("button");
    closeBtn.textContent = "×";
    Object.assign(closeBtn.style, {fontSize:"18px", border:"1px solid #ddd", borderRadius:"8px", background:"#fff", cursor:"pointer", width:"32px", height:"32px"});
    closeBtn.addEventListener("click", ()=> overlay.style.display="none");

    const body = document.createElement("div");
    body.id = "scoreModalBody";
    Object.assign(body.style, {padding:"14px 16px", lineHeight:"1.6", color:"#111"});

    head.appendChild(title); head.appendChild(closeBtn);
    modal.appendChild(head); modal.appendChild(body);
    overlay.appendChild(modal);
    overlay.addEventListener("click", (e)=>{ if (e.target===overlay) overlay.style.display="none"; });

    document.body.appendChild(overlay);
  }

  async function openTodayModal(){
    buildModalSkeleton();
    const overlay = document.getElementById("scoreModalOverlay");
    const body    = document.getElementById("scoreModalBody");
    const dateStr = (dateInput?.value || todayYmd());

    body.innerHTML = `<div style="color:#555;">読み込み中…</div>`;
    overlay.style.display = "flex";

    try{
      const url = `${API_TODAY}?date=${encodeURIComponent(dateStr)}`;
      const res = await fetch(url, {cache:"no-store"});
      const data = await res.json();

      if (!data || data.status!=="ok"){
        throw new Error(data?.message || "データ取得に失敗しました");
      }

      const games = data.games || 0;
      const players = Array.isArray(data.players) ? data.players : [];

      let html = `
        <div style="margin-bottom:8px;color:#333;">日付：<b>${escapeHtml(dateStr)}</b>　ゲーム数：<b>${games}</b></div>
        <div style="overflow:auto;">
          <table style="width:100%; border-collapse:collapse;">
            <thead>
              <tr style="background:#f6f6f6;">
                <th style="text-align:left;  padding:8px; border-bottom:1px solid #eee;">Player</th>
                <th style="text-align:right; padding:8px; border-bottom:1px solid #eee;">Win</th>
                <th style="text-align:right; padding:8px; border-bottom:1px solid #eee;">Score</th>
                <th style="text-align:right; padding:8px; border-bottom:1px solid #eee;">Pocketed</th>
              </tr>
            </thead>
            <tbody>
      `;
      players.forEach(p=>{
        html += `
          <tr>
            <td style="padding:8px; border-bottom:1px solid #f0f0f0;">${escapeHtml(p.name)}</td>
            <td style="padding:8px; border-bottom:1px solid #f0f0f0; text-align:right;">${Number(p.wins||0)}</td>
            <td style="padding:8px; border-bottom:1px solid #f0f0f0; text-align:right;">${Number(p.score||0)}</td>
            <td style="padding:8px; border-bottom:1px solid #f0f0f0; text-align:right;">${Number(p.balls||0)}</td>
          </tr>
        `;
      });
      html += `</tbody></table></div>`;
      body.innerHTML = html;

    }catch(err){
      console.error(err);
      body.innerHTML = `<div style="color:#c00;">エラー：${escapeHtml(err.message || String(err))}</div>`;
    }
  }

  function escapeHtml(s){
    return String(s).replace(/[&<>"']/g, m=>({ "&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;" }[m]));
  }

  // ====== リセット ======
  function resetAll(){
    for (let i=1;i<=9;i++){
      const st = ballState[i]; if (!st) continue;
      st.swiped=false; st.assigned=null; st.multiplier=1; st.isSide=false;
      const w=st.wrapper; w.classList.remove("roll-left","roll-right"); w.style.opacity="0.5";
      updateBadge(i);
    }
    score1=0; score2=0; updateScoreDisplay();

    // ACE のリセット
    breakAce = false;
    const aceWrap = document.getElementById("aceWrap");
    if (aceWrap){ aceWrap.style.opacity = "0.5"; }

    const box = document.getElementById("postRegistActions");
    if (box) box.style.display="none";
  }

  // ====== 登録関連 ======
  function normalizeSelect(sel){
    if (!sel) return null;
    ensureFirstNonEmpty(sel);
    let id = parseInt(sel.value,10);
    if (!Number.isFinite(id)){
      const op = sel.options[sel.selectedIndex];
      id = parseInt(op?.dataset?.id ?? "", 10);
    }
    return Number.isFinite(id)? id : null;
  }
  function generateGameId(){
    const d=new Date();
    const y=d.getFullYear(), m=z2(d.getMonth()+1), dd=z2(d.getDate()),
          hh=z2(d.getHours()), mm=z2(d.getMinutes()), ss=z2(d.getSeconds());
    const rnd=Math.random().toString(36).slice(2,6);
    return `pm-${y}${m}${dd}-${hh}${mm}${ss}-${rnd}`;
  }
  function gatherPayload(){
    const dateStr = (dateInput?.value || todayYmd());
    const rule_id = normalizeSelect(ruleSel);
    const shop_id = normalizeSelect(shopSel);
    const p1_id   = normalizeSelect(p1Sel);
    const p2_id   = normalizeSelect(p2Sel);

    const s1 = Number(document.getElementById("score1")?.textContent || score1 || 0);
    const s2 = Number(document.getElementById("score2")?.textContent || score2 || 0);

    const balls={};
    for (let i=1;i<=9;i++){
      const st = ballState[i] || {assigned:null, multiplier:1, isSide:false};
      balls[i] = { assigned: st.assigned, multiplier: st.multiplier };
    }
    return {
      game_id: generateGameId(),
      date: dateStr,
      rule_id,
      shop_id,
      player1_id: p1_id,
      player2_id: p2_id,
      score1: s1,
      score2: s2,
      balls,
      ace: breakAce ? 1 : 0
    };
  }
  async function submit(){
    autoAvoidSamePlayers(null);

    const payload = gatherPayload();

    if (payload.player1_id === payload.player2_id){
      const cnt = playersCount(p1Sel);
      if (cnt <= 1){
        alert("プレイヤーが1名しか登録されていません。もう1名をマスタに追加してください。");
      } else {
        alert("同一プレイヤー同士は登録できません。P1/P2を別の人に変更してください。");
      }
      return;
    }

    registBtn?.classList.add("clicked");
    setTimeout(()=>registBtn?.classList.remove("clicked"), 550);

    const missing=[];
    if (!payload.date) missing.push("日付");
    if (!payload.rule_id) missing.push("ルール");
    if (!payload.shop_id) missing.push("店舗");
    if (!payload.player1_id) missing.push("プレイヤー1");
    if (!payload.player2_id) missing.push("プレイヤー2");
    if (missing.length){ alert("未選択："+missing.join(" / ")); return; }

    // --- 確認ダイアログ ---
    const p1Text = p1Sel?.selectedOptions?.[0]?.textContent || "Player 1";
    // "Code：Name" 形式なら Name だけ抽出、そうでなければそのまま
    const p1Name = p1Text.includes("：") ? p1Text.split("：")[1] : p1Text;

    const p2Text = p2Sel?.selectedOptions?.[0]?.textContent || "Player 2";
    const p2Name = p2Text.includes("：") ? p2Text.split("：")[1] : p2Text;

    const msg = `以下の内容で登録します。よろしいですか？\n\n` +
                `${p1Name}: ${payload.score1}\n` +
                `${p2Name}: ${payload.score2}`;

    if (!confirm(msg)) {
      registBtn?.classList.remove("clicked");
      return;
    }
    // ----------------------

    try{
      const res = await fetch(API_FINALIZE, {
        method:"POST", headers:{ "Content-Type":"application/json" },
        body: JSON.stringify(payload),
      });
      const raw = await res.text();
      let data; try{ data=JSON.parse(raw); }catch{ throw new Error("Invalid JSON: "+raw.slice(0,200)); }

      if (data && data.success){
        showPopup("登録しました！");
        const box = document.getElementById("postRegistActions");
        if (box) box.style.display="flex";
        resetAll();
      } else {
        console.error("保存エラー:", data);
        alert("保存に失敗しました。\n"+(data?.error || "詳細不明"));
        showPopup("保存に失敗しました");
      }
    }catch(err){
      console.error("送信エラー:", err);
      alert("送信に失敗しました。\n"+(err?.message || err));
      showPopup("送信に失敗しました");
    }
  }

  // ====== 設定ボタン（表示/非表示） ======
  function toggleSettings(){
    if (!settingsBox) return;
    const cur = window.getComputedStyle(settingsBox).display;
    settingsBox.style.display = (cur==="none") ? "block" : "none";
  }
  window.toggleSettings = toggleSettings;

  // ====== 初期化 ======
  async function init(){
    if (dateInput && !dateInput.value) dateInput.value = todayYmd();

    await loadMasters();
    buildGrid();
    updateLabels();
    recalcScores();

    // イベント
    p1Sel?.addEventListener("change", ()=>{ autoAvoidSamePlayers(p1Sel); updateLabels(); });
    p2Sel?.addEventListener("change", ()=>{ autoAvoidSamePlayers(p2Sel); updateLabels(); });
    ruleSel?.addEventListener("change", ()=>{ updateRuleUI(); recalcScores(); });

    resetBtn?.addEventListener("click", resetAll);
    registBtn?.addEventListener("click", submit);
    settingsBtn?.addEventListener("click", toggleSettings);

    // トップ帯を塗る／右上設定ボタンを画像化
    paintTopBand();
    paintSettingsButton();

    // ボタン行の整備（英字ラベル＋色付け）
    ensureBottomButtons();
  }

  if (document.readyState === "loading"){
    document.addEventListener("DOMContentLoaded", init, { once:true });
  } else {
    init();
  }
})();
