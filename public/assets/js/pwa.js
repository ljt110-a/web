/* ============================================================
   PWA 的两件小事：注册 Service Worker、以及在能装的时候露出「安装到桌面」

   单独一个文件而不是塞进 core.js：core.js 是每个页面都用的基础能力
   （api / el / 提示条），而这一份全是「装了更好、没装也无所谓」的加分项，
   任何一步失败都该安静地咽掉——用户不需要知道离线功能没起来。

   顶层名字一律 pwa 前缀：普通 <script> 的顶层 const 是**共享的全局词法作用域**，
   和同一页上别的脚本撞名字是整页 SyntaxError。
   ============================================================ */

/** 注册 SW。地址必须写绝对路径 /sw.js：它的可控范围由 URL 决定 */
function pwaRegister() {
  if (!('serviceWorker' in navigator)) return;// 老浏览器，或 http 明文（非 localhost）下浏览器直接不给
  window.addEventListener('load', function () {
    // 放到 load 之后：注册会触发 install → 取页面资源，别和首屏抢带宽
    navigator.serviceWorker.register('/sw.js').catch(function () {
      // 注册失败最常见的原因是部署在 http://非本机 下——离线是加分项，不值得弹提示
    });
  });
}

/**
 * 「安装到桌面」的入口。
 *
 * 浏览器自己会在地址栏右侧放一个安装图标，但那个位置在手机浏览器和多数车机浏览器里
 * 要么没有、要么藏在菜单里，用户找不到。所以这里在页脚补一个真按钮：
 * 只有浏览器真的认为可安装（抛出 beforeinstallprompt）时才把它插进 DOM，
 * 已经装过了、或者浏览器不支持，就什么都不加——不留一个点了没反应的按钮。
 */
function pwaInstallButton() {
  let deferred = null;

  window.addEventListener('beforeinstallprompt', function (event) {
    event.preventDefault();// 拦住浏览器自己的自动安装弹窗，改由用户点这个按钮触发
    deferred = event;
    const footer = document.querySelector('.footer');
    if (!footer || document.getElementById('pwa-install')) return;
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.id = 'pwa-install';
    btn.className = 'btn-mini pwa-install';
    btn.textContent = '安装到桌面';
    btn.addEventListener('click', function () {
      if (!deferred) return;
      deferred.prompt();// 交给浏览器弹系统级的安装框，样式我们管不着也不该管
      deferred.userChoice.then(function (choice) {
        // 无论用户选了什么，这一次机会就用掉了，按钮留着只会点了没反应
        deferred = null;
        btn.disabled = true;
        btn.textContent = choice && choice.outcome === 'accepted' ? '正在安装…' : '安装已取消';
      });
    });
    footer.appendChild(btn);
  });

  window.addEventListener('appinstalled', function () {
    const btn = document.getElementById('pwa-install');
    if (btn) {
      btn.disabled = true;
      btn.textContent = '已安装';
    }
  });
}

pwaRegister();
pwaInstallButton();
