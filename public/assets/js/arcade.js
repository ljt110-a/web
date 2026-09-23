/* ============================================================
   游戏板块里的两个小游戏：2048 与贪吃蛇（/games 页面）

   与 games.js 的关系：两个脚本服务同一个页面，所以它们共享**同一份全局作用域**
   （普通 <script> 里的顶层 const / let 是全局词法绑定，不是各自私有的）。
   这意味着这里不能重新声明 me、navUser、visitCountEl 这些 games.js 已经占掉的名字——
   撞名不是「覆盖」，而是整页 SyntaxError 直接不执行。
   所有本文件的顶层名字都带 2048 / Snake / arcade 前缀，就是这个原因。
   （tests/js-smoke.js 会把同页脚本按顺序塞进同一个上下文跑一遍，撞名当场报出来。）

   另一条约定：游戏规则都写成**不碰 DOM 的纯函数**（slideRow2048 / moveBoard2048 /
   canMove2048 / stepSnake …），随机数由参数注入。这样规则本身可断言，
   文件末尾的 arcadeSelfTest() 就是拿这些纯函数做的，它登记进 core.js 的 SMOKE_TESTS，
   前端烟测会挨个调用。
   ============================================================ */

/* ============================================================
   第 1 部分：2048 的规则（纯函数）
   ============================================================ */

const BOARD_SIZE = 4;// 2048 是 4x4

function emptyBoard2048() {
  const rows = [];
  for (let r = 0; r < BOARD_SIZE; r++) {
    rows.push([0, 0, 0, 0]);
  }
  return rows;
}

/**
 * 一行往左滑并合并，返回 {row, gained}。
 * 规则里最容易写错的是「一次移动里每个格子最多参与一次合并」：
 * [4,4,4,4] 是 [8,8] 而不是 [16]，[2,2,4] 是 [4,4] 而不是 [8]。
 */
function slideRow2048(row) {
  const nums = row.filter(function (v) { return v !== 0; });
  const out = [];
  let gained = 0;
  for (let i = 0; i < nums.length; i++) {
    if (i + 1 < nums.length && nums[i] === nums[i + 1]) {
      const merged = nums[i] * 2;
      out.push(merged);
      gained += merged;
      i++;// 这两个都用掉了，下一个不能再并进来
    } else {
      out.push(nums[i]);
    }
  }
  while (out.length < BOARD_SIZE) out.push(0);
  return { row: out, gained: gained };
}

/** 把「往某个方向走」统一成「往左走」：按方向取出对应的四条线 */
function linesOfBoard2048(board, dir) {
  const lines = [];
  for (let i = 0; i < BOARD_SIZE; i++) {
    if (dir === 'left') {
      lines.push(board[i].slice());
    } else if (dir === 'right') {
      lines.push(board[i].slice().reverse());
    } else if (dir === 'up') {
      lines.push([board[0][i], board[1][i], board[2][i], board[3][i]]);
    } else {
      lines.push([board[3][i], board[2][i], board[1][i], board[0][i]]);
    }
  }
  return lines;
}

/** linesOfBoard2048 的逆操作：把处理过的线写回棋盘 */
function writeLines2048(board, lines, dir) {
  for (let i = 0; i < BOARD_SIZE; i++) {
    const line = dir === 'right' ? lines[i].slice().reverse() : lines[i];
    for (let j = 0; j < BOARD_SIZE; j++) {
      if (dir === 'left' || dir === 'right') {
        board[i][j] = line[j];
      } else if (dir === 'up') {
        board[j][i] = line[j];
      } else {
        board[BOARD_SIZE - 1 - j][i] = line[j];
      }
    }
  }
  return board;
}

function sameBoard2048(a, b) {
  for (let r = 0; r < BOARD_SIZE; r++) {
    for (let c = 0; c < BOARD_SIZE; c++) {
      if (a[r][c] !== b[r][c]) return false;
    }
  }
  return true;
}

/** 走一步：不动原棋盘，返回新棋盘、得分和「有没有真的动」 */
function moveBoard2048(board, dir) {
  const next = board.map(function (r) { return r.slice(); });
  const lines = linesOfBoard2048(next, dir);
  const slid = [];
  let gained = 0;
  for (let i = 0; i < lines.length; i++) {
    const res = slideRow2048(lines[i]);
    gained += res.gained;
    slid.push(res.row);
  }
  writeLines2048(next, slid, dir);
  return { board: next, gained: gained, moved: !sameBoard2048(board, next) };
}

function emptyCells2048(board) {
  const cells = [];
  for (let r = 0; r < BOARD_SIZE; r++) {
    for (let c = 0; c < BOARD_SIZE; c++) {
      if (board[r][c] === 0) cells.push([r, c]);
    }
  }
  return cells;
}

/** 在空格里放一个新数字（90% 是 2，10% 是 4）。rand 由外面传进来，为的是可断言 */
function spawnTile2048(board, rand) {
  const cells = emptyCells2048(board);
  if (cells.length === 0) return null;
  const at = cells[Math.floor(rand() * cells.length)];
  const value = rand() < 0.1 ? 4 : 2;
  board[at[0]][at[1]] = value;
  return { row: at[0], col: at[1], value: value };
}

/** 还有没有可走的步：有空格，或有相邻的同数字 */
function canMove2048(board) {
  for (let r = 0; r < BOARD_SIZE; r++) {
    for (let c = 0; c < BOARD_SIZE; c++) {
      const v = board[r][c];
      if (v === 0) return true;
      if (c + 1 < BOARD_SIZE && board[r][c + 1] === v) return true;
      if (r + 1 < BOARD_SIZE && board[r + 1][c] === v) return true;
    }
  }
  return false;
}

function hasTile2048(board) {
  for (let r = 0; r < BOARD_SIZE; r++) {
    for (let c = 0; c < BOARD_SIZE; c++) {
      if (board[r][c] >= 2048) return true;
    }
  }
  return false;
}

/* ============================================================
   第 2 部分：2048 的界面
   ============================================================ */

const game2048 = { board: null, score: 0, best: 0, over: false, won: false };

const game2048Board = el('game-2048');
const game2048Score = el('game-2048-score');
const game2048Best = el('game-2048-best');
const game2048Status = el('game-2048-status');
const game2048New = el('game-2048-new');
/** 16 个格子只建一次，之后原地改内容与 class——整块重建会让每格都重放动画 */
let game2048Cells = [];

/** 本机最高分。读写都包一层：隐私模式下 localStorage 会直接抛异常 */
function bestStoreRead(key, fallback) {
  try {
    const raw = window.localStorage ? window.localStorage.getItem(key) : null;
    const n = parseInt(raw, 10);
    return isNaN(n) ? fallback : n;
  } catch (e) {
    return fallback;
  }
}
function bestStoreWrite(key, value) {
  try {
    if (window.localStorage) window.localStorage.setItem(key, String(value));
  } catch (e) {
    // 存不下就算了：最高分是锦上添花，不该把它变成一条错误提示
  }
}

function render2048(changed) {
  for (let r = 0; r < BOARD_SIZE; r++) {
    for (let c = 0; c < BOARD_SIZE; c++) {
      const cell = game2048Cells[r * BOARD_SIZE + c];
      const value = game2048.board[r][c];
      const cls = 'tile' + (value ? ' tile-' + value : '');
      const mark = changed && changed.row === r && changed.col === c ? ' tile-new' : '';
      cell.className = cls;
      cell.textContent = value ? String(value) : '';
      if (mark) {
        // 强制回流，让同一个格子上连续两次「新出现」都能重放动画。
        // 顺序必须是「先不带 tile-new → 读一次布局 → 再加 tile-new」：
        // 直接写成最终 class 的话，class 值和上次一模一样，浏览器认为没变化，动画根本不会重头播。
        void cell.offsetWidth;
        cell.className = cls + mark;
      }
    }
  }
  game2048Score.textContent = String(game2048.score);
  game2048Best.textContent = String(game2048.best);
}

function status2048(text) {
  if (game2048Status) game2048Status.textContent = text;
}

function start2048() {
  game2048.board = emptyBoard2048();
  game2048.score = 0;
  game2048.over = false;
  game2048.won = false;
  const first = spawnTile2048(game2048.board, Math.random);
  const second = spawnTile2048(game2048.board, Math.random);
  render2048(second || first);
  status2048('点一下棋盘拿到焦点，再用方向键或 WASD 合并数字，凑出 2048。');
}

function move2048(dir) {
  if (!game2048.board || game2048.over) return;
  const res = moveBoard2048(game2048.board, dir);
  if (!res.moved) return;// 这一方向走不动：不生成新数字，也不该有动静
  game2048.board = res.board;
  game2048.score += res.gained;
  if (game2048.score > game2048.best) {
    game2048.best = game2048.score;
    bestStoreWrite('webone.best2048', game2048.best);
  }
  const spawned = spawnTile2048(game2048.board, Math.random);
  render2048(spawned);
  if (!game2048.won && hasTile2048(game2048.board)) {
    game2048.won = true;// 到 2048 之后可以继续玩，所以只是提示一句
    status2048('到 2048 了，可以继续往合并里走。');
  }
  if (!canMove2048(game2048.board)) {
    game2048.over = true;
    status2048('没有可走的方向了。最高分 ' + game2048.best + ' 分，点「重新开始」再来一局。');
  }
}

/**
 * 一个四向小键盘：手机没有方向键，键盘用户也可能不想摸方向键。
 * 按钮上写的是箭头字符，读屏软件念不出来，所以 aria-label 用汉字。
 */
function buildDpad(container, onDir) {
  if (!container) return;
  const labels = [
    { dir: 'up', text: '↑', aria: '向上' },
    { dir: 'left', text: '←', aria: '向左' },
    { dir: 'down', text: '↓', aria: '向下' },
    { dir: 'right', text: '→', aria: '向右' },
  ];
  container.textContent = '';
  labels.forEach(function (item) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn-mini dpad-key dpad-' + item.dir;
    btn.textContent = item.text;
    btn.setAttribute('aria-label', item.aria);
    btn.addEventListener('click', function () { onDir(item.dir); });
    container.appendChild(btn);
  });
}

/**
 * 棋盘/画布获得焦点才接方向键：否则页面滚动会被抢走。
 *
 * 两个游戏都走这一条规则，因为同一页上有两个「方向键消费者」——
 * 只要有一个是挂在 document 上的，另一个的按键就会被顺手牵走，
 * 而且用户在任何地方按方向键都玩不到想玩的那个。
 * @param {Function} [onSpace] 传了才接空格（贪吃蛇用它开始/暂停）
 */
function keysForBoard(boardEl, onDir, onSpace) {
  if (!boardEl) return;
  const map = {
    ArrowUp: 'up', ArrowDown: 'down', ArrowLeft: 'left', ArrowRight: 'right',
    w: 'up', s: 'down', a: 'left', d: 'right',
    W: 'up', S: 'down', A: 'left', D: 'right',
  };
  boardEl.addEventListener('keydown', function (event) {
    if (onSpace && (event.key === ' ' || event.code === 'Space')) {
      event.preventDefault();
      onSpace();
      return;
    }
    const dir = map[event.key];
    if (!dir) return;
    event.preventDefault();// 方向键在这个棋盘上是游戏输入，不该滚页
    onDir(dir);
  });
}

/* ============================================================
   第 3 部分：贪吃蛇的规则（纯函数）
   ============================================================ */

const SNAKE_SIZE = 15;// 15x15 格
const SNAKE_DIRS = {
  up: { x: 0, y: -1 },
  down: { x: 0, y: 1 },
  left: { x: -1, y: 0 },
  right: { x: 1, y: 0 },
};
const SNAKE_OPPPOSITE = { up: 'down', down: 'up', left: 'right', right: 'left' };

function createSnake() {
  const body = [{ x: 7, y: 7 }, { x: 6, y: 7 }, { x: 5, y: 7 }];
  return {
    body: body,
    dir: 'right',
    want: 'right',// 这一步之内的转向先攒着，等下一步再生效
    food: { x: 11, y: 7 },
    score: 0,
    dead: false,
    ate: false,
  };
}

function samePoint(a, b) {
  return a.x === b.x && a.y === b.y;
}

/** 在没被蛇身占住的格子里放一个食物；rand 注入，方便断言 */
function placeFood(body, rand) {
  const free = [];
  for (let y = 0; y < SNAKE_SIZE; y++) {
    for (let x = 0; x < SNAKE_SIZE; x++) {
      const p = { x: x, y: y };
      let taken = false;
      for (let i = 0; i < body.length; i++) {
        if (samePoint(body[i], p)) { taken = true; break; }
      }
      if (!taken) free.push(p);
    }
  }
  if (free.length === 0) return null;// 填满棋盘了，这一局本来也就结束了
  const pick = free[Math.floor(rand() * free.length)];
  return { x: pick.x, y: pick.y };
}

/** 输入转向：禁止直接掉头，否则一格之内头会撞进第二节身体 */
function turnSnake(state, dir) {
  if (!SNAKE_DIRS[dir]) return state;
  if (dir === SNAKE_OPPPOSITE[state.dir]) return state;
  state.want = dir;
  return state;
}

/**
 * 蛇往前走一格，返回新状态（不原地改入参的 body）。
 * 两种死法都判：撞墙、撞自己。吃到食物时尾巴不收，于是长度 +1。
 */
function stepSnake(state, rand) {
  if (state.dead) return state;
  const dir = state.want;
  const step = SNAKE_DIRS[dir];
  const head = { x: state.body[0].x + step.x, y: state.body[0].y + step.y };

  const hitWall = head.x < 0 || head.y < 0 || head.x >= SNAKE_SIZE || head.y >= SNAKE_SIZE;
  const body = state.body.map(function (p) { return { x: p.x, y: p.y }; });
  const eats = !hitWall && samePoint(head, state.food);
  if (!eats) body.pop();// 不吃东西时尾巴让开一格，所以要先让再判撞没撞

  let dead = hitWall;
  if (!dead) {
    for (let i = 0; i < body.length; i++) {
      if (samePoint(body[i], head)) { dead = true; break; }
    }
  }

  const nextBody = [{ x: head.x, y: head.y }].concat(body);
  let food = state.food;
  let score = state.score;
  if (dead) {
    // 撞上的那一格不该真的画进身体里
    return {
      body: state.body, dir: state.dir, want: state.want, food: state.food,
      score: state.score, dead: true, ate: false,
    };
  }
  if (eats) {
    score += 1;
    food = placeFood(nextBody, rand) || state.food;
  }
  return {
    body: nextBody, dir: dir, want: dir, food: food,
    score: score, dead: false, ate: eats,
  };
}

/* ============================================================
   第 4 部分：贪吃蛇的界面（canvas）
   ============================================================ */

const snakeGame = {
  state: null, running: false, last: 0, raf: 0, best: 0, interval: 150,
};
const snakeCanvas = el('game-snake-canvas');
const snakeScoreEl = el('game-snake-score');
const snakeBestEl = el('game-snake-best');
const snakeStatusEl = el('game-snake-status');
const snakeNewEl = el('game-snake-new');
const snakeDpadEl = el('game-snake-dpad');

const SNAKE_COLORS = {
  bg: '#10152e', grid: 'rgba(255,255,255,0.04)',
  head: '#4cc9f0', body: '#3d7ec9', food: '#ff6b9d',
};

/**
 * 画布的格子边长。
 *
 * 不能直接拿 clientWidth 去除：canvas 有两套尺寸——CSS 决定它显示多大，
 * width/height 属性决定里面有多少像素可画，画图用的坐标是后者的坐标系。
 * 用显示宽度算格子、却画在 480 宽的底图上，画面就会偏到左上角去。
 * 所以每次画之前把底图对齐到「显示宽度 × 屏像素比」，并且凑成 15 的整数倍，
 * 这样每格都是整像素，格子线不糊、也不差半个格。
 */
function snakeCellSize() {
  if (!snakeCanvas) return 16;
  const dpr = window.devicePixelRatio || 1;
  const shown = snakeCanvas.clientWidth || Number(snakeCanvas.width) || 300;
  const want = Math.max(SNAKE_SIZE, Math.round(shown * dpr / SNAKE_SIZE) * SNAKE_SIZE);
  if (Number(snakeCanvas.width) !== want) {
    // 改宽度会清空画布，不过 drawSnake 本来就是整帧重画，无所谓
    snakeCanvas.width = want;
    snakeCanvas.height = want;
  }
  return want / SNAKE_SIZE;
}

function drawSnake() {
  if (!snakeCanvas || !snakeGame.state) return;
  const ctx = snakeCanvas.getContext ? snakeCanvas.getContext('2d') : null;
  if (!ctx) return;
  const cell = snakeCellSize();
  const size = cell * SNAKE_SIZE;

  ctx.fillStyle = SNAKE_COLORS.bg;
  ctx.fillRect(0, 0, size, size);
  ctx.fillStyle = SNAKE_COLORS.grid;
  for (let i = 0; i < SNAKE_SIZE; i++) {
    ctx.fillRect(i * cell, 0, 1, size);
    ctx.fillRect(0, i * cell, size, 1);
  }

  const food = snakeGame.state.food;
  ctx.fillStyle = SNAKE_COLORS.food;
  ctx.beginPath();
  ctx.arc((food.x + 0.5) * cell, (food.y + 0.5) * cell, cell * 0.3, 0, Math.PI * 2);
  ctx.fill();

  const body = snakeGame.state.body;
  for (let i = body.length - 1; i >= 0; i--) {
    ctx.fillStyle = i === 0 ? SNAKE_COLORS.head : SNAKE_COLORS.body;
    const pad = cell * 0.12;
    ctx.fillRect(body[i].x * cell + pad, body[i].y * cell + pad, cell - pad * 2, cell - pad * 2);
  }
}

function snakeStatus(text) {
  if (snakeStatusEl) snakeStatusEl.textContent = text;
}

function renderSnakeHud() {
  if (snakeScoreEl) snakeScoreEl.textContent = String(snakeGame.state ? snakeGame.state.score : 0);
  if (snakeBestEl) snakeBestEl.textContent = String(snakeGame.best);
}

/**
 * 每一帧检查要不要走一步。
 * 注意这里和番茄钟的计时器**故意相反**：计时器要「按真实流逝的时间补齐」，
 * 而游戏如果切回来发现落后 3 秒就补 20 步，蛇会直接穿到墙上去。
 * 落后就跳过，只走一步。
 */
function snakeFrame(now) {
  if (!snakeGame.running) return;
  if (now - snakeGame.last >= snakeGame.interval) {
    snakeGame.last = now;
    snakeGame.state = stepSnake(snakeGame.state, Math.random);
    if (snakeGame.state.score > snakeGame.best) {
      snakeGame.best = snakeGame.state.score;
      bestStoreWrite('webone.bestSnake', snakeGame.best);
    }
    // 越长越快，但封一个底：不然到后面人类没法玩
    snakeGame.interval = Math.max(85, 150 - snakeGame.state.score * 4);
    renderSnakeHud();
    drawSnake();
    if (snakeGame.state.dead) {
      stopSnake();
      snakeStatus('撞上了。这一局 ' + snakeGame.state.score + ' 分，点「再来一局」重来。');
      return;
    }
  }
  snakeGame.raf = requestAnimationFrame(snakeFrame);
}

function startSnake() {
  if (snakeGame.running) return;
  if (!snakeGame.state || snakeGame.state.dead) {
    snakeGame.state = createSnake();
    snakeGame.interval = 150;
  }
  snakeGame.running = true;
  snakeGame.last = (window.performance ? performance.now() : Date.now());
  snakeStatus('走起来了。方向键 / WASD 转向，空格暂停。');
  renderSnakeHud();
  // 用鼠标点「开始」的人下一步多半要用方向键：直接把焦点交给画布，省掉「再点一下画布」这一步
  if (snakeCanvas && snakeCanvas.focus) snakeCanvas.focus();
  snakeGame.raf = requestAnimationFrame(snakeFrame);
}

function stopSnake() {
  snakeGame.running = false;
  if (snakeGame.raf && typeof cancelAnimationFrame === 'function') {
    cancelAnimationFrame(snakeGame.raf);
  }
  snakeGame.raf = 0;
}

function resetSnake() {
  stopSnake();
  snakeGame.state = createSnake();
  snakeGame.interval = 150;
  renderSnakeHud();
  drawSnake();
  snakeStatus('准备好了。点「开始」，或者点一下画布拿到焦点后用方向键 / 空格。');
}

function snakeTurn(dir) {
  if (!snakeGame.state) return;
  turnSnake(snakeGame.state, dir);
  if (!snakeGame.running) snakeStatus('先点「开始」，转向已经记下了。');
}

/** 「开始 / 暂停」这一个开关：按钮和空格都走它 */
function snakeToggle() {
  if (snakeGame.running) {
    stopSnake();
    snakeStatus('暂停了。点「开始」接着走。');
  } else {
    startSnake();
  }
}

/* ============================================================
   第 5 部分：启动
   ============================================================ */

function arcadeBoot() {
  // 2048
  if (game2048Board) {
    game2048Board.textContent = '';
    game2048Cells = [];
    for (let i = 0; i < BOARD_SIZE * BOARD_SIZE; i++) {
      const cell = document.createElement('div');
      cell.className = 'tile';
      game2048Board.appendChild(cell);
      game2048Cells.push(cell);
    }
    game2048.best = bestStoreRead('webone.best2048', 0);
    start2048();
    keysForBoard(game2048Board, move2048);
    buildDpad(el('game-2048-dpad'), move2048);
    if (game2048New) {
      game2048New.addEventListener('click', function () { start2048(); });
    }
  }

  // 贪吃蛇
  if (snakeCanvas) {
    snakeGame.best = bestStoreRead('webone.bestSnake', 0);
    resetSnake();
    if (snakeNewEl) snakeNewEl.addEventListener('click', resetSnake);
    const startBtn = el('game-snake-start');
    if (startBtn) startBtn.addEventListener('click', snakeToggle);
    buildDpad(snakeDpadEl, snakeTurn);
    // 键盘也走「拿到焦点才接方向键」这一条，和 2048 那边一致。
    // 之前这里是挂在 document 上的，结果同一页有两个方向键消费者：
    // 用户想操作 2048，蛇也跟着拐；而且页面上任何地方按方向键都滚不动页了。
    keysForBoard(snakeCanvas, snakeTurn, snakeToggle);
  }
}

arcadeBoot();

/* ============================================================
   第 6 部分：规则自测
   tests/js-smoke.js 加载完这一页的脚本后会调用 SMOKE_TESTS 里登记的每个函数。
   只测上面的纯函数，不碰 DOM，也不发请求。
   ============================================================ */
function arcadeSelfTest() {
  function expect(label, actual, expected) {
    if (actual !== expected) {
      throw new Error('小游戏规则不对：' + label + '（期望 ' + expected + '，实际 ' + actual + '）');
    }
  }
  function rowOf(row) {
    return slideRow2048(row).row.join(',');
  }
  // 每格一次移动里最多合并一次
  expect('[2,2,4,0] 往左', rowOf([2, 2, 4, 0]), '4,4,0,0');
  expect('[4,4,4,4] 往左', rowOf([4, 4, 4, 4]), '8,8,0,0');
  expect('[2,2,2,2] 往左', rowOf([2, 2, 2, 2]), '4,4,0,0');
  expect('[2,4,2,0] 不相邻就不合', rowOf([2, 4, 2, 0]), '2,4,2,0');
  expect('[0,0,0,2] 只平移', rowOf([0, 0, 0, 2]), '2,0,0,0');
  expect('合并得分', slideRow2048([4, 4, 0, 0]).gained, 8);
  expect('不动就没有得分', slideRow2048([2, 4, 8, 16]).gained, 0);

  const board = [
    [2, 0, 0, 2],
    [4, 4, 8, 0],
    [0, 0, 0, 0],
    [8, 0, 0, 8],
  ];
  expect('往左走确实变了', moveBoard2048(board, 'left').moved, true);
  expect('往左：第一行并成 4', moveBoard2048(board, 'left').board[0].join(','), '4,0,0,0');
  expect('往左：第二行 4+4=8', moveBoard2048(board, 'left').board[1].join(','), '8,8,0,0');
  expect('往左：空行还是空', moveBoard2048(board, 'left').board[2].join(','), '0,0,0,0');
  // 竖着走的时候按「列」读结果才对得上直觉：滑的是列，写回去还是那一列
  function colOf(b, i) { return [b[0][i], b[1][i], b[2][i], b[3][i]].join(','); }
  const up = moveBoard2048(board, 'up').board;
  const down = moveBoard2048(board, 'down').board;
  const right = moveBoard2048(board, 'right').board;
  expect('往上：第一列 2,4,0,8 收拢', colOf(up, 0), '2,4,8,0');
  expect('往上：第四列 2,0,0,8 不相邻就不合', colOf(up, 3), '2,8,0,0');
  expect('往下：第一列贴到底', colOf(down, 0), '0,2,4,8');
  expect('往下：第四列 2,0,0,8 也贴底', colOf(down, 3), '0,0,2,8');
  expect('往右：最后一行并进右边', right[3].join(','), '0,0,0,16');
  expect('往右：第一行两个 2 并成 4', right[0].join(','), '0,0,0,4');
  expect('走一步不改原棋盘', board[0].join(','), '2,0,0,2');
  expect('走不动的方向 moved 为假',
    moveBoard2048([[2, 4, 8, 16], [0, 0, 0, 0], [0, 0, 0, 0], [0, 0, 0, 0]], 'left').moved, false);

  // 随机数由外面给：seq 按调用次数依次吐出写死的数值，落点和数字才是确定的
  function seq() {
    const values = Array.prototype.slice.call(arguments);
    let i = 0;
    return function () {
      const v = i < values.length ? values[i] : values[values.length - 1];
      i++;
      return v;
    };
  }
  // 第一次调用决定落在哪个空格，第二次决定是 2 还是 4（<0.1 才是 4）
  const first = spawnTile2048(emptyBoard2048(), seq(0, 0.5));
  expect('空格全满时落在第一格', first.row + ',' + first.col, '0,0');
  expect('新数字默认是 2', first.value, 2);
  expect('十分之一概率是 4', spawnTile2048(emptyBoard2048(), seq(0, 0.05)).value, 4);
  expect('满盘时生成不出新数字', spawnTile2048([
    [2, 4, 8, 16], [2, 4, 8, 16], [2, 4, 8, 16], [2, 4, 8, 16],
  ], seq(0)), null);
  expect('没有空格也还有可走的方向', canMove2048([
    [2, 2, 8, 16], [2, 4, 8, 16], [2, 4, 8, 16], [2, 4, 8, 16],
  ]), true);
  expect('满盘且无可合并就是结束', canMove2048([
    [2, 4, 8, 16], [4, 8, 16, 2], [2, 4, 8, 16], [4, 8, 16, 2],
  ]), false);
  expect('到 2048 判得出', hasTile2048([[2048, 0, 0, 0], [0, 0, 0, 0], [0, 0, 0, 0], [0, 0, 0, 0]]), true);

  // 贪吃蛇
  const snake = createSnake();
  const movedSnake = stepSnake(snake, seq(0));
  expect('往右一步头到 8,7', movedSnake.body[0].x + ',' + movedSnake.body[0].y, '8,7');
  expect('不吃东西长度不变', movedSnake.body.length, 3);
  expect('原来那只蛇没被改掉', snake.body[0].x, 7);

  const eating = stepSnake({
    body: [{ x: 10, y: 7 }, { x: 9, y: 7 }, { x: 8, y: 7 }],
    dir: 'right', want: 'right', food: { x: 11, y: 7 }, score: 0, dead: false, ate: false,
  }, seq(0));
  expect('吃到食物长度 +1', eating.body.length, 4);
  expect('吃到食物 score +1', eating.score, 1);
  expect('吃到食物会重新放食物', eating.food.x + ',' + eating.food.y, '0,0');

  const intoWall = stepSnake({
    body: [{ x: 14, y: 0 }, { x: 13, y: 0 }, { x: 12, y: 0 }],
    dir: 'right', want: 'right', food: { x: 0, y: 14 }, score: 5, dead: false, ate: false,
  }, seq(0));
  expect('撞墙判死', intoWall.dead, true);
  expect('撞墙不算分', intoWall.score, 5);
  expect('撞墙时身体保持在原地（不会画进墙里）', intoWall.body[0].x + ',' + intoWall.body[0].y, '14,0');

  const intoSelf = stepSnake({
    body: [{ x: 5, y: 5 }, { x: 5, y: 6 }, { x: 6, y: 6 }, { x: 6, y: 5 }, { x: 6, y: 4 }],
    dir: 'down', want: 'down', food: { x: 0, y: 0 }, score: 3, dead: false, ate: false,
  }, seq(0));
  expect('撞自己判死', intoSelf.dead, true);

  const noReverse = turnSnake({ body: [], dir: 'left', want: 'left', food: {}, score: 0, dead: false, ate: false }, 'right');
  expect('不允许一格内直接掉头', noReverse.want, 'left');
  const canTurn = turnSnake({ body: [], dir: 'left', want: 'left', food: {}, score: 0, dead: false, ate: false }, 'up');
  expect('掉头之外的转向会记下', canTurn.want, 'up');
}

SMOKE_TESTS.push(arcadeSelfTest);
