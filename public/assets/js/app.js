/* ============================================================
   web-one 前端脚本（全栈版）

   与原来单文件版本的根本区别：
     浏览器里不再存任何账号数据。登录状态由后端发的 Cookie 表示，
     所有用户数据都通过 /api/* 由 PHP 从 MySQL 读写。
     因此原来的「localStorage 数据库」「Web Crypto 算密码哈希」「ensureAdmin」
     三段代码整体删除了——密码哈希改由后端 bcrypt 完成，管理员改由安装脚本写入。

   本次新增的部分：
     个人中心与修改密码、找回密码与令牌重置、邮箱验证、后台搜索与分页。
     依然是同一个原则：前端只负责收集输入并展示结果，
     所有「能不能」的判断都在后端，前端任何校验都只是为了让用户少等一次往返。

   公共能力（api / el / 提示条 / 弹窗提示）在 core.js 里，本文件依赖它先加载；
   游戏板块的二级页面用的是同一个 core.js，这样两边不需要各写一份请求逻辑。
   ============================================================ */

/* ============================================================
   第 1 部分：配置 —— 想改前端校验的即时提示门槛，改这里
   （请求超时在 core.js 的 CORE.apiTimeout；动画参数在 assets/css/style.css 第 3 部分）
   ============================================================ */
const CONFIG = {
  passwordMin: 6,// 与后端 config.php 的 password_min 保持一致，仅用于即时提示
};

/* ============================================================
   第 2 部分：与后端通信
   api()、el()、showAlert()、setMsg() 都定义在 core.js 里，两个页面共用。
   ============================================================ */

/* ============================================================
   第 3 部分：页面状态与元素引用
   ============================================================ */
let me = { logged: false, username: '', role: '', email: null, emailVerified: false };// 当前登录者，完全由 /api/me 决定
let currentMode = 'login';// 弹窗当前模式：'login' / 'register'
let pendingAuth = false;// 登录/注册请求进行中，防止连点重复提交
let resetToken = null;// 从邮件链接带过来的重置令牌
let adminState = { page: 1, perPage: 10, q: '' };// 后台列表的当前页码、每页条数与搜索词
let adminResetTarget = null;// 正在被后台重置密码的用户名

const visitCountEl = el('visit-count');// 页脚访问次数
const topAlert = el('top-alert');// 顶部提示条
const navLoginBtn = el('nav-login');// “登录”按钮
const navUserChip = el('nav-user');// 用户胶囊
const navUsername = el('nav-username');// 胶囊里的用户名
const navAccountBtn = el('nav-account');// “个人中心”按钮
const navLogoutBtn = el('nav-logout');// “退出”按钮
const navAdminBtn = el('nav-admin');// “后台”按钮（仅管理员可见）
const verifyBar = el('verify-bar');// 邮箱验证提醒条
const verifyText = el('verify-text');// 提醒条文案
const verifyResend = el('verify-resend');// “重新发送验证邮件”按钮
const loginModal = el('login-modal');// 登录/注册弹窗
const modalTitle = el('modal-title');// 弹窗标题
const modalMsg = el('modal-msg');// 弹窗提示文字
const inputUsername = el('input-username');// 用户名输入框
const inputEmail = el('input-email');// 邮箱输入框（注册时才显示）
const inputPassword = el('input-password');// 密码输入框
const inputConfirm = el('input-confirm');// 确认密码框
const modalSubmit = el('modal-submit');// 提交按钮
const loginTab = el('tab-login');// “登录”标签
const registerTab = el('tab-register');// “注册”标签
const accountModal = el('account-modal');// 个人中心弹窗
const accUsername = el('acc-username');// 个人中心：用户名
const accRole = el('acc-role');// 个人中心：角色
const accEmail = el('acc-email');// 个人中心：邮箱
const accEmailState = el('acc-email-state');// 个人中心：邮箱验证状态
const accCreated = el('acc-created');// 个人中心：注册时间
const pwdCurrent = el('pwd-current');// 改密：当前密码
const pwdNew = el('pwd-new');// 改密：新密码
const pwdConfirm = el('pwd-confirm');// 改密：确认新密码
const pwdMsg = el('pwd-msg');// 改密结果提示
const pwdSubmit = el('pwd-submit');// 改密提交按钮
const forgotModal = el('forgot-modal');// 找回密码弹窗
const forgotEmail = el('forgot-email');// 找回密码：邮箱输入框
const forgotMsg = el('forgot-msg');// 找回密码结果提示
const forgotSubmit = el('forgot-submit');// 找回密码提交按钮
const resetModal = el('reset-modal');// 重置密码弹窗
const resetPassword = el('reset-password');// 重置：新密码
const resetConfirm = el('reset-confirm');// 重置：确认新密码
const resetMsg = el('reset-msg');// 重置结果提示
const resetSubmit = el('reset-submit');// 重置提交按钮
const adminModal = el('admin-modal');// 后台面板遮罩
const adminRows = el('admin-user-rows');// 表格 tbody
const statVisits = el('stat-visits');// 统计：累计访问
const statToday = el('stat-today');// 统计：今日访问
const statUv = el('stat-uv');// 统计：独立访客
const statUsers = el('stat-users');// 统计：注册用户
const statOnline = el('stat-online');// 统计：当前在线
const adminTrend = el('admin-trend');// 近 7 天访问趋势
const adminSearch = el('admin-search');// 后台搜索输入框
const adminPageInfo = el('admin-page-info');// 分页信息文字
const adminPrev = el('admin-prev');// 上一页
const adminNext = el('admin-next');// 下一页
const adminPwdModal = el('admin-pwd-modal');// 后台重置密码弹窗
const adminPwdName = el('admin-pwd-name');// 被重置的用户名
const adminPwdInput = el('admin-pwd-input');// 后台重置：新密码
const adminPwdMsg = el('admin-pwd-msg');// 后台重置结果提示
const adminPwdSubmit = el('admin-pwd-submit');// 后台重置提交按钮
const navSystemBtn = el('nav-system');// “系统状态”按钮（仅管理员可见）
const msgInput = el('msg-input');// 留言输入框
const msgMeta = el('msg-meta');// 留言字数
const msgSubmit = el('msg-submit');// 发布留言
const msgTip = el('msg-tip');// 留言提示
const msgList = el('msg-list');// 留言列表
const msgPageInfo = el('msg-page-info');// 留言分页信息
const msgPrev = el('msg-prev');// 留言上一页
const msgNext = el('msg-next');// 留言下一页
const memoGate = el('memo-gate');// 备忘录未登录提示
const memoGateLogin = el('memo-gate-login');// 「去登录」按钮
const memoBody = el('memo-body');// 备忘录主体（登录后显示）
const memoTitle = el('memo-title');// 备忘录标题输入框
const memoText = el('memo-text');// 备忘录正文输入框
const memoMeta = el('memo-meta');// 备忘录字数
const memoSubmit = el('memo-submit');// 添加备忘录
const memoTip = el('memo-tip');// 备忘录提示
const memoStats = el('memo-stats');// 备忘录统计
const memoList = el('memo-list');// 备忘录列表
const systemModal = el('system-modal');// 系统状态弹窗
const sysMetrics = el('sys-metrics');// 系统状态：数字卡片
const sysChart = el('sys-chart');// 系统状态：趋势图
const sysMysqlMem = el('sys-mysql-mem');// 系统状态：MySQL 内存分布
const sysTables = el('sys-tables');// 系统状态：表占用空间
const sysVerdict = el('sys-verdict');// 系统状态：诊断结论
const sysCollectedAt = el('sys-collected-at');// 系统状态：采集时间
const systemRefresh = el('system-refresh');// 系统状态：重新采集
const monitorSection = el('monitor');// 首页的「运行状态」一节（仅管理员可见）
const homeMetrics = el('home-metrics');// 首页区块：关键指标卡片
const homeTrend = el('home-trend');// 首页区块：趋势折线
const homeMonitorAt = el('home-monitor-at');// 首页区块：采集时间 / 失败原因
const homeMonitorRefresh = el('home-monitor-refresh');// 首页区块：重新采集
const homeMonitorDetail = el('home-monitor-detail');// 首页区块：打开完整面板
const usageSection = el('usage');// 首页的「我的用量」一节（所有人可见，只放自己那份数）
const usageCardsEl = el('usage-cards');// 用量卡片容器
const usageNote = el('usage-note');// 用量一行的说明 / 失败原因
const usageRefresh = el('usage-refresh');// 用量：重新读取

/* ============================================================
   第 4 部分：首页专用的「后端连不上」提示
   showAlert / hideAlert / setMsg 在 core.js 里，两个页面共用。
   ============================================================ */
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
   第 5 部分：导航栏 —— 登录状态 +「首页」下拉菜单
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
  navSystemBtn.classList.toggle('hidden', !isAdmin());
  if (me.logged) navUsername.textContent = '你好，' + me.username;
  refreshVerifyBar();
  // 登录态一变，留言输入框的可用状态、备忘录和「运行状态」板块都要跟着切
  refreshMemoSection();
  refreshMonitorSection();
  refreshUsageSection();
  applyComposerState();
}
/** 邮箱填了但没验证时，在导航栏下方提示一条，并给一个重发按钮 */
function refreshVerifyBar() {
  const needVerify = me.logged && !!me.email && me.emailVerified !== true;
  verifyBar.classList.toggle('hidden', !needVerify);
  if (needVerify) {
    verifyText.textContent = '你的邮箱 ' + me.email + ' 还没有验证，验证后才能用来找回密码。';
  }
}
function handleLogout() {
  api('logout', 'POST', {}).then(function () {
    me = { logged: false, username: '', role: '', email: null, emailVerified: false };
    closeAllModals();
    refreshNavbar();
    showAlert('已退出登录', 3000);
  }).catch(function (error) {
    showAlert('退出失败：' + error.message, 5000);
  });
}

/* ------------------------------------------------------------
   导航栏「首页」的下拉菜单
   桌面端点它或鼠标悬停都会动画展开 游戏 / 留言板 / 备忘录；点空白处或 Esc 收起。
   展开状态只改一个 class，动画全交给 CSS（见 style.css 的 .nav-submenu）。
   ------------------------------------------------------------ */
const navHomeItem = el('nav-home-item');
const navHomeTrigger = el('nav-home-trigger');
const navHomeSubmenu = el('nav-home-submenu');

function setDropdownOpen(open) {
  if (!navHomeItem || !navHomeTrigger) return;
  navHomeItem.classList.toggle('open', open);
  navHomeTrigger.setAttribute('aria-expanded', open ? 'true' : 'false');
}

function bindHomeDropdown() {
  if (!navHomeTrigger) return;

  navHomeTrigger.addEventListener('click', function () {
    const isOpen = navHomeItem.classList.contains('open');
    // 鼠标正停在上面的时侯，CSS 的 :hover 也会让菜单展开：
    // 这时想「用点击把它收起来」就得先临时屏蔽 hover 展开，
    // 否则 class 去掉了但菜单还挂在屏幕上，看起来像点了没反应。
    // 鼠标移开时会自动恢复（见下面的 mouseleave）。
    navHomeItem.classList.toggle('hover-off', isOpen);
    setDropdownOpen(!isOpen);
  });

  navHomeItem.addEventListener('mouseleave', function () {
    navHomeItem.classList.remove('hover-off');
    setDropdownOpen(false);
  });

  // 点了子项就收起，免得跳过去之后菜单还挂在那里挡内容
  navHomeSubmenu.addEventListener('click', function (e) {
    if (e.target.tagName === 'A') setDropdownOpen(false);
  });

  // 点菜单以外的任何地方都收起
  document.addEventListener('click', function (e) {
    if (!navHomeItem.contains(e.target)) setDropdownOpen(false);
  });
}

/* ============================================================
   第 6 部分：弹窗的打开、关闭、模式切换
   ============================================================ */
function openLogin() {
  loginModal.classList.add('show');
  inputUsername.focus();
}
function closeLogin() {
  loginModal.classList.remove('show');
  setMsg(modalMsg, '', '');
}
function closeAllModals() {
  // 退出登录、会话失效时统一收口：把所有弹窗一次性关掉
  const modals = [
    loginModal, accountModal, forgotModal, resetModal, adminModal, adminPwdModal, systemModal,
  ];
  for (let i = 0; i < modals.length; i++) {
    if (modals[i]) modals[i].classList.remove('show');
  }
  disarmConfirm();
}
function switchMode(mode) {
  currentMode = mode;
  const isLogin = mode === 'login';
  loginTab.classList.toggle('active', isLogin);
  registerTab.classList.toggle('active', !isLogin);
  inputConfirm.classList.toggle('hidden', isLogin);
  inputEmail.classList.toggle('hidden', isLogin);// 邮箱只在注册时填：登录认的是用户名
  modalTitle.textContent = isLogin ? '欢迎回来' : '创建账号';
  modalSubmit.textContent = isLogin ? '登 录' : '注 册';
  setMsg(modalMsg, '', '');
}
function setPendingAuth(isPending, button) {
  const target = button || modalSubmit;
  pendingAuth = isPending;
  if (target === modalSubmit) target.disabled = isPending;// 请求期间禁用按钮，防止连点注册出两个账号
  target.style.opacity = isPending ? '0.6' : '';
  target.style.cursor = isPending ? 'progress' : '';
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
  const email = inputEmail.value.trim();
  if (name.length < 2 || name.length > 20) return setMsg(modalMsg, '用户名长度需为 2~20 个字符', 'error');
  if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return setMsg(modalMsg, '邮箱格式不正确', 'error');
  if (pwd.length < CONFIG.passwordMin) return setMsg(modalMsg, '密码至少需要 ' + CONFIG.passwordMin + ' 位', 'error');
  if (pwd === name) return setMsg(modalMsg, '密码不能和用户名相同', 'error');
  if (/^\d+$/.test(pwd)) return setMsg(modalMsg, '密码不能是纯数字', 'error');
  if (pwd !== inputConfirm.value) return setMsg(modalMsg, '两次输入的密码不一致', 'error');
  submitAuth('register', name, pwd, email);
}
function handleLogin() {
  if (pendingAuth) return;
  const name = inputUsername.value.trim();
  const pwd = inputPassword.value;
  if (!name) return setMsg(modalMsg, '请填写用户名', 'error');
  if (!pwd) return setMsg(modalMsg, '请填写密码', 'error');
  submitAuth('login', name, pwd, '');
}
function submitAuth(action, name, pwd, email) {
  setPendingAuth(true);
  setMsg(modalMsg, '正在提交…', '');
  const payload = { username: name, password: pwd };
  if (email) payload.email = email;

  api(action, 'POST', payload).then(function (data) {
    me = {
      logged: true,
      username: data.username,
      role: data.role,
      email: data.email || null,
      emailVerified: data.emailVerified === true,
      createdAt: data.createdAt || '',
    };
    hideAlert();
    afterAuthSuccess(data.username);
    if (data.verificationMailSent === true) {
      showAlert('验证邮件已发到 ' + data.email + '，点开链接即可完成验证', 7000);
    } else if (data.verificationMailSent === false) {
      showAlert('账号已创建，但验证邮件发送失败；可以稍后在个人中心里重发', 8000);
    }
  }).catch(function (error) {
    setMsg(modalMsg, error.message, 'error');
    if (error.status === 0) showBackendDownAlert(error);// 顺带把后端没启动的原因也说清楚
  }).then(function () {
    setPendingAuth(false);
  });
}
function afterAuthSuccess(name) {
  setMsg(modalMsg, '欢迎你，' + name + '！', 'ok');
  refreshNavbar();
  inputUsername.value = '';
  inputEmail.value = '';
  inputPassword.value = '';
  inputConfirm.value = '';
  setTimeout(closeLogin, 700);// 停 0.7 秒让人看到成功提示再自动关窗
}

/* ============================================================
   第 8 部分：个人中心与修改密码
   ============================================================ */
function openAccount() {
  if (!me.logged) return;
  accUsername.textContent = me.username;
  accRole.textContent = me.role === 'admin' ? '管理员' : '普通用户';
  accEmail.textContent = me.email || '未绑定';
  accCreated.textContent = me.createdAt || '—';

  // 邮箱状态用一个徽章表示，比纯文字更醒目
  accEmailState.textContent = '';
  if (!me.email) {
    accEmailState.textContent = '—';
  } else {
    const tag = document.createElement('span');
    tag.className = 'badge ' + (me.emailVerified ? 'badge-ok' : 'badge-warn');
    tag.textContent = me.emailVerified ? '已验证' : '未验证';
    accEmailState.appendChild(tag);
  }

  pwdCurrent.value = '';
  pwdNew.value = '';
  pwdConfirm.value = '';
  setMsg(pwdMsg, '', '');
  accountModal.classList.add('show');
  pwdCurrent.focus();
}
function handleChangePassword() {
  if (pwdSubmit.disabled) return;
  const cur = pwdCurrent.value;
  const next = pwdNew.value;
  if (!cur) return setMsg(pwdMsg, '请填写当前密码', 'error');
  if (next.length < CONFIG.passwordMin) return setMsg(pwdMsg, '新密码至少需要 ' + CONFIG.passwordMin + ' 位', 'error');
  if (next === cur) return setMsg(pwdMsg, '新密码不能和当前密码相同', 'error');
  if (/^\d+$/.test(next)) return setMsg(pwdMsg, '新密码不能是纯数字', 'error');
  if (next !== pwdConfirm.value) return setMsg(pwdMsg, '两次输入的新密码不一致', 'error');

  pwdSubmit.disabled = true;
  setMsg(pwdMsg, '正在提交…', '');
  api('password', 'POST', { currentPassword: cur, newPassword: next }).then(function (result) {
    const revoked = result.revokedSessions || 0;
    setMsg(pwdMsg, '密码已修改，其它设备上的 ' + revoked + ' 个登录已下线', 'ok');
    pwdCurrent.value = '';
    pwdNew.value = '';
    pwdConfirm.value = '';
  }).catch(function (error) {
    setMsg(pwdMsg, error.message, 'error');
  }).then(function () {
    pwdSubmit.disabled = false;
  });
}

/* ============================================================
   第 9 部分：找回密码与令牌重置
   ============================================================ */
function openForgot() {
  closeLogin();
  forgotEmail.value = inputUsername.value.trim();// 顺手把刚输的用户名带过去，少打一次字
  setMsg(forgotMsg, '', '');
  forgotModal.classList.add('show');
  forgotEmail.focus();
}
function handleForgot() {
  if (forgotSubmit.disabled) return;
  const email = forgotEmail.value.trim();
  if (!email) return setMsg(forgotMsg, '请填写邮箱', 'error');

  forgotSubmit.disabled = true;
  setMsg(forgotMsg, '正在提交…', '');
  api('forgot', 'POST', { email: email }).then(function (result) {
    // 后端的回答对「已注册」和「未注册」完全一样，前端也就照原样显示
    setMsg(forgotMsg, result.message, 'ok');
  }).catch(function (error) {
    setMsg(forgotMsg, error.message, 'error');
  }).then(function () {
    forgotSubmit.disabled = false;
  });
}
function openReset(token) {
  resetToken = token;
  resetPassword.value = '';
  resetConfirm.value = '';
  setMsg(resetMsg, '', '');
  resetModal.classList.add('show');
  resetPassword.focus();
}
function handleReset() {
  if (resetSubmit.disabled) return;
  if (!resetToken) return setMsg(resetMsg, '重置链接无效，请重新发起找回密码', 'error');
  const pwd = resetPassword.value;
  if (pwd.length < CONFIG.passwordMin) return setMsg(resetMsg, '密码至少需要 ' + CONFIG.passwordMin + ' 位', 'error');
  if (pwd !== resetConfirm.value) return setMsg(resetMsg, '两次输入的密码不一致', 'error');

  resetSubmit.disabled = true;
  setMsg(resetMsg, '正在提交…', '');
  api('reset', 'POST', { token: resetToken, password: pwd }).then(function () {
    setMsg(resetMsg, '密码已重设，请用新密码登录', 'ok');
    resetToken = null;
    setTimeout(function () {
      resetModal.classList.remove('show');
      openLogin();
    }, 900);
  }).catch(function (error) {
    setMsg(resetMsg, error.message, 'error');
  }).then(function () {
    resetSubmit.disabled = false;
  });
}

/* ============================================================
   第 10 部分：邮箱验证
   链接形如 /?verify=令牌，进页面时自动提交一次。
   ============================================================ */
function handleVerifyToken(token) {
  api('email/verify', 'POST', { token: token }).then(function (result) {
    me.emailVerified = true;
    refreshNavbar();
    showAlert('邮箱 ' + result.email + ' 验证成功', 7000);
  }).catch(function (error) {
    showAlert('邮箱验证失败：' + error.message, 9000);
  });
}
function handleResendVerify() {
  if (verifyResend.disabled) return;
  verifyResend.disabled = true;
  api('email/resend', 'POST', {}).then(function () {
    showAlert('验证邮件已重新发送，请查收（也看一眼垃圾邮件箱）', 7000);
  }).catch(function (error) {
    showAlert('发送失败：' + error.message, 8000);
  }).then(function () {
    verifyResend.disabled = false;
  });
}

/* ============================================================
   第 11 部分：后台管理面板（数据全部来自 MySQL）
   ============================================================ */
function cell(text) {
  // 刻意用 textContent 而不是拼 innerHTML：用户名是用户任意输入的，
  // 走 textContent 才不会把 <img onerror=...> 之类内容当成 HTML 执行（防 XSS）。
  const td = document.createElement('td');
  td.textContent = text;
  return td;
}
/** 生成一个次要说明行（邮箱、改密时间这类补充信息） */
function subLine(text) {
  const span = document.createElement('span');
  span.className = 'email-cell';
  span.textContent = text;
  return span;
}
function renderAdmin(data) {
  statVisits.textContent = String(data.stats.totalVisits);
  statToday.textContent = String(data.stats.todayVisits);
  statUv.textContent = String(data.stats.totalVisitors);
  statUsers.textContent = String(data.stats.userCount);
  statOnline.textContent = String(data.stats.onlineCount);

  // 近 7 天趋势：拼成「09-20 12次/5人」这样一行，比再画一个图简单得多也够用
  const daily = data.stats.daily || [];
  if (daily.length === 0) {
    adminTrend.textContent = '近 7 天还没有访问记录。';
  } else {
    const parts = [];
    for (let i = 0; i < daily.length; i++) {
      parts.push(daily[i].day + ' ' + daily[i].visits + '次/' + daily[i].visitors + '人');
    }
    adminTrend.textContent = '近 7 天（访问次数 / 独立访客）：' + parts.join(' · ');
  }

  adminRows.textContent = '';// 清空旧行
  data.users.forEach(function (u) {
    const tr = document.createElement('tr');

    const nameTd = document.createElement('td');
    nameTd.textContent = u.username;
    if (u.online) {// 绿点：该账号还有未过期的登录会话
      const dot = document.createElement('span');
      dot.className = 'dot-online';
      dot.title = '有 ' + u.activeSessions + ' 个未过期的登录会话';
      nameTd.appendChild(dot);
    }
    nameTd.appendChild(subLine(u.email ? (u.email + (u.emailVerified ? '（已验证）' : '（未验证）')) : '未绑定邮箱'));
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

    const loginTd = cell(u.lastLoginAt);// 最后登录
    loginTd.appendChild(subLine('改密：' + u.passwordChangedAt));
    tr.appendChild(loginTd);

    tr.appendChild(cell(String(u.loginCount)));// 登录次数

    const opTd = document.createElement('td');
    const pwdBtn = document.createElement('button');
    pwdBtn.type = 'button';
    pwdBtn.className = 'row-del';
    pwdBtn.textContent = '重置密码';
    if (u.role === 'admin') {
      pwdBtn.disabled = true;// 置灰只是提示，后端 admin_reset_password() 也会拒绝
      pwdBtn.title = '管理员账号的密码不能由后台重置';
    } else {
      pwdBtn.style.marginRight = '8px';
      pwdBtn.addEventListener('click', function () { openAdminReset(u.username); });
    }
    opTd.appendChild(pwdBtn);

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'row-del';
    btn.textContent = '删除';
    if (u.role === 'admin') {
      btn.disabled = true;// 置灰只是提示，后端 admin_delete_user() 也会拒绝
      btn.title = '管理员账号不可删除';
    } else {
      btn.addEventListener('click', function () {
        armConfirm(btn, '确认删除？', function () { deleteUser(u.username); });
      });
    }
    opTd.appendChild(btn);
    tr.appendChild(opTd);

    adminRows.appendChild(tr);
  });

  // 分页：以服务端返回的页码为准（传了越界的页码时后端会拉回最后一页）
  adminState.page = data.paging.page;
  adminState.perPage = data.paging.perPage;
  adminState.q = data.search || '';
  adminSearch.value = adminState.q;
  adminPageInfo.textContent = '第 ' + data.paging.page + ' / ' + data.paging.totalPages
    + ' 页 · 共 ' + data.paging.total + ' 人';
  adminPrev.disabled = data.paging.page <= 1;
  adminNext.disabled = data.paging.page >= data.paging.totalPages;
}
/** 把当前页码/每页条数/搜索词拼成查询串，三个后台接口共用 */
function adminQuery() {
  let query = '?page=' + adminState.page + '&perPage=' + adminState.perPage;
  if (adminState.q) query += '&q=' + encodeURIComponent(adminState.q);
  return query;
}
function loadAdmin() {
  adminSearch.disabled = true;
  return api('admin/users' + adminQuery()).then(function (data) {
    renderAdmin(data);
  }).catch(function (error) {
    if (error.status === 401 || error.status === 403) {// 会话过期或权限不足
      me = { logged: false, username: '', role: '', email: null, emailVerified: false };
      refreshNavbar();
      closeAllModals();
    }
    showAlert('读取后台数据失败：' + error.message, 5000);
  }).then(function () {
    adminSearch.disabled = false;
  });
}
/* 删除用两段式确认（armConfirm，见第 12 部分）：
   第一次点变成「确认删除？」，4 秒内再点一次才真的发请求。
   刻意不用 window.confirm——它会阻塞整个页面线程，浏览器标签会一直卡住
   （自动化测试也没法继续），而且样式和这个暗色面板完全不搭。 */
function deleteUser(name) {
  api('admin/users' + adminQuery(), 'DELETE', { username: name }).then(function () {
    showAlert('已删除用户 ' + name, 3000);
    return loadAdmin();// 重新拉一份，统计和列表都跟着更新
  }).catch(function (error) {
    showAlert('删除失败：' + error.message, 5000);
  });
}
function openAdminReset(name) {
  adminResetTarget = name;
  adminPwdName.textContent = name;
  adminPwdInput.value = '';
  setMsg(adminPwdMsg, '', '');
  adminPwdModal.classList.add('show');
  adminPwdInput.focus();
}
function handleAdminReset() {
  if (adminPwdSubmit.disabled || !adminResetTarget) return;
  const pwd = adminPwdInput.value;
  if (pwd.length < CONFIG.passwordMin) return setMsg(adminPwdMsg, '密码至少需要 ' + CONFIG.passwordMin + ' 位', 'error');

  adminPwdSubmit.disabled = true;
  setMsg(adminPwdMsg, '正在提交…', '');
  api('admin/reset-password', 'POST', { username: adminResetTarget, newPassword: pwd }).then(function (result) {
    setMsg(adminPwdMsg, '已重置，该用户的 ' + (result.revokedSessions || 0) + ' 个登录已下线', 'ok');
    adminPwdInput.value = '';
    return loadAdmin();
  }).catch(function (error) {
    setMsg(adminPwdMsg, error.message, 'error');
  }).then(function () {
    adminPwdSubmit.disabled = false;
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
   第 12 部分：两段式确认
   armConfirm() / disarmConfirm() 定义在 core.js 里，
   后台删用户、留言板、备忘录、游戏板块四个地方共用。
   ============================================================ */

/* ============================================================
   第 13 部分：留言板
   读公开、写要登录。内容一律按纯文本渲染，换行用 CSS 的 pre-wrap 保留。
   ============================================================ */
let msgState = { page: 1, perPage: 5, totalPages: 1, maxLen: 500, sending: false };

function updateMsgCounter() {
  msgMeta.textContent = msgInput.value.length + ' / ' + msgState.maxLen + ' 字';
}

/** 未登录时把输入框关掉、把按钮改成「登录后发布」 */
function applyComposerState() {
  msgInput.disabled = !me.logged;
  // 按钮刻意保持可点：未登录时点它会提示原因并直接打开登录框。
  // 如果连按钮一起禁用，用户看到的是一个点不动的死按钮，
  // 既不知道为什么不给发，也没有「下一步该做什么」的入口——
  // 输入框本身已经禁用，不会再出现「写完才被拒绝」的浪费。
  msgSubmit.disabled = msgState.sending;
  msgSubmit.textContent = me.logged ? '发布留言' : '登录后发布';
  msgInput.placeholder = me.logged ? '说点什么…' : '发留言需要先登录';
}

function messageItemView(item) {
  const li = document.createElement('li');
  li.className = 'msg-item' + (item.mine ? ' mine' : '');

  const head = document.createElement('div');
  head.className = 'msg-head';

  const author = document.createElement('span');
  author.className = 'msg-author';
  author.textContent = item.author;          // 用户名可能是任意输入，只走 textContent
  head.appendChild(author);

  if (item.role === 'admin') {
    const tag = document.createElement('span');
    tag.className = 'tag-admin';
    tag.textContent = '管理员';
    head.appendChild(tag);
  }

  const time = document.createElement('span');
  time.className = 'msg-time';
  time.textContent = item.createdAt;
  head.appendChild(time);

  if (item.canDelete) {                      // 能不能删由后端算好，前端只是不显示按钮
    const del = document.createElement('button');
    del.type = 'button';
    del.className = 'link-btn msg-delete';
    del.textContent = '删除';
    del.addEventListener('click', function () {
      armConfirm(del, '确认删除？', function () { deleteMessage(item.id); });
    });
    head.appendChild(del);
  }
  li.appendChild(head);

  const body = document.createElement('div');
  body.className = 'msg-body';
  body.textContent = item.body;              // HTML 标签不会被解析，配合 pre-wrap 保留换行
  li.appendChild(body);

  return li;
}

function renderMessages(data) {
  msgState.page = data.page;
  msgState.totalPages = data.totalPages;
  msgState.perPage = data.perPage;
  msgState.maxLen = data.maxLen;

  msgList.textContent = '';
  if (data.items.length === 0) {
    const li = document.createElement('li');
    li.className = 'list-empty';
    li.textContent = '还没有人留言，来当第一个。';
    msgList.appendChild(li);
  } else {
    data.items.forEach(function (item) { msgList.appendChild(messageItemView(item)); });
  }

  msgPageInfo.textContent = '第 ' + data.page + ' / ' + data.totalPages + ' 页 · 共 ' + data.total + ' 条';
  msgPrev.disabled = data.page <= 1;
  msgNext.disabled = data.page >= data.totalPages;

  updateMsgCounter();
  applyComposerState();
}

function loadMessages() {
  return api('messages?page=' + msgState.page + '&perPage=' + msgState.perPage)
    .then(renderMessages)
    .catch(function (error) { showAlert('读取留言失败：' + error.message, 5000); });
}

function handleSendMessage() {
  if (msgState.sending) return;
  if (!me.logged) {
    showAlert('发留言需要先登录', 4000);
    openLogin();
    return;
  }
  const text = msgInput.value.trim();
  if (!text) return setMsg(msgTip, '先写点什么再发布', 'error');

  msgState.sending = true;
  applyComposerState();
  setMsg(msgTip, '正在发布…', '');

  api('messages', 'POST', { body: text }).then(function () {
    msgInput.value = '';
    setMsg(msgTip, '已发布', 'ok');
    msgState.page = 1;                       // 新留言在最前面，回到第一页才看得到
    return loadMessages();
  }).catch(function (error) {
    setMsg(msgTip, error.message, 'error');
  }).then(function () {
    msgState.sending = false;
    applyComposerState();
  });
}

function deleteMessage(id) {
  api('messages', 'DELETE', { id: id }).then(function () {
    showAlert('留言已删除', 3000);
    return loadMessages();
  }).catch(function (error) {
    showAlert('删除失败：' + error.message, 5000);
  });
}

/* ============================================================
   第 14 部分：备忘录
   私有数据，必须登录。编辑用行内表单，不再开一个弹窗。
   ============================================================ */
let memoState = { filter: 'all', editingId: null, saving: false, lastData: null, maxTitle: 80, maxBody: 2000 };

function updateMemoCounter() {
  memoMeta.textContent = '标题 ' + memoTitle.value.length + ' / ' + memoState.maxTitle +
    ' 字 · 正文 ' + memoText.value.length + ' / ' + memoState.maxBody + ' 字';
}

function renderMemoStats(stats) {
  memoStats.textContent = '共 ' + stats.total + ' 条 · 未完成 ' + stats.open +
    ' · 已完成 ' + stats.done + '（上限 ' + stats.limit + '）';
}

function renderMemos() {
  const data = memoState.lastData;
  if (data === null) return;

  memoState.maxTitle = data.maxTitle;
  memoState.maxBody = data.maxBody;
  memoTitle.maxLength = data.maxTitle;
  memoText.maxLength = data.maxBody;
  renderMemoStats(data.stats);

  memoList.textContent = '';
  if (data.items.length === 0) {
    const li = document.createElement('li');
    li.className = 'list-empty';
    li.textContent = memoState.filter === 'all'
      ? '还没有备忘录，在上面添加一条试试。'
      : '这个筛选条件下没有内容。';
    memoList.appendChild(li);
    return;
  }
  data.items.forEach(function (memo) { memoList.appendChild(memoItemView(memo)); });
}

function memoItemView(memo) {
  const li = document.createElement('li');
  li.className = 'memo-item' + (memo.done ? ' done' : '');

  if (memoState.editingId === memo.id) {
    li.appendChild(memoEditForm(memo));
    return li;
  }

  const head = document.createElement('div');
  head.className = 'memo-head';

  const check = document.createElement('input');
  check.type = 'checkbox';
  check.className = 'memo-check';
  check.checked = memo.done;
  check.title = memo.done ? '标记为未完成' : '标记为已完成';
  check.addEventListener('change', function () { toggleMemo(memo.id, check.checked); });
  head.appendChild(check);

  const title = document.createElement('span');
  title.className = 'memo-title';
  title.textContent = memo.title;
  head.appendChild(title);

  const time = document.createElement('span');
  time.className = 'memo-time';
  time.textContent = memo.updatedAt;
  head.appendChild(time);

  const edit = document.createElement('button');
  edit.type = 'button';
  edit.className = 'link-btn';
  edit.textContent = '编辑';
  edit.addEventListener('click', function () {
    memoState.editingId = memo.id;
    renderMemos();
  });
  head.appendChild(edit);

  const del = document.createElement('button');
  del.type = 'button';
  del.className = 'link-btn memo-delete';
  del.textContent = '删除';
  del.addEventListener('click', function () {
    armConfirm(del, '确认删除？', function () { deleteMemo(memo.id); });
  });
  head.appendChild(del);
  li.appendChild(head);

  if (memo.body !== '') {
    const body = document.createElement('div');
    body.className = 'memo-text';
    body.textContent = memo.body;
    li.appendChild(body);
  }

  return li;
}

function memoEditForm(memo) {
  const form = document.createElement('div');
  form.className = 'memo-edit';

  const titleInput = document.createElement('input');
  titleInput.className = 'composer-input composer-title';
  titleInput.value = memo.title;
  titleInput.maxLength = memoState.maxTitle;
  form.appendChild(titleInput);

  const bodyInput = document.createElement('textarea');
  bodyInput.className = 'composer-input';
  bodyInput.rows = 3;
  bodyInput.value = memo.body;
  bodyInput.maxLength = memoState.maxBody;
  form.appendChild(bodyInput);

  const foot = document.createElement('div');
  foot.className = 'composer-foot';

  const cancel = document.createElement('button');
  cancel.type = 'button';
  cancel.className = 'btn-mini';
  cancel.textContent = '取消';
  cancel.addEventListener('click', function () {
    memoState.editingId = null;
    renderMemos();
  });
  foot.appendChild(cancel);

  const save = document.createElement('button');
  save.type = 'button';
  save.className = 'btn-primary composer-submit';
  save.textContent = '保存';
  save.addEventListener('click', function () {
    saveMemo(memo.id, titleInput.value, bodyInput.value);
  });
  foot.appendChild(save);
  form.appendChild(foot);

  return form;
}

function loadMemos() {
  if (!me.logged) return Promise.resolve();
  return api('memos?filter=' + memoState.filter).then(function (data) {
    memoState.lastData = data;
    memoState.filter = data.filter;
    renderMemos();
    renderUsage();//「你的备忘录」那张卡用的就是这一份 stats，别再发一次请求
  }).catch(function (error) {
    if (error.status === 401) {              // 会话过期：退回未登录状态
      me = { logged: false, username: '', role: '', email: null, emailVerified: false };
      refreshNavbar();
      return;
    }
    showAlert('读取备忘录失败：' + error.message, 5000);
  });
}

function handleAddMemo() {
  if (memoState.saving) return;
  const title = memoTitle.value.trim();
  if (!title) return setMsg(memoTip, '标题不能为空', 'error');

  memoState.saving = true;
  memoSubmit.disabled = true;
  setMsg(memoTip, '正在保存…', '');
  api('memos', 'POST', { title: title, body: memoText.value }).then(function () {
    memoTitle.value = '';
    memoText.value = '';
    updateMemoCounter();
    setMsg(memoTip, '已添加', 'ok');
    return loadMemos();
  }).catch(function (error) {
    setMsg(memoTip, error.message, 'error');
  }).then(function () {
    memoState.saving = false;
    memoSubmit.disabled = false;
  });
}

function saveMemo(id, title, body) {
  api('memos/update', 'POST', { id: id, title: title, body: body }).then(function () {
    memoState.editingId = null;
    setMsg(memoTip, '已保存', 'ok');
    return loadMemos();
  }).catch(function (error) {
    showAlert('保存失败：' + error.message, 5000);
  });
}

function toggleMemo(id, done) {
  api('memos/update', 'POST', { id: id, done: done }).then(function () {
    return loadMemos();
  }).catch(function (error) {
    showAlert('更新失败：' + error.message, 5000);
    return loadMemos();                      // 失败也重新拉一次，把勾选状态恢复成真实值
  });
}

function deleteMemo(id) {
  api('memos', 'DELETE', { id: id }).then(function () {
    showAlert('备忘录已删除', 3000);
    return loadMemos();
  }).catch(function (error) {
    showAlert('删除失败：' + error.message, 5000);
  });
}

function refreshMemoSection() {
  memoGate.classList.toggle('hidden', me.logged);
  memoBody.classList.toggle('hidden', !me.logged);
  if (me.logged) {
    loadMemos();
  } else {
    memoState.lastData = null;
    memoState.editingId = null;
  }
}

/* ============================================================
   第 15 部分：系统状态（资源监控可视化）
   图表全部用手写内联 SVG / CSS，不引任何 CDN：
   既不会和 CSP 的 script-src 'self' 冲突，离线也能看。
   所有文本都走 textContent，保持全站「不拼 innerHTML」的做法。
   ============================================================ */
const SVG_NS = 'http://www.w3.org/2000/svg';

function svgEl(name, attrs) {
  const node = document.createElementNS(SVG_NS, name);
  if (attrs) {
    for (const key in attrs) {
      if (Object.prototype.hasOwnProperty.call(attrs, key)) {
        node.setAttribute(key, attrs[key]);
      }
    }
  }
  return node;
}

function svgText(x, y, text, attrs) {
  const node = svgEl('text', Object.assign({ x: x, y: y, 'font-size': 11, fill: '#98a2c8' }, attrs || {}));
  node.textContent = text;
  return node;
}

/** 字节数 → 人能读的写法（和后端 system_format_bytes 保持一致的口径） */
function bytesText(bytes) {
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  let value = Number(bytes) || 0;
  let index = 0;
  while (value >= 1024 && index < units.length - 1) {
    value = value / 1024;
    index = index + 1;
  }
  return (index === 0 ? Math.round(value) : value.toFixed(1)) + ' ' + units[index];
}

function renderCards(container, items) {
  container.textContent = '';
  items.forEach(function (item) {
    const box = document.createElement('div');
    box.className = 'sys-card';

    const big = document.createElement('b');
    big.textContent = item.value;
    box.appendChild(big);

    const name = document.createElement('span');
    name.textContent = item.label;
    box.appendChild(name);

    if (item.sub) {
      const hint = document.createElement('em');
      hint.textContent = item.sub;
      box.appendChild(hint);
    }
    container.appendChild(box);
  });
}

/**
 * 一份快照 → 一叠数字卡片。
 *
 * 标了 home 的那几张会额外出现在首页的「运行状态」一节里。
 * 两处共用同一份清单，改文案或加指标就不会出现「弹窗有、首页没有」。
 */
function sysCardItems(data) {
  const php = data.php;
  const mysql = data.mysql;
  const app = data.app;

  const items = [
    {
      home: true,
      label: 'PHP 进程内存',
      value: bytesText(php.memoryBytes),
      sub: '峰值 ' + bytesText(php.peakBytes) + ' / 上限 ' + php.limitText,
    },
    {
      home: true,
      label: '本次采集耗时',
      value: php.requestMs + ' ms',
      sub: '含 ' + php.queriesThisRequest + ' 条 SQL、' + php.includedFiles + ' 个文件',
    },
    {
      home: true,
      label: '缓冲池命中率',
      value: mysql.bufferPoolHitRate === null ? '—' : mysql.bufferPoolHitRate.toFixed(2) + '%',
      sub: '内存命中 ' + mysql.bufferPoolReadRequests + ' 次 / 读盘 ' + mysql.bufferPoolReads + ' 次',
    },
    {
      home: true,
      label: 'MySQL 连接',
      value: String(mysql.threadsConnected),
      sub: '执行中 ' + mysql.threadsRunning + ' / 累计 ' + mysql.connections,
    },
    {
      label: '慢查询',
      value: String(mysql.slowQueries),
      sub: '临时表落盘 ' + mysql.tmpDiskTables + ' 次',
    },
    {
      label: '有效会话',
      value: String(app.sessionsAlive),
      sub: app.users + ' 个账号（' + app.admins + ' 管理员）',
    },
    {
      label: '留言 / 备忘录',
      value: app.messages + ' / ' + app.memos,
      sub: '今日访问 ' + app.visitsToday + ' 次 / ' + app.visitorsToday + ' 人',
    },
    {
      label: 'PHP / MySQL',
      value: php.version + ' / ' + mysql.version,
      sub: 'OPcache ' + (php.opcacheEnabled ? '已启用' : '未启用'),
    },
  ];

  data.disks.forEach(function (disk) {
    items.push({
      home: true,
      label: '磁盘 ' + disk.mount,
      value: disk.usedPercent + '%',
      sub: '剩余 ' + bytesText(disk.freeBytes) + ' / 共 ' + bytesText(disk.totalBytes),
    });
  });

  return items;
}

/** 单条折线：只画一个指标，各自用各自的量程，避免双轴图带来的误读 */
function buildSparkline(values, color, label, unit) {
  const width = 600;
  const height = 120;
  const padX = 18;
  const padY = 22;

  const max = Math.max.apply(null, values);
  const min = Math.min.apply(null, values);
  const span = max - min || 1;

  const svg = svgEl('svg', { viewBox: '0 0 ' + width + ' ' + height, width: '100%', role: 'img' });
  const title = svgEl('title');
  title.textContent = label + '：共 ' + values.length + ' 个采样点，最低 ' + min.toFixed(2) + unit + '，最高 ' + max.toFixed(2) + unit;
  svg.appendChild(title);

  const xOf = function (i) {
    return padX + i * (width - padX * 2) / Math.max(1, values.length - 1);
  };
  const yOf = function (v) {
    return height - padY - ((v - min) / span) * (height - padY * 2);
  };

  // 基线
  svg.appendChild(svgEl('line', {
    x1: padX, y1: height - padY, x2: width - padX, y2: height - padY,
    stroke: 'rgba(255,255,255,0.12)', 'stroke-width': 1,
  }));

  const points = values.map(function (v, i) { return xOf(i) + ',' + yOf(v); }).join(' ');
  svg.appendChild(svgEl('polyline', {
    points: points, fill: 'none', stroke: color, 'stroke-width': 1.5,
    'stroke-linejoin': 'round', 'stroke-linecap': 'round',
  }));

  // 末点标记 + 极值标注
  svg.appendChild(svgEl('circle', {
    cx: xOf(values.length - 1), cy: yOf(values[values.length - 1]), r: 3, fill: color,
  }));
  svg.appendChild(svgText(padX, 13, label + '（' + unit + '）', { fill: color }));
  svg.appendChild(svgText(width - padX, 13, '最高 ' + max.toFixed(2) + unit, { 'text-anchor': 'end' }));
  svg.appendChild(svgText(width - padX, height - 5, '最新 ' + values[values.length - 1].toFixed(2) + unit, { 'text-anchor': 'end' }));

  return svg;
}

/** 趋势图能画的几个指标：key 就是采样记录里的字段名 */
const SYS_SERIES = {
  mem: { label: 'PHP 进程内存', unit: ' MB', color: '#4cc9f0' },
  ms: { label: '请求耗时', unit: ' ms', color: '#ff6b9d' },
  q: { label: '单次请求执行的 SQL 条数', unit: ' 条', color: '#7b5cff' },
};

/** 往指定容器里画几条折线。弹窗要三根，首页那一节只要前两根。 */
function renderTrend(container, samples, keys) {
  container.textContent = '';

  const usable = samples.filter(function (s) {
    return typeof s.mem === 'number' && typeof s.ms === 'number';
  });
  if (usable.length < 2) {
    const p = document.createElement('p');
    p.className = 'list-empty';
    p.textContent = '采样点还不够画趋势（至少需要 2 条）。'
      + '每次访问页面或采集一次最多记录一条（间隔由后端的 stats_sample_interval 控制），多来几次就会积累出来。';
    container.appendChild(p);
    return;
  }

  keys.forEach(function (key) {
    const series = SYS_SERIES[key];
    container.appendChild(buildSparkline(
      usable.map(function (s) { return typeof s[key] === 'number' ? s[key] : 0; }),
      series.color,
      series.label,
      series.unit
    ));
  });

  const note = document.createElement('p');
  note.className = 'sys-note';
  const first = usable[0];
  const last = usable[usable.length - 1];
  note.textContent = '共 ' + usable.length + ' 个采样点，从 ' + new Date(first.t * 1000).toLocaleString()
    + ' 到 ' + new Date(last.t * 1000).toLocaleString() + '。';
  container.appendChild(note);
}

function renderBars(container, rows, labelOf, valueOf) {
  container.textContent = '';
  if (rows.length === 0) {
    const p = document.createElement('p');
    p.className = 'list-empty';
    p.textContent = '该数据源不可用（例如 performance_schema 被关闭）。';
    container.appendChild(p);
    return;
  }

  let max = 0;
  rows.forEach(function (row) {
    const v = Math.abs(Number(row.bytes) || 0);
    if (v > max) max = v;
  });

  rows.forEach(function (row) {
    const wrap = document.createElement('div');
    wrap.className = 'sys-bar';

    const label = document.createElement('span');
    label.className = 'sys-bar-label';
    label.textContent = labelOf(row);
    label.title = labelOf(row);
    wrap.appendChild(label);

    const track = document.createElement('div');
    track.className = 'sys-bar-track';
    const fill = document.createElement('div');
    fill.className = 'sys-bar-fill';
    fill.style.width = (max > 0 ? Math.max(1.5, (Number(row.bytes) || 0) / max * 100) : 0) + '%';
    track.appendChild(fill);
    wrap.appendChild(track);

    const value = document.createElement('span');
    value.className = 'sys-bar-value';
    value.textContent = valueOf(row);
    wrap.appendChild(value);

    container.appendChild(wrap);
  });
}

function renderVerdict(list) {
  sysVerdict.textContent = '';
  list.forEach(function (item) {
    const li = document.createElement('li');
    li.className = 'sys-verdict-item level-' + item.level;

    const dot = document.createElement('span');
    dot.className = 'sys-dot';
    li.appendChild(dot);

    const text = document.createElement('span');
    text.textContent = item.text;
    li.appendChild(text);

    sysVerdict.appendChild(li);
  });
}

function renderSystem(data) {
  const mysql = data.mysql;

  renderCards(sysMetrics, sysCardItems(data));

  renderTrend(sysChart, data.samples || [], ['mem', 'ms', 'q']);

  renderBars(
    sysMysqlMem,
    (mysql.memoryByComponent || []).map(function (item) {
      return { name: item.name, bytes: item.bytes };
    }),
    function (row) { return row.name; },
    function (row) { return bytesText(row.bytes); }
  );

  renderBars(
    sysTables,
    (mysql.tables || []).map(function (item) {
      return { name: item.table, bytes: item.bytes, rows: item.rows };
    }),
    function (row) { return row.name + '（' + row.rows + ' 行）'; },
    function (row) { return bytesText(row.bytes); }
  );

  renderVerdict(data.verdict || []);

  sysCollectedAt.textContent = '采集时间：' + data.sampledAt
    + '；本次' + (data.sampleRecorded ? '已记录一个新的采样点' : '未记录新采样点（两次采样之间有最小间隔，避免频繁写盘）');
}

function loadSystem() {
  systemRefresh.disabled = true;
  return api('admin/system').then(renderSystem).catch(function (error) {
    if (error.status === 401 || error.status === 403) {
      showAlert('需要管理员权限才能查看系统状态', 5000);
      return;
    }
    showAlert('读取系统状态失败：' + error.message, 6000);
  }).then(function () {
    systemRefresh.disabled = false;
  });
}

function openSystem() {
  if (!isAdmin()) return;                    // 双重保险，真正的门槛在后端
  sysChart.textContent = '';
  sysMetrics.textContent = '';
  sysCollectedAt.textContent = '正在采集…';
  systemModal.classList.add('show');
  loadSystem();
}

/* ------------------------------------------------------------
   首页的「运行状态」一节：同一份快照的精简视图，管理员常驻可见。
   loaded 这个标记是为了 refreshNavbar() 可以被反复调用——
   登录、退出、改密之后都会刷一遍导航栏，不加判断就会每次多打一次接口。
   ------------------------------------------------------------ */
let monitorState = { loaded: false };

function renderHomeMonitor(data) {
  renderCards(homeMetrics, sysCardItems(data).filter(function (item) {
    return item.home;
  }));
  renderTrend(homeTrend, data.samples || [], ['mem', 'ms']);
  homeMonitorAt.textContent = '采集时间：' + data.sampledAt;
}

function loadHomeMonitor() {
  homeMonitorRefresh.disabled = true;
  homeMonitorAt.textContent = '正在采集…';
  return api('admin/system').then(renderHomeMonitor).catch(function (error) {
    // 这一节就在页面上，出错只写在这一节里，不去占顶部提示条
    homeMonitorAt.textContent = '读取失败：' + error.message;
  }).then(function () {
    homeMonitorRefresh.disabled = false;
  });
}

function refreshMonitorSection() {
  if (!monitorSection) return;
  if (!isAdmin()) {
    monitorSection.classList.add('hidden');
    homeMetrics.textContent = '';
    homeTrend.textContent = '';
    monitorState.loaded = false;
    return;
  }
  monitorSection.classList.remove('hidden');
  if (monitorState.loaded) return;
  monitorState.loaded = true;
  loadHomeMonitor();
}

/* ============================================================
   第 15.5 部分：「我的用量」——每个人只看自己那一份
   刻意不复用 GET /api/admin/system：那一节里的内存、磁盘、MySQL 缓冲池是
   整台机器共用的一份，A 和 B 看到的是同一串数字，把它叫「你的性能」是假的，
   而且等于把服务器底细摊给每一个路过的人。这里的三份数都走各板块本来就有的
   按账号接口（user_id 一起进 WHERE 那一套），耗时完全在浏览器侧算，不问服务器。
   ============================================================ */
const usageState = { novels: null, study: null, loading: false, failed: '' };

/** 首页的备忘录区块本来就要发这一次 GET /api/memos，这里顺带读它的结果，不多打一次请求 */
function usageMemoStats() {
  return memoState.lastData && memoState.lastData.stats ? memoState.lastData.stats : null;
}

/**
 * 浏览器自己量出来的耗时 → 卡片。
 * nav 是 performance.getEntriesByType('navigation')[0]，entries 是 performance.getEntries()。
 * 取不到就少那一张卡：老浏览器、或者浏览器把计时关了，宁可不显示也别报一个假的 0 ms。
 */
function usageTimingCards(nav, entries) {
  const cards = [];
  if (nav && nav.domContentLoadedEventEnd > 0) {
    cards.push({
      value: (nav.domContentLoadedEventEnd / 1000).toFixed(2) + ' s',
      label: '首屏就绪',
      sub: '从开始导航到 DOM 可用，浏览器自己测的，没问服务器',
    });
  }
  let hits = 0;
  let slowest = 0;
  (entries || []).forEach(function (entry) {
    if (!entry || !entry.name || entry.name.indexOf('/api/') < 0) return;
    if (!(entry.duration > 0)) return;
    hits += 1;
    if (entry.duration > slowest) slowest = entry.duration;
  });
  if (hits) {
    cards.push({
      value: Math.round(slowest) + ' ms',
      label: '接口往返',
      sub: '本页 ' + hits + ' 次接口请求里最慢的一次',
    });
  }
  return cards;
}

/** Performance API 的取数集中在这儿判空，好让上面那个纯函数能直接喂假数据测 */
function usagePerf() {
  if (typeof performance === 'undefined' || !performance.getEntriesByType) {
    return { nav: null, entries: [] };
  }
  const navList = performance.getEntriesByType('navigation');
  return {
    nav: navList && navList.length ? navList[0] : null,
    entries: performance.getEntries ? performance.getEntries() : [],
  };
}

/** 属于这个账号的三张卡。哪一份还没回来就少哪一张，不摆「—」占位糊人 */
function usageAccountCards() {
  const cards = [];
  const memo = usageMemoStats();
  if (memo) {
    cards.push({
      value: memo.total + ' 条',
      label: '你的备忘录',
      sub: '上限 ' + memo.limit + ' 条 · 还没做完 ' + memo.open + ' 条',
    });
  }
  if (usageState.novels && usageState.novels.stats) {
    const shelf = usageState.novels.stats;
    cards.push({
      value: shelf.total + ' 本',
      label: '你的书架',
      sub: '已用 ' + formatChars(shelf.chars) + ' / 上限 ' + formatChars(shelf.charLimit)
        + ' · 最多 ' + shelf.limit + ' 本',
    });
  }
  if (usageState.study && usageState.study.stats) {
    const pom = usageState.study.stats;
    cards.push({
      value: pom.minutes + ' 分钟',
      label: '你专注的时间',
      sub: '今天 ' + pom.today + ' 轮 · 跑完 ' + pom.finished + ' 轮 · 一共记了 ' + pom.total + ' 条',
    });
  }
  return cards;
}

function renderUsage() {
  if (!usageSection) return;
  const perf = usagePerf();
  const timing = usageTimingCards(perf.nav, perf.entries);
  renderCards(usageCardsEl, me.logged ? usageAccountCards().concat(timing) : timing);

  if (!me.logged) {
    // 没登录就没什么「自己的」数可算，也没什么可重读：按钮直接不收起来。
    // 留一个点不动的按钮是最难查的那种「页面坏了」
    usageRefresh.classList.add('hidden');
    usageNote.textContent = '还没登录，这里只有浏览器自己测的耗时。登录后会多出备忘录、书架与专注三张卡。';
    return;
  }
  usageRefresh.classList.remove('hidden');
  if (usageState.loading) {
    usageNote.textContent = '正在读取…';
    return;
  }
  usageNote.textContent = usageState.failed
    ? '有一部分没读到：' + usageState.failed
    : '这三张卡就是你在备忘录、阅读和学习那三个板块上看到的同一份数。';
}

function loadUsage() {
  if (!usageSection) return Promise.resolve();
  if (!me.logged) {
    usageState.novels = null;
    usageState.study = null;
    usageState.failed = '';
    renderUsage();
    return Promise.resolve();
  }
  if (usageState.loading) return Promise.resolve();
  usageState.loading = true;
  usageState.failed = '';
  const one = function (path, key) {
    return api(path).then(function (data) {
      usageState[key] = data;
    }).catch(function (error) {
      usageState[key] = null;
      // 出错只写在这一节里，不去抢顶部提示条：这一节是次要信息，不值得打断用户
      usageState.failed = error.message;
    });
  };
  return Promise.all([one('novels', 'novels'), one('study', 'study')]).then(function () {
    usageState.loading = false;
    renderUsage();
  });
}

function refreshUsageSection() {
  // 登录态一变就重新问一遍：留着上一个账号的数字，比不显示糟糕得多
  loadUsage();
}

/* ============================================================
   第 16 部分：启动
   三件事互不依赖，并行问：“我登录了吗”“站点被访问了多少次”“留言板有什么”。
   等它们都有结果（或都已失败）之后再统一刷新导航栏与备忘录区块，
   否则会出现「导航栏说你没登录、备忘录已经按登录渲染」的中间状态。
   ============================================================ */
function loadMe() {
  return api('me').then(function (data) {
    me = data && data.logged
      ? {
        logged: true,
        username: data.username,
        role: data.role,
        email: data.email || null,
        emailVerified: data.emailVerified === true,
        createdAt: data.createdAt || '',
      }
      : { logged: false, username: '', role: '', email: null, emailVerified: false };
  }).catch(function (error) {
    me = { logged: false, username: '', role: '', email: null, emailVerified: false };
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

/**
 * 邮件链接带过来的参数：
 *   ?verify=令牌  → 自动完成邮箱验证
 *   ?reset=令牌   → 打开重置密码弹窗
 * 处理完就把参数从地址栏抹掉：留着的话用户一刷新就会重复提交，
 * 而令牌是一次性的，第二次必然报「链接已失效」，看着像出了故障。
 */
function handleMailLink() {
  const params = new URLSearchParams(window.location.search);
  const verifyToken = params.get('verify');
  const reset = params.get('reset');
  if (!verifyToken && !reset) return;

  if (verifyToken) handleVerifyToken(verifyToken);
  if (reset) openReset(reset);

  if (window.history && window.history.replaceState) {
    window.history.replaceState({}, '', window.location.pathname);
  }
}

/**
 * 二级页面（游戏板块）没有登录弹窗，那边的「登录」是回到首页并带上 #login。
 * 这里见到这个锚点就把登录框直接展开，省掉「回来了还要自己再点一次」。
 * 处理完顺手抹掉锚点，免得刷新时又弹一次。
 */
function handleLoginHash() {
  if (window.location.hash !== '#login') return;
  if (me.logged) return;// 已经登录了就不用弹
  openLogin();
  if (window.history && window.history.replaceState) {
    window.history.replaceState({}, '', window.location.pathname);
  }
}

function bindEvents() {
  navLoginBtn.addEventListener('click', openLogin);
  navLogoutBtn.addEventListener('click', handleLogout);
  navAccountBtn.addEventListener('click', openAccount);
  bindClose(el('login-close'), closeLogin);
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

  // 个人中心
  bindClose(el('account-close'), function () { accountModal.classList.remove('show'); });
  pwdSubmit.addEventListener('click', handleChangePassword);
  accountModal.addEventListener('click', function (e) {
    if (e.target === accountModal) accountModal.classList.remove('show');
  });

  // 找回密码 / 重置密码
  el('go-forgot').addEventListener('click', openForgot);
  el('back-login').addEventListener('click', function () {
    forgotModal.classList.remove('show');
    openLogin();
  });
  bindClose(el('forgot-close'), function () { forgotModal.classList.remove('show'); });
  forgotSubmit.addEventListener('click', handleForgot);
  forgotModal.addEventListener('click', function (e) {
    if (e.target === forgotModal) forgotModal.classList.remove('show');
  });
  bindClose(el('reset-close'), function () { resetModal.classList.remove('show'); });
  resetSubmit.addEventListener('click', handleReset);
  resetModal.addEventListener('click', function (e) {
    if (e.target === resetModal) resetModal.classList.remove('show');
  });

  // 邮箱验证
  verifyResend.addEventListener('click', handleResendVerify);

  // 后台
  navAdminBtn.addEventListener('click', openAdmin);
  bindClose(el('admin-close'), closeAdmin);
  el('admin-refresh').addEventListener('click', loadAdmin);
  adminModal.addEventListener('click', function (e) {
    if (e.target === adminModal) closeAdmin();
  });
  el('admin-search-btn').addEventListener('click', function () {
    adminState.q = adminSearch.value.trim();
    adminState.page = 1;// 换了搜索词就回到第一页，否则可能停在一个空页上
    loadAdmin();
  });
  el('admin-clear-btn').addEventListener('click', function () {
    adminSearch.value = '';
    adminState.q = '';
    adminState.page = 1;
    loadAdmin();
  });
  adminSearch.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') el('admin-search-btn').click();
  });
  adminPrev.addEventListener('click', function () {
    if (adminState.page > 1) {
      adminState.page = adminState.page - 1;
      loadAdmin();
    }
  });
  adminNext.addEventListener('click', function () {
    adminState.page = adminState.page + 1;// 越界时后端会拉回最后一页，这里不用自己算总页数
    loadAdmin();
  });
  bindClose(el('admin-pwd-close'), function () { adminPwdModal.classList.remove('show'); });
  adminPwdSubmit.addEventListener('click', handleAdminReset);
  adminPwdModal.addEventListener('click', function (e) {
    if (e.target === adminPwdModal) adminPwdModal.classList.remove('show');
  });

  // 系统状态（管理员）
  navSystemBtn.addEventListener('click', openSystem);
  bindClose(el('system-close'), function () { systemModal.classList.remove('show'); });
  systemRefresh.addEventListener('click', loadSystem);
  systemModal.addEventListener('click', function (e) {
    if (e.target === systemModal) systemModal.classList.remove('show');
  });

  // 首页的运行状态区块（管理员）
  homeMonitorRefresh.addEventListener('click', loadHomeMonitor);
  homeMonitorDetail.addEventListener('click', openSystem);
  usageRefresh.addEventListener('click', function () {
    // 备忘录那张卡来自首页本来就在发的 GET /api/memos，重读时把它一起再拉一次，
    // 不然三张卡里有一张永远停在第一次的结果上
    loadMemos();
    loadUsage();
  });

  // 留言板
  msgSubmit.addEventListener('click', handleSendMessage);
  msgInput.addEventListener('input', updateMsgCounter);
  msgPrev.addEventListener('click', function () {
    if (msgState.page > 1) { msgState.page = msgState.page - 1; loadMessages(); }
  });
  msgNext.addEventListener('click', function () {
    msgState.page = msgState.page + 1;   // 越界时后端会拉回最后一页，不用自己算总页数
    loadMessages();
  });

  // 备忘录
  memoSubmit.addEventListener('click', handleAddMemo);
  memoTitle.addEventListener('input', updateMemoCounter);
  memoText.addEventListener('input', updateMemoCounter);
  memoGateLogin.addEventListener('click', openLogin);
  const memoFilters = [
    ['memo-filter-all', 'all'],
    ['memo-filter-open', 'open'],
    ['memo-filter-done', 'done'],
  ];
  memoFilters.forEach(function (pair) {
    const button = el(pair[0]);
    button.addEventListener('click', function () {
      memoState.filter = pair[1];
      memoState.editingId = null;
      memoFilters.forEach(function (other) {
        el(other[0]).classList.toggle('active', other[1] === pair[1]);
      });
      loadMemos();
    });
  });

  window.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      closeAllModals();
      setDropdownOpen(false);// 导航下拉菜单也一起收起
    }
  });
}
/** 给弹窗右上角的 ✕ 绑定关闭动作 */
function bindClose(button, handler) {
  if (button) button.addEventListener('click', handler);
}

function boot() {
  bindEvents();
  bindHomeDropdown();
  updateMsgCounter();
  updateMemoCounter();
  // 留言板是公开数据，和「我是谁」「访问计数」并行拉，互不阻塞
  Promise.all([loadMe(), loadVisitCount(), loadMessages()]).then(function () {
    refreshNavbar();// 这里会顺带切换备忘录板块的登录态与留言输入框的可用状态
    handleMailLink();// 邮件链接要等 /api/me 有结果再处理，才能正确显示验证后的状态
    handleLoginHash();// 从二级页面点「登录」回来的情况
  });
}
boot();

/* ============================================================
   规则自测：只测「我的用量」里那套纯规则（喂假数据，不碰 DOM、不碰真接口）。
   登记进 core.js 的 SMOKE_TESTS，由 tests/js-smoke.js 在启动烟测里调用。
   ============================================================ */
function appSelfTest() {
  const assert = function (cond, message) {
    if (!cond) throw new Error(message);
  };

  const nav = { domContentLoadedEventEnd: 421.7 };
  const entries = [
    { name: 'http://x/api/messages', duration: 30 },
    { name: 'http://x/api/novels', duration: 96.4 },
    { name: 'http://x/assets/js/app.js', duration: 500 },// 静态资源不算接口
    { name: 'http://x/api/study', duration: 0 },// 没计时的丢掉，别当 0 ms
  ];
  const cards = usageTimingCards(nav, entries);
  assert(cards.length === 2, '两张耗时卡：首屏就绪 + 接口往返');
  assert(cards[0].value === '0.42 s', '首屏就绪按秒报两位小数');
  assert(cards[1].value === '96 ms', '接口往返取最慢那一次，四舍五入成整数');
  assert(cards[1].sub.indexOf('2 次') > 0, '只数打给 /api/ 的请求：' + cards[1].sub);
  assert(usageTimingCards(null, []).length === 0, '取不到计时就一张都不摆，别报一个假的 0 ms');
  assert(usageTimingCards({ domContentLoadedEventEnd: 0 }, entries.slice(0, 1)).length === 1,
    '哪一样取不到就只少那一张，另一张照常');

  // 用量卡片的取值：后端少回一样就少一张卡，不能印 NaN
  usageState.novels = { stats: { total: 2, limit: 30, chars: 291, charLimit: 50000000 } };
  usageState.study = null;
  const memoSaved = memoState.lastData;
  memoState.lastData = { stats: { total: 5, open: 2, done: 3, limit: 200 } };
  const own = usageAccountCards();
  assert(own.length === 2, '番茄钟那份没回来就少一张，不摆空卡');
  assert(own[0].value === '5 条', '备忘录条数直接取自首页那一次请求的 stats');
  assert(own[1].sub.indexOf('291 字 / 上限 5000 万字') > 0, '书架按万字说人话，和 /read 上是同一个格式化函数：' + own[1].sub);
  memoState.lastData = memoSaved;
  usageState.novels = null;
}
SMOKE_TESTS.push(appSelfTest);
