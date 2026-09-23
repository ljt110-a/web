#!/usr/bin/env node
'use strict';
/* ============================================================
   前端启动烟测：node tests/js-smoke.js

   为什么要有这个文件：HTTP 测试只能证明「后端答得对」，证明不了「页面脚本跑起来了」。
   2026-09-22 那次，study.js 里把一个变量名写错（pomo / pomoState），
   boot() 在第一次 renderPomo() 就抛 ReferenceError 中断——
   页面剩下的部分全是 HTML 里的初始文字，看着像是「加载完了」，
   558 条断言一条没红。这类错误只有真的执行一遍脚本才会露头。

   这里不模拟渲染，也不校验样式，只做一件事：
   把每个 <page>.html 里 <script src> 声明的脚本按顺序、原样喂给一个假 DOM 跑一遍，
   只要顶层同步执行（含 boot()）抛错，或者 JS 要的元素在 HTML 里根本不存在，就算失败。

   假 DOM 的骨架是从 HTML 里扫出来的：id、class、data-*、value、标签内文字。
   所以 getElementById 返回 null 的情形和浏览器一致——
   JS 里写错 id 时这里会直接点名，而不是悄悄走「取不到就跳过」那条路。

   另外认一个可选钩子：页面脚本往 core.js 的 SMOKE_TESTS 数组里登记了自测函数的话，
   跑完这页的脚本后会挨个调用一次，用来断言不碰 DOM 的纯规则
   （arcade.js 的 2048 / 贪吃蛇规则和 physics.js 的弹球规则就是这么测的）。
   ============================================================ */

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const PUBLIC = path.resolve(__dirname, '..', 'public');

/* ------------------------------------------------------------
   第 1 部分：把 HTML 读成节点描述
   ------------------------------------------------------------ */

/** 解析一个开始标签里的属性 */
function parseAttrs(text) {
  const attrs = {};
  const re = /([A-Za-z_:][-A-Za-z0-9_:.]*)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+)))?/g;
  let m;
  while ((m = re.exec(text))) {
    const value = m[2] !== undefined ? m[2] : m[3] !== undefined ? m[3] : m[4] !== undefined ? m[4] : '';
    attrs[m[1].toLowerCase()] = value;
  }
  return attrs;
}

const VOID_TAGS = /^(?:area|base|br|col|embed|hr|img|input|link|meta|param|source|track|wbr)$/;

/**
 * 扫描 HTML，返回 { nodes, ids }。
 * nodes 是打平的元素描述，每个带 parent（-1 表示没建到父节点）：
 * 只记标签名、id、class、属性和首个文本子节点，不还原完整 DOM。
 * 层级只为「querySelectorAll 的作用域」服务，选择器本身按最后一个片段近似匹配。
 */
function scanHtml(html) {
  const nodes = [];
  const ids = new Map();
  // 栈里存 { tag, index }，闭合标签回退时按 tag 配对（漏闭合的 HTML 也不至于串位）
  const stack = [];
  const re = /<\/?([a-zA-Z][a-zA-Z0-9-]*)\b([^>]*)>/g;
  let m;
  while ((m = re.exec(html))) {
    const closing = m[0][1] === '/';
    const tag = m[1].toLowerCase();
    if (tag === 'script' || tag === 'style' || tag === 'meta' || tag === 'link') continue;
    if (closing) {
      for (let i = stack.length - 1; i >= 0; i--) {
        if (stack[i].tag === tag) { stack.length = i; break; }
      }
      continue;
    }
    if (/\/$/.test(m[2])) continue;// 自闭合写法没有子节点，也不必入栈
    const attrs = parseAttrs(m[2]);
    const classes = attrs.class ? attrs.class.split(/\s+/).filter(Boolean) : [];
    const rest = html.slice(m.index + m[0].length);
    const close = rest.indexOf('<');
    const text = close >= 0 ? rest.slice(0, close).replace(/\s+/g, ' ').trim() : '';
    const node = {
      tag: tag,
      id: attrs.id || '',
      classes: classes,
      attrs: attrs,
      parent: stack.length ? stack[stack.length - 1].index : -1,
      // input/textarea 的初值写在 value 属性上，别的元素写在标签中间
      text: /^(?:input|textarea|select)$/.test(tag) ? (attrs.value !== undefined ? attrs.value : '') : text,
    };
    node.index = nodes.length;
    nodes.push(node);
    if (node.id && !ids.has(node.id)) ids.set(node.id, node);
    if (!VOID_TAGS.test(tag)) stack.push({ tag: tag, index: node.index });
  }
  return { nodes: nodes, ids: ids };
}

/** 从 HTML 里按出现顺序取出 <script src>，返回绝对路径 */
function scriptSrcs(html) {
  const out = [];
  const re = /<script\b([^>]*)>/g;
  let m;
  while ((m = re.exec(html))) {
    const attrs = parseAttrs(m[1]);
    if (!attrs.src || attrs['data-smoke-skip'] !== undefined) continue;
    const rel = attrs.src.replace(/^\/+/, '').replace(/^\.\//, '');
    out.push(path.join(PUBLIC, rel));
  }
  return out;
}

/* ------------------------------------------------------------
   第 2 部分：假 DOM
   ------------------------------------------------------------ */

/** 接口返回的数据形状本测试不关心：给一个「怎么问都答得上」的松散对象 */
function loose() {
  const target = function () {};
  return new Proxy(target, {
    get: function (_t, key) {
      if (key === 'then' || key === 'catch' || key === 'finally') return undefined;// 别被当成 thenable
      if (key === Symbol.toPrimitive) return function () { return ''; };
      if (key === Symbol.iterator) return function* () {};
      if (key === 'length') return 0;
      return loose();
    },
    apply: function () { return loose(); },
    set: function () { return true; },
    has: function () { return true; },
  });
}

function splitSelector(selector) {
  return String(selector).trim().split(/\s+/).filter(Boolean);
}

/** 支持 .class / #id / tag / [attr] / [attr="v"]，以及只看最后一片的后代选择器 */
function matchesToken(node, token) {
  if (token === '*' || token === 'html' || token === 'body') return true;
  const attr = /^\[([^\]=]+)(?:=["']?([^"'\]]*)["']?)?\]$/.exec(token);
  if (attr) {
    const name = attr[1];
    if (!(name in node.attrs)) return false;
    return attr[2] === undefined || node.attrs[name] === attr[2];
  }
  const parts = token.match(/(^[.#]?[-A-Za-z_][-A-Za-z0-9_]*)/);
  if (!parts) return false;
  const p = parts[1];
  if (p[0] === '.') return node.classes.indexOf(p.slice(1)) >= 0;
  if (p[0] === '#') return node.id === p.slice(1);
  return node.tag === p.toLowerCase();
}

function selectorMatches(node, selector) {
  const tokens = splitSelector(selector);
  if (!tokens.length) return false;
  // 后代、兄弟之类的组合选择器只按最后一段判断：足以覆盖「这页有没有这类元素」
  return matchesToken(node, tokens[tokens.length - 1].replace(/:not\(.*\)$/, ''));
}

function makeClassList(node) {
  return {
    add: function () {
      for (let i = 0; i < arguments.length; i++) {
        const c = arguments[i];
        if (c && node.classes.indexOf(c) < 0) node.classes.push(c);
      }
    },
    remove: function () {
      for (let i = 0; i < arguments.length; i++) {
        const c = arguments[i];
        const at = node.classes.indexOf(c);
        if (at >= 0) node.classes.splice(at, 1);
      }
    },
    toggle: function (c, force) {
      const has = node.classes.indexOf(c) >= 0;
      const want = force === undefined ? !has : !!force;
      if (want && !has) node.classes.push(c);
      if (!want && has) node.classes.splice(node.classes.indexOf(c), 1);
      return want;
    },
    contains: function (c) { return node.classes.indexOf(c) >= 0; },
  };
}

const CANVAS_CTX = {
  canvas: null,
  fillStyle: '', strokeStyle: '', lineWidth: 1, font: '', globalAlpha: 1,
  lineCap: 'butt', lineJoin: 'miter', textAlign: 'start', textBaseline: 'alphabetic',
  shadowBlur: 0, shadowColor: '', globalCompositeOperation: 'source-over',
  save: function () {}, restore: function () {}, beginPath: function () {}, closePath: function () {},
  moveTo: function () {}, lineTo: function () {}, arc: function () {}, arcTo: function () {}, ellipse: function () {},
  rect: function () {}, clip: function () {}, bezierCurveTo: function () {}, quadraticCurveTo: function () {},
  fill: function () {}, stroke: function () {}, clearRect: function () {}, fillRect: function () {},
  setTransform: function () {}, transform: function () {}, resetTransform: function () {}, translate: function () {},
  scale: function () {}, rotate: function () {}, setLineDash: function () {}, getLineDash: function () { return []; },
  createLinearGradient: function () { return { addColorStop: function () {} }; },
  createRadialGradient: function () { return { addColorStop: function () {} }; },
  measureText: function () { return { width: 0 }; }, fillText: function () {}, strokeText: function () {},
  getImageData: function () { return { data: [0, 0, 0, 0] }; }, putImageData: function () {},
  drawImage: function () {},
};

/** 建一个元素桩；state 负责记账（缺失的 id、注册过的节点） */
function makeElement(node, state) {
  const el = {
    tagName: node.tag.toUpperCase(),
    nodeType: 1,
    id: node.id,
    classes: node.classes.slice(),
    attrs: Object.assign({}, node.attrs),
    children: [],
    parentNode: null,
    listeners: {},
    dataset: {},
    style: { setProperty: function () {}, removeProperty: function () {}, getPropertyValue: function () { return ''; } },
  };
  Object.keys(el.attrs).forEach(function (name) {
    if (name.indexOf('data-') !== 0) return;
    const key = name.slice(5).replace(/-([a-z])/g, function (_a, c) { return c.toUpperCase(); });
    el.dataset[key] = el.attrs[name];
  });
  el.className = el.classes.join(' ');
  Object.defineProperty(el, 'textContent', {
    get: function () { return node.text === undefined ? '' : node.text; },
    set: function (v) { node.text = String(v); },
  });
  Object.defineProperty(el, 'value', {
    get: function () { return el._value === undefined ? (node.text || '') : el._value; },
    set: function (v) { el._value = String(v); },
  });
  el.classList = makeClassList(el);
  el.appendChild = function (child) {
    el.children.push(child);
    child.parentNode = el;
    return child;
  };
  el.append = function () {
    for (let i = 0; i < arguments.length; i++) el.appendChild(arguments[i]);
  };
  el.removeChild = function (child) {
    const at = el.children.indexOf(child);
    if (at >= 0) el.children.splice(at, 1);
    return child;
  };
  el.remove = function () { if (el.parentNode) el.parentNode.removeChild(el); };
  el.insertBefore = function (child) { return el.appendChild(child); };
  el.addEventListener = function (type, fn) { (el.listeners[type] = el.listeners[type] || []).push(fn); };
  el.removeEventListener = function () {};
  el.dispatch = function (type, event) {
    (el.listeners[type] || []).forEach(function (fn) { fn(event || { target: el, preventDefault: function () {} }); });
  };
  el.click = function () {};
  el.focus = function () {};
  el.blur = function () {};
  el.select = function () {};
  el.scrollIntoView = function () {};
  el.setAttribute = function (name, v) { el.attrs[name] = String(v); };
  el.getAttribute = function (name) { return el.attrs[name] === undefined ? null : el.attrs[name]; };
  el.hasAttribute = function (name) { return el.attrs[name] !== undefined; };
  el.removeAttribute = function (name) { delete el.attrs[name]; };
  el.matches = function (sel) { return selectorMatches(describe(el), sel); };
  el.closest = function (sel) { let p = el; while (p) { if (p.matches && p.matches(sel)) return p; p = p.parentNode; } return null; };
  el.querySelector = function (sel) { return el.querySelectorAll(sel)[0] || null; };
  el.querySelectorAll = function (sel) {
    return state.query(sel).filter(function (other) { return other === el || isUnder(other, el); });
  };
  el.getContext = function () { return CANVAS_CTX; };
  el.getBoundingClientRect = function () { return { top: 0, left: 0, width: 800, height: 600, right: 800, bottom: 600 }; };
  return el;
}

function describe(el) {
  return { tag: el.tagName.toLowerCase(), id: el.id, classes: el.classes, attrs: el.attrs, text: el.textContent };
}

function isUnder(node, parent) {
  let p = node.parentNode;
  while (p) { if (p === parent) return true; p = p.parentNode; }
  return false;
}

/** 造一个页面的执行环境；返回 { sandbox, state } */
function makeSandbox(page, html) {
  const scanned = scanHtml(html);
  const state = {
    missing: [],          // JS 问了 HTML 里没有的 id
    registry: [],         // 所有可被 querySelectorAll 找到的元素
    byId: new Map(),
  };

  const root = makeElement({ tag: 'html', id: '', classes: [], attrs: {}, text: '', parent: -1 }, state);

  scanned.nodes.forEach(function (node) {
    const el = makeElement(node, state);
    el.parentNode = node.parent >= 0 ? state.registry[node.parent] : root;
    state.registry.push(el);
    if (node.id && !state.byId.has(node.id)) state.byId.set(node.id, el);
  });

  state.query = function (sel) {
    return state.registry.filter(function (el) { return selectorMatches(describe(el), sel); });
  };

  // body 单独找出来：脚本会往 document.body 上挂东西，挂到 <html> 上就错位了
  let bodyEl = root;
  scanned.nodes.forEach(function (node, i) { if (node.tag === 'body') bodyEl = state.registry[i]; });

  const document = {
    title: page,
    readyState: 'complete',
    hidden: false,
    visibilityState: 'visible',
    documentElement: root,
    body: bodyEl,
    head: root,
    activeElement: bodyEl,
    getElementById: function (id) {
      const hit = state.byId.get(id);
      if (hit) return hit;
      state.missing.push(id);
      return null;
    },
    querySelector: function (sel) { return state.query(sel)[0] || null; },
    querySelectorAll: function (sel) { return state.query(sel); },
    createElement: function (tag) {
      const el = makeElement({ tag: String(tag).toLowerCase(), id: '', classes: [], attrs: {}, text: '' }, state);
      state.registry.push(el);
      return el;
    },
    createElementNS: function (_ns, tag) { return document.createElement(tag); },
    createTextNode: function (text) { return { nodeType: 3, textContent: String(text) }; },
    createDocumentFragment: function () { return document.createElement('fragment'); },
    addEventListener: function () {},
    removeEventListener: function () {},
    getElementsByTagName: function () { return []; },
    getElementsByClassName: function () { return []; },
  };

  const timers = { set: function () { return 0; }, clear: function () {} };

  const storage = function () {
    const map = {};
    return {
      getItem: function (k) { return map[k] === undefined ? null : map[k]; },
      setItem: function (k, v) { map[k] = String(v); },
      removeItem: function (k) { delete map[k]; },
      clear: function () { Object.keys(map).forEach(function (k) { delete map[k]; }); },
    };
  };

  const location = {
    href: 'http://127.0.0.1:8000/' + page,
    protocol: 'http:', host: '127.0.0.1:8000', hostname: '127.0.0.1', port: '8000',
    pathname: '/' + page, search: '', hash: '', origin: 'http://127.0.0.1:8000',
    reload: function () {}, assign: function () {}, replace: function () {},
  };

  const sandbox = {
    document: document,
    navigator: { userAgent: 'node-smoke', language: 'zh-CN', maxTouchPoints: 0, clipboard: { writeText: function () { return Promise.resolve(); } } },
    location: location,
    localStorage: storage(),
    sessionStorage: storage(),
    console: { log: function () {}, warn: function () {}, error: function () {}, info: function () {} },
    setTimeout: timers.set,
    setInterval: timers.set,
    clearTimeout: timers.clear,
    clearInterval: timers.clear,
    requestAnimationFrame: function () { return 0; },
    cancelAnimationFrame: function () {},
    performance: { now: function () { return Date.now(); } },
    matchMedia: function (q) {
      return { matches: false, media: q, addEventListener: function () {}, removeEventListener: function () {}, addListener: function () {}, removeListener: function () {} };
    },
    getComputedStyle: function () {
      return { getPropertyValue: function () { return ''; } };
    },
    history: { pushState: function () {}, replaceState: function () {}, back: function () {}, scrollRestoration: 'auto' },
    AbortController: AbortController,
    // vm 的上下文只带 ECMAScript 内置对象，浏览器那套全局要自己补进来
    URLSearchParams: URLSearchParams,
    URL: URL,
    Image: function () { return makeElement({ tag: 'img', id: '', classes: [], attrs: {}, text: '' }, state); },
    devicePixelRatio: 1,
    innerWidth: 1280,
    innerHeight: 800,
    pageYOffset: 0,
    scrollY: 0,
    addEventListener: function () {},
    removeEventListener: function () {},
    alert: function () {},
    confirm: function () { return true; },
    open: function () { return null; },
    fetch: function (url) {
      const res = {
        ok: true, status: 200, url: String(url),
        json: function () { return Promise.resolve(loose()); },
        text: function () { return Promise.resolve(''); },
        headers: { get: function () { return null; } },
      };
      return Promise.resolve(res);
    },
  };
  sandbox.window = sandbox;
  sandbox.self = sandbox;
  sandbox.globalThis = sandbox;
  sandbox.top = sandbox;
  sandbox.parent = sandbox;
  return { sandbox: sandbox, state: state };
}

/* ------------------------------------------------------------
   第 3 部分：跑一个页面
   ------------------------------------------------------------ */

async function smokePage(page) {
  const html = fs.readFileSync(path.join(PUBLIC, page), 'utf8');
  const files = scriptSrcs(html);
  const made = makeSandbox(page, html);
  const context = vm.createContext(made.sandbox, { name: page });
  const failures = [];

  for (const file of files) {
    if (!fs.existsSync(file)) {
      failures.push('脚本文件不存在：' + path.relative(PUBLIC, file) + '（HTML 里 src 写错了）');
      continue;
    }
    const code = fs.readFileSync(file, 'utf8');
    try {
      vm.runInContext(code, context, { filename: file, timeout: 5000 });
    } catch (error) {
      failures.push(shortError(path.basename(file), error));
    }
  }

  // 把脚本里挂出去的 Promise 链跑完（微任务），再收口
  for (let i = 0; i < 12; i++) await new Promise(function (r) { setImmediate(r); });

  made.state.missing.forEach(function (id) {
    failures.push('getElementById(\'' + id + '\') 取不到：' + page + ' 里没有 id="' + id + '" 的元素');
  });

  /* 页面脚本把「纯规则」自测函数登记到 core.js 的 SMOKE_TESTS 里（不碰 DOM、不发请求）。
     数组是空的也算通过：这是可选钩子，不能因为某一页没写自测就报失败。
     但它一旦登记了、又没被调到，那些断言等于没跑——所以最后一行要汇总报了谁跑过、跑了几组，
     tests/cases/12-js-smoke.php 会盯着这条汇总。 */
  let selfTests = [];
  try {
    selfTests = vm.runInContext(
      '(typeof SMOKE_TESTS === "undefined" || !SMOKE_TESTS) ? [] : SMOKE_TESTS.slice().map(function (fn, i) {' +
      '  if (typeof fn !== "function") throw new Error("SMOKE_TESTS[" + i + "] 不是函数");' +
      '  fn();' +
      '  return fn.name || ("SMOKE_TESTS[" + i + "]");' +
      '})',
      context,
      { filename: page, timeout: 5000 }
    );
  } catch (error) {
    selfTests = null;
    failures.push('规则自测没过：' + shortError(page, error));
  }

  return {
    page: page,
    scripts: files.map(function (f) { return path.basename(f); }),
    selfTests: selfTests,
    failures: failures,
  };
}

/** 错误信息压成一行，并且尽量带上「在哪一行」 */
function shortError(file, error) {
  const stack = error && error.stack ? String(error.stack) : '';
  let where = '';
  const own = new RegExp(file + ':(\\d+):\\d+').exec(stack);
  if (own) {
    where = ':' + own[1];
  } else {
    // 兜底：错误常常是从别的脚本里抛出来的（比如自测函数定义在 arcade.js，
    // 却是按页面名调起来的），那一帧的行号才是人要看的位置
    const other = /([\w.-]+\.js):(\d+):\d+/.exec(stack);
    if (other) where = other[1] + ':' + other[2];
  }
  return (error && error.name ? error.name : 'Error') + where + ' ' + (error && error.message ? error.message : String(error));
}

async function main() {
  const pages = fs.readdirSync(PUBLIC).filter(function (f) { return /\.html$/.test(f); });
  const unhandled = [];
  process.on('unhandledRejection', function (reason) {
    unhandled.push(reason && reason.message ? reason.message : String(reason));
  });

  let bad = 0;
  const selfTested = [];
  for (const page of pages) {
    let result;
    try {
      result = await smokePage(page);
    } catch (error) {
      result = { page: page, scripts: [], selfTests: [], failures: ['烟测框架自己出错了：' + shortError(page, error)] };
    }
    if (result.selfTests && result.selfTests.length) {
      selfTested.push(result.page + '（' + result.selfTests.join('、') + '）');
    }
    if (result.failures.length) {
      bad++;
      console.log('FAIL ' + result.page + ' [' + result.scripts.join(' → ') + ']');
      result.failures.forEach(function (f) { console.log('     · ' + f); });
    } else {
      console.log('OK   ' + result.page + ' [' + result.scripts.join(' → ') + ']'
        + (result.selfTests && result.selfTests.length ? '（含 ' + result.selfTests.length + ' 组规则自测）' : ''));
    }
  }
  if (unhandled.length) {
    bad++;
    console.log('FAIL 未捕获的 Promise 异常');
    unhandled.forEach(function (m) { console.log('     · ' + m); });
  }
  // 汇总谁跑了自测：钩子是可选的，但「登记了却从没被调到」会让那些断言悄悄变成死代码
  console.log(selfTested.length
    ? '规则自测跑过的页面：' + selfTested.join('，')
    : '规则自测：没有页面往 SMOKE_TESTS 里登记函数');
  console.log(bad ? '\n' + bad + ' 个页面没通过启动烟测' : '\n全部页面启动烟测通过（' + pages.length + ' 个）');
  process.exitCode = bad ? 1 : 0;
}

main();
