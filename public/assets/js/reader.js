/* ============================================================
   小说阅读页（/read）的脚本：书架 + 文件导入 + 阅读器

   五条写在这里就得说清楚的取舍：

   1. 「认章节」的规则**全在后端**（src/novels.php），这个文件里一份都没有。
      前端要是复制一份正则去做实时预览，就会有「预览说三章、存进去变五章」这种
      查都没法查的分歧。所以这里只显示字数与上限，章节数等后端算完再告诉用户。

   2. 文件是**浏览器自己读成文本**再发给接口的，没有走 `<form enctype="multipart/form-data">`
      上传。省掉的是服务端临时文件、multipart 边界解析和「按 MIME 猜它是不是文本」这一摊
      ——每一项都是攻击面；代价是一份大文件会先在浏览器里占一块内存，
      所以 readByteCeiling() 先按「书架还剩多少字」把光看字节数就装不下的文件挡在门外
      （单本多大不限，限的是账号总量，见 readRemainingChars()）。

   3. 中文 txt 有一半不是 UTF-8：GBK / GB18030 是老机器上的主流，UTF-16 也常见
      （记事本那个「Unicode」编码存出来的就是它）。解码只能在这里做：文本一旦以 UTF-8
      字符串发出接口，编码信息就丢了，后端再猜是在猜一份已经错掉的文字。

   4. 正文一律用 createElement + textContent 一段一段建节点，**从不写 innerHTML**。
      后端刻意不对正文做转义（那是用户自己的小说，库里该是原样），
      于是「防止文件里的内容变成代码」这件事完全由渲染侧负责。
      选文件那一刻还多一道 readLooksBinary()：把改名成 .txt 的 exe / 压缩包在本地就拦下，
      不用等几十兆传上去才拿到那句「这不是文本」。它认的是「这压根不是一份文本」，**不是杀毒**——
      认病毒要特征库，浏览器里没有那个东西；而本站收到的一直是 JSON 里的一段字符串，
      从不落盘、不解压、不执行，「上传个木马打进来」这条路从结构上就不存在。

   5. 离线读的是**本机副本**：读过的章节会存进 localStorage，取不到网络时拿出来用。
      没有让 Service Worker 去缓存 /api/novel/chapter ——那个接口返回的是某个账号的
      书，缓存下来既陈旧又危险（sw.js 顶部写了这条规矩）。
   ============================================================ */

const READ = {
  fontSizes: [16, 18, 20, 22, 25, 28, 32],// 一档一档跳，不给自由滑块：字号是「舒适区间」，不是无级变速
  themes: ['', 'reader-sepia', 'reader-light'],// 空串 = 跟站点的深色一致
  themeNames: ['夜色', '米黄', '白纸'],
  cacheMaxChars: 400000,// 每本书在本机最多存多少字的副本（约 1.2 MB 文本，浏览器配额一般 5 MB）
  readLine: 96,// 距视口顶部这么多像素算「正在读的那一行」
  saveDelay: 1200,// 滚动停下来多久才存一次进度
  prefsKey: 'web-one-read-prefs',
  cachePrefix: 'web-one-read-',
};

const readState = {
  me: { logged: false, username: '' },
  limits: {},
  stats: {},// 书架的汇总：已存几本、已经占了多少字、配额多少
  shelf: [],
  book: null,// { novel, toc }
  seq: 0,
  total: 0,
  fontIndex: 2,
  themeIndex: 0,
  offline: false,
  pick: null,// 选中并读进内存的那份文件：{name, text, encoding, bytes}，存进书架后清掉
  lastSaved: { chapter: -1, paragraph: -1 },
  saveTimer: 0,
  busy: false,
};

const navUser = el('nav-user');
const navUsername = el('nav-username');
const readLoginLink = el('read-login-link');
const visitCountEl = el('visit-count');
const readGateEl = el('read-gate');
const readGateTextEl = el('read-gate-text');
const readShelfBodyEl = el('read-shelf-body');
const shelfListEl = el('shelf-list');
const shelfEmptyEl = el('shelf-empty');
const importTitleEl = el('import-title');
const importFileEl = el('import-file');
const importFileNameEl = el('import-file-name');
const importCountEl = el('import-count');
const importLimitEl = el('import-limit');
const importSubmitEl = el('import-submit');
const importClearEl = el('import-clear');
const importTipEl = el('import-tip');
const readerEl = el('reader');
const readerBookEl = el('reader-book');
const readerTocEl = el('reader-toc');
const readerChapterEl = el('reader-chapter');
const readerTextEl = el('reader-text');
const readerEmptyEl = el('reader-empty');
const readerPrevEl = el('reader-prev');
const readerNextEl = el('reader-next');
const readerPageEl = el('reader-page');
const readerStatusEl = el('reader-status');
const readerCloseEl = el('reader-close');
const fontMinusEl = el('read-font-minus');
const fontPlusEl = el('read-font-plus');
const themeBtnEl = el('read-theme');

/* ============================================================
   第 1 部分：不碰 DOM 的规则（tests/js-smoke.js 会跑到这一节）
   ============================================================ */

/** 字数：不含空白。和后端 novel_count_chars() 同一把尺子 */
function readCountChars(text) {
  return String(text === undefined || text === null ? '' : text).replace(/\s+/g, '').length;
}

/**
 * 只看头两个字节决定用哪个编码解：UTF-16 的字节序**只能**靠 BOM 认——
 * 没有 BOM 的 UTF-16 和任何别的编码从字节上根本分不开。
 * UTF-8 的 BOM（EF BB BF）不在这里判，TextDecoder 解 UTF-8 时自己会吃掉它。
 * 认不出 BOM 一律先按 UTF-8 试，解不动再由 readTextFile 退到 GB18030。
 */
function readEncodingLabel(bytes) {
  if (!bytes || bytes.length < 2) return 'utf-8';
  if (bytes[0] === 0xFF && bytes[1] === 0xFE) return 'utf-16le';
  if (bytes[0] === 0xFE && bytes[1] === 0xFF) return 'utf-16be';
  return 'utf-8';
}

/**
 * 一份文件最多「maxChars 字」时可能占多少字节：一个字符最多 4 字节
 * （UTF-8 的 emoji 和 GB18030 的四字节区都正好是 4）。所以字节数超过这个值的文件
 * **一定**超标，没必要先花内存把它整个读进来。这条只是提前拦一刀，
 * 卡在这个数以内的仍然由后端按真实字数拒。
 *
 * 传进来的不再是「单次上限」而是「书架还剩多少字」——本站不限单本多大，
 * 只按账号总量算，见 readRemainingChars()。
 */
function readByteCeiling(maxChars) {
  const n = parseInt(maxChars, 10);
  if (!n || n <= 0) return 0;// 拿不到上限就不预判，别让一个 0 把所有文件都挡住
  return n * 4;
}

/** 书架还剩多少字可以放。拿不到配额就返回 0，意思是「别在前端预判，让后端说」 */
function readRemainingChars(stats, limits) {
  const total = parseInt(limits && limits.maxTotalChars, 10) || 0;
  if (!total) return 0;
  const used = parseInt(stats && stats.chars, 10) || 0;
  return total > used ? total - used : 0;
}

/** 一眼就不是文本的文件头，与后端 NOVEL_BINARY_SIGNATURES 一一对应 */
const READ_BINARY_HEADS = [
  [[0x4D, 0x5A], '可执行文件（exe / dll）'],
  [[0x50, 0x4B, 0x03, 0x04], '压缩包（zip / docx / epub / apk 都是它换皮）'],
  [[0x1F, 0x8B], 'gzip 压缩文件'],
  [[0x37, 0x7A, 0xBC, 0xAF], '7z 压缩文件'],
  [[0x52, 0x61, 0x72, 0x21, 0x1A], 'rar 压缩文件'],
  [[0x7F, 0x45, 0x4C, 0x46], 'Linux 可执行文件'],
  [[0xD0, 0xCF, 0x11, 0xE0], '老版 Office 文档（宏病毒常藏在这里）'],
  [[0x25, 0x50, 0x44, 0x46], 'PDF 文件'],
];

/** 不可打印字符：制表、换行、回车之外的那些控制字节 */
function readIsControlByte(b) {
  return b <= 0x08 || (b >= 0x0E && b <= 0x1F) || b === 0x7F;
}

/**
 * 「这到底是文本，还是一个改名成 .txt 的二进制文件」。像二进制就返回一句能直接给人看的话，
 * 是文本返回空串。
 *
 * 判的三样和后端 novel_assert_plain_text() 是同一条规则。为什么两边各判一遍：
 * 后端那一遍才算数（绕开这个页面直接发请求，也一样会被拒），前端这一遍省的是人的时间——
 * 少了它，选了个 exe 得先把几十兆传上去，再等一次往返才看见「这不是文本」。
 * NUL 只在采样的三段里找（整本扫几十兆会在手机上白卡一下），后端是整本扫的，
 * 所以这里放过一个也只是晚一点由后端拒掉，不会真的漏进去。
 *
 * **这不是杀毒。** 认木马要特征库，浏览器里没有那个东西；真被感染的也是用户自己那台机器，
 * 那是杀毒软件的活。这一层认的是「这压根不是一份文本」，顺带别让人把几十兆垃圾塞进书架。
 */
function readLooksBinary(bytes) {
  if (!bytes || !bytes.length) return '';
  const head = [];
  for (let i = 0; i < 8 && i < bytes.length; i++) head.push(bytes[i]);
  for (let s = 0; s < READ_BINARY_HEADS.length; s++) {
    const sig = READ_BINARY_HEADS[s][0];
    if (head.length < sig.length) continue;
    let hit = true;
    for (let i = 0; i < sig.length; i++) {
      if (head[i] !== sig[i]) {
        hit = false;
        break;
      }
    }
    if (hit) return '这个文件是' + READ_BINARY_HEADS[s][1] + '，不是小说正文：本站只收纯文本的 txt';
  }

  // 采三段：头、中、尾。真二进制的控制字符是「每一屏都是」，采样足够和「夹了几个杂点」分开
  const size = bytes.length;
  let bad = 0;
  let sampled = 0;
  const spans = [[0, Math.min(65536, size)],
    [Math.floor(size / 2), Math.floor(size / 2) + Math.min(32768, size)],
    [Math.max(0, size - 65536), size]];
  for (let i = 0; i < spans.length; i++) {
    for (let j = spans[i][0]; j < spans[i][1]; j++) {
      sampled++;
      if (bytes[j] === 0) return '这个文件里有 \\0 字节，是二进制文件而不是文本：请确认导的是 txt 正文';
      if (readIsControlByte(bytes[j])) bad++;
    }
  }
  if (sampled && bad * 100 > sampled) {
    return '这个文件里有 ' + bad + ' 个不可打印字符（在抽查的头、中、尾三段里占一成以上），不像小说正文：'
      + '多半是把别的格式改名成了 .txt，用记事本「另存为 → 编码 UTF-8」再导一次';
  }
  return '';
}

/** 文件大小说成人话：别让用户看见 1572864 这种数 */
function formatBytes(n) {
  const value = parseInt(n, 10) || 0;
  if (value < 1024) return value + ' B';
  if (value < 1024 * 1024) return Math.round(value / 1024) + ' KB';
  return (Math.round(value / 1024 / 1024 * 10) / 10).toFixed(1) + ' MB';
}

/** 一章的正文 → 自然段数组。库里用换行分段，这里就按换行分 */
function readParagraphs(content) {
  const list = String(content === undefined || content === null ? '' : content).split('\n');
  const out = [];
  for (let i = 0; i < list.length; i++) {
    const line = list[i].replace(/^\s+|\s+$/g, '');
    if (line !== '') out.push(line);
  }
  return out;
}

function readClamp(n, min, max) {
  const value = parseInt(n, 10);
  if (isNaN(value)) return min;
  return Math.min(max, Math.max(min, value));
}

/**
 * 阅读线落在第几段：boxes 是每段的 {top, bottom}（相对正文容器），
 * line 是「正在读的那条线」相对容器顶部的位置。取最后一个顶边在线上方的段。
 *
 * 为什么不用「面积最大的那段」：一段话经常占三行高，滚动时它既在上方也在下方，
 * 按面积判断会让进度在两段之间来回跳。按顶边判断，读数只会往前走。
 */
function readVisibleParagraph(boxes, line) {
  if (!boxes || boxes.length === 0) return 0;
  let index = 0;
  for (let i = 0; i < boxes.length; i++) {
    if (boxes[i].top <= line) index = i;
  }
  return index;
}

/** 进度换算成百分比：总段数为 0 时给 0，绝不能给出 NaN（NaN 会一路画到页面上） */
function readPercent(done, total) {
  const t = parseInt(total, 10);
  const d = parseInt(done, 10);
  if (!t || t <= 0 || isNaN(d)) return 0;
  return readClamp(Math.round((Math.max(0, d) / t) * 100), 0, 100);
}

/**
 * 离线副本该留哪几章：entries 是 [{seq, chars}]，从 currentSeq 由近到远往外扩，
 * 装不下就停。
 *
 * 不按「最后读的顺序」留，也不按 seq 从小到大留：前者会在换章时把刚存的那章挤掉，
 * 后者等于永远只留着开头几章。以当前位置为中心，才符合「接着上次读的地方往下读」。
 */
function readCacheKeep(entries, currentSeq, maxChars) {
  const list = (entries || []).slice();
  list.sort(function (a, b) {
    const da = Math.abs(a.seq - currentSeq);
    const db = Math.abs(b.seq - currentSeq);
    if (da !== db) return da - db;
    return a.seq - b.seq;
  });
  const kept = [];
  let used = 0;
  for (let i = 0; i < list.length; i++) {
    if (kept.length > 0 && used + list[i].chars > maxChars) continue;
    used += list[i].chars;
    kept.push(list[i].seq);
  }
  kept.sort(function (a, b) { return a - b; });
  return kept;
}

/* ============================================================
   第 2 部分：本机存储（隐私模式会抛异常，全都包起来）
   ============================================================ */

function readStoreRead(key, fallback) {
  try {
    if (!window.localStorage) return fallback;
    const raw = window.localStorage.getItem(key);
    return raw === null ? fallback : raw;
  } catch (e) {
    return fallback;
  }
}

function readStoreWrite(key, value) {
  try {
    if (window.localStorage) window.localStorage.setItem(key, value);
  } catch (e) {
    // 存不下就算了：字号、底色、离线副本都是加分项，不该变成一条错误提示
  }
}

function readStoreRemove(key) {
  try {
    if (window.localStorage) window.localStorage.removeItem(key);
  } catch (e) {
    // 同上
  }
}

function loadPrefs() {
  const raw = readStoreRead(READ.prefsKey, '');
  if (!raw) return;
  let data = null;
  try {
    data = JSON.parse(raw);
  } catch (e) {
    data = null;// 上一版的格式认不出来就用默认值，别拿一条错误拦住整页
  }
  if (!data) return;
  readState.fontIndex = readClamp(data.f, 0, READ.fontSizes.length - 1);
  readState.themeIndex = readClamp(data.t, 0, READ.themes.length - 1);
}

function savePrefs() {
  readStoreWrite(READ.prefsKey, JSON.stringify({ f: readState.fontIndex, t: readState.themeIndex }));
}

function readCacheKey(bookId) {
  return READ.cachePrefix + bookId;
}

function readCacheLoad(bookId) {
  const raw = readStoreRead(readCacheKey(bookId), '');
  if (!raw) return {};
  try {
    const data = JSON.parse(raw);
    return (data && data.chapters) ? data.chapters : {};
  } catch (e) {
    return {};
  }
}

/** 读完一章就顺手留一份，并按「离当前位置最近」裁到配额以内 */
function readCacheSave(bookId, seq, title, content) {
  const chapters = readCacheLoad(bookId);
  chapters[String(seq)] = { t: title, c: content };
  const entries = [];
  Object.keys(chapters).forEach(function (key) {
    entries.push({ seq: parseInt(key, 10), chars: readCountChars(chapters[key].c) });
  });
  const keep = readCacheKeep(entries, seq, READ.cacheMaxChars);
  const trimmed = {};
  keep.forEach(function (index) {
    trimmed[String(index)] = chapters[String(index)];
  });
  readStoreWrite(readCacheKey(bookId), JSON.stringify({ v: 1, chapters: trimmed }));
}

/* ============================================================
   第 3 部分：显示
   ============================================================ */

function applyReadStyle() {
  if (!readerEl) return;
  readerEl.style.setProperty('--read-font', READ.fontSizes[readState.fontIndex] + 'px');
  READ.themes.forEach(function (name) {
    if (name) readerEl.classList.remove(name);
  });
  if (READ.themes[readState.themeIndex]) readerEl.classList.add(READ.themes[readState.themeIndex]);
  if (themeBtnEl) themeBtnEl.textContent = '底色：' + READ.themeNames[readState.themeIndex];
  if (fontMinusEl) fontMinusEl.disabled = readState.fontIndex === 0;
  if (fontPlusEl) fontPlusEl.disabled = readState.fontIndex === READ.fontSizes.length - 1;
}

function renderShelf(data) {
  readState.shelf = data.items || [];
  readState.limits = data.limits || {};
  readState.stats = data.stats || {};
  shelfListEl.textContent = '';

  const total = (readState.shelf || []).length;
  readGateEl.classList.toggle('hidden', !!data.items);
  readShelfBodyEl.classList.remove('hidden');
  shelfEmptyEl.classList.toggle('hidden', total > 0);

  // 报的是「书架还剩多少位置」而不是「一个文件最多多少字」：单本多大都不限，
  // 真正会挡住人的是账号总量，所以这一行要把已用和上限一起说清楚
  if (importLimitEl && readState.limits.maxTotalChars) {
    importLimitEl.textContent = '书架总量上限 ' + formatChars(readState.limits.maxTotalChars)
      + ' · 已用 ' + formatChars(readState.stats.chars) + ' · 已存 '
      + data.stats.total + '/' + data.stats.limit + ' 本';
  }

  readState.shelf.forEach(function (book) {
    shelfListEl.appendChild(buildBookCard(book));
  });
}

function buildBookCard(book) {
  const li = document.createElement('li');
  li.className = 'shelf-item';

  const info = document.createElement('div');
  info.className = 'shelf-info';

  const title = document.createElement('b');
  title.className = 'shelf-title';
  title.textContent = book.title;
  info.appendChild(title);

  const meta = document.createElement('span');
  meta.className = 'shelf-meta';
  meta.textContent = book.chapterCount + ' 章 · ' + formatChars(book.charCount)
    + ' · 读到 ' + book.progressPercent + '%'
    + ' · 最近 ' + book.updatedAt;
  info.appendChild(meta);

  const bar = document.createElement('span');
  bar.className = 'shelf-bar';
  const fill = document.createElement('i');
  fill.style.width = book.progressPercent + '%';
  bar.appendChild(fill);
  info.appendChild(bar);

  const actions = document.createElement('div');
  actions.className = 'shelf-actions';

  const open = document.createElement('button');
  open.className = 'btn-mini';
  open.type = 'button';
  open.textContent = book.progressPercent > 0 ? '接着读' : '翻开';
  open.addEventListener('click', function () {
    openBook(book.id);
  });
  actions.appendChild(open);

  const rename = document.createElement('button');
  rename.className = 'btn-mini';
  rename.type = 'button';
  rename.textContent = '改名';
  actions.appendChild(rename);

  const del = document.createElement('button');
  del.className = 'btn-mini danger';
  del.type = 'button';
  del.dataset.label = '删除';
  del.textContent = '删除';
  del.addEventListener('click', function () {
    armConfirm(del, '确认删除？', function () {
      api('novels', 'DELETE', { id: book.id }).then(function () {
        // 本机的离线副本一起清掉：库里删了却在本机留着全文，等于删了个半拉子
        readStoreRemove(readCacheKey(book.id));
        // 正翻开的就是这一本的话把阅读器合上：留着一屏读不到的正文，
        // 用户点「下一章」只会拿到一句 404。这里不存进度——书已经没了
        if (readState.book && readState.book.novel.id === book.id) {
          if (readState.saveTimer) clearTimeout(readState.saveTimer);
          readerEl.classList.add('hidden');
          readState.book = null;
        }
        showAlert('《' + book.title + '》已经从书架上删掉了', 4000);
        return loadShelf();
      }).catch(function (error) {
        showAlert('删除失败：' + error.message, 6000);
      });
    });
  });
  actions.appendChild(del);

  info.appendChild(actions);
  li.appendChild(info);

  // 改名：原地换出一个输入框，不用弹窗（window.prompt 会卡住整个页面线程）
  rename.addEventListener('click', function () {
    if (li.querySelector('.rename-row')) return;
    const row = document.createElement('div');
    row.className = 'rename-row';
    const input = document.createElement('input');
    input.className = 'composer-input';
    input.maxLength = readState.limits.maxTitle || 120;
    input.value = book.title;
    const save = document.createElement('button');
    save.className = 'btn-mini';
    save.type = 'button';
    save.textContent = '保存';
    const cancel = document.createElement('button');
    cancel.className = 'btn-mini';
    cancel.type = 'button';
    cancel.textContent = '取消';
    const tip = document.createElement('span');
    tip.className = 'rename-tip';
    save.addEventListener('click', function () {
      api('novels/update', 'POST', { id: book.id, title: input.value }).then(function () {
        return loadShelf();
      }).catch(function (error) {
        tip.textContent = error.message;
      });
    });
    cancel.addEventListener('click', function () {
      row.remove();
    });
    row.appendChild(input);
    row.appendChild(save);
    row.appendChild(cancel);
    row.appendChild(tip);
    li.appendChild(row);
    input.focus();
    input.select();
  });

  return li;
}

function renderToc(book) {
  readerTocEl.textContent = '';
  (book.toc || []).forEach(function (chapter) {
    const option = document.createElement('option');
    option.value = String(chapter.seq);
    option.textContent = chapter.seq + 1 + '. ' + chapter.title + '（' + chapter.charCount + ' 字）';
    readerTocEl.appendChild(option);
  });
}

function renderChapter(chapter, paragraphs) {
  readerChapterEl.textContent = chapter.title;
  readerTextEl.textContent = '';
  paragraphs.forEach(function (text) {
    const p = document.createElement('p');
    p.textContent = text;
    readerTextEl.appendChild(p);
  });
  readerEmptyEl.classList.add('hidden');

  readState.seq = chapter.seq;
  readState.total = chapter.total;
  setReaderStatus(readState.offline ? '这一章来自本机副本（离线可读）' : '');

  readerPageEl.textContent = (chapter.seq + 1) + ' / ' + chapter.total;
  readerPrevEl.disabled = !chapter.hasPrev;
  readerNextEl.disabled = !chapter.hasNext;
  if (readerTocEl.options.length) readerTocEl.value = String(chapter.seq);
}

/** 章没打开成功时把正文清空：留着上一章的文字，用户会以为已经翻过去了 */
function clearReaderText(message) {
  readerTextEl.textContent = '';
  readerChapterEl.textContent = '';
  readerEmptyEl.textContent = message;
  readerEmptyEl.classList.remove('hidden');
}

function setReaderStatus(text) {
  readerStatusEl.textContent = text || '';
}

/* ============================================================
   第 4 部分：取数
   ============================================================ */

function loadMe() {
  return api('me').then(function (data) {
    readState.me = data && data.logged
      ? { logged: true, username: data.username }
      : { logged: false, username: '' };
    navUser.classList.toggle('hidden', !readState.me.logged);
    if (readLoginLink) readLoginLink.classList.toggle('hidden', readState.me.logged);
    if (readState.me.logged) navUsername.textContent = '你好，' + readState.me.username;
    return readState.me.logged;
  }).catch(function (error) {
    showAlert('后端接口连不上（' + error.message + '）', 8000);
    return false;
  });
}

function loadShelf() {
  if (!readState.me.logged) {
    readGateTextEl.textContent = '登录后才有书架。正文只存进你自己的账号下。';
    readGateEl.classList.remove('hidden');
    readShelfBodyEl.classList.add('hidden');
    return Promise.resolve(null);
  }
  return api('novels').then(function (data) {
    renderShelf(data);
    return data;
  }).catch(function (error) {
    readGateTextEl.textContent = error.message;
    readGateEl.classList.remove('hidden');
    return null;
  });
}

function openBook(id) {
  return api('novel?id=' + encodeURIComponent(id)).then(function (data) {
    readState.book = data;
    readerEl.classList.remove('hidden');
    readerBookEl.textContent = '《' + data.novel.title + '》';
    renderToc(data);
    const chapter = readClamp(data.novel.progressChapter, 0, Math.max(0, data.novel.chapterCount - 1));
    const paragraph = readClamp(data.novel.progressParagraph, 0, 5000);
    readState.lastSaved = { chapter: -1, paragraph: -1 };
    return showChapter(chapter, paragraph);
  }).catch(function (error) {
    showAlert('打不开这本书：' + error.message, 6000);
  });
}

/** 取并显示某一章；paragraph 是要跳到的自然段序号（0 = 开头） */
function showChapter(seq, paragraph) {
  const book = readState.book;
  if (!book) return Promise.resolve(null);
  readState.offline = false;
  return api('novel/chapter?id=' + encodeURIComponent(book.novel.id) + '&seq=' + seq)
    .then(function (data) {
      renderChapter(data.chapter, readParagraphs(data.chapter.content));
      readCacheSave(book.novel.id, data.chapter.seq, data.chapter.title, data.chapter.content);
      jumpToParagraph(paragraph);
      return null;
    })
    .catch(function (error) {
      // 网络/后端不通时翻本机副本：这正是「读过的章节离线也还在」的那一半
      const cached = readCacheLoad(book.novel.id)[String(seq)];
      if (!cached) {
        // 清掉上一 chap 的文字再报错：留着旧正文，用户会以为已经翻过去了
        clearReaderText('这一章没打开：' + error.message);
        return null;
      }
      readState.offline = true;
      const fake = {
        seq: seq,
        title: cached.t,
        total: book.novel.chapterCount,
        hasPrev: seq > 0,
        hasNext: seq < book.novel.chapterCount - 1,
      };
      renderChapter(fake, readParagraphs(cached.c));
      jumpToParagraph(paragraph);
      return null;
    });
}

/**
 * 每段相对「正文容器顶边」的位置。用两个 rect 相减而不是 offsetTop：
 * offsetTop 的参照系是 offsetParent（可能不是这个容器），而 rect 已经带着页面
 * 滚动量——相减之后滚动量正好抵消，得到的是容器内的稳定坐标。
 */
function paragraphBoxes() {
  const kids = readerTextEl.children;
  const boxes = [];
  if (!kids.length) return { boxes: boxes, line: 0 };
  const base = readerTextEl.getBoundingClientRect().top;
  for (let i = 0; i < kids.length; i++) {
    const r = kids[i].getBoundingClientRect();
    boxes.push({ top: r.top - base, bottom: r.bottom - base });
  }
  // 阅读线在视口里是第 READ.readLine 像素，换算到同一参照系
  return { boxes: boxes, line: READ.readLine - base };
}

function currentParagraph() {
  const m = paragraphBoxes();
  if (m.boxes.length === 0) return 0;
  return readVisibleParagraph(m.boxes, m.line);
}

function jumpToParagraph(index) {
  const kids = readerTextEl.children;
  if (!kids.length) return;
  const target = kids[readClamp(index, 0, kids.length - 1)];
  // 多滚 1px 让段落压过阅读线：window.scrollTo 收的是取整后的坐标，而段落自己的 rect 是小数，
  // 于是跳完之后段落顶边可能落在阅读线下方零点几像素处。currentParagraph() 认的是
  // 「顶边到没到阅读线」，差这点就会被算成上一段——刚恢复的进度立刻又被存回去，
  // 表现出来就是「每次接着读都往前退一段」。
  const y = target.getBoundingClientRect().top + window.pageYOffset - READ.readLine + 1;
  window.scrollTo(0, Math.max(0, Math.round(y)));
}

/* ============================================================
   第 5 部分：进度
   ============================================================ */

function saveProgress(chapter, paragraph, force) {
  const book = readState.book;
  if (!book) return;
  if (!force && readState.lastSaved.chapter === chapter && readState.lastSaved.paragraph === paragraph) {
    return;// 没动就不用再发一次：滚动事件一秒能触发几十次
  }
  readState.lastSaved = { chapter: chapter, paragraph: paragraph };
  api('novel/progress', 'POST', { id: book.novel.id, chapter: chapter, paragraph: paragraph })
    .catch(function (error) {
      if (readState.offline) return;// 断网时存不上是预期内的，回线上自然会补
      setReaderStatus('进度没存上：' + error.message);
    });
}

function queueProgress() {
  if (!readState.book || readerEl.classList.contains('hidden')) return;
  if (readState.saveTimer) clearTimeout(readState.saveTimer);
  readState.saveTimer = setTimeout(function () {
    readState.saveTimer = 0;
    saveProgress(readState.seq, currentParagraph(), false);
  }, READ.saveDelay);
}

/* ============================================================
   第 6 部分：交互
   ============================================================ */

/**
 * 读一个文本文件：按 BOM 定编码，UTF-8 严格解不动就退到 GB18030。
 * 成功给 {name, text, encoding, bytes}，失败 reject 一句能直接给用户看的话。
 *
 * 只有第一次尝试带 `{fatal: true}`：不带 fatal 的解码器**永远不会失败**，
 * 认不了的字节一律换成 U+FFFD，于是 GBK 的文件会「成功」地变成满屏问号。
 */
function readTextFile(file, budgetChars) {
  return new Promise(function (resolve, reject) {
    if (!file) {
      reject(new Error('没有选到文件'));
      return;
    }
    const ceiling = readByteCeiling(budgetChars);
    if (ceiling && file.size > ceiling) {
      reject(new Error('这个文件有 ' + formatBytes(file.size) + '，书架上只剩 ' + budgetChars
        + ' 字的位置了（一个字符最多按 4 字节算），所以没去读它：先删掉几本不读的再导'));
      return;
    }
    const reader = new FileReader();
    reader.onerror = function () {
      reject(new Error('文件读不出来：它可能正被别的程序占着，或者你没有读取权限'));
    };
    reader.onload = function () {
      const bytes = new Uint8Array(reader.result);
      // 先验是不是文本，再决定要不要解码：解一份五十兆的二进制只会白占一块内存
      const notText = readLooksBinary(bytes);
      if (notText) {
        reject(new Error(notText));
        return;
      }
      const label = readEncodingLabel(bytes);
      const tries = label === 'utf-8' ? ['utf-8', 'gb18030'] : [label];
      for (let i = 0; i < tries.length; i++) {
        let text;
        try {
          text = new TextDecoder(tries[i], { fatal: i === 0 }).decode(bytes);
        } catch (e) {
          continue;// 这个编码解不了（或者浏览器压根不认识它），换下一个
        }
        resolve({ name: file.name, text: text, encoding: tries[i], bytes: bytes.length });
        return;
      }
      reject(new Error('认不出这个文件的编码（UTF-8 和 GB18030 都试过），'
        + '用记事本另存为时选 UTF-8 再导一次'));
    };
    reader.readAsArrayBuffer(file);
  });
}

/** 把选中的文件与读进来的文字一起丢掉：value 不清，下次选同一个文件就不触发 change 了 */
function clearPick() {
  readState.pick = null;
  if (importFileEl) importFileEl.value = '';
  if (importFileNameEl) importFileNameEl.textContent = '还没选文件';
  if (importCountEl) importCountEl.textContent = '0 字';
  if (importTitleEl) importTitleEl.value = '';
  setMsg(importTipEl, '', '');
}

function bindRead() {
  if (!importSubmitEl) return;

  importFileEl.addEventListener('change', function () {
    const file = importFileEl.files && importFileEl.files[0];
    readState.pick = null;
    importCountEl.textContent = '0 字';
    // 重新选文件就是把上一个结论作废：不清的话，被拒过一次之后再选正常文件，
    // 上面那行红色的「这不是小说正文」会一直留着——字数明明在涨，用户却以为还是没通过
    setMsg(importTipEl, '', '');
    if (!file) {
      importFileNameEl.textContent = '还没选文件';
      return;
    }
    // 先占一行「正在读」：几十万字解起来不是零时间，没有回显看着像点了没反应
    importFileNameEl.textContent = '正在读 ' + file.name + '…';
    readTextFile(file, readRemainingChars(readState.stats, readState.limits)).then(function (pick) {
      readState.pick = pick;
      importCountEl.textContent = formatChars(readCountChars(pick.text));
      importFileNameEl.textContent = pick.name + ' · ' + formatBytes(pick.bytes)
        + ' · 按 ' + pick.encoding + ' 读的';
    }).catch(function (error) {
      clearPick();
      setMsg(importTipEl, error.message, 'warn');
    });
  });

  importClearEl.addEventListener('click', clearPick);

  importSubmitEl.addEventListener('click', function () {
    if (readState.busy) return;
    if (!readState.me.logged) {
      // 按钮保持可点，点了说明原因：禁用掉的按钮等于让用户自己猜为什么不能存
      setMsg(importTipEl, '登录后才能存进书架，点导航栏的「登录」。', 'warn');
      return;
    }
    const pick = readState.pick;
    if (!pick || !pick.text) {
      // 这一条同时管着「还没选文件」和「文件在读 / 读失败了」两种情况
      setMsg(importTipEl, '先选一个 .txt 文件，等它把文件名和字数列出来再存。', 'warn');
      return;
    }
    readState.busy = true;
    importSubmitEl.textContent = '正在认章节…';
    api('novels', 'POST', { title: importTitleEl.value, text: pick.text, filename: pick.name })
      .then(function (data) {
        setMsg(importTipEl, '认出了 ' + data.novel.chapterCount + ' 章。', 'ok');
        clearPick();
        return loadShelf().then(function () {
          return openBook(data.novel.id);
        });
      }).catch(function (error) {
        setMsg(importTipEl, error.message, 'warn');
      }).then(function () {
        readState.busy = false;
        importSubmitEl.textContent = '存进书架';
      });
  });

  readerCloseEl.addEventListener('click', function () {
    saveProgress(readState.seq, currentParagraph(), true);
    readerEl.classList.add('hidden');
    readState.book = null;
    window.scrollTo(0, 0);
  });

  readerPrevEl.addEventListener('click', function () {
    if (readState.seq > 0) turnTo(readState.seq - 1);
  });
  readerNextEl.addEventListener('click', function () {
    if (readState.seq < readState.total - 1) turnTo(readState.seq + 1);
  });
  readerTocEl.addEventListener('change', function () {
    turnTo(readClamp(readerTocEl.value, 0, Math.max(0, readState.total - 1)));
  });

  fontMinusEl.addEventListener('click', function () {
    readState.fontIndex = readClamp(readState.fontIndex - 1, 0, READ.fontSizes.length - 1);
    applyReadStyle();
    savePrefs();
  });
  fontPlusEl.addEventListener('click', function () {
    readState.fontIndex = readClamp(readState.fontIndex + 1, 0, READ.fontSizes.length - 1);
    applyReadStyle();
    savePrefs();
  });
  themeBtnEl.addEventListener('click', function () {
    readState.themeIndex = (readState.themeIndex + 1) % READ.themes.length;
    applyReadStyle();
    savePrefs();
  });

  function turnTo(seq) {
    saveProgress(readState.seq, currentParagraph(), true);
    showChapter(seq, 0);
  }

  window.addEventListener('scroll', queueProgress, { passive: true });

  // 左右方向键翻页；输入框里的左右键是移光标，不能抢
  document.addEventListener('keydown', function (event) {
    if (readerEl.classList.contains('hidden')) return;
    const tag = (event.target && event.target.tagName) || '';
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
    if (event.key === 'Escape') {
      disarmConfirm();
      return;
    }
    if (event.key === 'ArrowLeft' && readState.seq > 0) {
      event.preventDefault();
      turnTo(readState.seq - 1);
    }
    if (event.key === 'ArrowRight' && readState.seq < readState.total - 1) {
      event.preventDefault();
      turnTo(readState.seq + 1);
    }
  });

  // 关掉标签页 / 切走时补存一次：scroll 的防抖还没来得及跑
  window.addEventListener('pagehide', function () {
    if (readState.saveTimer) clearTimeout(readState.saveTimer);
    saveProgress(readState.seq, currentParagraph(), true);
  });
}

function readBoot() {
  loadPrefs();
  applyReadStyle();
  bindRead();
  setReaderStatus('');
  loadMe().then(function (logged) {
    if (!logged) {
      readGateEl.classList.remove('hidden');
      return null;
    }
    return loadShelf();
  });

  if (visitCountEl) {
    api('visit', 'POST', {}).then(function (stats) {
      if (visitCountEl) visitCountEl.textContent = String(stats.totalVisits);
    }).catch(function () {
      if (visitCountEl) visitCountEl.textContent = '未知';
    });
  }
}
readBoot();

/* 规则自测：只测上面那些不碰 DOM 的函数 */
function readerSelfTest() {
  const assert = function (cond, message) {
    if (!cond) throw new Error(message);
  };

  // 字数与分段
  assert(readCountChars('甲乙 丙\n丁') === 4, '字数不把空格和换行算进去');
  assert(readCountChars('') === 0, '空文本是 0 字');
  assert(readCountChars(undefined) === 0, '没给值也不能抛');
  const parts = readParagraphs('第一段。\n\n  第二段。  \n第三段。');
  assert(parts.length === 3, '按换行切成自然段，空行不留下空段');
  assert(parts[1] === '第二段。', '每段两头空白被去掉');
  assert(readParagraphs(null).length === 0, '正文缺失时给空数组而不是崩');

  // 数字与百分比
  assert(readClamp('5', 0, 3) === 3, '超上限被夹住');
  assert(readClamp('abc', 0, 3) === 0, '不是数字退回下限');
  assert(readClamp(-2, 0, 3) === 0, '负数不会往下跑');
  assert(readPercent(0, 0) === 0, '一章都没有时是 0%，不能是 NaN');
  assert(readPercent(1, 4) === 25, '四分之一就是 25%');
  assert(readPercent(9, 4) === 100, '越界也不会写成 225%');

  // 阅读线取哪一段
  const boxes = [{ top: 0, bottom: 40 }, { top: 40, bottom: 80 }, { top: 80, bottom: 120 }];
  assert(readVisibleParagraph(boxes, 0) === 0, '线在最上面时是第 0 段');
  assert(readVisibleParagraph(boxes, 100) === 2, '线落到第三段顶边之后就是第 2 段');
  assert(readVisibleParagraph(boxes, -50) === 0, '线在容器上方也不会跑出负数');
  assert(readVisibleParagraph([], 10) === 0, '一段都没有时给 0');

  // 离线副本留哪几章
  const entries = [{ seq: 0, chars: 100 }, { seq: 1, chars: 100 }, { seq: 2, chars: 100 }, { seq: 3, chars: 100 }];
  const kept = readCacheKeep(entries, 2, 250);
  assert(kept.join(',') === '1,2', '装不下时留下离当前章最近的，而不是留下开头的');
  assert(kept[kept.length - 1] === 2, '当前章一定在保留列表里（副本要能立刻用上）');
  assert(readCacheKeep(entries, 0, 500).join(',') === '0,1,2,3', '配额够就全留');
  assert(readCacheKeep(entries, 3, 0).join(',') === '3', '配额是 0 也至少留住当前这一章');

  // 选文件导入：先认编码，再挡大小
  assert(readEncodingLabel([0xE7, 0xAC, 0xAC, 0xE4, 0xB8, 0x80]) === 'utf-8',
    '没有 BOM 的先按 UTF-8 试');
  assert(readEncodingLabel([0xFF, 0xFE, 0x00, 0x01]) === 'utf-16le', 'FF FE 是 UTF-16 小端');
  assert(readEncodingLabel([0xFE, 0xFF, 0x00, 0x01]) === 'utf-16be', 'FE FF 是 UTF-16 大端');
  assert(readEncodingLabel([0xEF, 0xBB, 0xBF, 0x41]) === 'utf-8',
    'UTF-8 自己的 BOM 不能被认成 UTF-16：那两个字节不是 FF FE 也不是 FE FF');
  assert(readEncodingLabel([]) === 'utf-8', '空文件也不能崩');
  assert(readByteCeiling(500000) === 2000000, '一个字符最多四个字节，就是这条上限的根据');
  assert(readByteCeiling(0) === 0, '没拿到上限就不预判，别把每个文件都挡在门外');
  assert(readByteCeiling('abc') === 0, '上限不是数字时同样不预判');
  assert(formatBytes(512) === '512 B', '小文件按字节报');
  assert(formatBytes(2048) === '2 KB', '2048 字节是 2 KB');
  assert(formatBytes(1572864) === '1.5 MB', '一兆半写成 1.5 MB，不甩一串大数字');
  assert(formatBytes(null) === '0 B', '没给数也不能是 NaN');

  // 「这是不是文本」的本地预判（权威那一遍在后端，这里只是不让人白传几十兆）
  assert(readLooksBinary(new Uint8Array([0xE7, 0xAC, 0xAC, 0xE4, 0xB8, 0x80, 0x0A])) === '',
    '正常中文带个换行：不判成二进制');
  assert(readLooksBinary(new Uint8Array([])) === '', '空文件不在这里判，交给「太短了不像一本书」那句');
  assert(readLooksBinary(new Uint8Array([0x4D, 0x5A, 0x90, 0x00, 0x03])) !== '',
    'MZ 打头的是 exe：当下就说清它是什么，不用等一次往返');
  assert(readLooksBinary(new Uint8Array([0x50, 0x4B, 0x03, 0x04, 0x14])) !== '',
    'PK\\x03\\x04 是 zip：docx / epub / apk 换的什么皮都逃不掉这个头');
  assert(readLooksBinary(new Uint8Array([0x41, 0x42, 0x00, 0x43])) !== '',
    '文本中间夹一个 \\0 也拦：txt 里不该有 NUL');
  const dense = new Uint8Array(200);
  for (let i = 0; i < dense.length; i++) dense[i] = i % 2 ? 0x01 : 0x02;
  assert(readLooksBinary(dense) !== '', '整屏全是控制字符：这不是小说');
  const paged = [];
  for (let i = 0; i < 2000; i++) paged.push(i % 400 === 0 ? 0x0C : 0x41);
  assert(readLooksBinary(new Uint8Array(paged)) === '',
    '老 txt 每隔几行留一个换页符（\\x0C）不算二进制：按占比判，不按「有没有」判');

  // 天花板按「书架还剩多少字」算，不是按单本上限算
  assert(readRemainingChars({ chars: 500 }, { maxTotalChars: 5000 }) === 4500, '配额减去已用就是还能塞多少');
  assert(readRemainingChars({}, { maxTotalChars: 5000 }) === 5000, '已用读不到就按整个配额算，别把用户挡在门外');
  assert(readRemainingChars({ chars: 6000 }, { maxTotalChars: 5000 }) === 0, '已经超了配额就是 0，让后端去说清楚');
  assert(readRemainingChars({ chars: 1 }, {}) === 0, '没拿到配额就返回 0：不预判，交给后端');
}
SMOKE_TESTS.push(readerSelfTest);
