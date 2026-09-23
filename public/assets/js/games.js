/* ============================================================
   游戏板块二级页面（/games）的脚本

   与首页的关系：
     · 公共能力（api / el / 提示条 / 两段式确认）都来自 core.js，这里不重复实现
     · 登录态只用来决定「要不要显示管理区」，能不能改仍然由后端说了算

   列表来源：
     管理员走 /api/admin/games（含已下架的，否则下架之后就再也上不架了），
     其他人走 /api/games（只有已上架的）。这个分流由后端按登录身份判定，
     前端只是选个地址，伪造地址也拿不到多余数据。
   ============================================================ */

const gamesState = { items: [], manageable: false, sending: false };
let me = { logged: false, username: '', role: '' };

const gamesGrid = el('games-grid');
const gamesEmpty = el('games-empty');
const gamesAdmin = el('games-admin');
const gamesAdminLink = el('games-admin-link');
const navUser = el('nav-user');
const navUsername = el('nav-username');
const visitCountEl = el('visit-count');
const gamesLoginLink = el('games-login-link');

const gameName = el('game-name');
const gameUrl = el('game-url');
const gameIcon = el('game-icon');
const gamePublisher = el('game-publisher');
const gameGenre = el('game-genre');
const gameSort = el('game-sort');
const gameSlug = el('game-slug');
const gameDesc = el('game-desc');
const gameSubmit = el('game-submit');
const gameTip = el('game-tip');
const gameFormMeta = el('game-form-meta');

/* ============================================================
   第 1 部分：登录态
   ============================================================ */
function loadMe() {
  return api('me').then(function (data) {
    me = (data && data.logged)
      ? { logged: true, username: data.username, role: data.role }
      : { logged: false, username: '', role: '' };
    navUser.classList.toggle('hidden', !me.logged);
    if (gamesLoginLink) gamesLoginLink.classList.toggle('hidden', me.logged);
    if (me.logged) navUsername.textContent = '你好，' + me.username;
  }).catch(function (error) {
    // 后端连不上时也要让页面能用（列表会显示为空 + 一条提示）
    showAlert('后端接口连不上（' + error.message + '）', 8000);
  });
}

/* ============================================================
   第 2 部分：游戏卡片
   ============================================================ */
function badge(text, extraClass) {
  const span = document.createElement('span');
  span.className = 'badge ' + extraClass;
  span.textContent = text;
  return span;
}

/**
 * 只有 http / https 才当作可跳转地址。
 *
 * 后端 validate_game_url() 已经拦掉了 javascript: 这类伪协议，
 * 这里再判一次是纵深防御：这个值会被写进 <a href>，
 * 万一以后有别的写入路径绕过了校验，也不至于在别人浏览器里执行脚本。
 * 不合法时降级成纯文本展示，不生成链接。
 */
function safeUrl(url) {
  return /^https?:\/\/[^\s]+$/i.test(String(url || '')) ? url : '';
}

function gameCard(game) {
  const li = document.createElement('li');
  // id 用后端算好的锚点：有 slug 就是 game-xxx，没有就用 game-<id>
  // 这样 /games#game-genshin-impact 能直接定位到某一张卡片
  li.id = game.anchor;
  li.className = 'game-card' + (game.enabled ? '' : ' offline');

  const head = document.createElement('div');
  head.className = 'game-head';

  const icon = document.createElement('span');
  icon.className = 'game-icon';
  icon.textContent = game.icon;// 后端已保证非空，兜底是 🎮
  head.appendChild(icon);

  const name = document.createElement('span');
  name.className = 'game-name';
  name.textContent = game.name;// 游戏名是管理员填的，依然只走 textContent
  head.appendChild(name);
  li.appendChild(head);

  const meta = document.createElement('div');
  meta.className = 'game-meta';
  if (game.publisher) meta.appendChild(badge(game.publisher, 'badge-plain'));
  if (game.genre) meta.appendChild(badge(game.genre, 'badge-plain'));
  if (!game.enabled) meta.appendChild(badge('已下架', 'badge-warn'));
  if (meta.childNodes.length > 0) li.appendChild(meta);

  if (game.description) {
    const desc = document.createElement('p');
    desc.className = 'game-desc';
    desc.textContent = game.description;
    li.appendChild(desc);
  }

  const urlText = document.createElement('span');
  urlText.className = 'game-url';
  urlText.textContent = game.url;
  li.appendChild(urlText);

  const actions = document.createElement('div');
  actions.className = 'game-actions';

  const href = safeUrl(game.url);
  if (href !== '') {
    // 跳转：新标签打开。rel 同时给 noopener 与 noreferrer ——
    // noopener 防止新页面通过 window.opener 反向操作本页
    const go = document.createElement('a');
    go.className = 'btn-primary game-go';
    go.href = href;
    go.target = '_blank';
    go.rel = 'noopener noreferrer';
    go.textContent = '前往官网 ↗';
    actions.appendChild(go);
  } else {
    const broken = document.createElement('span');
    broken.className = 'game-go-disabled';
    broken.textContent = '地址不可跳转';
    actions.appendChild(broken);
  }

  if (game.canManage) {
    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'btn-mini';
    toggle.textContent = game.enabled ? '下架' : '上架';
    toggle.addEventListener('click', function () { setGameEnabled(game, !game.enabled); });
    actions.appendChild(toggle);

    const del = document.createElement('button');
    del.type = 'button';
    del.className = 'row-del';
    del.textContent = '删除';
    del.addEventListener('click', function () {
      armConfirm(del, '确认删除？', function () { deleteGame(game); });
    });
    actions.appendChild(del);
  }

  li.appendChild(actions);
  return li;
}

/* ============================================================
   第 3 部分：列表加载与渲染
   ============================================================ */
function renderGames(data) {
  gamesState.items = data.items;
  gamesState.manageable = data.manageable === true;

  gamesAdmin.classList.toggle('hidden', !gamesState.manageable);
  gamesAdminLink.classList.toggle('hidden', !gamesState.manageable);

  gameName.maxLength = data.maxName || 40;
  gameDesc.maxLength = data.maxDescription || 300;

  gamesGrid.textContent = '';
  gamesEmpty.classList.toggle('hidden', data.items.length > 0);
  data.items.forEach(function (game) { gamesGrid.appendChild(gameCard(game)); });

  updateFormMeta();
}

function loadGames() {
  const path = me.role === 'admin' ? 'admin/games' : 'games';
  return api(path).then(renderGames).catch(function (error) {
    showAlert('读取游戏列表失败：' + error.message, 6000);
  });
}

/* ============================================================
   第 4 部分：添加（预留接口的前端入口）
   ============================================================ */
function updateFormMeta() {
  if (!gameFormMeta) return;
  const total = gamesState.items.length;
  const onShelf = gamesState.items.filter(function (g) { return g.enabled; }).length;
  gameFormMeta.textContent = '当前共 ' + total + ' 个，其中上架 ' + onShelf + ' 个';
}

function handleAddGame() {
  if (gamesState.sending) return;
  const name = gameName.value.trim();
  const url = gameUrl.value.trim();
  if (!name) return setMsg(gameTip, '请填写游戏名', 'error');
  if (!url) return setMsg(gameTip, '请填写跳转地址', 'error');
  if (!/^https?:\/\//i.test(url)) return setMsg(gameTip, '跳转地址必须以 http:// 或 https:// 开头', 'error');

  gamesState.sending = true;
  gameSubmit.disabled = true;
  setMsg(gameTip, '正在添加…', '');

  api('admin/games', 'POST', {
    name: name,
    url: url,
    icon: gameIcon.value.trim(),
    publisher: gamePublisher.value.trim(),
    genre: gameGenre.value.trim(),
    slug: gameSlug.value.trim(),
    description: gameDesc.value,
    sortOrder: gameSort.value,
  }).then(function (result) {
    setMsg(gameTip, '已添加「' + result.game.name + '」', 'ok');
    gameName.value = '';
    gameUrl.value = '';
    gameIcon.value = '';
    gamePublisher.value = '';
    gameGenre.value = '';
    gameSlug.value = '';
    gameDesc.value = '';
    gameSort.value = '100';
    return loadGames();
  }).catch(function (error) {
    setMsg(gameTip, error.message, 'error');
  }).then(function () {
    gamesState.sending = false;
    gameSubmit.disabled = false;
  });
}

/* ============================================================
   第 5 部分：上下架与删除
   ============================================================ */
function setGameEnabled(game, enabled) {
  api('admin/games/update', 'POST', { id: game.id, enabled: enabled }).then(function () {
    showAlert('已' + (enabled ? '上架' : '下架') + '「' + game.name + '」', 3000);
    return loadGames();
  }).catch(function (error) {
    showAlert('操作失败：' + error.message, 5000);
  });
}

function deleteGame(game) {
  api('admin/games', 'DELETE', { id: game.id }).then(function () {
    showAlert('已删除「' + game.name + '」', 3000);
    return loadGames();
  }).catch(function (error) {
    showAlert('删除失败：' + error.message, 5000);
  });
}

/* ============================================================
   第 6 部分：启动
   ============================================================ */
function loadVisitCount() {
  return api('visit', 'POST', {}).then(function (stats) {
    if (visitCountEl) visitCountEl.textContent = String(stats.totalVisits);
  }).catch(function () {
    if (visitCountEl) visitCountEl.textContent = '未知';// 计数失败不该影响看页面
  });
}

function bindEvents() {
  gameSubmit.addEventListener('click', handleAddGame);
  // 回车提交：填完地址顺手回车就能加，不必去点按钮
  [gameName, gameUrl, gameIcon, gamePublisher, gameGenre, gameSort, gameSlug].forEach(function (input) {
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        handleAddGame();
      }
    });
  });
  window.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') disarmConfirm();
  });
}

function boot() {
  bindEvents();
  // 先问「我是谁」再拉列表：管理员要换成含下架内容的那个接口
  Promise.all([loadMe(), loadVisitCount()]).then(loadGames);
}
boot();
