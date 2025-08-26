// /pocketmode/assets/pocketmode.js

const grid       = document.getElementById("ballGrid");
const popup      = document.getElementById("popup");
const resetBtn   = document.getElementById("resetBtn");
const registBtn  = document.getElementById("registBtn");
const ruleSelect = document.getElementById("ruleSelect");

let score1 = 0;
let score2 = 0;

const ballState = {}; // { [num]: { swiped, assigned(1|2|null), multiplier(1|2), wrapper:El } }

function playSoundOverlap(src) {
  const sound = new Audio(src);
  sound.play().catch((e) => console.warn("音声再生エラー:", e));
}

function restartAnimation(el, className) {
  el.classList.remove("roll-left", "roll-right");
  void el.offsetWidth;
  el.classList.add(className);
  el.style.opacity = "1";
}

function toggleSettings() {
  const settings = document.getElementById("gameSettings");
  settings.style.display = settings.style.display === "none" ? "block" : "none";
}

function updateScoreDisplay() {
  document.getElementById("score1").textContent = score1;
  document.getElementById("score2").textContent = score2;
}

function showPopup(text) {
  popup.textContent = text;
  popup.style.display = "block";
  setTimeout(() => { popup.style.display = "none"; }, 1000);
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
  // masters.php の code（'A' / 'B' 等）。無ければ 'B' 扱い
  return (opt && opt.dataset && opt.dataset.code) ? opt.dataset.code : 'B';
}

function recalculateScores() {
  score1 = 0;
  score2 = 0;
  const ruleCode = getSelectedRuleCode(); // 'A' or 'B' (others→'B'扱い)

  for (let j = 1; j <= 9; j++) {
    const state = ballState[j];
    if (state.swiped && state.assigned) {
      let point = 0;
      if (ruleCode === "A") {
        if (j === 9) point = 2;
        else if (j % 2 === 1) point = 1;
        point *= state.multiplier;
      } else { // 'B' or others
        point = j === 9 ? 2 : 1;
      }
      if (state.assigned === 1) score1 += point;
      if (state.assigned === 2) score2 += point;
    }
  }
  updateScoreDisplay();
}

function resetAll() {
  score1 = 0; score2 = 0; updateScoreDisplay();
  for (let i = 1; i <= 9; i++) {
    const state = ballState[i];
    const wrapperEl = state.wrapper;
    wrapperEl.classList.remove("roll-left", "roll-right");
    wrapperEl.style.opacity = "0.5";
    state.swiped = false;
    state.assigned = null;
    state.multiplier = 1;
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

function hideActions() {
  document.getElementById("postRegistActions").style.display = "none";
}

// ------------------------------------
// 登録（勝敗=1/0 で match_detail 保存）
// ------------------------------------
registBtn.addEventListener("click", () => {
  registBtn.classList.add("clicked");
  setTimeout(()=>registBtn.classList.remove("clicked"), 550);

  const gameId = (crypto?.randomUUID ? crypto.randomUUID() : String(Date.now()));
  const p1Id = Number(document.getElementById("player1").value);
  const p2Id = Number(document.getElementById("player2").value);
  const shopId = Number(document.getElementById("shop").value);
  const ruleId = Number(ruleSelect.value);
  const dateStr = document.getElementById("dateInput").value || new Date().toISOString().slice(0,10);

  if (!p1Id || !p2Id || !shopId || !ruleId) {
    showPopup("日付/ルール/店舗/プレイヤーを選択してください");
    return;
  }

  // 点数が高い方を勝者（同点はP1勝ち）
  const score1Flag = (score1 > score2) ? 1 : (score1 === score2 ? 1 : 0);
  const score2Flag = (score1 > score2) ? 0 : (score1 === score2 ? 0 : 1);

  const payload = {
    game_id: gameId,
    date: dateStr,
    rule_id: ruleId,
    shop_id: shopId,
    player1_id: p1Id,
    player2_id: p2Id,
    score1: score1Flag,
    score2: score2Flag,
    // 参考：ボールの割当情報（保存はしないがデバッグ用に残す）
    balls: Object.fromEntries(
      Object.entries(ballState).map(([k, v]) => [
        k,
        { assigned: v.assigned, multiplier: v.multiplier }
      ])
    )
  };

  fetch("./api/finalize_game.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload)
  })
  .then((res) => res.json())
  .then((data) => {
    if (data && data.success) {
      showPopup("登録しました！");
      resetAll();
      document.getElementById("postRegistActions").style.display = "flex";
    } else {
      console.error("保存エラー:", data);
      showPopup("保存に失敗しました");
    }
  })
  .catch((err) => {
    console.error("送信エラー", err);
    showPopup("送信に失敗しました");
  });
});

// ------------------------------------
// マスタ読み込み（/pocketmode/api/masters.php）
// players(id,name) / shops(id,name) / rules(id,code,name)
// ------------------------------------
fetch("./api/masters.php")
  .then(res => res.json())
  .then(data => {
    const shopSel = document.getElementById("shop");
    const p1Sel   = document.getElementById("player1");
    const p2Sel   = document.getElementById("player2");

    // ルール
    ruleSelect.innerHTML = "";
    data.rules.forEach((r, idx) => {
      const opt = document.createElement("option");
      opt.value = r.id;
      opt.textContent = (r.code ? `${r.code}：` : "") + r.name;
      if (r.code) opt.dataset.code = r.code; // A/B 等
      ruleSelect.appendChild(opt);
      if (idx === 0) opt.selected = true;
    });

    // 店舗
    shopSel.innerHTML = "";
    data.shops.forEach((s, idx) => {
      const option = document.createElement("option");
      option.value = s.id;
      option.textContent = s.name;
      if (idx === 0) option.selected = true;
      shopSel.appendChild(option);
    });

    // プレイヤー
    p1Sel.innerHTML = "";
    p2Sel.innerHTML = "";
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

    // localStorage 復元
    const lsP1 = localStorage.getItem("player1_id");
    const lsP2 = localStorage.getItem("player2_id");
    const lsShop = localStorage.getItem("shop_id");
    const lsRule = localStorage.getItem("rule_id");
    if (lsP1 && [...p1Sel.options].some(o => o.value === lsP1)) p1Sel.value = lsP1;
    if (lsP2 && [...p2Sel.options].some(o => o.value === lsP2)) p2Sel.value = lsP2;
    if (lsShop && [...shopSel.options].some(o => o.value === lsShop)) shopSel.value = lsShop;
    if (lsRule && [...ruleSelect.options].some(o => o.value === lsRule)) ruleSelect.value = lsRule;

    attachPlayerChangeListeners();
    updateLabels();
    recalculateScores();
  });

// ------------------------------------
// ボールUI生成（1〜9）
// ------------------------------------
for (let i = 1; i <= 9; i++) {
  const wrapper = document.createElement("div");
  wrapper.classList.add("ball-wrapper");
  wrapper.style.opacity = "0.5";

  const img = document.createElement("img");
  img.src = `/images/ball${i}.png`; // 既存パスを踏襲
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
  const onStart = (clientX) => { startX = clientX; };
  const onEnd   = (clientX) => {
    if (startX === null) return;
    const deltaX = clientX - startX;
    if (Math.abs(deltaX) < 30) return;

    const prevAssigned = ballState[i].assigned;
    const isSwiped     = ballState[i].swiped;
    const wrapperEl    = ballState[i].wrapper;

    if (!isSwiped) {
      if (deltaX < -30) { ballState[i].assigned = 1; restartAnimation(wrapperEl, "roll-left"); }
      else if (deltaX > 30) { ballState[i].assigned = 2; restartAnimation(wrapperEl, "roll-right"); }
      ballState[i].swiped = true;
      playSoundOverlap("sounds/swipe.mp3");
    } else {
      // 反転方向にスワイプでキャンセル
      if ((prevAssigned === 1 && deltaX > 30) || (prevAssigned === 2 && deltaX < -30)) {
        ballState[i].assigned = null;
        ballState[i].swiped = false;
        wrapperEl.classList.remove("roll-left", "roll-right");
        wrapperEl.style.opacity = "0.5";
        playSoundOverlap("sounds/cancel.mp3");
      }
    }
    recalculateScores();
  };

  wrapper.addEventListener("touchstart", (e) => onStart(e.touches[0].clientX));
  wrapper.addEventListener("touchend",   (e) => onEnd(e.changedTouches[0].clientX));
  wrapper.addEventListener("mousedown",  (e) => onStart(e.clientX));
  wrapper.addEventListener("mouseup",    (e) => onEnd(e.clientX));

  // クリックで倍率切替（×1/×2）
  wrapper.addEventListener("click", () => {
    if (!ballState[i].swiped) return;
    ballState[i].multiplier = ballState[i].multiplier === 1 ? 2 : 1;
    updateMultiplierLabel(i);
    showPopup(ballState[i].multiplier === 2 ? "サイド（得点×2）" : "コーナー（得点×1）");
    playSoundOverlap("sounds/side.mp3");
    recalculateScores();
  });
}

resetBtn.addEventListener("click", resetAll);

// 既存のPWA Service Worker を使う場合はこのまま（なければエラーにはなりません）
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('service-worker.js')
    .then(() => console.log("Service Worker Registered"))
    .catch(err => console.error("SW registration failed:", err));
}
