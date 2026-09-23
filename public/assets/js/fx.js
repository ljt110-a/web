/* ============================================================
   首页背景特效：一束光穿过玻璃三棱镜
   两束光从上方斜插下来，汇聚在棱镜底边那一点，再从那里散成一道彩虹；
   背景是缓慢漂移的星点。全部用 Canvas 2D 手写，不引任何库或 CDN：
   既守得住 CSP 的 script-src 'self'，也保证离线可用。

   这个文件只在首页加载（见 public/index.html 底部的 script 标签）。
   另外还负责下面各节的「滚动到才入场」，见文件末尾的 initReveals()。
   ============================================================ */
(function () {
  'use strict';

  const canvas = document.getElementById('fx');
  if (!canvas) return;
  const ctx = canvas.getContext ? canvas.getContext('2d') : null;
  if (!ctx) return;

  const SPECTRUM = ['#ff4d4d', '#ff9f43', '#ffe066', '#5ee6a8', '#4cc9f0', '#7b5cff', '#ff6b9d'];
  const reduceMotion = window.matchMedia
    ? window.matchMedia('(prefers-reduced-motion: reduce)').matches
    : false;

  let W = 0;
  let H = 0;
  let stars = [];
  let scene = null;
  let dim = 1;// 往下滚之后把背景压暗，别让光束和正文抢眼睛
  const pointer = { x: 0, y: 0, ex: 0, ey: 0 };// 鼠标视差：目标是光标位置，实际值缓慢追上去

  // ------------------------------------------------------------
  // 尺寸与场景几何
  // ------------------------------------------------------------

  function measure() {
    // 上限 1.75 倍：再高的话整屏像素数太多，每帧重绘会掉帧，而肉眼看不出差别
    const dpr = Math.min(window.devicePixelRatio || 1, 1.75);
    W = window.innerWidth;
    H = window.innerHeight;
    canvas.width = Math.max(1, Math.round(W * dpr));
    canvas.height = Math.max(1, Math.round(H * dpr));
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    buildScene();
    seedStars();
    updateDim();
  }

  /**
   * 棱镜的位置随屏幕宽度走：宽屏放在右侧给左边的标题让位，
   * 窄屏挪到下方居中，否则会和正文叠在一起。
   */
  function buildScene() {
    const wide = W >= 900;
    const size = Math.min(W, H) * (wide ? 0.3 : 0.24);
    const cx = wide ? W * 0.71 : W * 0.5;
    const cy = wide ? H * 0.5 : H * 0.72;
    scene = {
      wide: wide,
      size: size,
      apex: { x: cx, y: cy - size * 0.92 },
      left: { x: cx - size, y: cy + size * 0.66 },
      right: { x: cx + size, y: cy + size * 0.66 },
      // 两束光的汇聚点在底边上偏左一点，彩虹就从这里散出去
      focus: { x: cx - size * 0.08, y: cy + size * 0.66 },
    };
  }

  function seedStars() {
    const count = Math.round(Math.min(190, (W * H) / 11000));
    stars = [];
    for (let i = 0; i < count; i++) {
      stars.push({
        x: Math.random() * W,
        y: Math.random() * H,
        r: 0.35 + Math.random() * 1.1,
        a: 0.1 + Math.random() * 0.45,
        phase: Math.random() * Math.PI * 2,
        blink: 0.4 + Math.random() * 1.1,
        drift: 2 + Math.random() * 7,// 每年向上漂多少像素，慢到几乎察觉不到才有空间感
      });
    }
  }

  function updateDim() {
    const scrolled = Math.min(1, (window.pageYOffset || 0) / (H * 1.15));
    dim = 1 - scrolled * 0.66;
  }

  // ------------------------------------------------------------
  // 画笔
  // ------------------------------------------------------------

  /** #rrggbb + 透明度 → rgba()，画渐变要用字符串 */
  function rgba(hex, alpha) {
    const n = parseInt(hex.slice(1), 16);
    return 'rgba(' + ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + alpha + ')';
  }

  /**
   * 从 (x, y) 出发、沿 angle 方向画一条「光」。
   * 起点最亮、越远越淡，所以调用方传的方向都是「从汇聚点指回光源」。
   * 一笔只有一条细线，靠两笔宽的半透明副本叠出辉光，比 shadowBlur 便宜得多。
   */
  function ray(x, y, angle, len, width, color, alpha) {
    ctx.save();
    ctx.translate(x, y);
    ctx.rotate(angle);
    const g = ctx.createLinearGradient(0, 0, len, 0);
    g.addColorStop(0, rgba(color, alpha));
    g.addColorStop(0.45, rgba(color, alpha * 0.66));
    g.addColorStop(1, rgba(color, 0));
    ctx.fillStyle = g;
    ctx.fillRect(0, -width / 2, len, width);
    ctx.restore();
  }

  /** 同一条光画三层：细亮芯 + 中晕 + 大范围淡辉光 */
  function beam(x, y, angle, len, color, strength) {
    ray(x, y, angle, len, 26, color, strength * 0.09);
    ray(x, y, angle, len, 7, color, strength * 0.24);
    ray(x, y, angle, len, 1.8, color, strength);
  }

  function drawStars(t) {
    ctx.fillStyle = '#ffffff';
    for (let i = 0; i < stars.length; i++) {
      const s = stars[i];
      const twinkle = 0.55 + 0.45 * Math.sin(t * s.blink + s.phase);
      ctx.globalAlpha = s.a * twinkle;
      ctx.beginPath();
      ctx.arc(s.x, s.y, s.r, 0, Math.PI * 2);
      ctx.fill();
    }
  }

  function moveStars(dt) {
    for (let i = 0; i < stars.length; i++) {
      const s = stars[i];
      s.y -= s.drift * dt;
      if (s.y < -2) {
        s.y = H + 2;
        s.x = Math.random() * W;
      }
    }
  }

  /** 三棱镜：一层极淡的玻璃填充 + 两条描边（外沿和往里收一圈，做出厚度） */
  function drawPrism() {
    const s = scene;
    const points = [s.apex, s.right, s.left];
    const centroid = {
      x: (s.apex.x + s.left.x + s.right.x) / 3,
      y: (s.apex.y + s.left.y + s.right.y) / 3,
    };

    function path(scale) {
      ctx.beginPath();
      for (let i = 0; i < points.length; i++) {
        const x = centroid.x + (points[i].x - centroid.x) * scale;
        const y = centroid.y + (points[i].y - centroid.y) * scale;
        if (i === 0) ctx.moveTo(x, y);
        else ctx.lineTo(x, y);
      }
      ctx.closePath();
    }

    const g = ctx.createLinearGradient(s.apex.x, s.apex.y, s.left.x, s.left.y);
    g.addColorStop(0, rgba('#ffffff', 0.09));
    g.addColorStop(0.55, 'rgba(255,255,255,0.015)');
    g.addColorStop(1, rgba('#ffffff', 0.06));
    ctx.fillStyle = g;
    path(1);
    ctx.fill();

    ctx.strokeStyle = 'rgba(255,255,255,0.22)';
    ctx.lineWidth = 1.1;
    path(1);
    ctx.stroke();

    ctx.strokeStyle = 'rgba(255,255,255,0.07)';
    ctx.lineWidth = 1;
    path(0.9);
    ctx.stroke();
  }

  function draw(t, dt) {
    ctx.clearRect(0, 0, W, H);
    // 往下滚就整体压暗：光束退到背景里，正文自己待在前层
    ctx.globalAlpha = dim;
    ctx.globalCompositeOperation = 'lighter';

    // 星点单独一层视差，幅度只有光束的一半，远近感就出来了
    ctx.save();
    ctx.translate(pointer.ex * 5, pointer.ey * 5);
    drawStars(t);
    ctx.restore();

    ctx.save();
    ctx.translate(pointer.ex * 16, pointer.ey * 16);

    const s = scene;
    const reach = Math.max(W, H) * 1.7;
    // 两条光缓慢地各自摆一点角度，像有人把光源慢慢挪动
    const leftAngle = -2.08 + 0.1 * Math.sin(t * 0.11);
    const rightAngle = -1.02 + 0.085 * Math.sin(t * 0.087 + 1.7);
    const pulse = 0.86 + 0.14 * Math.sin(t * 0.6);

    beam(s.focus.x, s.focus.y, leftAngle, reach, '#4cc9f0', 0.9 * pulse);
    beam(s.focus.x, s.focus.y, rightAngle, reach, '#ffffff', 0.8 * pulse);
    drawPrism();

    // 汇聚点那颗白核：光在这里最亮，彩虹从这里离开
    const glow = ctx.createRadialGradient(s.focus.x, s.focus.y, 0, s.focus.x, s.focus.y, s.size * 0.55);
    glow.addColorStop(0, rgba('#ffffff', 0.55 * pulse));
    glow.addColorStop(0.25, rgba('#ffffff', 0.14));
    glow.addColorStop(1, 'rgba(255,255,255,0)');
    ctx.fillStyle = glow;
    ctx.fillRect(s.focus.x - s.size, s.focus.y - s.size, s.size * 2, s.size * 2);

    // 色散：同一个起点，每种颜色偏一点角度，扇开就是彩虹
    const fanBase = 2.16 + 0.05 * Math.sin(t * 0.09);
    for (let i = 0; i < SPECTRUM.length; i++) {
      const step = i / (SPECTRUM.length - 1) - 0.5;
      beam(s.focus.x, s.focus.y, fanBase + step * 0.34, H * 0.95, SPECTRUM[i], 0.34 * pulse);
    }

    ctx.restore();
    ctx.globalCompositeOperation = 'source-over';
    ctx.globalAlpha = 1;
  }

  // ------------------------------------------------------------
  // 运行
  // ------------------------------------------------------------

  let raf = 0;
  let last = 0;

  function frame(now) {
    const t = now / 1000;
    const dt = Math.min(0.05, (now - last) / 1000 || 0.016);
    last = now;
    // 视差用插值追目标，鼠标停下后还会自己滑回去，不会有「跟手但生硬」的感觉
    pointer.ex += (pointer.x - pointer.ex) * Math.min(1, dt * 2.2);
    pointer.ey += (pointer.y - pointer.ey) * Math.min(1, dt * 2.2);
    moveStars(dt);
    draw(t, dt);
    raf = window.requestAnimationFrame(frame);
  }

  function start() {
    if (raf || reduceMotion) return;
    last = window.performance ? performance.now() : 0;
    raf = window.requestAnimationFrame(frame);
  }

  function stop() {
    if (!raf) return;
    window.cancelAnimationFrame(raf);
    raf = 0;
  }

  /** 静态一帧：给「减少动态效果」的用户，画面还在，只是不动 */
  function still() {
    pointer.ex = pointer.ey = 0;
    draw(1.2, 0);
  }

  function renderOnce() {
    if (reduceMotion) still();
  }

  window.addEventListener('resize', function () {
    measure();
    renderOnce();
  });
  window.addEventListener('scroll', function () {
    updateDim();
    if (reduceMotion) still();// 没有循环可依赖，滚动了手动补一帧把新的明暗画出来
  }, { passive: true });
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) {
      stop();
      return;
    }
    // 标签页第一次被切到前台时，innerWidth 可能刚从 0 变成真实值。
    // 尺寸不对的话整个场景的坐标都是 0，看着就像"背景是空的"，这里补测一次。
    if (window.innerWidth !== W || window.innerHeight !== H) measure();
    start();
    renderOnce();
  });

  if (!reduceMotion) {
    window.addEventListener('pointermove', function (e) {
      pointer.x = (e.clientX / Math.max(1, W) - 0.5) * 2;
      pointer.y = (e.clientY / Math.max(1, H) - 0.5) * 2;
    }, { passive: true });
  }

  measure();
  if (reduceMotion) {
    still();
  } else {
    start();
  }

  /* ------------------------------------------------------------
     下面各节的入场：滚进视口才显现，同一容器里的元素按顺序错开
     ------------------------------------------------------------ */
  function initReveals() {
    const items = Array.prototype.slice.call(document.querySelectorAll('[data-reveal]'));
    if (items.length === 0) return;

    items.forEach(function (node) {
      const siblings = Array.prototype.slice.call(node.parentNode.querySelectorAll('[data-reveal]'));
      node.style.setProperty('--i', siblings.indexOf(node));
    });

    if (!('IntersectionObserver' in window)) {
      items.forEach(function (node) { node.classList.add('is-in'); });
      return;
    }

    const io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        entry.target.classList.add('is-in');
        io.unobserve(entry.target);// 入场是一次性的，反复触发反而晃眼
      });
    }, { threshold: 0.15, rootMargin: '0px 0px -8% 0px' });

    items.forEach(function (node) { io.observe(node); });
  }

  // 首屏那几行的上升是 CSS 关键帧，页面一打开自己就跑，脚本不用参与；这里只管区块入场。
  initReveals();
})();
