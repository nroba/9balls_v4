// /pocketmode/assets/pocketmode.js  (堅牢化版)
// - masters 読み込み失敗/空の可視化
// - セレクト未選択なら自動で先頭を選択
// - 登録直前に再チェックして足りない項目を具体表示
// - A/B ルール計算は従来通り

const grid       = document.getElementById("ballGrid");
const popup      = document.getElementById("popup");
const resetBtn   = document.getElementById("resetBtn");
const registBtn  = document.getElementById("registBtn");
const ruleSelect = document.getElementById("ruleSelect");

let score1 = 0;
let score2 = 0;
const ballState = {}; // { [num]: { swiped, assigned(1|2|null), multiplier(1|2), wrapper:El } }

// -------------- helpers --------------
const toInt = (v) => {
  const n = parseInt(v, 10);
  return Number.isFinite(n) ? n : null;
};
const normalizeSelect = (sel) => {
  if (!sel) return null;
  if (!sel.value || sel.selectedIndex < 0) {
    if (sel.options.length > 0) sel.selectedIndex = 0; // 自動補正
  }
  return toInt(sel.value);
};
function playSoundOverlap(src) {
  try { new Audio(src).play().catch(()=>{}); } catch(_){}
}
function restartAnimation(el, className) {
  el.classList.remove("roll-left","roll-right");
  void el.offsetWidth;
  el.classList.add(className);
  el.style.opacity = "1";
}
function toggleSettings() {
  const s = document.getElementById("gameSettings");
  s.style.display = s.style.display === "none" ? "block" : "none";
}
function updateScoreDisplay() {
  document.getElementById("score1").textContent = score1;
  document.getElementById("score2").textContent = score2;
}
function showPopup(text) {
  popup.textContent = text;
  popup.style.display = "block";
  setTimeout(()=>{ popup.style.display="none"; }, 1000);
}
function updateMultiplierLabel(num) {
  const label = document.getElementById(`multi${num}`);
  const mult = ballState[num].multiplier;
  if (mult === 2) {
    label.textContent = "×2";
    label.style.display = "block";
    label.classList.remove("bounce");
    void label.offsetWidth;
    label.classList.add("bounce");
  } else {
    label.style.display = "none";
    label.classList.remove("bounce");
  }
}
function getSelectedRuleCode() {
  const opt = ruleSelect.options[ruleSelect.selectedIndex];
  return (opt && opt.dataset && opt.dataset.code) ? opt.dataset.code : "B";
}
function recalculateScores() {
  score1 = 0; score2 = 0;
  const ruleCode = getSelectedRuleCode(); // 'A' or 'B'(others)
  for (let j=1; j<=9; j++) {
    const st = ballState[j];
    if (!st) continue;
    if (st.swiped && st.assigned) {
      let p = 0;
      if (ruleCode === "A") {
        if (j===9) p=2;
        else if (j%2===1) p=1;
        p *= st.multiplier; // ×2はAのみ
      } else {
        p = (j===9)?2:1;
      }
      if (st.assigned===1) score1 += p;
      if (st.assigned===2) score2 += p;
    }
  }
  updateScoreDisplay();
}
function resetAll() {
  score1=0; score2=0; updateScoreDisplay();
  for (let i=1; i<=9; i++) {
    const st = ballState[i];
    if (!st) continue;
    const w = st.wrapper;
    w.classList.remove("roll-left","roll-right");
    w.style.opacity = "0.5";
    st.swiped=false; st.assigned=null; st.multiplier=1;
    updateMultiplierLabel(i);
  }
}
function updateLabels() {
  const p1 = document.getElementById("player1");
  const p2 = document.getElementById("player2");
  document.getElementById("label1").textContent = p1.selectedOptions[0]?.textContent || "Player 1";
  document.getElementById("label2").textContent = p2.selectedOptions[0]?.textContent || "Player 2";
}
function attachPlayerChangeListeners() {
  document.getElementById("player1").addEventListener("change", () => {
    updateLabels();
    localStorage.setItem("player1_id", document.getElementById("player1").value);
  });
  document.getElementById("player2").addEventListener("change", () => {
    updateLabels();
    localStorage.setItem("player2_id", document.getElementById("player2").value);
  });
  document.getElementById("shop").addEventListener("change", () => {
    localStorage.setItem("shop_id", document.getElementById("shop").value);
  });
  ruleSelect.addEventListener("change", () => {
    localStorage.setItem("rule_id", ruleSelect.value);
    recalculateScores();
  });
}
function hideActions(){ const b=document.getElementById("postRegistActions"); if(b) b.style.display="none"; }
window.toggleSettings = toggleSettings;
window.resetAll = resetAll;
window.hideActions = hideActions;

// -------------- 登録 --------------
registBtn.addEventListener("click", () => {
  registBtn.classList.add("clicked");
  setTimeout(()=>registBtn.classList.remove("clicked"), 550);

  const p1Sel = document.getElementById("player1");
  const p2Sel = document.getElementById("player2");
  const shopSel = document.getElementById("shop");

  // 未選択なら先頭を自動選択して再取得
  const p1Id   = normalizeSelect(p1Sel);
  const p2Id   = normalizeSelect(p2Sel);
  const shopId = normalizeSelect(shopSel);
  const ruleId = normalizeSelect(ruleSelect);

  const dateStr = document.getElementById("dateInput").value || new Date().toISOString().slice(0,10);

  const missing = [];
  if (!dateStr) missing.push("日付");
  if (!ruleId)  missing.push("ルール");
  if (!shopId)  missing.push("店舗");
  if (!p1Id)    missing.push("プレイヤー1");
  if (!p2Id)    missing.push("プレイヤー2");

  if (missing.length) {
    showPopup("未選択: " + missing.join(" / "));
    alert("未選択: " + missing.join(" / ") + "\n『各種マスタ設定』で登録済みか確認し、ページを再読み込みしてください。");
    console.warn("[register blocked] values:", {dateStr, ruleId, shopId, p1Id, p2Id});
    return;
  }

  // 勝敗フラグ（同点はP1勝ち）
  const score1Flag = (score1 > score2) ? 1 : (score1 === score2 ? 1 : 0);
  const score2Flag = (score1 > score2) ? 0 : (score1 === score2 ? 0 : 1);

  const payload = {
    game_id: (crypto && crypto.randomUUID) ? crypto.randomUUID() : String(Date.now()),
    date: dateStr,
    rule_id: ruleId,
    shop_id: shopId,
    player1_id: p1Id,
    player2_id: p2Id,
    score1: score1Flag,
    score2: score2Flag,
    balls: Object.fromEntries(
      Object.entries(ballState).map(([k, v]) => [k, { assigned: v.assigned, multiplier: v.multiplier }])
    )
  };

  fetch("/pocketmode/api/finalize_game.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload)
  })
  .then(res => res.json())
  .then(data => {
    if (data && data.success) {
      showPopup("登録しました！");
      resetAll();
      const box = document.getElementById("postRegistActions");
      if (box) box.style.display = "flex";
    } else {
      console.error("保存エラー:", data);
      showPopup("保存に失敗しました");
    }
  })
  .catch(err => {
    console.error("送信エラー", err);
    showPopup("送信に失敗しました");
  });
});

// -------------- masters 読み込み --------------
fetch("/pocketmode/api/masters.php")
  .then(res => {
    if (!res.ok) throw new Error("masters http " + res.status);
    return res.json();
  })
  .then(data => {
    const shopSel = document.getElementById("shop");
    const p1Sel   = document.getElementById("player1");
    const p2Sel   = document.getElementById("player2");

    // ルール
    ruleSelect.innerHTML = "";
    if (Array.isArray(data.rules)) {
      data.rules.forEach((r, idx) => {
        const opt = document.createElement("option");
        // 万が一 r.id が無い環境でも表示はできるように
        opt.value = (r.id ?? r.code ?? "").toString();
        opt.dataset.id = (r.id ?? "").toString();   // ← 数値IDを別属性に保持
        if (r.code) opt.dataset.code = r.code;      // A/B 等
        opt.textContent = (r.code ? `${r.code}：` : "") + r.name;
          if (idx === 0) opt.selected = true;
          ruleSelect.appendChild(opt);
        if (idx === 0) opt.selected = true;
        ruleSelect.appendChild(opt);
      });
    }

    // 店舗
    shopSel.innerHTML = "";
    if (Array.isArray(data.shops)) {
      data.shops.forEach((s, idx) => {
        const option = document.createElement("option");
        option.value = s.id;
        option.textContent = s.name;
        if (idx === 0) option.selected = true;
        shopSel.appendChild(option);
      });
    }

    // プレイヤー
    p1Sel.innerHTML = "";
    p2Sel.innerHTML = "";
    if (Array.isArray(data.players)) {
      data.players.forEach((u, idx) => {
        const opt1 = document.createElement("option");
        opt1.value = u.id; opt1.textContent = u.name;
        if (idx === 0) opt1.selected = true;
        p1Sel.appendChild(opt1);

        const opt2 = document.createElement("option");
        opt2.value = u.id; opt2.textContent = u.name;
        if (idx === 1) opt2.selected = true;
        p2Sel.appendChild(opt2);
      });
    }

    // localStorage 復元（値が無効なら自動で先頭に補正）
    const p1LS = localStorage.getItem("player1_id");
    const p2LS = localStorage.getItem("player2_id");
    const shLS = localStorage.getItem("shop_id");
    const rlLS = localStorage.getItem("rule_id");

    if (p1LS && [...p1Sel.options].some(o => o.value === p1LS)) p1Sel.value = p1LS;
    if (p2LS && [...p2Sel.options].some(o => o.value === p2LS)) p2Sel.value = p2LS;
    if (shLS && [...shopSel.options].some(o => o.value === shLS)) shopSel.value = shLS;
    if (rlLS && [...ruleSelect.options].some(o => o.value === rlLS)) ruleSelect.value = rlLS;

    // ここで最終的に未選択なら自動補正
    normalizeSelect(ruleSelect);
    normalizeSelect(shopSel);
    normalizeSelect(p1Sel);
    normalizeSelect(p2Sel);

    attachPlayerChangeListeners();
    updateLabels();
    recalculateScores();

    // 未登録ガイド
    const warn = [];
    if (!data.players || data.players.length === 0) warn.push("プレイヤー");
    if (!data.shops   || data.shops.length   === 0) warn.push("店舗");
    if (!data.rules   || data.rules.length   === 0) warn.push("ルール");

    console.log("[masters]", {
      players: data.players?.length ?? 0,
      shops:   data.shops?.length   ?? 0,
      rules:   data.rules?.length   ?? 0,
      selected: {
        ruleId: ruleSelect.value, shopId: shopSel.value, p1: p1Sel.value, p2: p2Sel.value
      }
    });

    if (warn.length) {
      registBtn.style.pointerEvents = "none";
      registBtn.style.opacity = "0.5";
      alert("マスタ未登録: " + warn.join(" / ") + "。まず『各種マスタ設定』で登録してください。");
    } else {
      registBtn.style.pointerEvents = "";
      registBtn.style.opacity = "";
    }
  })
  .catch(err => {
    console.error("masters load error:", err);
    alert("マスタ読み込みに失敗しました。/pocketmode/api/masters.php を確認してください。");
  });

// -------------- ボールUI --------------
for (let i=1; i<=9; i++) {
  const wrapper = document.createElement("div");
  wrapper.classList.add("ball-wrapper");
  wrapper.style.opacity = "0.5";

  const img = document.createElement("img");
  img.src = `/images/ball${i}.png`;
  img.classList.add("ball");
  img.dataset.number = i;

  const label = document.createElement("div");
  label.classList.add("ball-multiplier");
  label.id = `multi${i}`;
  label.textContent = "";
  label.style.display = "none";

  wrapper.appendChild(img);
  wrapper.appendChild(label);
  grid.appendChild(wrapper);

  ballState[i] = { swiped:false, assigned:null, multiplier:1, wrapper };

  let startX = null;
  const onStart = (x) => { startX = x; };
  const onEnd = (x) => {
    if (startX === null) return;
    const dx = x - startX;
    if (Math.abs(dx) < 30) return;

    const prev = ballState[i].assigned;
    const sw   = ballState[i].swiped;
    const w    = ballState[i].wrapper;

    if (!sw) {
      if (dx < -30) { ballState[i].assigned = 1; restartAnimation(w,"roll-left"); }
      else if (dx > 30) { ballState[i].assigned = 2; restartAnimation(w,"roll-right"); }
      ballState[i].swiped = true;
      playSoundOverlap("sounds/swipe.mp3");
    } else {
      if ((prev===1 && dx>30) || (prev===2 && dx<-30)) {
        ballState[i].assigned=null;
        ballState[i].swiped=false;
        w.classList.remove("roll-left","roll-right");
        w.style.opacity="0.5";
        playSoundOverlap("sounds/cancel.mp3");
      }
    }
    recalculateScores();
  };

  wrapper.addEventListener("touchstart", (e)=>onStart(e.touches[0].clientX));
  wrapper.addEventListener("touchend",   (e)=>onEnd(e.changedTouches[0].clientX));
  wrapper.addEventListener("mousedown",  (e)=>onStart(e.clientX));
  wrapper.addEventListener("mouseup",    (e)=>onEnd(e.clientX));

  wrapper.addEventListener("click", () => {
    if (!ballState[i].swiped) return;
    ballState[i].multiplier = (ballState[i].multiplier===1)?2:1;
    updateMultiplierLabel(i);
    showPopup(ballState[i].multiplier===2 ? "サイド（得点×2）" : "コーナー（得点×1）");
    playSoundOverlap("sounds/side.mp3");
    recalculateScores();
  });
}

resetBtn.addEventListener("click", resetAll);

if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('service-worker.js').catch(()=>{});
}
