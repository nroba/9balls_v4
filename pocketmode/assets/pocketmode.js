/* Pocketmode（横スワイプで固定＋登録処理を追加）
   - 左スワイプ：roll-left 付与 → P1
   - 右スワイプ：roll-right 付与 → P2
   - 逆方向へ大きく振る：解除（元位置へ戻す）
   - クリック：割当済みの玉だけ倍率トグル（×1/×2）
   - 登録：/pocketmode/api/finalize_game.php へ JSON POST
*/

(() => {
  "use strict";

  // ====== 定数・要素 ======
  const API_FINALIZE = "/pocketmode/api/finalize_game.php"; // 必要ならパス調整
  const grid      = document.getElementById("ballGrid");
  const popup     = document.getElementById("popup");
  const resetBtn  = document.getElementById("resetBtn");
  const registBtn = document.getElementById("registBtn");
  const ruleSel   = document.getElementById("ruleSelect");
  const p1Sel     = document.getElementById("player1");
  const p2Sel     = document.getElementById("player2");
  const shopSel   = document.getElementById("shop");
  const dateInput = document.getElementById("dateInput");

  if (!grid) { console.warn("ballGrid が見つかりません"); return; }

  // ====== ユーティリティ ======
  const z2 = (n)=> String(n).padStart(2,"0");
  const todayYmd = ()=>{ const d=new Date(); return `${d.getFullYear()}-${z2(d.getMonth()+1)}-${z2(d.getDate())}`; };

  function playSoundOverlap(src) {
    try { new Audio(src).play(); } catch(e) { /* noop */ }
  }

  function showPopup(text, ms=1000) {
    if (!popup) return;
    popup.textContent = text;
    popup.style.display = "block";
    setTimeout(() => { popup.style.display = "none"; }, ms);
  }

  function updateLabels() {
    const l1 = document.getElementById("label1");
    const l2 = document.getElementById("label2");
    if (l1) l1.textContent = p1Sel?.selectedOptions?.[0]?.textContent || p1Sel?.value || "Player 1";
    if (l2) l2.textContent = p2Sel?.selectedOptions?.[0]?.textContent || p2Sel?.value || "Player 2";
  }

  // ====== 状態 ======
  const ballState = {}; // { [i]: { swiped, assigned: 1|2|null, multiplier: 1|2, wrapper } }
  let score1 = 0, score2 = 0;

  function updateMultiplierLabel(num) {
    const label = document.getElementById(`multi${num}`);
    const mult = ballState[num].multiplier;
    if (mult === 2) {
      label.textContent = "×2";
      label.style.display = "block";
      label.classList.remove("bounce"); void label.offsetWidth; label.classList.add("bounce");
    } else {
      label.style.display = "none";
      label.classList.remove("bounce");
    }
  }

  function updateScoreDisplay() {
    const s1 = document.getElementById("score1");
    const s2 = document.getElementById("score2");
    if (s1) s1.textContent = score1;
    if (s2) s2.textContent = score2;
  }

  // 過去版互換の得点ロジック（A/B）
  function recalcScores() {
    score1 = 0; score2 = 0;
    const rule = (ruleSel?.value || "A").toUpperCase(); // "A" / "B"
    for (let j = 1; j <= 9; j++) {
      const st = ballState[j];
      if (!st?.swiped || !st.assigned) continue;
      let point = 0;
      if (rule === "A") {
        if (j === 9) point = 2;
        else if (j % 2 === 1) point = 1;
        point *= st.multiplier;
      } else if (rule === "B") {
        point = (j === 9) ? 2 : 1;
      }
      if (st.assigned === 1) score1 += point;
      if (st.assigned === 2) score2 += point;
    }
    updateScoreDisplay();
  }

  // ====== 生成 ======
  function buildGrid() {
    grid.innerHTML = "";
    for (let i = 1; i <= 9; i++) {
      const wrap = document.createElement("div");
      wrap.className = "ball-wrapper";
      wrap.style.opacity = "0.5";

      const img = document.createElement("img");
      img.className = "ball";
      img.src = `/images/ball${i}.png`;
      img.alt = `Ball ${i}`;
      img.setAttribute("draggable", "false");

      const label = document.createElement("div");
      label.className = "ball-multiplier";
      label.id = `multi${i}`;
      label.textContent = "";
      label.style.display = "none";

      wrap.appendChild(img);
      wrap.appendChild(label);
      grid.appendChild(wrap);

      ballState[i] = { swiped:false, assigned:null, multiplier:1, wrapper:wrap };

      attachSwipeHandlers(wrap, i);
    }
  }

  // ====== スワイプ（左右のみ） ======
  function attachSwipeHandlers(el, n) {
    const TH = 30; // スワイプ判定の閾値(px)
    let startX = null;

    const onStart = (x) => { startX = x; };
    const onEnd   = (x) => {
      if (startX == null) return;
      const dx = x - startX;
      startX = null;

      const st = ballState[n];
      const prev = st.assigned;

      if (!st.swiped) {
        if (dx < -TH) {
          st.assigned = 1; st.swiped = true;
          restartAnimation(el, "roll-left");
          playSoundOverlap("sounds/swipe.mp3");
        } else if (dx > TH) {
          st.assigned = 2; st.swiped = true;
          restartAnimation(el, "roll-right");
          playSoundOverlap("sounds/swipe.mp3");
        } else {
          return;
        }
      } else {
        // 既に固定済み → 逆方向で解除
        if ((prev === 1 && dx > TH) || (prev === 2 && dx < -TH)) {
          st.assigned = null; st.swiped = false;
          el.classList.remove("roll-left", "roll-right");
          el.style.opacity = "0.5";
          playSoundOverlap("sounds/cancel.mp3");
        } else {
          // 同方向は維持
        }
      }
      recalcScores();
    };

    // touch / mouse で down/up 差分を判定
    el.addEventListener("touchstart", (e)=> onStart(e.touches[0].clientX), {passive:true});
    el.addEventListener("touchend",   (e)=> onEnd(e.changedTouches[0].clientX));
    el.addEventListener("mousedown",  (e)=> onStart(e.clientX));
    el.addEventListener("mouseup",    (e)=> onEnd(e.clientX));

    // クリック：割当済みのときのみ倍率トグル
    el.addEventListener("click", () => {
      const st = ballState[n];
      if (!st.swiped) return;
      st.multiplier = (st.multiplier === 1) ? 2 : 1;
      updateMultiplierLabel(n);
      showPopup(st.multiplier === 2 ? "サイド（得点×2）" : "コーナー（得点×1）");
      playSoundOverlap("sounds/side.mp3");
      recalcScores();
    });
  }

  // CSSアニメをリセットして再適用
  function restartAnimation(el, cls) {
    el.classList.remove("roll-left", "roll-right");
    void el.offsetWidth; // reflow
    el.classList.add(cls);
    el.style.opacity = "1";
  }

  // ====== リセット ======
  function resetAll() {
    for (let i = 1; i <= 9; i++) {
      const st = ballState[i];
      if (!st) continue;
      st.swiped = false; st.assigned = null; st.multiplier = 1;
      const w = st.wrapper;
      w.classList.remove("roll-left", "roll-right");
      w.style.opacity = "0.5";
      updateMultiplierLabel(i);
    }
    score1 = 0; score2 = 0; updateScoreDisplay();
    const box = document.getElementById("postRegistActions");
    if (box) box.style.display = "none";
  }

  // ====== 登録処理 ======
  function normalizeSelect(sel) {
    if (!sel) return null;
    if (!sel.value && sel.options.length>0) sel.selectedIndex=0;
    let id = Number.parseInt(sel.value, 10);
    if (!Number.isFinite(id)) {
      const opt = sel.options[sel.selectedIndex];
      id = Number.parseInt(opt?.dataset?.id ?? "", 10);
    }
    return Number.isFinite(id) ? id : null;
  }

  function generateGameId() {
    const d = new Date();
    const y = d.getFullYear(), m=z2(d.getMonth()+1), dd=z2(d.getDate()),
          hh=z2(d.getHours()), mm=z2(d.getMinutes()), ss=z2(d.getSeconds());
    const rnd = Math.random().toString(36).slice(2,6);
    return `pm-${y}${m}${dd}-${hh}${mm}${ss}-${rnd}`;
  }

  function gatherPayload() {
    const dateStr = (dateInput?.value || todayYmd());
    const rule_id = normalizeSelect(ruleSel);
    const shop_id = normalizeSelect(shopSel);
    const p1_id   = normalizeSelect(p1Sel);
    const p2_id   = normalizeSelect(p2Sel);

    // 得点は画面表示の値（recalcScoresの結果）を送る
    const s1 = Number(document.getElementById("score1")?.textContent || score1 || 0);
    const s2 = Number(document.getElementById("score2")?.textContent || score2 || 0);

    const balls = {};
    for (let i=1;i<=9;i++){
      const st = ballState[i] || {assigned:null, multiplier:1};
      balls[i] = { assigned: st.assigned, multiplier: st.multiplier };
    }

    return {
      game_id: generateGameId(),
      date: dateStr,
      rule_id, shop_id,
      player1_id: p1_id,
      player2_id: p2_id,
      score1: s1, score2: s2,
      balls
    };
  }

  async function submit() {
    // 見た目のフィードバック
    registBtn?.classList.add("clicked");
    setTimeout(()=>registBtn?.classList.remove("clicked"), 550);

    const payload = gatherPayload();

    // 必須チェック
    const missing = [];
    if (!payload.date) missing.push("日付");
    if (!payload.rule_id) missing.push("ルール");
    if (!payload.shop_id) missing.push("店舗");
    if (!payload.player1_id) missing.push("プレイヤー1");
    if (!payload.player2_id) missing.push("プレイヤー2");
    if (missing.length) {
      alert("未選択：" + missing.join(" / "));
      return;
    }
    if (payload.player1_id === payload.player2_id) {
      alert("同一プレイヤー同士は登録できません");
      return;
    }

    try {
      const res = await fetch(API_FINALIZE, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const raw = await res.text();
      let data;
      try { data = JSON.parse(raw); } catch { throw new Error("Invalid JSON: " + raw.slice(0,120)); }

      if (data && data.success) {
        showPopup("登録しました！");
        const box = document.getElementById("postRegistActions");
        if (box) box.style.display = "flex";
        resetAll();
      } else {
        console.error("保存エラー:", data);
        alert("保存に失敗しました。\n" + (data?.error || "詳細不明"));
        showPopup("保存に失敗しました");
      }
    } catch (err) {
      console.error("送信エラー:", err);
      alert("送信に失敗しました。\n" + (err?.message || err));
      showPopup("送信に失敗しました");
    }
  }

  // ====== イベント ======
  function bindMeta() {
    p1Sel?.addEventListener("change", updateLabels);
    p2Sel?.addEventListener("change", updateLabels);
  }

  // ====== 初期化 ======
  function init() {
    if (dateInput && !dateInput.value) dateInput.value = todayYmd();
    buildGrid();
    updateLabels();
    recalcScores();
    bindMeta();

    resetBtn?.addEventListener("click", resetAll);
    registBtn?.addEventListener("click", submit);   // ←← これが無いと“反応しない”状態になります
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init, { once:true });
  } else {
    init();
  }
})();
