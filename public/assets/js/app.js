/* ============================================================
   web-one 前端脚本（全栈版）

   与原来单文件版本的根本区别：
     浏览器里不再存任何账号数据。登录状态由后端发的 Cookie 表示，
     所有用户数据都通过 /api/* 由 PHP 从 MySQL 读写。
     因此原来的「localStorage 数据库」「Web Crypto 算密码哈希」「ensureAdmin」
     三段代码整体删除了——密码哈希改由后端 bcrypt 完成，管理员改由安装脚本写入。
   ============================================================ */

/* ============================================================
   第 1 部分：配置 —— 想改文字、动画速度、是否自动进入，改这里
   ============================================================ */
const CONFIG = {
  welcomeText: '欢迎回来',// 欢迎页逐字显示的文字（登录后会自动变成“欢迎回来，用户名”）
  typeInterval: 260,// 打字机速度：每个字出现的间隔（毫秒）
  startDelay: 500,// 页面加载后延迟多少毫秒开始打字
  glowDelay: 350,// 打完最后一个字后延迟多少毫秒开始发光
  holdDuration: 1400,// 发光后停留多少毫秒再淡出进入主页面
  autoEnter: true,// 是否自动进入主页面；false 则需点击/按任意键
  apiTimeout: 5000,// 等后端返回的最长时间（毫秒），超时就不再卡住动画
};

/* ============================================================
   第 2 部分：与后端接口通信的唯一出口
   所有请求都走这一个函数，超时、报错、JSON 解析都在集中处理。
   ============================================================ */
function api(path, method, payload) {
  const options = {
    method: method || 'GET',
    headers: { Accept: 'application/json' },
    credentials: 'same-origin',// 同源请求自动携带登录 Cookie（不用手写 token 头）
  };
  if (payload !== undefined) {// 有请求体时按后端约定发 JSON
    options.headers['Content-Type'] = 'application/json';
    options.body = JSON.stringify(payload);
  }
  // 后端不响应时（忘了启动、端口被占）主动中断，避免欢迎动画一直等
  const controller = typeof AbortController === 'function' ? new AbortController() : null;
  if (controller) {
    options.signal = controller.signal;
    setTimeout(function () { controller.abort(); }, CONFIG.apiTimeout);
  }

  return fetch('/api/' + path, options).then(function (res) {
    return res.json().catch(function () { return null; }).then(function (data) {
      if (!res.ok) {// 后端的失败响应统一是 { error: "给用户看的一句话" }
        const err = new Error((data && data.error) || ('请求失败（HTTP ' + res.status + '）'));
        err.status = res.status;
        throw err;
      }
      return data;
    });
  }).catch(function (err) {
    if (err.name === 'AbortError') {
      const timeout = new Error('后端响应超时（' + CONFIG.apiTimeout + ' 毫秒）');
      timeout.status = 0;
      throw timeout;
    }
    throw err;// NetworkError：服务没启动、跨域被拦等，status 留空
  });
}

/* ============================================================
   第 3 部分：页面状态与元素引用
   ============================================================ */
let me = { logged: false, username: '', role: '' };// 当前登录者，完全由 /api/me 决定
let currentMode = 'login';// 弹窗当前模式：'login' / 'register'
let charIndex = 0;// 打字机：当前打到了第几个字
let hasEntered = false;// 是否已进入主页面，防止重复触发
let pendingAuth = false;// 登录/注册请求进行中，防止连点重复提交

const welcomeScreen = document.getElementById('welcome-screen');// 欢迎页整层
const welcomeTextEl = document.getElementById('welcome-text');// 打字机文字
const enterHint = document.getElementById('enter-hint');// “点击进入”提示
const visitCountEl = document.getElementById('visit-count');// 页脚访问次数
const topAlert = document.getElementById('top-alert');// 顶部提示条
const navLoginBtn = document.getElementById('nav-login');// “登录”按钮
const navUserChip = document.getElementById('nav-user');// 用户胶囊
const navUsername = document.getElementById('nav-username');// 胶囊里的用户名
const navLogoutBtn = document.getElementById('nav-logout');// “退出”按钮
const navAdminBtn = document.getElementById('nav-admin');// “后台”按钮（仅管理员可见）
const loginModal = document.getElementById('login-modal');// 登录/注册弹窗
const modalClose = document.getElementById('login-close');// 弹窗 ✕
const modalTitle = document.getElementById('modal-title');// 弹窗标题
const modalMsg = document.getElementById('modal-msg');// 弹窗提示文字
const inputUsername = document.getElementById('input-username');// 用户名输入框
const inputPassword = document.getElementById('input-password');// 密码输入框
const inputConfirm = document.getElementById('input-confirm');// 确认密码框
const modalSubmit = document.getElementById('modal-submit');// 提交按钮
const loginTab = document.getElementById('tab-login');// “登录”标签
const registerTab = document.getElementById('tab-register');// “注册”标签
const adminModal = document.getElementById('admin-modal');// 后台面板遮罩
const adminClose = document.getElementById('admin-close');// 面板 ✕
const adminRefresh = document.getElementById('admin-refresh');// 面板刷新按钮
const adminRows = document.getElementById('admin-user-rows');// 表格 tbody
const statVisits = document.getElementById('stat-visits');// 统计：累计访问
const statToday = document.getElementById('stat-today');// 统计：今日访问
const statUsers = document.getElementById('stat-users');// 统计：注册用户

/* ============================================================
   第 4 部分：顶部提示条 —— 用来报告后端层面的问题
   ============================================================ */
let alertTimer = null;
function showAlert(message, holdMs) {
  // 只写 textContent：提示里可能出现用户名等用户输入的内容，不能当 HTML 解析
  topAlert.textContent = message;
  topAlert.classList.remove('hidden');
  if (alertTimer) clearTimeout(alertTimer);
  if (holdMs === 0) return;// 传 0 表示常驻，需要用户自己处理
  alertTimer = setTimeout(hideAlert, holdMs || 5000);
}
function hideAlert() {
  topAlert.classList.add('hidden');
  topAlert.textContent = '';
}
function showBackendDownAlert(error) {
  // 这段提示里带一个可复制的命令，用 createElement 拼，不用 innerHTML
  const prefix = document.createTextNode(
    '后端接口连不上（' + error.message + '）。请先在项目根目录启动服务：'
  );
  const code = document.createElement('code');
  code.textContent = 'php -S localhost:8000 -t public public/index.php';
  topAlert.textContent = '';
  topAlert.appendChild(prefix);
  topAlert.appendChild(code);
  topAlert.classList.remove('hidden');
}

/* ============================================================
   第 5 部分：导航栏登录状态
   注意：前端的 isAdmin() 只决定“要不要显示后台按钮”，
   真正的权限判断在 src/auth.php 的 require_admin() 里。
   ============================================================ */
function isAdmin() {
  return me.logged === true && me.role === 'admin';
}
function refreshNavbar() {
  navLoginBtn.classList.toggle('hidden', me.logged);
  navUserChip.classList.toggle('hidden', !me.logged);
  navAdminBtn.classList.toggle('hidden', !isAdmin());
  if (me.logged) navUsername.textContent = '你好，' + me.username;
}
function handleLogout() {
  api('logout', 'POST', {}).then(function () {
    me = { logged: false, username: '', role: '' };
    closeAdmin();
    refreshNavbar();
    showAlert('已退出登录', 3000);
  }).catch(function (error) {
    showAlert('退出失败：' + error.message, 5000);
  });
}

/* ============================================================
   第 6 部分：登录 / 注册弹窗的打开、关闭、模式切换与提示
   ============================================================ */
function openLogin() {
  loginModal.classList.add('show');
  inputUsername.focus();
}
function closeLogin() {
  loginModal.classList.remove('show');
  showMsg('', '');
}
function switchMode(mode) {
  currentMode = mode;
  const isLogin = mode === 'login';
  loginTab.classList.toggle('active', isLogin);
  registerTab.classList.toggle('active', !isLogin);
  inputConfirm.classList.toggle('hidden', isLogin);
  modalTitle.textContent = isLogin ? '欢迎回来' : '创建账号';
  modalSubmit.textContent = isLogin ? '登 录' : '注 册';
  showMsg('', '');
}
function showMsg(text, type) {
  modalMsg.textContent = text;
  modalMsg.className = 'modal-msg' + (type ? ' ' + type : '');
}
function setPendingAuth(isPending) {
  pendingAuth = isPending;
  modalSubmit.disabled = isPending;// 请求期间禁用按钮，防止连点注册出两个账号
  modalSubmit.style.opacity = isPending ? '0.6' : '';
  modalSubmit.style.cursor = isPending ? 'progress' : '';
}

/* ============================================================
   第 7 部分：注册与登录
   前端的长度/一致性校验只是为了即时反馈，后端会再校验一次；
   绕过前端直接调接口是拦不住的，所以后端那一次才是准的。
   ============================================================ */
function handleRegister() {
  if (pendingAuth) return;
  const name = inputUsername.value.trim();
  const pwd = inputPassword.value;
  if (name.length < 2 || name.length > 20) return showMsg('用户名长度需为 2~20 个字符', 'error');
  if (pwd.length < 6) return showMsg('密码至少需要 6 位', 'error');
  if (pwd !== inputConfirm.value) return showMsg('两次输入的密码不一致', 'error');
  submitAuth('register', name, pwd);
}
function handleLogin() {
  if (pendingAuth) return;
  const name = inputUsername.value.trim();
  const pwd = inputPassword.value;
  if (!name) return showMsg('请填写用户名', 'error');
  if (!pwd) return showMsg('请填写密码', 'error');
  submitAuth('login', name, pwd);
}
function submitAuth(action, name, pwd) {
  setPendingAuth(true);
  showMsg('正在提交…', '');
  api(action, 'POST', { username: name, password: pwd }).then(function (data) {
    me = { logged: true, username: data.username, role: data.role };
    hideAlert();
    afterAuthSuccess(data.username);
  }).catch(function (error) {
    showMsg(error.message, 'error');
    if (error.status === 0) showBackendDownAlert(error);// 顺带把后端没启动的原因也说清楚
  }).then(function () {
    setPendingAuth(false);
  });
}
function afterAuthSuccess(name) {
  showMsg('欢迎你，' + name + '！', 'ok');
  refreshNavbar();
  inputUsername.value = '';
  inputPassword.value = '';
  inputConfirm.value = '';
  setTimeout(closeLogin, 700);// 停 0.7 秒让人看到成功提示再自动关窗
}

/* ============================================================
   第 8 部分：后台管理面板（数据全部来自 MySQL）
   ============================================================ */
function cell(text) {
  // 刻意用 textContent 而不是拼 innerHTML：用户名是用户任意输入的，
  // 走 textContent 才不会把 <img onerror=...> 之类内容当成 HTML 执行（防 XSS）。
  const td = document.createElement('td');
  td.textContent = text;
  return td;
}
function renderAdmin(data) {
  statVisits.textContent = String(data.stats.totalVisits);
  statToday.textContent = String(data.stats.todayVisits);
  statUsers.textContent = String(data.stats.userCount);
  adminRows.textContent = '';// 清空旧行
  data.users.forEach(function (u) {
    const tr = document.createElement('tr');

    const nameTd = document.createElement('td');
    nameTd.textContent = u.username;
    if (u.online) {// 绿点：该账号还有未过期的登录会话
      const dot = document.createElement('span');
      dot.className = 'dot-online';
      dot.title = '有未过期的登录会话';
      nameTd.appendChild(dot);
    }
    tr.appendChild(nameTd);

    const roleTd = document.createElement('td');
    if (u.role === 'admin') {
      const tag = document.createElement('span');
      tag.className = 'tag-admin';
      tag.textContent = '管理员';
      roleTd.appendChild(tag);
    } else {
      roleTd.textContent = '普通用户';
    }
    tr.appendChild(roleTd);

    tr.appendChild(cell(u.createdAt));// 注册时间（后端已格式化成 2026-09-19 22:41）
    tr.appendChild(cell(u.lastLoginAt));// 最后登录
    tr.appendChild(cell(String(u.loginCount)));// 登录次数

    const opTd = document.createElement('td');
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'row-del';
    btn.textContent = '删除';
    if (u.role === 'admin') {
      btn.disabled = true;// 置灰只是提示，后端 admin_delete_user() 也会拒绝
      btn.title = '管理员账号不可删除';
    } else {
      btn.addEventListener('click', function () { armDeleteButton(btn, u.username); });
    }
    opTd.appendChild(btn);
    tr.appendChild(opTd);

    adminRows.appendChild(tr);
  });
}
function loadAdmin() {
  adminRefresh.disabled = true;
  return api('admin/users').then(function (data) {
    renderAdmin(data);
  }).catch(function (error) {
    if (error.status === 401 || error.status === 403) {// 会话过期或权限不足
      me = { logged: false, username: '', role: '' };
      refreshNavbar();
      closeAdmin();
    }
    showAlert('读取后台数据失败：' + error.message, 5000);
  }).then(function () {
    adminRefresh.disabled = false;
  });
}
/* 删除确认用“点两次”的方式：第一次把按钮点亮成“确认删除？”，
   4 秒内再点一次才真的发请求。刻意不用 window.confirm——它会阻塞整个页面线程，
   浏览器标签会一直卡住（自动化测试也没法继续），而且样式和这个暗色面板完全不搭。 */
let deleteArmTimer = null;
function disarmDeleteButtons() {
  if (deleteArmTimer) { clearTimeout(deleteArmTimer); deleteArmTimer = null; }
  const armed = document.querySelectorAll('#admin-user-rows .row-del[data-armed="1"]');
  for (let i = 0; i < armed.length; i++) {
    armed[i].dataset.armed = '';
    armed[i].textContent = '删除';
    armed[i].classList.remove('confirming');
  }
}
function armDeleteButton(btn, name) {
  const alreadyArmed = btn.dataset.armed === '1';
  disarmDeleteButtons();// 任何时候只允许一个按钮处于“待确认”状态
  if (alreadyArmed) { deleteUser(name); return; }
  btn.dataset.armed = '1';
  btn.textContent = '确认删除？';
  btn.classList.add('confirming');
  deleteArmTimer = setTimeout(disarmDeleteButtons, 4000);
}
function deleteUser(name) {
  api('admin/users', 'DELETE', { username: name }).then(function () {
    showAlert('已删除用户 ' + name, 3000);
    return loadAdmin();// 重新拉一份，统计和列表都跟着更新
  }).catch(function (error) {
    showAlert('删除失败：' + error.message, 5000);
  });
}
function openAdmin() {
  if (!isAdmin()) return;// 双重保险：非管理员即使触发到点击也不该打开面板
  renderLoadingRows();
  adminModal.classList.add('show');
  loadAdmin();
}
function closeAdmin() {
  adminModal.classList.remove('show');
}
function renderLoadingRows() {
  adminRows.textContent = '';
  const tr = document.createElement('tr');
  const td = document.createElement('td');
  td.colSpan = 6;
  td.className = 'admin-loading';
  td.textContent = '正在从数据库读取…';
  tr.appendChild(td);
  adminRows.appendChild(tr);
}

/* ============================================================
   第 9 部分：打字机效果 —— 每 typeInterval 毫秒显示一个字
   ============================================================ */
function typeNextChar() {
  if (charIndex < CONFIG.welcomeText.length) {
    welcomeTextEl.textContent += CONFIG.welcomeText[charIndex];
    charIndex = charIndex + 1;
    setTimeout(typeNextChar, CONFIG.typeInterval);
  } else {
    setTimeout(finishTyping, CONFIG.glowDelay);
  }
}

/* ============================================================
   第 10 部分：打字完成 —— 发光，并按配置决定如何进入主页
   ============================================================ */
function finishTyping() {
  welcomeTextEl.classList.add('glow');
  if (CONFIG.autoEnter) {
    setTimeout(enterMainPage, CONFIG.holdDuration);
  } else {
    enterHint.classList.add('show');
    welcomeScreen.addEventListener('click', enterMainPage);
    window.addEventListener('keydown', enterMainPage);
  }
}
function enterMainPage() {
  if (hasEntered) return;
  hasEntered = true;
  welcomeScreen.classList.add('hide');
  document.body.classList.remove('lock');
  document.body.classList.add('revealed');
  setTimeout(function () {
    welcomeScreen.remove();
  }, 1000);
}

/* ============================================================
   第 11 部分：启动
   先并行问两件事：“我登录了吗”“站点被访问了多少次”，
   两件都有结果（或都已失败）之后再开始打字——
   因为欢迎语要不要带用户名，取决于第一个问题的答案。
   ============================================================ */
function loadMe() {
  return api('me').then(function (data) {
    me = data && data.logged
      ? { logged: true, username: data.username, role: data.role }
      : { logged: false, username: '', role: '' };
  }).catch(function (error) {
    me = { logged: false, username: '', role: '' };
    showBackendDownAlert(error);
  });
}
function loadVisitCount() {
  return api('visit', 'POST', {}).then(function (stats) {
    visitCountEl.textContent = String(stats.totalVisits);
  }).catch(function () {
    visitCountEl.textContent = '未知';// 计数失败不该打扰用户看页面
  });
}
function bindEvents() {
  navLoginBtn.addEventListener('click', openLogin);
  navLogoutBtn.addEventListener('click', handleLogout);
  modalClose.addEventListener('click', closeLogin);
  loginTab.addEventListener('click', function () { switchMode('login'); });
  registerTab.addEventListener('click', function () { switchMode('register'); });
  modalSubmit.addEventListener('click', function () {
    if (currentMode === 'login') handleLogin();
    else handleRegister();
  });
  loginModal.addEventListener('click', function (e) {
    if (e.target === loginModal) closeLogin();// 只有点在空白遮罩上才关闭
  });
  loginModal.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') modalSubmit.click();// 回车 = 点提交
  });
  navAdminBtn.addEventListener('click', openAdmin);
  adminClose.addEventListener('click', closeAdmin);
  adminRefresh.addEventListener('click', loadAdmin);
  adminModal.addEventListener('click', function (e) {
    if (e.target === adminModal) closeAdmin();
  });
  window.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { closeLogin(); closeAdmin(); }
  });
}
function boot() {
  bindEvents();
  Promise.all([loadMe(), loadVisitCount()]).then(function () {
    refreshNavbar();
    if (me.logged) CONFIG.welcomeText = '欢迎回来，' + me.username;
    setTimeout(typeNextChar, CONFIG.startDelay);
  });
}
boot();
