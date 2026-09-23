/* ============================================================
   学习板块二级页面（/study）的脚本：一个番茄钟 + 自己的专注记录

   两个值得记住的实现选择：

   1. 计时按「结束时刻」倒推，不按 tick 累加。
      start 时记下 endAt = Date.now() + 剩余秒数，之后每一拍都用
      (endAt - now) 重算剩余。这样切到别的标签页（浏览器会把定时器降到
      1 次/秒甚至更稀）、或者机器休眠醒来，时间都不会走慢——
      累加写法在这些场合必然偏少，而番茄钟偏了就等于白计。

   2. 记录只在「一轮结束」或「跑够一分钟后放弃」时写一条，没有编辑。
      番茄钟记的是发生过的事实；改一条记录等于伪造历史，不如删掉重来。
   ============================================================ */

const pomoState = {
  phase: 'focus',// focus 专注 | break 休息：休息那一轮不写进记录
  minutes: 25,
  total: 25 * 60,
  remaining: 25 * 60,
  endAt: 0,
  running: false,
  timer: 0,
  sending: false,
};

const RING_LENGTH = 2 * Math.PI * 52;// viewBox 里那个 r=52 的圆周长

const pomoTimeEl = el('pomo-time');
const pomoPhaseEl = el('pomo-phase');
const pomoRingEl = el('pomo-progress');
const pomoStageEl = document.querySelector('.pomo-ring');
const pomoSubjectEl = el('pomo-subject');
const pomoMinutesEl = el('pomo-minutes');
const pomoToggleEl = el('pomo-toggle');
const pomoResetEl = el('pomo-reset');
const pomoBreakEl = el('pomo-break');
const pomoTipEl = el('pomo-tip');
const pomoTodayEl = el('pomo-today');
const pomoTotalEl = el('pomo-total');
const pomoMinutesTotalEl = el('pomo-minutes-total');
const pomoGateEl = el('pomo-gate');
const pomoBodyEl = el('pomo-body');
const pomoListEl = el('pomo-list');
const pomoEmptyEl = el('pomo-empty');

const navUser = el('nav-user');
const navUsername = el('nav-username');
const visitCountEl = el('visit-count');
const studyLoginLink = el('study-login-link');

let me = { logged: false, username: '' };
const baseTitle = document.title;

/* ============================================================
   第 1 部分：显示
   ============================================================ */
function fmtSeconds(sec) {
  const s = Math.max(0, Math.round(sec));
  const m = Math.floor(s / 60);
  const r = s % 60;
  return (m < 10 ? '0' : '') + m + ':' + (r < 10 ? '0' : '') + r;
}

/** 把剩余时间画到环上和数字上；标题也顺带改掉，切到别的标签页也能瞟一眼 */
function renderPomo() {
  if (!pomoTimeEl) return;
  const text = fmtSeconds(pomoState.remaining);
  pomoTimeEl.textContent = text;
  pomoPhaseEl.textContent = pomoState.phase === 'break' ? '休息' : '专注';
  const ratio = pomoState.total > 0 ? pomoState.remaining / pomoState.total : 0;
  pomoRingEl.style.strokeDashoffset = String(RING_LENGTH * ratio);
  pomoStageEl.classList.toggle('is-running', pomoState.running);
  document.title = pomoState.running ? text + ' · 学习板块' : baseTitle;
}

function renderStats(stats) {
  pomoTodayEl.textContent = String(stats.today);
  pomoTotalEl.textContent = String(stats.total);
  pomoMinutesTotalEl.textContent = String(stats.minutes);
}

/* ============================================================
   第 2 部分：计时
   ============================================================ */
function stopTicker() {
  if (pomoState.timer) {
    clearInterval(pomoState.timer);
    pomoState.timer = 0;
  }
}

/** 换一轮：休息固定 5 分钟，专注用输入框里的分钟数 */
function setRound(phase) {
  stopTicker();
  const minutes = phase === 'break' ? 5 : clampMinutes(pomoMinutesEl.value);
  pomoState.phase = phase;
  pomoState.minutes = minutes;
  pomoState.total = minutes * 60;
  pomoState.remaining = minutes * 60;
  pomoState.running = false;
  pomoState.endAt = 0;
  pomoToggleEl.textContent = '开始';
  renderPomo();
}

function clampMinutes(value) {
  const min = pomoLimits.minMinutes || 1;
  const max = pomoLimits.maxMinutes || 180;
  const n = parseInt(value, 10);
  if (isNaN(n)) return pomoLimits.minutes || 25;
  return Math.min(max, Math.max(min, n));
}

function tick() {
  const left = Math.round((pomoState.endAt - Date.now()) / 1000);
  pomoState.remaining = left > 0 ? left : 0;
  renderPomo();
  if (pomoState.remaining === 0) {
    finishRound(true);
  }
}

function startPomo() {
  if (pomoState.remaining <= 0) {
    setRound(pomoState.phase);
  }
  pomoState.endAt = Date.now() + pomoState.remaining * 1000;
  pomoState.running = true;
  pomoToggleEl.textContent = '暂停';
  setMsg(pomoTipEl, '');
  stopTicker();
  pomoState.timer = setInterval(tick, 250);
  renderPomo();
}

function pausePomo() {
  stopTicker();
  // 暂停时把剩余秒数落回整数：endAt 是毫秒精度的，不取整会出现「暂停后差 1 秒」
  pomoState.remaining = Math.max(0, Math.round((pomoState.endAt - Date.now()) / 1000));
  pomoState.running = false;
  pomoToggleEl.textContent = '继续';
  renderPomo();
}

/** 一轮到头：专注轮去写记录，休息轮只是提示一句 */
function finishRound(completed) {
  stopTicker();
  pomoState.running = false;
  pomoToggleEl.textContent = '开始';
  const wasBreak = pomoState.phase === 'break';
  const elapsed = pomoState.total - pomoState.remaining;
  renderPomo();
  if (wasBreak) {
    setMsg(pomoTipEl, completed ? '休息结束，要不再来一轮？' : '', 'ok');
    setRound('focus');
    return;
  }
  if (completed) {
    savePomo(elapsed, true);
  }
}

/**
 * 重置。
 * 已经跑过一分钟的专注轮会按「中途放弃」记一条——放弃本身就是要被看见的信息；
 * 刚点下去就重置的（不到 60 秒）当成误触，不写库。
 */
function resetPomo() {
  const elapsed = pomoState.total - pomoState.remaining;
  const wasFocus = pomoState.phase === 'focus';
  stopTicker();
  if (wasFocus && elapsed >= 60) {
    setMsg(pomoTipEl, '这一轮按「未完成」记下了。', 'ok');
    if (me.logged && !pomoState.sending) {
      savePomo(elapsed, false);
    }
  } else {
    setMsg(pomoTipEl, '');
  }
  setRound('focus');
}

/* ============================================================
   第 3 部分：与后端
   ============================================================ */
let pomoLimits = { minutes: 25, minMinutes: 1, maxMinutes: 180, maxSubject: 40 };

function savePomo(elapsed, finished) {
  if (!me.logged) {
    setMsg(pomoTipEl, '未登录，这一轮没有保存。', 'warn');
    return;
  }
  if (pomoState.sending) return;
  pomoState.sending = true;
  api('study/pomodoro', 'POST', {
    subject: pomoSubjectEl.value,
    minutes: pomoState.minutes,
    elapsed: elapsed,
    finished: !!finished,
  }).then(function () {
    setMsg(pomoTipEl, finished ? '这一轮记下了。' : '放弃的那一轮也记下了。', 'ok');
    return loadStudy();
  }).catch(function (error) {
    setMsg(pomoTipEl, '没有保存成功：' + error.message, 'error');
  }).then(function () {
    pomoState.sending = false;
  });
}

function loadStudy() {
  return api('study').then(function (data) {
    if (data && data.defaults) pomoLimits = data.defaults;
    if (data && data.stats) renderStats(data.stats);
    renderPomoList(data && data.items ? data.items : []);
    const logged = !!(data && data.logged);
    pomoGateEl.classList.toggle('hidden', logged);
    pomoBodyEl.classList.toggle('hidden', !logged);
  }).catch(function (error) {
    showAlert('学习记录读不出来（' + error.message + '）', 8000);
  });
}

function renderPomoList(items) {
  if (!pomoListEl) return;
  pomoListEl.textContent = '';
  pomoEmptyEl.classList.toggle('hidden', items.length > 0);
  items.forEach(function (item) {
    const li = document.createElement('li');
    li.className = 'pomo-item' + (item.finished ? '' : ' aborted');

    const head = document.createElement('div');
    head.className = 'pomo-item-head';
    const name = document.createElement('b');
    name.textContent = item.subject || '未命名';
    const meta = document.createElement('span');
    meta.textContent = item.minutes + ' 分钟 · ' + item.percent + '% · ' + item.createdAt;
    head.appendChild(name);
    head.appendChild(meta);

    const del = document.createElement('button');
    del.className = 'btn-mini pomo-del';
    del.type = 'button';
    del.textContent = '删除';
    del.addEventListener('click', function () {
      armConfirm(del, '确认删除？', function () {
        api('study/pomodoro', 'DELETE', { id: item.id }).then(loadStudy).catch(function (error) {
          showAlert('删除失败：' + error.message, 6000);
        });
      });
    });

    const bar = document.createElement('i');
    bar.className = 'pomo-bar';
    bar.style.width = Math.max(2, item.percent) + '%';

    li.appendChild(head);
    li.appendChild(bar);
    li.appendChild(del);
    pomoListEl.appendChild(li);
  });
}

/* ============================================================
   第 4 部分：登录态与事件
   ============================================================ */
function loadMe() {
  return api('me').then(function (data) {
    me = (data && data.logged)
      ? { logged: true, username: data.username }
      : { logged: false, username: '' };
    navUser.classList.toggle('hidden', !me.logged);
    if (studyLoginLink) studyLoginLink.classList.toggle('hidden', me.logged);
    if (me.logged) navUsername.textContent = '你好，' + me.username;
  }).catch(function (error) {
    showAlert('后端接口连不上（' + error.message + '）', 8000);
  });
}

function bindPomo() {
  if (!pomoToggleEl) return;
  pomoToggleEl.addEventListener('click', function () {
    if (pomoState.running) {
      pausePomo();
    } else {
      startPomo();
    }
  });
  pomoResetEl.addEventListener('click', resetPomo);
  pomoBreakEl.addEventListener('click', function () {
    setRound('break');
    setMsg(pomoTipEl, '休息轮不会写进记录。', 'ok');
    startPomo();
  });
  pomoMinutesEl.addEventListener('change', function () {
    const n = clampMinutes(pomoMinutesEl.value);
    pomoMinutesEl.value = String(n);
    if (!pomoState.running && pomoState.phase === 'focus') setRound('focus');
    syncChips();
  });
  // 三个预设 chip 走事件委托：它们长在 HTML 里，改数字不用回来动 JS
  document.querySelectorAll('.pomo-controls .chip').forEach(function (chip) {
    chip.addEventListener('click', function () {
      pomoMinutesEl.value = chip.dataset.minutes;
      if (pomoState.running) {
        setMsg(pomoTipEl, '正在计时，先暂停或重置再改时长。', 'warn');
        return;
      }
      setRound('focus');
      syncChips();
    });
  });
  // 空格键 = 开始 / 暂停；输入框里打字时不算。Esc 收掉待确认的删除按钮。
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      disarmConfirm();
      return;
    }
    const tag = (event.target && event.target.tagName) || '';
    if (event.code === 'Space' && tag !== 'INPUT' && tag !== 'TEXTAREA' && tag !== 'BUTTON') {
      event.preventDefault();
      pomoToggleEl.click();
    }
  });
}

function syncChips() {
  const current = String(parseInt(pomoMinutesEl.value, 10));
  document.querySelectorAll('.pomo-controls .chip').forEach(function (chip) {
    chip.classList.toggle('active', chip.dataset.minutes === current);
  });
}

function boot() {
  bindPomo();
  setRound('focus');
  syncChips();
  loadMe().then(loadStudy).then(function () {
    pomoMinutesEl.value = String(pomoLimits.minutes || 25);
    setRound('focus');
    syncChips();
  });
  if (visitCountEl) {
    api('visit', 'POST', {}).then(function (stats) {
      if (visitCountEl) visitCountEl.textContent = String(stats.totalVisits);
    }).catch(function () {
      if (visitCountEl) visitCountEl.textContent = '未知';// 计数失败不该影响这一页的主功能
    });
  }
}
boot();
