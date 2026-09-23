/* ============================================================
   web-one 前端公共脚本
   首页（app.js）与游戏板块二级页面（games.js）共用这一份：
   元素取用、接口调用、顶部提示条、弹窗提示文字。

   抽出来是为了让「怎么发请求、出错怎么显示」只有一处实现——
   两个页面各写一份，迟早会走样，而且改一个地方必漏另一个。
   ============================================================ */

/** 与后端通信相关的公共参数 */
const CORE = {
  apiTimeout: 5000,// 等后端返回的最长时间（毫秒），超时就不再卡住页面
};

/** 按 id 取元素；取不到返回 null（不同页面元素本来就不一样，调用方自己判断） */
function el(id) {
  return document.getElementById(id);
}

/**
 * 与后端接口通信的唯一出口。
 * 所有请求都走这一个函数，超时、报错、JSON 解析都在这里集中处理。
 */
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
  // 后端不响应时（忘了启动、端口被占）主动中断，避免页面一直等
  const controller = typeof AbortController === 'function' ? new AbortController() : null;
  if (controller) {
    options.signal = controller.signal;
    setTimeout(function () { controller.abort(); }, CORE.apiTimeout);
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
      const timeout = new Error('后端响应超时（' + CORE.apiTimeout + ' 毫秒）');
      timeout.status = 0;
      throw timeout;
    }
    throw err;// NetworkError：服务没启动、跨域被拦等，status 留空
  });
}

/* ============================================================
   顶部提示条：报告后端层面的问题，两个页面都有这个元素
   ============================================================ */
let alertTimer = null;

function showAlert(message, holdMs) {
  const bar = el('top-alert');
  if (!bar) return;
  // 只写 textContent：提示里可能出现用户名等用户输入的内容，不能当 HTML 解析
  bar.textContent = message;
  bar.classList.remove('hidden');
  if (alertTimer) clearTimeout(alertTimer);
  if (holdMs === 0) return;// 传 0 表示常驻，需要用户自己处理
  alertTimer = setTimeout(hideAlert, holdMs || 5000);
}

function hideAlert() {
  const bar = el('top-alert');
  if (!bar) return;
  bar.classList.add('hidden');
  bar.textContent = '';
}

/** 弹窗 / 表单里的提示文字：统一走这里，避免各处拼字符串拼出不一致的写法 */
function setMsg(element, text, type) {
  if (!element) return;
  element.textContent = text;
  element.className = 'modal-msg' + (type ? ' ' + type : '');
}

/* ============================================================
   两段式确认：第一次点变成「确认删除？」，4 秒内再点一次才真的执行。
   后台删用户、留言板删留言、备忘录删备忘录、游戏删条目都用它。

   不用 window.confirm 的原因：它会阻塞整个页面线程（自动化测试也会被卡住），
   而且样式完全不受控。同一个时刻只允许一个按钮处于待确认状态。
   ============================================================ */
const armed = { button: null, timer: null };

function disarmConfirm() {
  if (armed.timer) {
    clearTimeout(armed.timer);
    armed.timer = null;
  }
  if (armed.button) {
    armed.button.textContent = armed.button.dataset.label || '';
    armed.button.classList.remove('confirming');
    armed.button = null;
  }
}

function armConfirm(button, armedText, onConfirm) {
  button.dataset.label = button.dataset.label || button.textContent;
  if (armed.button === button) {          // 第二次点击 → 真的执行
    disarmConfirm();
    onConfirm();
    return;
  }
  disarmConfirm();
  armed.button = button;
  button.textContent = armedText;
  button.classList.add('confirming');
  armed.timer = setTimeout(disarmConfirm, 4000);
}

/* ============================================================
   共用格式：把字数说成人话
   住在 core.js 是因为 /read 的书架和首页的「我的用量」都要报「多少万字」，
   两边各写一份迟早会不一样——同一本书在两个页面上显示成两个数字很难看。
   ============================================================ */
function formatChars(n) {
  const value = parseInt(n, 10) || 0;
  if (value < 10000) return value + ' 字';
  // 整万要丢掉小数点：配额这类数天生是整数，写成「5000.0 万字」像页面坏了一半
  const wan = Math.round(value / 1000) / 10;
  return (wan % 1 === 0 ? String(wan) : wan.toFixed(1)) + ' 万字';
}

/* ============================================================
   规则自测的登记表：页面脚本把自己的纯规则断言函数 push 进来，
   tests/js-smoke.js 跑完这一页的脚本后逐个调用。

   为什么是数组而不是一个 smokeSelfTest() 函数：同一页可能有好几个各自独立的
   规则集（/games 上的 arcade.js 与 physics.js 就是两套），而普通 <script> 共享
   全局词法作用域——第二份如果也叫同一个函数名，那是**静默覆盖**，
   先写的那套断言就再也没跑过，测试还全绿。登记进数组就没这个问题。
   ============================================================ */
const SMOKE_TESTS = [];

/** 共用工具自己的规则断言（每个页面都会跑一遍这几条） */
function coreSelfTest() {
  const assert = function (cond, message) {
    if (!cond) throw new Error(message);
  };

  assert(formatChars(291) === '291 字', '一万字以下直接报整数，不硬凑一个「万字」');
  assert(formatChars(2946133) === '294.6 万字', '一本书的真实字数按万字报一位小数');
  assert(formatChars(10000) === '1 万字', '刚好一万就过万字那一档');
  assert(formatChars(50000000) === '5000 万字', '整万要丢掉小数点：配额这类数最常见就是整万');
  assert(formatChars(null) === '0 字', '没给数也不能是 NaN');
  assert(formatChars('abc') === '0 字', '不是数字也不能把 NaN 印到页面上');
}
SMOKE_TESTS.push(coreSelfTest);
