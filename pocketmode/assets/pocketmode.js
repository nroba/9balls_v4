/* Pocketmode（横スワイプ固定＋登録＋マスタ読込＋ACE）
   - ルールA/B 共通でバッジは「Side」表記に統一
   - ルールA: 倍率 1⇔2（倍率2の時に「Side」表示）
   - ルールB: 倍率は使わず isSide をトグル（「Side」表示）。スコアは常に等倍
   - ルール切替時に A/B 間で状態を相互同期
   - 9番右に「ACE」ボタンを配置し、送信時に ace: 1/0 を付与
*/
(() => {
  "use strict";

  // ====== 定数・要素 ======
  const API_MASTERS  = "/pocketmode/api/masters.php";
  const API_FINALIZE = "/pocketmode/api/finalize_game.php";

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
  const titleEl     = document.querySelector(".pm-title"); // タイトル表示

  if (!grid) { console.warn("ballGrid が見つかりません"); return; }

  // ====== ユーティリティ ======
  const z2 = (n)=> String(n).padStart(2,"0");
  const todayYmd = ()=>{ const d=new Date(); return `${d.getFullYear()}-${d.getMonth()+1}`.replace(/-(\d)$/, (_,x)=>`-0${x}`) + `-${z2(d.getDate())}`; };

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
    if (titleEl){
      titleEl.textContent = `Pocketmode — ルール${code}`;
    }
    // ルール切替に伴う状態同期
    const aMode = isRuleA();
    for (let i=1;i<=9;i++){
      const st = ballState[i]; if (!st) continue;
      if (aMode){
        // B→A：B側 isSide を A の倍率2に同期
        if (st.isSide && st.multiplier !== 2) st.multiplier = 2;
      } else {
        // A→B：A側 倍率2 を B の isSide に同期（倍率は等倍にしておく）
        if (st.multiplier === 2) st.isSide = true;
        st.multiplier = 1; // Bでは倍率は無効化（スコアは常に等倍）
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
            target.value = tryVal;
            break;
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

      updateLabels();
      updateRuleUI();
    }catch(e){
      console.warn("masters取得失敗:", e);
      ensureFirstNonEmpty(ruleSel);
      ensureFirstNonEmpty(shopSel);
      ensureFirstNonEmpty(p1Sel);
      ensureFirstNonEmpty(p2Sel);
      updateLabels();
      updateRuleUI();
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
      // A: 倍率2 or isSide なら「Side」表示（isSideはB→A切替時の引継ぎ用）
      const show = (st.multiplier === 2) || !!st.isSide;
      if (show){
        label.textContent = "Side";
        label.style.display = "block";
        label.classList.remove("bounce"); void label.offsetWidth; label.classList.add("bounce");
      } else {
        label.style.display = "none"; label.classList.remove("bounce");
      }
    } else {
      // B: isSide が true のときに「Side」表示
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

  // ====== グリッド生成 ======
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

      // isSide を追加
      ballState[i] = { swiped:false, assigned:null, multiplier:1, isSide:false, wrapper:wrap };
      attachSwipeHandlers(wrap, i);
    }

    // === 9番の右に ACE ボタン ===
    const aceWrap = document.createElement("div");
    aceWrap.className = "ace-wrapper";
    aceWrap.id = "aceWrap";

    const aceBtn = document.createElement("button");
    aceBtn.type = "button";
    aceBtn.className = "ace-btn";
    aceBtn.id = "aceBtn";
    aceBtn.setAttribute("aria-pressed", "false");
    aceBtn.title = "ブレイクエース";

    const aceImg = document.createElement("img");
    aceImg.src = "/image/ball_ace.png";         // ★ 指定のパス
    aceImg.alt = "Break Ace";

    const aceCap = document.createElement("div");
    aceCap.className = "ace-label";
    aceCap.textContent = "ACE";

    aceBtn.appendChild(aceImg);
    aceWrap.appendChild(aceBtn);
    aceWrap.appendChild(aceCap);
    grid.appendChild(aceWrap);

    // トグル動作
    aceBtn.addEventListener("click", ()=>{
      breakAce = !breakAce;
      aceBtn.classList.toggle("active", breakAce);
      aceBtn.setAttribute("aria-pressed", breakAce ? "true" : "false");
      if (typeof showPopup === "function") showPopup(breakAce ? "ブレイクエース" : "解除");
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
      recalcScores();
      updateBadge(n); // 念のため整合
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
        // ルールB：isSide トグル（スコアは等倍のまま）
        st.isSide = !st.isSide;
        showPopup(st.isSide ? "サイド" : "通常");
        playSoundOverlap("sounds/side.mp3");
        updateBadge(n);
        return;
      }

      // ルールA：倍率トグル（倍率2 = Side）
      st.multiplier = (st.multiplier===1)?2:1;
      st.isSide = (st.multiplier === 2); // A⇔B切替時の引継ぎ用
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

  // ====== リセット ======
  function resetAll(){
    for (let i=1;i<=9;i++){
      const st = ballState[i]; if (!st) continue;
      st.swiped=false; st.assigned=null; st.multiplier=1; st.isSide=false;
      const w=st.wrapper; w.classList.remove("roll-left","roll-right"); w.style.opacity="0.5";
      updateBadge(i);
    }
    score1=0; score2=0; updateScoreDisplay();

    // ★ ACE のリセット
    breakAce = false;
    const aceBtnEl = document.getElementById("aceBtn");
    if (aceBtnEl){
      aceBtnEl.classList.remove("active");
      aceBtnEl.setAttribute("aria-pressed","false");
    }

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
      balls[i] = { assigned: st.assigned, multiplier: st.multiplier }; // 送信フォーマットは従来踏襲
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
      // ★ ACE：1/0
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
  }

  if (document.readyState === "loading"){
    document.addEventListener("DOMContentLoaded", init, { once:true });
  } else {
    init();
  }
})();
