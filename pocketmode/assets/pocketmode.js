/* /pocketmode/assets/pocketmode.js
   Pocketmode 本体（DOM完了後に初期化・ジェスチャー統一の堅牢版）
   - マスタ取得: /pocketmode/api/masters.php
   - 2列グリッド（ball1..9）
   - シングルタップ: 未→P1→P2→未
   - ダブルタップ/ダブルクリック: 倍率 ×1→×2→×3→×1
   - スワイプ(左右): 左=P1, 右=P2（HTML5 DnDは全無効）
   - 登録: /pocketmode/api/finalize_game.php?debug=1 に JSON POST
   - 9番の取得者を勝者とし、score1/score2 を自動設定
*/

(() => {
  "use strict";
  const DEBUG = location.search.includes("debug=1");
  const log = (...a) => { if (DEBUG) console.log("[pm]", ...a); };

  // ====== 要素参照（DOM読み込み後に取得） ======
  const els = {
    date: null, rule: null, shop: null, p1: null, p2: null,
    label1: null, label2: null, s1: null, s2: null,
    grid: null, reset: null, regist: null, postBox: null, popup: null,
  };
  function grabEls(){
    els.date   = document.getElementById("dateInput");
    els.rule   = document.getElementById("ruleSelect");
    els.shop   = document.getElementById("shop");
    els.p1     = document.getElementById("player1");
    els.p2     = document.getElementById("player2");
    els.label1 = document.getElementById("label1");
    els.label2 = document.getElementById("label2");
    els.s1     = document.getElementById("score1");
    els.s2     = document.getElementById("score2");
    els.grid   = document.getElementById("ballGrid");
    els.reset  = document.getElementById("resetBtn");
    els.regist = document.getElementById("registBtn");
    els.postBox= document.getElementById("postRegistActions");
    els.popup  = document.getElementById("popup");
    if (!els.grid) log("WARN: #ballGrid が見つかりません。HTMLのIDをご確認ください。");
  }

  // ====== 状態 ======
  const state = {
    // balls[n] = { assigned: null|1|2, multiplier: 1..3 }
    balls: Array.from({length:10}, () => ({ assigned: null, multiplier: 1 })), // index 0 未使用
    players: [], shops: [], rules: [],
  };

  // ====== ユーティリティ ======
  const z2 = (n) => String(n).padStart(2, "0");
  const todayYmd = () => {
    const d = new Date(); return `${d.getFullYear()}-${z2(d.getMonth()+1)}-${z2(d.getDate())}`;
  };

  function showPopup(msg, ms=1200){
    if(!els.popup) return;
    els.popup.textContent = msg;
    els.popup.style.display = "block";
    clearTimeout(showPopup._t);
    showPopup._t = setTimeout(()=>{ els.popup.style.display = "none"; }, ms);
  }

  function saveSelectionToLocal(){
    try{
      localStorage.setItem("rule_id",    els.rule?.value ?? "");
      localStorage.setItem("shop_id",    els.shop?.value ?? "");
      localStorage.setItem("player1_id", els.p1?.value ?? "");
      localStorage.setItem("player2_id", els.p2?.value ?? "");
      localStorage.setItem("date",       els.date?.value ?? "");
    }catch(_){}
  }
  function restoreSelectionFromLocal(){
    try{
      const x = (k)=> localStorage.getItem(k);
      if(els.rule && x("rule_id"))    els.rule.value = x("rule_id");
      if(els.shop && x("shop_id"))    els.shop.value = x("shop_id");
      if(els.p1   && x("player1_id")) els.p1.value   = x("player1_id");
      if(els.p2   && x("player2_id")) els.p2.value   = x("player2_id");
      if(els.date && x("date"))       els.date.value = x("date");
    }catch(_){}
  }

  function computeScores(){
    // 9番を取った側を勝者とする（なければ 0-0）
    const last = state.balls[9]?.assigned ?? null;
    return { s1: last === 1 ? 1 : 0, s2: last === 2 ? 1 : 0 };
  }

  function updateScoreboard(){
    // 表示は「取った玉の個数」
    const cnt1 = state.balls.reduce((a,b,i)=> i>0 && b.assigned===1 ? a+1 : a, 0);
    const cnt2 = state.balls.reduce((a,b,i)=> i>0 && b.assigned===2 ? a+1 : a, 0);
    if (els.s1) els.s1.textContent = String(cnt1);
    if (els.s2) els.s2.textContent = String(cnt2);
  }

  function updatePlayerLabels(){
    const p1t = els.p1?.selectedOptions[0]?.textContent || "Player 1";
    const p2t = els.p2?.selectedOptions[0]?.textContent || "Player 2";
    if (els.label1) els.label1.textContent = p1t;
    if (els.label2) els.label2.textContent = p2t;
  }

  function ensureFirstSelected(sel){
    if (!sel) return;
    if (!sel.value && sel.options.length > 0) sel.selectedIndex = 0;
  }

  function makeOption(id, name, code){
    const opt = document.createElement("option");
    opt.value = String(id);
    opt.dataset.id = String(id);
    if (code) opt.dataset.code = String(code);
    opt.textContent = code ? `${code}：${name}` : name;
    return opt;
  }

  // ====== マスタ取得 ======
  async function loadMasters(){
    try{
      const res = await fetch("/pocketmode/api/masters.php", { cache:"no-store" });
      const data = await res.json();

      // players
      if(Array.isArray(data.players) && els.p1 && els.p2){
        els.p1.innerHTML = ""; els.p2.innerHTML = "";
        state.players = data.players;
        data.players.forEach((p)=> {
          els.p1.appendChild(makeOption(p.id, p.name));
          els.p2.appendChild(makeOption(p.id, p.name));
        });
      }

      // shops
      if(Array.isArray(data.shops) && els.shop){
        els.shop.innerHTML = "";
        state.shops = data.shops;
        data.shops.forEach((s) => {
          els.shop.appendChild(makeOption(s.id, s.name));
        });
      }

      // rules
      if(Array.isArray(data.rules) && els.rule){
        els.rule.innerHTML = "";
        state.rules = data.rules;
        data.rules.forEach((r)=> {
          els.rule.appendChild(makeOption(r.id, r.name, r.code));
        });
      }
    }catch(err){
      console.warn("masters load failed:", err);
    }

    // 選択復元（存在しなければ先頭を選ぶ）
    restoreSelectionFromLocal();
    ensureFirstSelected(els.rule);
    ensureFirstSelected(els.shop);
    ensureFirstSelected(els.p1);
    ensureFirstSelected(els.p2);
    updatePlayerLabels();
  }

  // ====== グリッド生成（1..9） ======
  function buildGrid(){
    if (!els.grid) return;
    els.grid.innerHTML = "";

    for(let i=1; i<=9; i++){
      // 状態初期化
      state.balls[i] = { assigned: null, multiplier: 1 };

      const wrap = document.createElement("div");
      wrap.className = "ball-wrapper";
      wrap.dataset.ball = String(i);
      // ネイティブHTML5 DnD完全無効化（競合防止）
      wrap.setAttribute("draggable","false");

      const img = document.createElement("img");
      img.className = "ball";
      img.src = `/images/ball${i}.png`;
      img.alt = `Ball ${i}`;
      img.setAttribute("draggable","false"); // ← 重要

      const badge = document.createElement("div");
      badge.className = "ball-multiplier";
      badge.textContent = "×1";
      badge.dataset.v = "1"; // 1のときはCSSで非表示

      wrap.appendChild(img);
      wrap.appendChild(badge);
      els.grid.appendChild(wrap);

      // すべての操作（シングル/ダブル/スワイプ）を1本のハンドラで処理
      attachGesture(wrap, i);
    }
  }

  // ====== UI更新 ======
  function refreshBallUI(n){
    const wrap = els.grid?.querySelector(`.ball-wrapper[data-ball="${n}"]`);
    if (!wrap) return;
    const b = state.balls[n];

    // 選択スタイル
    if (b.assigned === 1 || b.assigned === 2) wrap.classList.add("is-active");
    else wrap.classList.remove("is-active");

    // 倍率バッジ
    const badge = wrap.querySelector(".ball-multiplier");
    const v = Math.max(1, Number(b.multiplier||1));
    badge.textContent = `×${v}`;
    badge.dataset.v = String(v);

    updateScoreboard();
  }

  function cycleAssign(n){
    const cur = state.balls[n].assigned;
    const next = (cur === null) ? 1 : (cur === 1 ? 2 : null);
    state.balls[n].assigned = next;
    refreshBallUI(n);
  }

  function incMultiplier(n){
    const cur = state.balls[n].multiplier || 1;
    const next = (cur % 3) + 1; // 1→2→3→1
    state.balls[n].multiplier = next;
    refreshBallUI(n);
  }

  // ====== ジェスチャー（Pointer優先。なければ Touch/Mouse） ======
  function attachGesture(el, n){
    const hasPointer = "PointerEvent" in window;

    if (hasPointer) {
      bindPointer(el, n);
    } else {
      bindFallback(el, n); // 古い環境
    }
  }

  function bindPointer(el, n){
    const SWIPE_X = 40;   // スワイプ閾値(px)
    const MOVE_MIN = 8;   // 微小移動の無視
    const TAP_MS = 280;   // ダブルタップ判定

    let lastTapAt = 0;

    el.addEventListener("pointerdown", (ev) => {
      // スクロール・長押しメニューなど抑止
      if (ev.pointerType !== "mouse") ev.preventDefault();

      const start = { x: ev.clientX, y: ev.clientY, t: Date.now() };
      let moved = false;
      const pointerId = ev.pointerId;

      // double tap 判定
      const deltaT = start.t - lastTapAt;
      if (deltaT > 0 && deltaT < TAP_MS) {
        lastTapAt = 0; // 消費
        incMultiplier(n);
        return;
      }

      const mm = (e) => {
        if (e.pointerId !== pointerId) return;
        const dx = e.clientX - start.x;
        const dy = e.clientY - start.y;
        if (Math.abs(dx) > MOVE_MIN || Math.abs(dy) > MOVE_MIN) moved = true;
        if (e.pointerType !== "mouse") e.preventDefault();
      };
      const mu = (e) => {
        if (e.pointerId !== pointerId) return;
        document.removeEventListener("pointermove", mm, {passive:false});
        document.removeEventListener("pointerup", mu, {passive:false});

        const dx = e.clientX - start.x;
        const dy = e.clientY - start.y;

        if (moved && Math.abs(dx) > Math.abs(dy) && Math.abs(dx) >= SWIPE_X) {
          // スワイプ：左右で割当
          state.balls[n].assigned = dx > 0 ? 2 : 1;
          refreshBallUI(n);
        } else {
          // タップ（シングル）
          lastTapAt = Date.now();
          cycleAssign(n);
        }
      };

      try { el.setPointerCapture(pointerId); } catch(_){}
      document.addEventListener("pointermove", mm, {passive:false});
      document.addEventListener("pointerup", mu, {passive:false});
    });
  }

  function bindFallback(el, n){
    // Touch + Mouse 併用（古いブラウザ用）
    const SWIPE_X = 40, MOVE_MIN = 8, TAP_MS = 280;
    let lastTapAt = 0;

    // touch
    el.addEventListener("touchstart", (ev) => {
      if (!ev.changedTouches || ev.changedTouches.length === 0) return;
      const t0 = ev.changedTouches[0];
      const start = { x: t0.clientX, y: t0.clientY, t: Date.now() };
      let moved = false;
      ev.preventDefault();

      const deltaT = start.t - lastTapAt;
      if (deltaT > 0 && deltaT < TAP_MS) { lastTapAt = 0; incMultiplier(n); return; }

      const tm = (e) => {
        const t = e.changedTouches[0];
        const dx = t.clientX - start.x;
        const dy = t.clientY - start.y;
        if (Math.abs(dx) > MOVE_MIN || Math.abs(dy) > MOVE_MIN) moved = true;
        e.preventDefault();
      };
      const tu = (e) => {
        document.removeEventListener("touchmove", tm, {passive:false});
        document.removeEventListener("touchend", tu, {passive:false});
        const t = e.changedTouches[0]; if (!t) return;
        const dx = t.clientX - start.x;
        const dy = t.clientY - start.y;
        if (moved && Math.abs(dx) > Math.abs(dy) && Math.abs(dx) >= SWIPE_X) {
          state.balls[n].assigned = dx > 0 ? 2 : 1;
          refreshBallUI(n);
        } else {
          lastTapAt = Date.now();
          cycleAssign(n);
        }
      };
      document.addEventListener("touchmove", tm, {passive:false});
      document.addEventListener("touchend", tu, {passive:false});
    }, {passive:false});

    // mouse
    el.addEventListener("mousedown", (ev) => {
      const start = { x: ev.clientX, y: ev.clientY, t: Date.now() };
      let moved = false;

      const deltaT = start.t - lastTapAt;
      if (deltaT > 0 && deltaT < TAP_MS) { lastTapAt = 0; incMultiplier(n); return; }

      const mm = (e) => {
        const dx = e.clientX - start.x;
        const dy = e.clientY - start.y;
        if (Math.abs(dx) > MOVE_MIN || Math.abs(dy) > MOVE_MIN) moved = true;
        e.preventDefault();
      };
      const mu = (e) => {
        document.removeEventListener("mousemove", mm);
        document.removeEventListener("mouseup", mu);
        const dx = e.clientX - start.x;
        const dy = e.clientY - start.y;
        if (moved && Math.abs(dx) > Math.abs(dy) && Math.abs(dx) >= SWIPE_X) {
          state.balls[n].assigned = dx > 0 ? 2 : 1;
          refreshBallUI(n);
        } else {
          lastTapAt = Date.now();
          cycleAssign(n);
        }
      };
      document.addEventListener("mousemove", mm);
      document.addEventListener("mouseup", mu);
    });
  }

  // ====== リセット ======
  function resetAll(){
    for(let i=1; i<=9; i++){
      state.balls[i].assigned = null;
      state.balls[i].multiplier = 1;
      refreshBallUI(i);
    }
    updateScoreboard();
    if (els.postBox) els.postBox.style.display = "none";
  }

  // ====== 登録 ======
  function normalizeSelect(sel){
    if (!sel) return null;
    if (!sel.value && sel.options.length > 0) sel.selectedIndex = 0;
    let id = Number.parseInt(sel.value, 10);
    if (!Number.isFinite(id)) {
      const opt = sel.options[sel.selectedIndex];
      id = Number.parseInt(opt?.dataset?.id ?? "", 10);
    }
    return Number.isFinite(id) ? id : null;
  }

  function gatherPayload(){
    const dateStr = els.date?.value || todayYmd();
    const ruleId  = normalizeSelect(els.rule);
    const shopId  = normalizeSelect(els.shop);
    const p1Id    = normalizeSelect(els.p1);
    const p2Id    = normalizeSelect(els.p2);
    const { s1, s2 } = computeScores();

    const balls = {};
    for(let i=1;i<=9;i++){
      balls[i] = {
        assigned: state.balls[i].assigned,   // 1|2|null
        multiplier: state.balls[i].multiplier // 1..3
      };
    }

    return {
      date: dateStr,
      rule_id: ruleId,
      shop_id: shopId,
      player1_id: p1Id,
      player2_id: p2Id,
      score1: s1,
      score2: s2,
      balls
    };
  }

  async function submit(){
    const payload = gatherPayload();
    // 未選択チェック（フロント側）
    const missing = [];
    if (!payload.date) missing.push("日付");
    if (!payload.rule_id) missing.push("ルール");
    if (!payload.shop_id) missing.push("店舗");
    if (!payload.player1_id) missing.push("プレイヤー1");
    if (!payload.player2_id) missing.push("プレイヤー2");
    if (missing.length){
      alert("未選択：" + missing.join(" / "));
      return;
    }
    if (payload.player1_id === payload.player2_id){
      alert("同一プレイヤー同士は登録できません");
      return;
    }

    try{
      const res = await fetch("/pocketmode/api/finalize_game.php?debug=1", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const text = await res.text();
      let data;
      try { data = JSON.parse(text); }
      catch { console.error("raw response:", text); throw new Error("Invalid JSON"); }

      if (data && data.success){
        showPopup("登録しました！");
        resetAll();
        if (els.postBox) els.postBox.style.display = "flex";
      } else {
        console.error("保存エラー:", data);
        alert("保存に失敗しました。\n" + (data && data.error ? data.error : "詳細不明")
              + (data && data.log_path ? "\nlog: " + data.log_path : ""));
        showPopup("保存に失敗しました");
      }
    }catch(err){
      console.error("送信エラー:", err);
      showPopup("送信に失敗しました");
      alert("送信に失敗しました。\n" + (err?.message || err));
    }
  }

  // ====== 設定表示のトグル ======
  function toggleSettings(){
    const box = document.getElementById("gameSettings");
    if (!box) return;
    box.style.display = (box.style.display === "none" || box.style.display === "") ? "block" : "none";
  }
  window.toggleSettings = toggleSettings;
  window.hideActions = function(){ if (els.postBox) els.postBox.style.display = "none"; };

  // ====== イベントバインド ======
  function bindEvents(){
    els.reset?.addEventListener("click", resetAll);
    els.regist?.addEventListener("click", submit);

    [els.rule, els.shop, els.p1, els.p2, els.date].forEach((sel)=>{
      sel?.addEventListener("change", ()=>{
        if (sel === els.p1 || sel === els.p2) updatePlayerLabels();
        saveSelectionToLocal();
      });
    });
  }

  // ====== 初期化（DOM 完了後に実行） ======
  async function init(){
    grabEls();
    if (els.date && !els.date.value) els.date.value = todayYmd();
    await loadMasters();
    buildGrid();
    updateScoreboard();
    bindEvents();
    log("initialized");
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init, { once:true });
  } else {
    init();
  }
})();
