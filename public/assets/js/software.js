/* ============================================================
   软件仓库二级页面（/software）的脚本

   两个值得记住的实现选择：

   1. 只发一次 GET，筛选、搜索、排序、分组全在浏览器里做。
      这张表的上限是配置里的 soft_max_count（默认 300 款），一次拉完也就几十 KB；
      换成「每点一个筛选就打一次接口」，侧栏上那些「这一类有几款」就得让后端
      为每种组合各算一遍——要么算成七次请求，要么算成一个谁都看不懂的 SQL。
      一次拉完，两边的数还天然一致（不会出现「侧栏说 8 款、点进去 7 款」）。

   2. 访客与管理员吃的是两个接口，页面结构完全一样。
      GET /api/software 公开、且结果不随身份变化；GET /api/admin/software 多出
      下架条目、抓包细节与配额。用哪个由 /api/me 的 role 决定——但这一层只是
      「少摆一组用不着的控件」，真正的边界在接口那一头：写接口一律 require_admin。

   外链一律不过滤就印上 href 等于把「点这张卡片会发生什么」交给数据库里那串文本，
   所以所有链接都过 softSafeHref()：只认 http(s) 本站接口，别的宁可少一个按钮。
   ============================================================ */

/** 这一页的全部状态 */
const softState = {
  me: { logged: false, username: '', role: '' },
  isAdmin: false,
  items: [],
  facets: { categories: [], platforms: [], tags: [] },
  stats: { total: 0, categories: 0, directFiles: 0 },
  labels: {},
  fetchEnabled: true,
  maxFileBytes: 0,
  quotaBytes: 0,
  quota: null,// 只有管理员的响应里有：{ usedBytes, limitBytes, fileCount }
  filter: { category: '', platform: '', tag: '', q: '', sort: 'default', view: 'grid' },
  tagsOpen: false,
  editing: null,// 正在编辑的条目（列表里那一份对象），null 表示新增
  busy: false,
};

/* ---------- 元素（id 全部对得上 public/software.html） ---------- */
const softStatsEl = el('soft-stats');
const softShownEl = el('soft-stat-shown');
const softCatsEl = el('soft-cats');
const softPlatformsEl = el('soft-platforms');
const softTagsEl = el('soft-tags');
const softTagMoreEl = el('soft-tag-more');
const softSearchEl = el('soft-search');
const softSortEl = el('soft-sort');
const softViewEl = el('soft-view');
const softTipEl = el('soft-tip');
const softListEl = el('soft-list');
const softEmptyEl = el('soft-empty');

const softAdminEl = el('soft-admin');
const softFetchStateEl = el('soft-fetch-state');
const softQuotaCountEl = el('soft-quota-count');
const softQuotaUsedEl = el('soft-quota-used');
const softQuotaLimitEl = el('soft-quota-limit');
const softQuotaFillEl = el('soft-quota-fill');
const softEditingEl = el('soft-editing');
const softIconBoxEl = el('soft-icon-box');
const softIconImgEl = el('soft-icon-img');
const softIconLetterEl = el('soft-icon-letter');
const softIconFileEl = el('soft-icon-file');
const softIconFetchEl = el('soft-icon-fetch');
const softIconClearEl = el('soft-icon-clear');
const softIconTipEl = el('soft-icon-tip');
const softPlatformBoxEl = el('soft-platform-box');
const softCatListEl = el('soft-cat-list');
const softFormTipEl = el('soft-form-tip');
const softIdentifyEl = el('soft-identify');
const softGrabEl = el('soft-grab');
const softReleaseEl = el('soft-release');
const softDeleteEl = el('soft-delete');
const softSaveEl = el('soft-save');
const softResetEl = el('soft-reset');

/** 表单里的输入框：按字段的「接口键名」取用，读写表单时就不用一个一个手写 id */
const softFields = {
  name: 'soft-f-name',
  slug: 'soft-f-slug',
  category: 'soft-f-category',
  version: 'soft-f-version',
  license: 'soft-f-license',
  starCount: 'soft-f-stars',
  sortOrder: 'soft-f-sort',
  tags: 'soft-f-tags',
  description: 'soft-f-desc',
  githubUrl: 'soft-f-github',
  giteeUrl: 'soft-f-gitee',
  homepage: 'soft-f-home',
  downloadUrl: 'soft-f-dl',
  sourceMode: 'soft-f-mode',
};
const softEnabledEl = el('soft-f-enabled');

const navUser = el('nav-user');
const navUsername = el('nav-username');
const visitCountEl = el('visit-count');
const softLoginLink = el('soft-login-link');

/* ============================================================
   第 1 部分：纯规则（不碰 DOM，规则自测就测这一组）
   ============================================================ */

/** 本站那两个二进制接口的地址长什么样：只认这一个形状，别的一律当不合法 */
const SOFT_SELF_API = /^\/api\/software\/(?:file|icon)\?id=\d+$/;

/**
 * 能印上 href 的地址：http(s)，或本站那两个接口。
 *
 * 后端存进去之前已经过 software_url() 校验，这里再拦一道是为了
 * 「库里有一条老数据 / 手工塞的数据」时页面仍然不会多一个会执行脚本的链接。
 * 认不出来就返回 null——宁可少一个按钮，也不要一个点了会跳 javascript: 的链接。
 */
function softSafeHref(url) {
  const value = String(url === undefined || url === null ? '' : url).trim();
  if (value === '') return null;
  if (SOFT_SELF_API.test(value)) return value;
  return /^https?:\/\/[^\s]+$/i.test(value) ? value : null;
}

/** 一个条目用来被搜索的全部文字：名称、分类、简介、版本、协议与标签 */
function softHaystack(item) {
  const parts = [item.name, item.category, item.description, item.version, item.license];
  return parts.concat(item.tags || []).join(' ').toLowerCase();
}

/** 这一款挂在哪个标签组下：只看第一个标签，一款只进一组，「当前展示」才对得上总数 */
function softTagOf(item) {
  const tags = item.tags || [];
  return tags.length ? String(tags[0]) : '未标签';
}

/** 搜索 / 筛选：查询词按空格切开，各词之间是「并且」 */
function softMatch(item, filter) {
  if (filter.category && item.category !== filter.category) return false;
  if (filter.platform) {
    const codes = (item.platforms || []).map(function (p) { return p.code; });
    if (codes.indexOf(filter.platform) < 0) return false;
  }
  if (filter.tag) {
    const hit = (item.tags || []).some(function (t) {
      return String(t).toLowerCase() === filter.tag.toLowerCase();
    });
    if (!hit) return false;
  }
  const words = String(filter.q || '').trim().toLowerCase().split(/\s+/).filter(Boolean);
  if (!words.length) return true;
  const hay = softHaystack(item);
  return words.every(function (w) { return hay.indexOf(w) >= 0; });
}

/** 可能为空的整数：空值一律当「没有这一栏」，排序时沉到最后而不是当成 0 */
function softIntOrNull(value) {
  if (value === null || value === undefined || value === '') return null;
  const n = parseInt(value, 10);
  return isNaN(n) ? null : n;
}

/** 排序：default 保持后端给的定义顺序，其余几种都在本地重排 */
function softSort(items, mode) {
  const out = items.slice();
  const desc = function (key) {
    return function (a, b) {
      const av = softIntOrNull(a[key]);
      const bv = softIntOrNull(b[key]);
      if (av === null && bv === null) return 0;
      if (av === null) return 1;// 没有数的永远排在后面
      if (bv === null) return -1;
      return bv - av;
    };
  };
  if (mode === 'name') {
    out.sort(function (a, b) { return String(a.name).localeCompare(String(b.name), 'zh'); });
  } else if (mode === 'stars') {
    out.sort(desc('starCount'));
  } else if (mode === 'size') {
    out.sort(desc('fileBytes'));
  } else if (mode === 'group-tag') {
    out.sort(function (a, b) {
      const at = softTagOf(a);
      const bt = softTagOf(b);
      if (at === '未标签' || bt === '未标签') return at === bt ? 0 : (at === '未标签' ? 1 : -1);
      const byTag = at.localeCompare(bt, 'zh');
      return byTag !== 0 ? byTag : String(a.name).localeCompare(String(b.name), 'zh');
    });
  }
  return out;
}

/** 按标签分组：组按标签名排，「未标签」永远垫底 */
function softGroup(items) {
  const groups = [];
  const index = {};
  items.forEach(function (item) {
    const tag = softTagOf(item);
    if (!(tag in index)) {
      index[tag] = groups.length;
      groups.push({ tag: tag, items: [] });
    }
    groups[index[tag]].items.push(item);
  });
  groups.sort(function (a, b) {
    if (a.tag === '未标签' || b.tag === '未标签') return a.tag === b.tag ? 0 : (a.tag === '未标签' ? 1 : -1);
    return a.tag.localeCompare(b.tag, 'zh');
  });
  return groups;
}

/** star 数说成人话：卡片那一角放不下 12345 这种数 */
function softStars(value) {
  const n = softIntOrNull(value);
  if (n === null || n < 0) return '';
  if (n < 1000) return String(n);
  if (n < 1000000) return (Math.round(n / 100) / 10).toFixed(1) + 'k';
  return (Math.round(n / 100000) / 10).toFixed(1) + 'M';
}

/* ============================================================
   第 2 部分：渲染
   ============================================================ */

function softEl(tag, className, text) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined && text !== null) node.textContent = String(text);
  return node;
}

/** 把 stats 里的三个数写进页面上所有 [data-stat] 的位置（表头与侧栏共用同一份数） */
function softFillStats() {
  const values = {
    total: softState.stats.total,
    categories: softState.stats.categories,
    directFiles: softState.stats.directFiles,
  };
  document.querySelectorAll('[data-stat]').forEach(function (node) {
    const key = node.getAttribute('data-stat') || node.dataset.stat;
    if (key && values[key] !== undefined) node.textContent = String(values[key]);
  });
}

/** 侧栏：全部分类 + 每个分类有几款 */
function softRenderCats() {
  if (!softCatsEl) return;
  softCatsEl.textContent = '';
  const rows = [{ name: '', count: softState.stats.total, label: '全部分类' }]
    .concat(softState.facets.categories.map(function (c) {
      return { name: c.name, count: c.count, label: c.name };
    }));
  rows.forEach(function (row) {
    const li = softEl('li');
    const button = softEl('button', 'soft-cat' + (row.name === softState.filter.category ? ' active' : ''), row.label);
    button.type = 'button';
    button.appendChild(softEl('span', 'soft-cat-count', row.count));
    button.dataset.cat = row.name;
    button.addEventListener('click', function () {
      softState.filter.category = softState.filter.category === row.name ? '' : row.name;
      softRender();
    });
    li.appendChild(button);
    softCatsEl.appendChild(li);
  });
}

/** 平台筛选条：chip 后面那个数是「这个平台有几款」 */
function softRenderPlatforms() {
  if (!softPlatformsEl) return;
  softPlatformsEl.textContent = '';
  softState.facets.platforms.forEach(function (p) {
    const chip = softEl('button', 'chip' + (p.code === softState.filter.platform ? ' active' : ''),
      p.label);
    chip.type = 'button';
    chip.appendChild(softEl('span', 'chip-count', p.count));
    chip.addEventListener('click', function () {
      softState.filter.platform = softState.filter.platform === p.code ? '' : p.code;
      softRender();
    });
    softPlatformsEl.appendChild(chip);
  });
}

/** 标签条：默认只露前 12 个，「展开全清单」才全给 */
function softRenderTags() {
  if (!softTagsEl) return;
  softTagsEl.textContent = '';
  const all = softState.facets.tags;
  const shown = softState.tagsOpen ? all : all.slice(0, 12);
  shown.forEach(function (t) {
    const chip = softEl('button', 'chip soft-tag' + (t.name === softState.filter.tag ? ' active' : ''),
      '#' + t.name);
    chip.type = 'button';
    chip.appendChild(softEl('span', 'chip-count', t.count));
    chip.addEventListener('click', function () {
      softState.filter.tag = softState.filter.tag === t.name ? '' : t.name;
      softRender();
    });
    softTagsEl.appendChild(chip);
  });
  if (softTagMoreEl) {
    const rest = all.length - shown.length;
    softTagMoreEl.classList.toggle('hidden', all.length <= 12);
    softTagMoreEl.textContent = softState.tagsOpen ? '收起清单' : '展开全清单（还有 ' + rest + ' 个）';
  }
}

/** 卡片上的图标：有本地图标就用接口地址，没有就用首字母占位 */
function softCardIcon(item) {
  const box = softEl('span', 'soft-card-icon');
  const href = softSafeHref(item.iconUrl);
  if (href) {
    const img = softEl('img', 'soft-card-img');
    img.src = href;
    img.alt = '';
    img.loading = 'lazy';
    box.appendChild(img);
    return box;
  }
  box.appendChild(softEl('b', 'soft-card-letter', String(item.name || '?').slice(0, 1)));
  return box;
}

/** 一行外链按钮；地址认不出来就干脆不画这个按钮 */
function softLinkButton(label, url, extraClass) {
  const href = softSafeHref(url);
  if (!href) return null;
  const a = softEl('a', extraClass || 'btn-mini', label);
  a.href = href;
  a.target = '_blank';
  // nofollow：这些是别人家的站点，不该由本站替它们做信誉背书
  a.rel = 'noopener noreferrer nofollow';
  return a;
}

function softCard(item) {
  const card = softEl('article', 'soft-card');
  card.id = String(item.anchor || 'soft-' + item.id);
  // enabled 只有管理清单里才有：访客那份根本不含下架条目，不能拿「没有这个键」当「已下架」
  if (item.enabled === false) card.classList.add('is-off');

  const head = softEl('div', 'soft-card-head');
  head.appendChild(softCardIcon(item));
  const titles = softEl('div', 'soft-card-titles');
  const name = softEl('h3', 'soft-card-name', item.name);
  const stars = softStars(item.starCount);
  if (stars) name.appendChild(softEl('b', 'soft-card-stars', '★ ' + stars));
  titles.appendChild(name);
  titles.appendChild(softEl('span', 'soft-card-cat', item.category));
  head.appendChild(titles);
  card.appendChild(head);

  if ((item.tags || []).length) {
    const tags = softEl('div', 'soft-card-tags');
    item.tags.forEach(function (tag) {
      tags.appendChild(softEl('span', 'soft-card-tag', '#' + tag));
    });
    card.appendChild(tags);
  }

  if ((item.platforms || []).length) {
    card.appendChild(softEl('p', 'soft-card-plat', item.platforms.map(function (p) {
      return p.label;
    }).join(' · ')));
  }
  if (item.description) {
    // 只写 textContent：简介是管理员填的自由文本，不能当 HTML 解析
    card.appendChild(softEl('p', 'soft-card-desc', item.description));
  }

  const meta = [];
  if (item.version) meta.push('v' + String(item.version).replace(/^v/i, ''));
  if (item.hasFile && item.fileBytes) meta.push(formatBytes(item.fileBytes));
  else if (item.sizeBytes) meta.push(formatBytes(item.sizeBytes));
  if (item.license) meta.push(item.license);
  card.appendChild(softEl('p', 'soft-card-meta', meta.length ? meta.join(' · ') : '—'));

  const actions = softEl('div', 'soft-card-actions');
  const file = softSafeHref(item.fileUrl);
  if (file) {
    const a = softEl('a', 'btn-primary', '下载');
    a.href = file;
    if (item.fileName) a.setAttribute('download', item.fileName);
    actions.appendChild(a);
    actions.appendChild(softEl('span', 'soft-card-note', '服务器直链'));
  } else {
    const out = softLinkButton('去下载', item.downloadUrl, 'btn-primary');
    if (out) actions.appendChild(out);
  }
  const github = softLinkButton('GitHub', item.githubUrl);
  if (github) actions.appendChild(github);
  const home = softLinkButton('官网', item.homepage);
  if (home) actions.appendChild(home);
  if (item.hasFile) actions.appendChild(softEl('span', 'soft-card-note', '本地包'));

  if (softState.isAdmin) {
    const edit = softEl('button', 'btn-mini', '编辑');
    edit.type = 'button';
    edit.addEventListener('click', function () { softEdit(item.id); });
    actions.appendChild(edit);
    const toggle = softEl('button', 'btn-mini', item.enabled ? '下架' : '上架');
    toggle.type = 'button';
    toggle.addEventListener('click', function () { softToggle(item.id, item.enabled); });
    actions.appendChild(toggle);
  }
  card.appendChild(actions);
  return card;
}

function softRenderList(items) {
  if (!softListEl) return;
  const className = softState.filter.view === 'list' ? 'soft-list' : 'soft-grid';
  softListEl.textContent = '';
  if (softState.filter.sort === 'group-tag') {
    softGroup(items).forEach(function (group) {
      const section = softEl('section', 'soft-group');
      section.appendChild(softEl('h3', 'soft-group-title', '#' + group.tag
        + '（' + group.items.length + '）'));
      const inner = softEl('div', className);
      group.items.forEach(function (item) { inner.appendChild(softCard(item)); });
      section.appendChild(inner);
      softListEl.appendChild(section);
    });
    return;
  }
  const grid = softEl('div', className);
  items.forEach(function (item) { grid.appendChild(softCard(item)); });
  softListEl.appendChild(grid);
}

function softRender() {
  const items = softSort(
    softState.items.filter(function (item) { return softMatch(item, softState.filter); }),
    softState.filter.sort
  );
  softFillStats();
  softRenderCats();
  softRenderPlatforms();
  softRenderTags();
  softRenderList(items);
  if (softShownEl) softShownEl.textContent = String(items.length);
  if (softEmptyEl) softEmptyEl.classList.toggle('hidden', items.length > 0);
  // 三个筛选条件一个都没剩时，「清空筛选」这个出口就不该留着误导人
  const dirty = softState.filter.category || softState.filter.platform || softState.filter.tag
    || softState.filter.q || softState.filter.sort !== 'default';
  if (softTagMoreEl && !dirty) softTipClear();
}

function softTipClear() {
  if (softTipEl && softTipEl.classList.contains('error')) setMsg(softTipEl, '', '');
}

/* ============================================================
   第 3 部分：后台（编辑器、图标、抓包）
   ============================================================ */

/** 只在这一次请求期间把那几个会改数据的按钮按住：抓包是同步的，重复点会排第二发 */
function softBusy(on, message) {
  softState.busy = !!on;
  [softSaveEl, softGrabEl, softReleaseEl, softDeleteEl, softIdentifyEl,
    softIconFetchEl, softIconClearEl].forEach(function (button) {
      if (button) button.disabled = softState.busy;
    });
  if (on && message) setMsg(softFormTipEl, message, 'ok');
}

function softRenderQuota() {
  if (!softAdminEl) return;
  if (softFetchStateEl) {
    softFetchStateEl.textContent = softState.fetchEnabled ? '已开启' : '已停用（soft_fetch_enabled=false）';
  }
  const quota = softState.quota || { usedBytes: 0, limitBytes: softState.quotaBytes, fileCount: 0 };
  if (softQuotaCountEl) softQuotaCountEl.textContent = String(quota.fileCount);
  if (softQuotaUsedEl) softQuotaUsedEl.textContent = formatBytes(quota.usedBytes);
  if (softQuotaLimitEl) {
    softQuotaLimitEl.textContent = formatBytes(quota.limitBytes)
      + '（单包上限 ' + formatBytes(softState.maxFileBytes) + '）';
  }
  if (softQuotaFillEl) {
    const ratio = quota.limitBytes > 0 ? quota.usedBytes / quota.limitBytes : 0;
    softQuotaFillEl.style.width = Math.min(100, Math.round(ratio * 100)) + '%';
  }
}

/** 平台多选：候选来自后端那张 label 表，不在前端另写一份 */
function softRenderPlatformBox() {
  if (!softPlatformBoxEl) return;
  softPlatformBoxEl.textContent = '';
  Object.keys(softState.labels).forEach(function (code) {
    const chip = softEl('button', 'chip', softState.labels[code]);
    chip.type = 'button';
    chip.dataset.code = code;
    chip.addEventListener('click', function () { chip.classList.toggle('active'); });
    softPlatformBoxEl.appendChild(chip);
  });
}

function softCheckedPlatforms() {
  const picked = [];
  if (!softPlatformBoxEl) return picked;
  softPlatformBoxEl.querySelectorAll('.chip.active').forEach(function (chip) {
    if (chip.dataset.code) picked.push(chip.dataset.code);
  });
  return picked;
}

function softFillCategoryList() {
  if (!softCatListEl) return;
  softCatListEl.textContent = '';
  softState.facets.categories.forEach(function (c) {
    const option = softEl('option', '', c.name);
    option.value = c.name;
    softCatListEl.appendChild(option);
  });
}

/** 表单 → 请求体。按字段名读写，新增与修改共用同一份 */
function softReadForm() {
  const body = {};
  Object.keys(softFields).forEach(function (key) {
    const node = el(softFields[key]);
    if (!node) return;
    body[key] = String(node.value || '').trim();
  });
  body.platforms = softCheckedPlatforms();
  body.enabled = !!(softEnabledEl && softEnabledEl.checked);
  // number 输入框清空时是 ''：这一栏要传 null 才是「不知道」，传 '' 后端会当没填
  if (body.starCount === '') body.starCount = null;
  return body;
}

function softFillForm(item) {
  Object.keys(softFields).forEach(function (key) {
    const node = el(softFields[key]);
    if (!node) return;
    const value = item && item[key] !== undefined && item[key] !== null ? item[key] : '';
    node.value = String(value);
  });
  if (softEnabledEl) softEnabledEl.checked = item ? !!item.enabled : true;
  if (softPlatformBoxEl) {
    const codes = item ? (item.platforms || []).map(function (p) { return p.code; }) : [];
    softPlatformBoxEl.querySelectorAll('.chip').forEach(function (chip) {
      chip.classList.toggle('active', codes.indexOf(chip.dataset.code) >= 0);
    });
  }
  softRenderIcon(item);
  softEditingEl.textContent = item
    ? '正在编辑「' + item.name + '」（id ' + item.id + '）'
    : '正在新增一款（还没有 id）';
}

function softRenderIcon(item) {
  if (!softIconBoxEl) return;
  const href = item ? softSafeHref(item.iconUrl) : null;
  if (softIconImgEl) {
    softIconImgEl.classList.toggle('hidden', !href);
    if (href) softIconImgEl.src = href;
    else softIconImgEl.removeAttribute('src');
  }
  if (softIconLetterEl) {
    softIconLetterEl.textContent = item && item.name ? String(item.name).slice(0, 1) : '?';
    softIconLetterEl.classList.toggle('hidden', !!href);
  }
  if (softIconTipEl) {
    setMsg(softIconTipEl, item && item.iconKind
      ? '当前图标：' + item.iconKind + '（格式按文件内容认的，与文件名无关）' : '', '');
  }
}

function softSelect(id) {
  const item = softState.items.filter(function (row) { return row.id === id; })[0];
  if (!item) return null;
  softState.editing = item;
  softFillForm(item);
  return item;
}

function softEdit(id) {
  const item = softSelect(id);
  if (!item) return;
  if (softAdminEl) softAdminEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
  setMsg(softFormTipEl, '已把「' + item.name + '」填进上面的表单。', 'ok');
}

function softNew() {
  softState.editing = null;
  softFillForm(null);
}

function softApplyList(list) {
  if (!list || !Array.isArray(list.items)) return;
  softState.items = list.items;
  softState.facets = list.facets || softState.facets;
  softState.stats = list.stats || softState.stats;
  softState.labels = list.platformLabels || softState.labels;
  softState.fetchEnabled = list.fetchEnabled !== false;
  softState.maxFileBytes = parseInt(list.maxFileBytes, 10) || 0;
  softState.quotaBytes = parseInt(list.quotaBytes, 10) || 0;
  softState.quota = list.quota || null;
  softState.isAdmin = !!list.manageable;
  if (softAdminEl) softAdminEl.classList.toggle('hidden', !softState.isAdmin);
  softRender();
  softRenderQuota();
  softFillCategoryList();
  // 首次进入时 boot() 里还没有 label 表（它随清单才回来），这一栏当时是空的。
  // 现在拿到名字了就补一次——只在空着的时候补，免得保存一次就把管理员勾好的平台清掉。
  if (softPlatformBoxEl && !softPlatformBoxEl.children.length) softRenderPlatformBox();
  if (softState.editing) {
    const again = softSelect(softState.editing.id);
    if (!again) softNew();// 编辑的对象被别的窗口删掉了：表单退回新增，不拿半个条目去保存
  }
}

/** 统一处理这几个后台动作共用的收尾：成功刷新清单、失败把后端的理由原样贴出来 */
function softDone(promise, okMessage, tipEl) {
  return promise.then(function (data) {
    softApplyList(data.list);
    setMsg(tipEl || softFormTipEl, okMessage, 'ok');
    return data;
  }).catch(function (error) {
    setMsg(tipEl || softFormTipEl, error.message, 'error');
    showAlert('软件仓库：' + error.message, 8000);
    return null;
  }).then(function (data) {
    softBusy(false);
    return data;
  });
}

function softSave() {
  if (softState.busy) return;
  const body = softReadForm();
  const id = softState.editing ? softState.editing.id : 0;
  softBusy(true, '正在保存…');
  const request = id
    ? api('admin/software/update', 'POST', Object.assign({ id: id }, body))
    : api('admin/software', 'POST', body);
  return softDone(request, id ? '已保存。' : '已新增，现在可以点「服务器代下载」了。').then(function (data) {
    if (data && data.software) softSelect(data.software.id);// 表单跟上后端清洗过的结果
  });
}

function softToggle(id, enabled) {
  if (softState.busy) return;
  softBusy(true, enabled ? '正在下架…' : '正在上架…');
  return softDone(api('admin/software/update', 'POST', { id: id, enabled: !enabled }),
    enabled ? '已下架：访客看不到这一款了，安装包与图标都留着。' : '已上架。');
}

function softGrab() {
  if (softState.busy) return;
  if (!softState.editing) {
    setMsg(softFormTipEl, '先保存这一款，再让服务器去抓包（抓回来的东西按 id 落盘）。', 'warn');
    return;
  }
  const id = softState.editing.id;
  // 同步出网：后端要等远端读完才回话，这一条按 CORE.slowApiTimeout 等，不按默认的五秒
  softBusy(true, '正在由服务器代下载，可能要几十秒…');
  return softDone(api('admin/software/grab', 'POST', { id: id }, CORE.slowApiTimeout), '已抓到本机。')
    .then(function (data) {
      if (!data) return;
      setMsg(softFormTipEl, '抓好了：' + formatBytes(data.bytes) + '，走了 ' + data.seconds
        + ' 秒，来源是「' + data.via + '」。摘要 ' + String(data.sha256).slice(0, 12) + '…', 'ok');
    });
}

function softRelease() {
  if (softState.busy) return;
  if (!softState.editing) {
    setMsg(softFormTipEl, '还没选中要释放哪一款。', 'warn');
    return;
  }
  softBusy(true, '正在删除本地安装包…');
  return softDone(api('admin/software/release', 'POST', { id: softState.editing.id }),
    '已不再由本站代下载：本地安装包删掉了，条目与图标留着。');
}

function softRemove() {
  if (softState.busy) return;
  if (!softState.editing) {
    setMsg(softFormTipEl, '还没选中要删除哪一款。', 'warn');
    return;
  }
  const id = softState.editing.id;
  softBusy(true, '正在删除…');
  return softDone(api('admin/software', 'DELETE', { id: id }), '已删除，连本地的安装包一起。')
    .then(function (data) { if (data) softNew(); });
}

/** 「自动识别信息」：只读仓库元数据填进表单，存不存由管理员看完再说 */
function softIdentify() {
  if (softState.busy) return;
  const body = {
    githubUrl: String((el(softFields.githubUrl) || {}).value || '').trim(),
    giteeUrl: String((el(softFields.giteeUrl) || {}).value || '').trim(),
  };
  softBusy(true, '正在读仓库信息…');
  return api('admin/software/identify', 'POST', body, CORE.slowApiTimeout).then(function (data) {
    const filled = [];
    Object.keys(data.detected).forEach(function (key) {
      const node = el(softFields[key]);
      if (!node) return;
      node.value = String(data.detected[key]);
      filled.push(key);
    });
    softRenderIcon(softState.editing);
    setMsg(softFormTipEl, '按「' + data.source + '」的结果填好了：' + filled.join('、')
      + '。核对一遍再点保存——识别只动表单，不动库里的内容。'
      + (data.tried.length ? '（顺带一提：' + data.tried.join('；') + '）' : ''), 'ok');
  }).catch(function (error) {
    setMsg(softFormTipEl, error.message, 'error');
  }).then(function () {
    softBusy(false);
  });
}

/** 选中的图标文件 → data URL → 后端按文件头认格式 */
function softUploadIcon(file) {
  if (!softState.editing) {
    setMsg(softIconTipEl, '先保存这一款，再传图标（图标落在 <id>.icon.<格式>，得先有 id）。', 'warn');
    return;
  }
  const id = softState.editing.id;
  const reader = new FileReader();
  softBusy(true, '正在上传图标…');
  reader.onload = function () {
    softDone(api('admin/software/icon', 'POST', { id: id, image: String(reader.result) }), '图标已换。', softIconTipEl)
      .then(function (data) {
        if (data) setMsg(softIconTipEl, '图标已存为 ' + data.kind + '，' + formatBytes(data.bytes) + '。', 'ok');
      });
  };
  reader.onerror = function () {
    softBusy(false);
    setMsg(softIconTipEl, '这个文件读不出来，换一个试试。', 'error');
  };
  reader.readAsDataURL(file);
}

function softFetchIcon() {
  if (softState.busy) return;
  if (!softState.editing) {
    setMsg(softIconTipEl, '先保存这一款（智能获取要按 id 落盘）。', 'warn');
    return;
  }
  softBusy(true, '正在按仓库、官网的顺序找图标…');
  softDone(api('admin/software/icon/fetch', 'POST', { id: softState.editing.id }, CORE.slowApiTimeout),
    '图标已取回。', softIconTipEl).then(function (data) {
      if (data) setMsg(softIconTipEl, '从「' + data.via + '」取回了 ' + data.kind + ' 图标，'
        + formatBytes(data.bytes) + '。', 'ok');
    });
}

function softClearIcon() {
  if (softState.busy) return;
  if (!softState.editing) return;
  softBusy(true, '正在去掉图标…');
  softDone(api('admin/software/icon', 'DELETE', { id: softState.editing.id }), '已去掉本站图标。', softIconTipEl);
}

/* ============================================================
   第 4 部分：登录态、数据加载与事件
   ============================================================ */

function softLoadList() {
  // 管理员多要的那一份里有下架条目与配额；访客这份不随身份变化，可以放缓存
  return api(softState.isAdmin ? 'admin/software' : 'software').then(function (data) {
    softApplyList(data);
  }).catch(function (error) {
    showAlert('软件仓库清单读不出来（' + error.message + '）', 8000);
  });
}

function softLoadMe() {
  return api('me').then(function (data) {
    softState.me = data && data.logged
      ? { logged: true, username: data.username, role: data.role || '' }
      : { logged: false, username: '', role: '' };
    softState.isAdmin = softState.me.role === 'admin';
    navUser.classList.toggle('hidden', !softState.me.logged);
    if (softLoginLink) softLoginLink.classList.toggle('hidden', softState.me.logged);
    if (softState.me.logged) navUsername.textContent = '你好，' + softState.me.username;
    if (softAdminEl) softAdminEl.classList.toggle('hidden', !softState.isAdmin);
  }).catch(function (error) {
    showAlert('后端接口连不上（' + error.message + '）', 8000);
  });
}

function softBindTools() {
  if (!softSearchEl) return;
  softSearchEl.addEventListener('input', function () {
    softState.filter.q = softSearchEl.value;
    softRender();
  });
  softSortEl.addEventListener('change', function () {
    softState.filter.sort = softSortEl.value;
    softRender();
  });
  softViewEl.querySelectorAll('.chip').forEach(function (chip) {
    chip.addEventListener('click', function () {
      softState.filter.view = chip.dataset.view;
      softViewEl.querySelectorAll('.chip').forEach(function (other) {
        other.classList.toggle('active', other === chip);
      });
      softRender();
    });
  });
  softTagMoreEl.addEventListener('click', function () {
    softState.tagsOpen = !softState.tagsOpen;
    softRender();
  });
}

function softBindForm() {
  if (!softSaveEl) return;
  softSaveEl.addEventListener('click', softSave);
  softResetEl.addEventListener('click', function () {
    softNew();
    setMsg(softFormTipEl, '', '');
  });
  softIdentifyEl.addEventListener('click', softIdentify);
  softGrabEl.addEventListener('click', softGrab);
  softReleaseEl.addEventListener('click', function () {
    armConfirm(softReleaseEl, '确认删本地包？', softRelease);
  });
  softDeleteEl.addEventListener('click', function () {
    armConfirm(softDeleteEl, '确认删除软件？', softRemove);
  });
  softIconFileEl.addEventListener('change', function () {
    const file = softIconFileEl.files && softIconFileEl.files[0];
    if (!file) return;
    softUploadIcon(file);
    softIconFileEl.value = '';// 同一个文件再选一次也要触发 change（改了名字没改内容时常见）
  });
  softIconFetchEl.addEventListener('click', softFetchIcon);
  softIconClearEl.addEventListener('click', function () {
    armConfirm(softIconClearEl, '确认去掉图标？', softClearIcon);
  });
  // Esc 收掉处在「再点一次确认」状态的按钮；输入框里打字时不掺和
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') disarmConfirm();
  });
}

function boot() {
  if (!softListEl) return;// 这一页的元素不在（别的页面误载了这份脚本）就别往下走
  softFillForm(null);
  softBindTools();
  softBindForm();
  softLoadMe().then(softLoadList);
  if (visitCountEl) {
    api('visit', 'POST', {}).then(function (stats) {
      if (visitCountEl) visitCountEl.textContent = String(stats.totalVisits);
    }).catch(function () {
      if (visitCountEl) visitCountEl.textContent = '未知';// 计数失败不该影响这一页的主功能
    });
  }
}
boot();

/* ============================================================
   第 5 部分：规则自测（tests/js-smoke.js 会调用）
   ============================================================ */
function softwareSelfTest() {
  const assert = function (cond, message) {
    if (!cond) throw new Error(message);
  };
  const item = function (over) {
    return Object.assign({
      id: 1, name: '示例', category: '系统工具', description: '一句话简介',
      tags: ['绿色', '离线'], platforms: [{ code: 'windows', label: 'Windows' }],
      version: '4.5.8', license: 'MIT', starCount: 1234, fileBytes: 12000000,
    }, over || {});
  };
  const blank = { category: '', platform: '', tag: '', q: '', sort: 'default', view: 'grid' };

  // 能上 href 的地址：认不出的宁可少一个按钮
  assert(softSafeHref('https://github.com/a/b') === 'https://github.com/a/b', 'https 外链放行');
  assert(softSafeHref('http://127.0.0.1:8000/x') === 'http://127.0.0.1:8000/x', 'http 外链放行');
  assert(softSafeHref('/api/software/file?id=7') === '/api/software/file?id=7', '本站下载接口放行');
  assert(softSafeHref('javascript:alert(1)') === null, 'javascript: 伪协议绝不印上 href');
  assert(softSafeHref('data:text/html,<script>alert(1)</script>') === null, 'data: 也不给');
  assert(softSafeHref('/api/software/file?id=7&x=1') === null, '本站接口只认那一个形状，多带参数不算');
  assert(softSafeHref('https://a b') === null, '地址里有空格（多半是拼进去的）就不放行');
  assert(softSafeHref('') === null && softSafeHref(null) === null && softSafeHref(undefined) === null,
    '空值当没有这个链接');

  // 搜索：各词之间是「并且」，大小写不敏感，标签与协议也搜得到
  assert(softMatch(item(), blank) === true, '不设条件时全部命中');
  assert(softMatch(item(), Object.assign({}, blank, { q: '示例 简介' })) === true, '两个词都在正文里 → 命中');
  assert(softMatch(item(), Object.assign({}, blank, { q: '示例 不存在' })) === false, '有一个词没有 → 不命中');
  assert(softMatch(item(), Object.assign({}, blank, { q: 'MIT' })) === true, '协议也搜得到（大小写不敏感）');
  assert(softMatch(item(), Object.assign({}, blank, { q: '绿色' })) === true, '标签搜得到');
  assert(softMatch(item(), Object.assign({}, blank, { category: '编辑器' })) === false, '分类不同 → 不命中');
  assert(softMatch(item(), Object.assign({}, blank, { platform: 'linux' })) === false, '平台没选上 → 不命中');
  assert(softMatch(item(), Object.assign({}, blank, { tag: '离线' })) === true, '按标签筛');

  // 排序：没有数的那一栏永远沉底，不能被当成 0 混进正常的数里
  const rows = [item({ id: 1, starCount: null, fileBytes: 9 }),
    item({ id: 2, starCount: 50, fileBytes: null }),
    item({ id: 3, starCount: 5000, fileBytes: 200 })];
  assert(softSort(rows, 'stars').map(function (r) { return r.id; }).join('') === '321',
    '按 star 数从大到小，空值垫底');
  assert(softSort(rows, 'size').map(function (r) { return r.id; }).join('') === '312',
    '按包体大小从大到小，没本地包的垫底');
  assert(softSort(rows, 'default').map(function (r) { return r.id; }).join('') === '123',
    '「按收录顺序」保持后端给的定义顺序');
  assert(softSort(rows, 'stars').length === 3 && softSort(rows, 'stars') !== rows,
    '排序不改动传进来的那个数组（筛选条要能来回切）');

  // 分组：一款只进第一个标签那一组，总数才对得上「当前展示」
  const grouped = softGroup([item({ tags: ['绿色'] }), item({ tags: ['编辑器', 'x'] }), item({ tags: [] })]);
  assert(grouped.map(function (g) { return g.tag; }).join(',') === '编辑器,绿色,未标签',
    '组按标签名排，「未标签」垫底（中文组名用 localeCompare 排，不假设它按拼音顺序）');
  assert(grouped.reduce(function (n, g) { return n + g.items.length; }, 0) === 3,
    '分组不会把某一款重复算进两个组');

  // star 数与字节的说法
  assert(softStars(0) === '0', '0 颗要印出来，不是「没有这一栏」');
  assert(softStars(null) === '', '没查过 star 时不画那个 ★');
  assert(softStars(999) === '999', '一千以内原样报');
  assert(softStars(12345) === '12.3k', '上万写成 12.3k，卡片那一角放不下');
  assert(softStars(2400000) === '2.4M', '百万级写 M');
  assert(formatBytes(12000000) === '11.4 MB', '安装包按 1024 进制报 MB');
}
SMOKE_TESTS.push(softwareSelfTest);
