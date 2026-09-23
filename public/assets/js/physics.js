/* ============================================================
   游戏板块里的第三个互动区：弹球物理沙盒（/games 页面）

   和 arcade.js 的关系：两个脚本服务同一个页面，共享**同一份全局词法作用域**，
   所以这里的顶层名字全部带 physics / PHYSICS 前缀。
   规则自测也不叫 smokeSelfTest —— 那个名字 arcade.js 已经用了，
   同名函数声明是静默覆盖，先写的那套断言会悄悄不跑；两边都 push 进 core.js 的 SMOKE_TESTS。

   这一节同样一行都不碰后端：没有登录、没有存档，参数只活在内存里，刷新就回默认值。
   ============================================================ */

/* ============================================================
   第 1 部分：物理规则（纯函数，不碰 DOM）

   约定：这些函数**就地修改**传进来的球。每帧要对所有球做几次积分和成百上千次
   碰撞判断，返回新对象会白扔一堆临时对象；测试时自己现造球就行，别复用。
   ============================================================ */

const PHYSICS_W = 100;// 虚拟世界的宽（单位不是像素，见第 4 部分的缩放）
const PHYSICS_H = 60;// 虚拟世界的高
const PHYSICS_STEP = 1 / 120;// 固定步长：不管屏幕刷新率多少，物理都按这个粒度推进
const PHYSICS_MAX_STEPS = 6;// 一帧最多算几步，算不完就丢掉（宁可慢，不可穿墙）
const PHYSICS_MAX_BALLS = 26;// 球数上限：碰撞是 O(n²)，也是「别把浏览器玩卡了」的护栏
const PHYSICS_MAX_SPEED = 260;// 速度上限。1/120 步长下最快一步走 2.17 个单位，
                               // 比最小的球（半径 2.4）还短，所以不会一步穿过另一颗球
const PHYSICS_MIN_RADIUS = 2.4;
const PHYSICS_MAX_RADIUS = 4.6;

/** 2D 里没有体积，就用面积当质量：大球撞小球时明显「撞不动」 */
function physicsMass(radius) {
  return radius * radius;
}

function makePhysicsBall(x, y, vx, vy, radius, hue) {
  const mass = physicsMass(radius);
  return {
    x: x, y: y, vx: vx, vy: vy,
    r: radius, mass: mass, invMass: 1 / mass,
    hue: hue,
    grabbed: false,// 被鼠标/手指按住的那颗：位置和速度由指针说了算
  };
}

function physicsEnv(gravity, bounce) {
  return {
    gravity: gravity,// 单位/秒²
    restitution: bounce,// 撞完还剩多少速度
    drag: 0.12,// 空气阻尼（每秒按 exp(-drag·t) 衰减）
    friction: 0.9,// 贴地滚动时水平方向的速度保留率
    sleepSpeed: 1.6,// 落地时竖直速度小于这个值就直接归零：不然会一直抖
  };
}

/** 半隐式欧拉：先更新速度、再用新速度更新位置。显式欧拉在这种场景下会自己加速 */
function integrateBall(ball, dt, env) {
  if (ball.grabbed) return;
  ball.vy += env.gravity * dt;
  const damp = Math.exp(-env.drag * dt);
  ball.vx *= damp;
  ball.vy *= damp;
  const speed = Math.hypot(ball.vx, ball.vy);
  if (speed > PHYSICS_MAX_SPEED) {// 直接按上限截：抛掷力度得有顶，否则穿墙靠步长兜不住
    const k = PHYSICS_MAX_SPEED / speed;
    ball.vx *= k;
    ball.vy *= k;
  }
  ball.x += ball.vx * dt;
  ball.y += ball.vy * dt;
}

/**
 * 四面墙：反射速度 + 把球挪回内侧。返回碰了几面墙。
 *
 * 反射前必须先问一句「是不是正朝着这面墙动」：只按位置判断的话，
 * 一颗刚被地面弹起来、还有一丁点嵌在地面里的球，下一帧会被地面再弹一次——
 * 方向反了，于是它被地面「吸」住，越粘越紧。位置该挪回内侧还是要挪（只差不到一步的量）。
 */
function bounceWalls(ball, env) {
  let hits = 0;
  if (ball.x - ball.r < 0) {
    ball.x = ball.r;
    if (ball.vx < 0) {
      ball.vx = -ball.vx * env.restitution;
      hits++;
    }
  } else if (ball.x + ball.r > PHYSICS_W) {
    ball.x = PHYSICS_W - ball.r;
    if (ball.vx > 0) {
      ball.vx = -ball.vx * env.restitution;
      hits++;
    }
  }
  if (ball.y - ball.r < 0) {
    ball.y = ball.r;
    if (ball.vy < 0) {
      ball.vy = -ball.vy * env.restitution;
      hits++;
    }
  } else if (ball.y + ball.r > PHYSICS_H) {
    ball.y = PHYSICS_H - ball.r;
    if (ball.vy > 0) {
      ball.vy = -ball.vy * env.restitution;
      ball.vx *= env.friction;// 地面摩擦：只碰地不减速的话球会永远滚下去
      if (Math.abs(ball.vy) < env.sleepSpeed) ball.vy = 0;// 抖动的来源就是这一条
      hits++;
    }
  }
  return hits;
}

/** 两个球的重叠深度；<=0 表示没碰上 */
function overlapDepth(a, b) {
  const dx = b.x - a.x;
  const dy = b.y - a.y;
  const dist = Math.hypot(dx, dy);
  return a.r + b.r - dist;
}

/**
 * 一对球的碰撞：先把重叠推开（按质量分），再按冲量换速度。
 * 返回 true 表示真的撞上了。
 *
 * 位置修正和速度冲量是两件事，缺一不可：只加冲量的话，重叠中的两个球下一帧
 * 还是重叠，会连撞好几帧把速度越推越歪；只推位置的话，穿透看起来解决了但动量不对。
 */
function resolvePair(a, b, env) {
  const dx = b.x - a.x;
  const dy = b.y - a.y;
  const dist = Math.hypot(dx, dy) || 0.000001;
  const depth = a.r + b.r - dist;
  if (depth <= 0) return false;

  const nx = dx / dist;
  const ny = dy / dist;
  const invA = a.grabbed ? 0 : a.invMass;
  const invB = b.grabbed ? 0 : b.invMass;
  const invSum = invA + invB;
  if (invSum === 0) return false;// 两颗都被按住，谁也推不动

  // 先把重叠分开，留 1% 的余量：完全分到位的话下一帧浮点误差又是重叠
  const push = (depth - 0.01) / invSum;
  a.x -= nx * push * invA;
  a.y -= ny * push * invA;
  b.x += nx * push * invB;
  b.y += ny * push * invB;

  const approach = (b.vx - a.vx) * nx + (b.vy - a.vy) * ny;
  if (approach > 0) return true;// 已经在分开了（上面只是把重叠推开），不再给冲量

  const j = -(1 + env.restitution) * approach / invSum;
  a.vx -= nx * j * invA;
  a.vy -= ny * j * invA;
  b.vx += nx * j * invB;
  b.vy += ny * j * invB;
  return true;
}

/** 推进一个固定步长，返回这一帧撞了几次（球与球 + 墙） */
function stepBalls(balls, dt, env) {
  let hits = 0;
  for (let i = 0; i < balls.length; i++) {
    integrateBall(balls[i], dt, env);
    hits += bounceWalls(balls[i], env);
  }
  for (let i = 0; i < balls.length; i++) {
    for (let j = i + 1; j < balls.length; j++) {
      if (resolvePair(balls[i], balls[j], env)) hits++;
    }
  }
  return hits;
}

/** 还在动的球有几颗：用来决定「都停了，要不要提醒一下」 */
function movingBalls(balls) {
  let n = 0;
  for (let i = 0; i < balls.length; i++) {
    if (balls[i].grabbed || Math.hypot(balls[i].vx, balls[i].vy) > 0.6) n++;
  }
  return n;
}

/** 随机一颗新球：位置可给定（抛掷时用指针位置），不给就在上半部分随机落 */
function spawnPhysicsBall(balls, rand, x, y) {
  if (balls.length >= PHYSICS_MAX_BALLS) return null;
  const radius = PHYSICS_MIN_RADIUS + rand() * (PHYSICS_MAX_RADIUS - PHYSICS_MIN_RADIUS);
  const px = typeof x === 'number' ? Math.min(PHYSICS_W - radius, Math.max(radius, x)) : radius + rand() * (PHYSICS_W - 2 * radius);
  const py = typeof y === 'number' ? Math.min(PHYSICS_H - radius, Math.max(radius, y)) : radius + rand() * (PHYSICS_H * 0.4);
  const ball = makePhysicsBall(px, py, (rand() - 0.5) * 26, 0, radius, Math.floor(rand() * 360));
  balls.push(ball);
  return ball;
}

/** 找到按在某个点上的那颗球（从最上面那颗开始，即后画的先被选中） */
function ballAtPoint(balls, x, y) {
  for (let i = balls.length - 1; i >= 0; i--) {
    const ball = balls[i];
    if (Math.hypot(ball.x - x, ball.y - y) <= ball.r + 1.2) return ball;// +1.2 是给手指按的容差
  }
  return null;
}

/* ============================================================
   第 2 部分：界面元素与状态
   ============================================================ */

const physicsCanvas = el('physics-canvas');
const physicsStatusEl = el('physics-status');
const physicsCountEl = el('physics-count');
const physicsHitsEl = el('physics-hits');
const physicsMovingEl = el('physics-moving');
const physicsGravityEl = el('physics-gravity');
const physicsBounceEl = el('physics-bounce');
const physicsGravityOut = el('physics-gravity-out');
const physicsBounceOut = el('physics-bounce-out');

const physics = {
  balls: [],
  env: physicsEnv(70, 0.78),
  running: false,
  raf: 0,
  last: 0,
  acc: 0,
  hits: 0,
  grabbed: null,
  pointer: { x: 0, y: 0, t: 0, vx: 0, vy: 0 },
};

function physicsStatus(text) {
  if (physicsStatusEl) physicsStatusEl.textContent = text;
}

function physicsHud() {
  if (physicsCountEl) physicsCountEl.textContent = String(physics.balls.length);
  if (physicsHitsEl) physicsHitsEl.textContent = String(physics.hits);
  if (physicsMovingEl) physicsMovingEl.textContent = String(movingBalls(physics.balls));
}

function prefersReducedMotion() {
  return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
}

/* ============================================================
   第 3 部分：画布尺寸与绘制

   物理算在 100×60 的虚拟单位里，画的时候才乘到像素。
   好处：改窗口大小、换高分屏都不用重新调重力——不然「重力」会变成像素/秒²，
   同一个滑块在 480px 和 960px 的画布上落下来的快慢不一样。
   ============================================================ */

/** 让画布的像素缓冲区和显示尺寸对齐（按 devicePixelRatio），返回一个虚拟单位等于多少像素 */
function physicsScale() {
  if (!physicsCanvas) return 4;// 没有画布时给个无害的值
  const dpr = window.devicePixelRatio || 1;
  const shown = physicsCanvas.clientWidth || Number(physicsCanvas.width) || 480;
  // 缓冲区取显示宽度的整数倍像素，高度按 5:3 锁死，和 CSS 的 aspect-ratio 一致
  const w = Math.max(PHYSICS_W, Math.round(shown * dpr));
  if (Number(physicsCanvas.width) !== w || Number(physicsCanvas.height) !== Math.round(w * PHYSICS_H / PHYSICS_W)) {
    physicsCanvas.width = w;
    physicsCanvas.height = Math.round(w * PHYSICS_H / PHYSICS_W);
  }
  return physicsCanvas.width / PHYSICS_W;
}

function drawPhysics() {
  if (!physicsCanvas || !physicsCanvas.getContext) return;
  const ctx = physicsCanvas.getContext('2d');
  if (!ctx) return;
  const scale = physicsScale();
  // 之后一律用虚拟单位画：一次 setTransform 把缩放和翻转交给矩阵，省掉每个坐标乘一遍
  ctx.setTransform(scale, 0, 0, scale, 0, 0);

  const sky = ctx.createLinearGradient(0, 0, 0, PHYSICS_H);
  sky.addColorStop(0, '#141a33');
  sky.addColorStop(0.62, '#0d1124');
  sky.addColorStop(1, '#1b1230');
  ctx.fillStyle = sky;
  ctx.fillRect(0, 0, PHYSICS_W, PHYSICS_H);

  // 地面：一条由左到右的渐变带，纯色的话看起来像贴图缺了一角
  const floor = ctx.createLinearGradient(0, 0, PHYSICS_W, 0);
  floor.addColorStop(0, 'rgba(76,201,240,0)');
  floor.addColorStop(0.5, 'rgba(76,201,240,0.5)');
  floor.addColorStop(1, 'rgba(199,125,255,0)');
  ctx.fillStyle = floor;
  ctx.fillRect(0, PHYSICS_H - 0.45, PHYSICS_W, 0.45);

  for (let i = 0; i < physics.balls.length; i++) {
    drawPhysicsBall(ctx, physics.balls[i]);
  }
}

function drawPhysicsBall(ctx, ball) {
  // 影子：越高越大越淡，这一条是「球浮起来了」的主要线索
  const height = Math.max(0, Math.min(1, (PHYSICS_H - ball.r - ball.y) / (PHYSICS_H * 0.7)));
  ctx.save();
  ctx.globalAlpha = 0.34 * (1 - height * 0.75);
  ctx.fillStyle = '#000';
  ctx.beginPath();
  ctx.ellipse(ball.x, PHYSICS_H - 0.7, ball.r * (1 + height * 0.8), ball.r * 0.34, 0, 0, Math.PI * 2);
  ctx.fill();
  ctx.restore();

  // 球体：偏心径向渐变冒充受光面，平面填色是没有立体感的
  const glow = ctx.createRadialGradient(
    ball.x - ball.r * 0.36, ball.y - ball.r * 0.42, ball.r * 0.12,
    ball.x, ball.y, ball.r
  );
  glow.addColorStop(0, 'hsl(' + ball.hue + ', 92%, 78%)');
  glow.addColorStop(0.55, 'hsl(' + ball.hue + ', 78%, 56%)');
  glow.addColorStop(1, 'hsl(' + ball.hue + ', 66%, 27%)');
  ctx.fillStyle = glow;
  ctx.beginPath();
  ctx.arc(ball.x, ball.y, ball.r, 0, Math.PI * 2);
  ctx.fill();

  if (ball.grabbed) {
    ctx.strokeStyle = 'rgba(255,255,255,0.8)';
    ctx.lineWidth = 0.35;
    ctx.beginPath();
    ctx.arc(ball.x, ball.y, ball.r + 1.1, 0, Math.PI * 2);
    ctx.stroke();
  }
}

/* ============================================================
   第 4 部分：推进循环
   ============================================================ */

/**
 * 每帧：把流逝的时间分成固定步长喂给物理。
 * 和贪吃蛇一条规矩——**落后就跳过，不补齐**：切到别的标签页再回来时
 * 可能已经过了 30 秒，补 3600 步既卡又会让所有球穿墙挤在一起。
 */
function physicsFrame(now) {
  if (!physics.running) return;
  const elapsed = Math.min(0.25, (now - physics.last) / 1000);
  physics.last = now;
  physics.acc += elapsed;
  let steps = 0;
  while (physics.acc >= PHYSICS_STEP && steps < PHYSICS_MAX_STEPS) {
    physics.hits += stepBalls(physics.balls, PHYSICS_STEP, physics.env);
    physics.acc -= PHYSICS_STEP;
    steps++;
  }
  if (steps === PHYSICS_MAX_STEPS) physics.acc = 0;
  drawPhysics();
  physicsHud();
  if (movingBalls(physics.balls) === 0 && physics.balls.length) {
    physicsStatus('都停了。拖一颗球甩出去，或者点空白处再扔一颗进去。');
  }
  physics.raf = requestAnimationFrame(physicsFrame);
}

function physicsStart() {
  if (physics.running) return;
  physics.running = true;
  physics.last = (window.performance ? performance.now() : Date.now());
  physics.acc = 0;
  physicsStatus('跑起来了。按住一颗球能拖着走、松手甩出去；点空白处会添一颗新球。');
  physics.raf = requestAnimationFrame(physicsFrame);
}

function physicsStop() {
  physics.running = false;
  if (physics.raf && typeof cancelAnimationFrame === 'function') {
    cancelAnimationFrame(physics.raf);
  }
  physics.raf = 0;
}

function physicsToggle() {
  if (physics.running) {
    physicsStop();
    physicsStatus('暂停了。参数还能调，「继续」之后按新的值跑。');
  } else {
    physicsStart();
  }
  const btn = el('physics-toggle');
  if (btn) btn.textContent = physics.running ? '暂停' : '继续';
}

/* ============================================================
   第 5 部分：指针交互（只用指针，不接键盘）

   刻意**不绑方向键 / 空格**：这一页已经有两个键盘消费者了，再来一个就是
   arcade.js 之前那个毛病——想操作 2048，别的区块跟着动。
   ============================================================ */

/** 客户端坐标 → 虚拟坐标。rect 和画布的显示尺寸都参与，所以和 dpr 无关 */
function physicsPoint(event) {
  const rect = physicsCanvas.getBoundingClientRect();
  const x = ((event.clientX - rect.left) / rect.width) * PHYSICS_W;
  const y = ((event.clientY - rect.top) / rect.height) * PHYSICS_H;
  return {
    x: Math.min(PHYSICS_W, Math.max(0, x)),
    y: Math.min(PHYSICS_H, Math.max(0, y)),
  };
}

/** 记下指针速度：抛出去的力量来自这里，取的是最近一次移动，不是累计平均 */
function trackPointerVelocity(point) {
  const now = (window.performance ? performance.now() : Date.now());
  const dt = Math.max(0.008, (now - physics.pointer.t) / 1000);
  physics.pointer.vx = (point.x - physics.pointer.x) / dt;
  physics.pointer.vy = (point.y - physics.pointer.y) / dt;
  physics.pointer.x = point.x;
  physics.pointer.y = point.y;
  physics.pointer.t = now;
}

function physicsPointerDown(event) {
  if (!physicsCanvas) return;
  const point = physicsPoint(event);
  physics.pointer.x = point.x;
  physics.pointer.y = point.y;
  physics.pointer.t = (window.performance ? performance.now() : Date.now());
  physics.pointer.vx = 0;
  physics.pointer.vy = 0;

  const hit = ballAtPoint(physics.balls, point.x, point.y);
  if (hit) {
    physics.grabbed = hit;
    hit.grabbed = true;
    hit.vx = 0;
    hit.vy = 0;
    physicsStatus('抓住了。拖到哪儿跟到哪儿，松手就按刚才的力道甩出去。');
  } else {
    const ball = spawnPhysicsBall(physics.balls, Math.random, point.x, point.y);
    if (!ball) {
      physicsStatus('已经有 ' + PHYSICS_MAX_BALLS + ' 颗球了，先「清空」再来。');
    } else {
      physicsStatus('添了一颗。拖一拖它，或者点别处再放一颗。');
    }
    drawPhysics();
    physicsHud();
  }
  if (physicsCanvas.setPointerCapture && typeof event.pointerId === 'number') {
    physicsCanvas.setPointerCapture(event.pointerId);// 拖出画布也继续跟着手指，不然一出界就脱手
  }
}

function physicsPointerMove(event) {
  if (!physics.grabbed || !physicsCanvas) return;
  const point = physicsPoint(event);
  trackPointerVelocity(point);
  const ball = physics.grabbed;
  ball.x = Math.min(PHYSICS_W - ball.r, Math.max(ball.r, point.x));
  ball.y = Math.min(PHYSICS_H - ball.r, Math.max(ball.r, point.y));
  ball.vx = physics.pointer.vx;
  ball.vy = physics.pointer.vy;
  drawPhysics();// 暂停时也得跟着手指动：动画循环可能根本没在跑
}

function physicsPointerUp() {
  const ball = physics.grabbed;
  if (!ball) return;
  ball.grabbed = false;
  ball.vx = physics.pointer.vx;
  ball.vy = physics.pointer.vy;
  physics.grabbed = null;
  physicsStatus('甩出去了。速度上限 ' + PHYSICS_MAX_SPEED + ' 单位/秒，再用力也是这个。');
}

/* ============================================================
   第 6 部分：控件与启动
   ============================================================ */

function physicsReadSliders() {
  if (physicsGravityEl) {
    physics.env.gravity = Number(physicsGravityEl.value) || 0;
    if (physicsGravityOut) physicsGravityOut.textContent = physics.env.gravity.toFixed(0);
  }
  if (physicsBounceEl) {
    physics.env.restitution = Math.min(1, Math.max(0, Number(physicsBounceEl.value) / 100));
    if (physicsBounceOut) physicsBounceOut.textContent = Math.round(physics.env.restitution * 100) + '%';
  }
}

function physicsSeed(count) {
  physics.balls.length = 0;
  for (let i = 0; i < count; i++) spawnPhysicsBall(physics.balls, Math.random);
  physicsHud();
  drawPhysics();
}

function physicsBoot() {
  if (!physicsCanvas) return;// 这一节只在 /games 上有

  physicsReadSliders();
  physicsSeed(7);

  if (physicsGravityEl) physicsGravityEl.addEventListener('input', physicsReadSliders);
  if (physicsBounceEl) physicsBounceEl.addEventListener('input', physicsReadSliders);

  const toggleBtn = el('physics-toggle');
  if (toggleBtn) {
    toggleBtn.addEventListener('click', physicsToggle);
    toggleBtn.textContent = '暂停';
  }
  const addBtn = el('physics-add');
  if (addBtn) {
    addBtn.addEventListener('click', function () {
      const ball = spawnPhysicsBall(physics.balls, Math.random);
      physicsStatus(ball ? '又放了一颗，现在 ' + physics.balls.length + ' 颗。'
        : '到 ' + PHYSICS_MAX_BALLS + ' 颗就封顶了（碰撞判断是 O(n²)，再多会开始掉帧）。');
      physicsHud();
      drawPhysics();
    });
  }
  const clearBtn = el('physics-clear');
  if (clearBtn) {
    clearBtn.addEventListener('click', function () {
      physics.balls.length = 0;
      physics.grabbed = null;
      physics.hits = 0;
      physicsHud();
      drawPhysics();
      physicsStatus('清空了。「加几颗球」先扔 7 颗进去看看。');
    });
  }
  const seedBtn = el('physics-seed');
  if (seedBtn) {
    seedBtn.addEventListener('click', function () {
      physicsSeed(7);
      physicsStatus('重新扔了 7 颗。');
    });
  }

  // 翻面卡片：纯 CSS 的 3D 变换，这里只负责加/去 class
  const flipBtn = el('physics-flip');
  if (flipBtn) {
    const card = el('physics-card');
    flipBtn.addEventListener('click', function () {
      if (!card) return;
      const turned = card.classList.toggle('flipped');
      flipBtn.textContent = turned ? '翻回正面' : '看看规矩';
      flipBtn.setAttribute('aria-expanded', turned ? 'true' : 'false');
    });
  }

  physicsCanvas.addEventListener('pointerdown', physicsPointerDown);
  physicsCanvas.addEventListener('pointermove', physicsPointerMove);
  physicsCanvas.addEventListener('pointerup', physicsPointerUp);
  physicsCanvas.addEventListener('pointercancel', physicsPointerUp);
  // 指针移出画布时补一次松手：没走到 pointerup 的话球会一直跟着鼠标跑
  physicsCanvas.addEventListener('pointerleave', physicsPointerUp);

  if (prefersReducedMotion()) {
    physicsStatus('这一节的动画已经按系统偏好停在起始帧了，要跑起来点「继续」。');
    const btn = el('physics-toggle');
    if (btn) btn.textContent = '继续';
  } else {
    physicsStart();
  }
}

physicsBoot();

/* ============================================================
   第 7 部分：规则自测（只测第 1 部分那些纯函数）
   ============================================================ */

function physicsSelfTest() {
  function expect(label, actual, expected) {
    if (Math.abs(actual - expected) > 0.0001) {
      throw new Error('弹球规则不对：' + label + '（期望 ' + expected + '，实际 ' + actual + '）');
    }
  }
  function near(label, actual, expected) {
    if (!(Math.abs(actual - expected) <= Math.abs(expected) * 0.02 + 0.02)) {
      throw new Error('弹球规则不对：' + label + '（约等于 ' + expected + '，实际 ' + actual + '）');
    }
  }
  function ball(x, y, vx, vy, r) {
    return makePhysicsBall(x, y, vx, vy || 0, r || 3, 200);
  }
  const env = physicsEnv(70, 0.8);

  // 积分：先重力后位移，一步就看得出现在该在哪儿。
  // 这里把空气阻尼关掉（drag=0），否则速度和位移都带着 exp(-drag·dt) 这个因子，
  // 断言就退化成「约等于」了——阻尼本身另外单独测。
  const vacuum = physicsEnv(70, 0.8);
  vacuum.drag = 0;
  const falling = ball(50, 10, 0, 0, 3);
  integrateBall(falling, 0.1, vacuum);
  expect('自由落体一步的竖直速度', falling.vy, 7);// 70 × 0.1
  expect('位移用的是新速度（半隐式）', falling.y, 10.7);// 显式欧拉会给 10，这才是两者的差别
  expect('自由落体不动水平', falling.x, 50);

  // 阻尼：重力关掉时只该减速，不该加速
  const glide = ball(50, 30, 100, 0, 3);
  integrateBall(glide, 0.1, physicsEnv(0, 0.8));
  expect('空气阻尼只减不增', glide.vx < 100 && glide.vx > 0, true);

  // 速度封顶：不然一步跨过另一颗球，物理看起来像「穿过去了」
  const fast = ball(50, 30, 5000, 0, 3);
  integrateBall(fast, PHYSICS_STEP, env);
  expect('水平速度被截到上限', Math.hypot(fast.vx, fast.vy), PHYSICS_MAX_SPEED);
  expect('一步走的距离比最小的球还短（所以不会整个穿过去）',
    PHYSICS_MAX_SPEED * PHYSICS_STEP < PHYSICS_MIN_RADIUS, true);

  // 四面墙：往下穿过地面才叫落地
  const dropped = ball(50, PHYSICS_H + 9, 0, 40, 3);
  expect('落地会算一次碰墙', bounceWalls(dropped, env), 1);
  expect('落地后被托回地面内侧', dropped.y, PHYSICS_H - 3);
  expect('落地后竖直反向并留 80%', dropped.vy, -32);
  const rolling = ball(50, PHYSICS_H - 2.5, 10, 1, 3);
  bounceWalls(rolling, env);
  expect('贴地滚时水平速度被摩擦吃掉', rolling.vx, 9);
  const jitter = ball(50, PHYSICS_H - 2.5, 0, 1.5, 3);
  bounceWalls(jitter, env);
  expect('很轻的落地不再弹（防抖动）', jitter.vy, 0);
  // 正在离开地面的一刻不许再弹一次：这是「球被地面吸住」那个 bug 的根
  const leaving = ball(50, PHYSICS_H - 3, 10, -1, 3);
  expect('刚弹起来、还嵌在地面里时不算碰地', bounceWalls(leaving, env), 0);
  expect('所以水平速度也没被摩擦吃掉', leaving.vx, 10);
  expect('竖直方向保持向上', leaving.vy, -1);
  const right = ball(PHYSICS_W + 5, 30, 12, 0, 3);
  bounceWalls(right, env);
  expect('撞右墙反向', right.vx, -9.6);
  expect('撞右墙后回到内侧', right.x, PHYSICS_W - 3);
  const escaping = ball(PHYSICS_W + 5, 30, -12, 0, 3);
  expect('已经往回走了就不算撞墙', bounceWalls(escaping, env), 0);
  expect('但位置仍然被挪回内侧', escaping.x, PHYSICS_W - 3);
  expect('挪回来不该改速度', escaping.vx, -12);
  const left = ball(-5, 30, -10, 0, 3);
  bounceWalls(left, env);
  expect('撞左墙反向', left.vx, 8);
  expect('撞左墙后回到内侧', left.x, 3);
  const ceiled = ball(50, -5, 0, -10, 3);
  bounceWalls(ceiled, env);
  expect('撞天花板反向', ceiled.vy, 8);
  expect('撞天花板后回到内侧', ceiled.y, 3);

  // 等质量对撞：动量交换，总动量守恒
  const a = ball(40, 30, 60, 0, 3);
  const b = ball(45.5, 30, -60, 0, 3);
  const before = a.vx + b.vx;
  expect('两个球确实重叠', overlapDepth(a, b) > 0, true);
  expect('对撞判成一次碰撞', resolvePair(a, b, env), true);
  expect('等质量对撞：左边被弹回', a.vx, -48);// j = 1.8×120 / (2/9) = 972，每边各担 1/9
  expect('等质量对撞：右边被弹回', b.vx, 48);
  expect('总动量守恒', a.vx + b.vx, before);
  expect('分开后不再重叠', overlapDepth(a, b) <= 0.02, true);
  expect('碰撞后动能不会变大（弹性系数 0.8 只许损耗）',
    0.5 * a.mass * a.vx * a.vx + 0.5 * b.mass * b.vx * b.vx <
    0.5 * a.mass * 60 * 60 + 0.5 * b.mass * 60 * 60, true);

  // 大球撞小球：小球该被撞得比大球原速还快，大球几乎不停
  const big = ball(50, 30, 80, 0, 4.6);
  const small = ball(56.5, 30, 0, 0, 2.4);// 半径 4.6+2.4=7 > 间距 6.5，确实碰上了
  expect('大球小球判成一次碰撞', resolvePair(big, small, env), true);
  expect('大球撞完还在往前走', big.vx > 0, true);
  expect('小球被撞飞的速度比大球原速还快', small.vx > 80, true);
  expect('重的那颗速度变化小', Math.abs(80 - big.vx) < small.vx, true);

  // 追上的两个球方向不能反：后面的快球撞前面的慢球，前面的必须被推快
  const chaser = ball(30, 30, 50, 0, 3);
  const runner = ball(35.5, 30, 10, 0, 3);
  resolvePair(chaser, runner, env);
  expect('被撞的那颗加速了', runner.vx > 10, true);
  expect('追的那颗减速了', chaser.vx < 50, true);

  // 按住的球当成撞不动：自己不获得冲量，对方按撞上墙一样弹回去
  const held = ball(50, 30, 0, 0, 3);
  held.grabbed = true;
  const passer = ball(55, 30, -40, 0, 3);
  resolvePair(held, passer, env);
  expect('按住的球不会被撞得乱飞', held.vx, 0);
  expect('按住的球位置也不动', held.x, 50);
  expect('撞上按住的球会原路弹回', passer.vx, 32);// 撞不动的东西＝墙：分开速度按弹性系数打八折
  expect('对方被推离接触点', passer.x > 55, true);

  // 已经分开的两个球即使还重叠，也不许再给冲量（否则会把刚分开的球吸回来）
  const parting = ball(40, 30, -50, 0, 3);
  const parting2 = ball(45, 30, 50, 0, 3);
  expect('分开的两个球仍然判成重叠要推开', resolvePair(parting, parting2, env), true);
  expect('正在分开的球速度不变', parting.vx, -50);
  expect('但重叠还是被推开了', parting.x < 40, true);

  // 整帧推进：数量不变、不会跑到界外、最后竖直方向收干净
  const pack = [ball(20, 55, 40, -30, 3), ball(30, 20, -25, 10, 4), ball(60, 40, 15, 0, 2.5)];
  for (let i = 0; i < 1600; i++) stepBalls(pack, PHYSICS_STEP, env);// ≈13 秒
  expect('推进若干帧后球还是那些球', pack.length, 3);
  let inside = true;
  for (let i = 0; i < pack.length; i++) {
    const p = pack[i];
    if (p.x - p.r < -0.001 || p.x + p.r > PHYSICS_W + 0.001
      || p.y - p.r < -0.001 || p.y + p.r > PHYSICS_H + 0.001) inside = false;
    expect('跑得久了竖直方向会彻底停下', p.vy, 0);
  }
  expect('跑久了也不会跑到界外', inside, true);
  near('最后都落在地面上', pack[0].y + pack[0].r, PHYSICS_H);
  // 在不在动只看速度：贴地滚动的球仍然算「在动」，界面才不会谎报「都停了」
  expect('慢到看不出来就不算在动', movingBalls([ball(50, PHYSICS_H - 3, 0.5, 0, 3)]), 0);
  expect('贴地还在滚的算在动', movingBalls([ball(50, PHYSICS_H - 3, 2, 0, 3)]), 1);
  expect('正在飞的算在动', movingBalls([ball(50, 30, 20, 0, 3)]), 1);
  expect('按住的球永远算在动（它跟着指针）', movingBalls([held]), 1);

  // 生成与拾取
  const emptyBalls = [];
  function always(v) { return function () { return v; }; }
  const seeded = spawnPhysicsBall(emptyBalls, always(0), 50, 40);
  expect('按给定位置生成', seeded.x + ',' + seeded.y, '50,40');
  expect('生成后计数 +1', emptyBalls.length, 1);
  expect('初始竖直速度为 0（不是随机）', seeded.vy, 0);
  const many = [];
  for (let i = 0; i < 60; i++) spawnPhysicsBall(many, always(0.5), 50, 30);
  expect('球数封顶', many.length, PHYSICS_MAX_BALLS);
  expect('封顶时返回 null 让界面能说人话', spawnPhysicsBall(many, always(0.5), 50, 30), null);
  const top = ball(50, 30, 0, 0, 3);
  const under = ball(50.5, 30.5, 0, 0, 3);
  expect('压在一起时选中最上面那颗（后画的优先）', ballAtPoint([top, under], 50.5, 30.5), under);
  expect('半径外一点点的容差内仍然选中（手指按不准）', ballAtPoint([top, under], 46.2, 30), top);
  expect('离得远就选不中', ballAtPoint([top, under], 70, 30), null);
  expect('空沙盒里点哪儿都选不中', ballAtPoint([], 50, 30), null);
}

SMOKE_TESTS.push(physicsSelfTest);
