<?php
/**
 * 小说阅读板块（/read、novels 与 novel_chapters）用例。
 *
 * 这一张表的特殊性在于：**导入不是「写一行数据」，而是一次带规则的解析**。
 * 所以用例的重心有三块，缺一块都会留下别的测试盖不到的洞：
 *
 * 1. 识别规则本身（哪些行算标题、哪些不算）。规则只住在 src/novels.php 里，
 *    这里逐条把它钉住——尤其那两条容易反悔的：文件开头那份「目录」不能变成几百个空章，
 *    以及正文里的「1、」不能把一章劈成两半。
 * 2. 账号隔离。和 memos / pomodoros 同一套理由：id 与 user_id 永远一起进 WHERE，
 *    所以拿别人的书 id 做任何事都是 404 而不是 403——404 才是「不透露别人的数据」。
 * 3. 边界值：字数下限、账号总量配额、章数上限、单章超长切分、进度越界。
 *
 * 三道上限在 tests/run.php 里被环境变量压小了（书架本数 3、账号总量 12 万字、单本 40 章），
 * 为的是几秒钟就把「拒绝」那条分支真的跑一遍，而不是只读一遍代码。
 * 本数压到 3 的代价是：**每个账号最多只能存三本**，所以下面每组各用自己的账号。
 * 单次导入**没有**字数上限了（一本连载完的长篇就是几百万字），所以这里既有用例
 * 证明四万多字的一本能直接进来，也有用例证明灌到账号总量会被拒。
 */

$nvRoot = dirname(__DIR__, 2);

/** 一个自然段：36 字。要凑够 200 字的「像一本书」门槛得多叠几段 */
$nvPara = '他提起那柄无锋的旧铁剑，推开柴房那扇吱呀作响的木门，山风立刻灌满了袖口。';
/** 造 n 段正文（默认 6 段 = 228 字） */
$nvBody = function ($times = 6) use ($nvPara) {
    return implode("\n", array_fill(0, $times, $nvPara));
};
/** 注册一个专用账号并返回 jar 名（书架本数只有 3，不能几个组共用一个账号） */
$nvAccount = function ($name) {
    t_clear_jar($name);
    $res = t_request('POST', '/api/register', [
        'json' => ['username' => $name, 'password' => 'nvpass123'],
        'jar' => $name,
    ]);
    t_eq(201, $res['status'], '测试账号 ' . $name . ' 已注册');
    return $name;
};

t_section('小说阅读：二级页面路由');

$res = t_request('GET', '/read');
t_eq(200, $res['status'], 'GET /read 返回 200');
t_contains($res['headers']['content-type'], 'text/html', '返回的是 HTML');
t_contains($res['body'], '小说阅读', '页面里有板块标题');
t_contains($res['body'], '/assets/js/reader.js', '页面引用了阅读脚本（绝对路径）');
foreach (['shelf-list', 'read-import', 'import-file', 'import-file-name', 'reader',
          'reader-text', 'reader-toc', 'reader-page', 'reader-status', 'read-gate'] as $nvId_) {
    t_contains($res['body'], 'id="' . $nvId_ . '"', 'HTML 里有 ' . $nvId_ . '（reader.js 按它取元素）');
}
t_assert(isset($res['headers']['content-security-policy']), '二级页面也带 CSP');

$res = t_request('GET', '/read.html');
t_eq(200, $res['status'], '/read.html 也能访问（与 /read 是同一个文件）');
$res = t_request('GET', '/read/');
t_eq(200, $res['status'], '/read/ 带斜杠也能访问');
$res = t_request('GET', '/read/no-such-page');
t_eq(404, $res['status'], '阅读板块下的未知路径返回 404');

// 四个页面互相走得到：手机上导航是隐藏的，页脚那几个链接是唯一的出口
foreach (['/' => '首页', '/games' => '游戏', '/study' => '学习'] as $nvPath => $nvLabel) {
    $res = t_request('GET', $nvPath);
    t_contains($res['body'], 'href="/read"', $nvLabel . '页上有通往阅读板块的入口');
}

$nvHtml = file_get_contents($nvRoot . '/public/read.html');
$coreAt = strpos($nvHtml, 'src="/assets/js/core.js"');
$readAt = strpos($nvHtml, 'src="/assets/js/reader.js"');
t_assert($readAt !== false && $coreAt !== false && $coreAt < $readAt,
    'core.js 排在 reader.js 前面：el() 与 api() 先定义才用得到');
$nvInline = [];
t_assert(preg_match('/\son(click|pointerdown|input|change|keydown|load)=/i', $nvHtml, $nvInline) === 0,
    'read.html 里没有内联事件属性：CSP 的 script-src 是 ‘self’，内联的会被直接拦掉',
    $nvInline ? '找到 ' . $nvInline[0] : '');

// 导入方式是「选一个本地 txt」而不是「粘一大段」。这三条钉的是页面结构本身：
// 结构被换回去时它们会红，而不是让 reader.js 静默取不到元素
t_contains($nvHtml, 'type="file"', '导入用的是原生文件选择框');
t_contains($nvHtml, 'accept=".txt', '文件框只列得见 txt：少一层「选了本书籍进来」的错');
t_assert(strpos($nvHtml, '<textarea') === false, '这一页没有 textarea：正文不再靠粘贴');

// 找的是属性写法而不是这个词本身：文件顶部的说明注释里也写着 innerHTML，
// 只匹配裸词会把一条合法的注释当成违规
$nvJs = file_get_contents($nvRoot . '/public/assets/js/reader.js');
// 后端刻意不对正文转义（那是用户自己的小说），所以「文件里的内容不许变成代码」全压在这一侧
t_assert(strpos($nvJs, '.innerHTML') === false,
    'reader.js 里没有一处 .innerHTML 赋值：正文只用 textContent 建节点');
t_contains($nvJs, 'SMOKE_TESTS.push(readerSelfTest);', 'reader.js 把自己的规则登记进 SMOKE_TESTS');
// 跳段时多滚 1px：scrollTo 取整、段落 rect 是小数，段落顶边可能落在阅读线下方零点几像素处，
// 于是刚恢复的进度会被下一次保存算成上一段（表现为「接着读每次都往前退一段」）。
// 2026-09-23 在真浏览器里量到过（line=113.4 / top=113.75），这一行别当多余代码删掉
t_contains($nvJs, '- READ.readLine + 1', '跳段对齐多滚 1px，恢复的段号不会被自己的判定退回去');

// 读文件那几步各钉一条：它们出错时页面是「看着正常、读出来是乱码」，最难靠肉眼发现
t_contains($nvJs, 'readAsArrayBuffer', '文件按字节读进来：编码要自己认，不能让浏览器替你猜');
t_contains($nvJs, '{ fatal: i === 0 }',
    '只有 UTF-8 那一次用严格解码：不严格就永远「成功」，GBK 的文件会解成满屏问号');
t_contains($nvJs, 'gb18030', 'UTF-8 解不动时退到 GB18030（GBK 的超集）');
t_contains($nvJs, 'filename: pick.name', '文件名跟着正文一起送：它是书名猜不出来时的兜底');
t_contains($nvJs, "importFileEl.value = ''",
    '读完把 input 的 value 清掉：不然「再选一次同一个文件」根本不触发 change，用户卡在原地');
t_contains($nvJs, 'readByteCeiling', '大文件先按字节数挡一刀，不整个读进内存');
t_contains($nvJs, 'readRemainingChars(readState.stats, readState.limits)',
    '那一刀按「书架还剩多少字」算：本站不限单本大小，限的是账号总量');
t_contains($nvJs, 'readLooksBinary(bytes)',
    '选文件时先在本地嗅一次二进制：不用等几十兆传上去才知道这不是文本');
t_contains($nvJs, "const notText = readLooksBinary(bytes)",
    '嗅出不是文本就不解码：省掉为一份 exe 白占一块内存');
$nvPick = substr($nvJs, strpos($nvJs, "importFileEl.addEventListener('change'"));
t_assert(strpos($nvPick, "setMsg(importTipEl, '', '')") < strpos($nvPick, 'if (!file)'),
    '换文件时先把上一条拒信擦掉，而且擦在分支前面：不擦的话正常文件的字数一路涨、'
    . '红字却还挂着，看着像依然没过');
t_contains($nvHtml, '只收纯文本', '页面上写着只收纯文本：让人在选之前就知道规则');
t_assert(strpos($nvHtml, '500000') === false,
    '页面里没有写死的旧「单次 50 万字」：那道上限已经拿掉了，留着就是句假话');

t_section('小说阅读：游客碰不到任何一本书');

$nvGuests = [
    ['GET', '/api/novels', null],
    ['POST', '/api/novels', ['title' => 'x', 'text' => 'y']],
    ['POST', '/api/novels/update', ['id' => 1, 'title' => 'x']],
    ['DELETE', '/api/novels', ['id' => 1]],
    ['GET', '/api/novel?id=1', null],
    ['GET', '/api/novel/chapter?id=1&seq=0', null],
    ['POST', '/api/novel/progress', ['id' => 1, 'chapter' => 0, 'paragraph' => 0]],
];
foreach ($nvGuests as $nvRow) {
    $options = ['jar' => 'nv_anon'];
    if ($nvRow[2] !== null) {
        $options['json'] = $nvRow[2];
    }
    $res = t_request($nvRow[0], $nvRow[1], $options);
    // 和 /api/study 刻意不同：书架是私有数据，游客连「配置」都拿不到
    t_eq(401, $res['status'], '未登录 ' . $nvRow[0] . ' ' . $nvRow[1] . ' 返回 401');
}

t_section('小说阅读：识别章节');

$nvA = $nvAccount('nv_a');

/*
 * 这份样本一次跑到四条规则：
 *   · 《剑影残章》与题记在第一个标题之前 → 成一章「前言」，一个字都不丢
 *   · 「目录」下面连着三行都像标题、中间没有正文 → 整块退回普通文字，不切出三个空章
 *   · 「1、…」是正文里的编号：文本里有命名式标题，纯数字式就彻底不作数
 *   · 「尾声」这类栏目标题也算标题
 */
$nvTextA = implode("\n", [
    '《剑影残章》',
    '题记：所有的手都松开了。',
    '',
    '目录',
    '第一章 出山',
    '第二章 断刃',
    '第三章 归途',
    '',
    '第一章 出山',
    $nvBody(4),
    '1、这一行只是正文里的编号，不该把一章劈成两半',
    '',
    '第二章 断刃',
    $nvBody(3),
    '',
    '尾声',
    $nvBody(2),
]);

$res = t_request('POST', '/api/novels', ['json' => ['title' => '剑影残章', 'text' => $nvTextA], 'jar' => $nvA]);
t_eq(201, $res['status'], '送一段正文就能存进书架');
$nvId = (int) $res['json']['novel']['id'];
t_assert($nvId > 0, '返回新建书的 id');
t_eq('剑影残章', $res['json']['novel']['title'], '用户填的书名优先于猜出来的');
t_eq(4, $res['json']['novel']['chapterCount'], '认出 4 章：前言 + 两章正文 + 尾声');
t_eq(['前言', '第一章 出山', '第二章 断刃', '尾声'], array_column($res['json']['toc'], 'title'),
    '目录块没有变成章节，只有真正带着正文的那几行算标题');
t_eq([0, 1, 2, 3], array_column($res['json']['toc'], 'seq'), 'seq 从 0 开始连续，没有空洞');
t_eq(0, $res['json']['novel']['progressChapter'], '新书的进度章是 0（还没读过）');
t_eq(0, $res['json']['novel']['progressPercent'], '没读过就是 0%');
t_eq(mb_strlen(preg_replace('/\s+/u', '', $nvTextA), 'UTF-8'), $res['json']['novel']['charCount'],
    '全书字数 = 送进去的字数（空白不算，一个字都没丢）');

$res = t_request('GET', '/api/novel/chapter?id=' . $nvId . '&seq=0', ['jar' => $nvA]);
t_eq(200, $res['status'], '取前言这一章');
t_contains($res['json']['chapter']['content'], '第一章 出山', '目录里那些行留在前言里，一个字都没丢');
t_contains($res['json']['chapter']['content'], '《剑影残章》', '书名页那行也留在前言里：猜名字不该扣掉正文');
// 「有没有上一章」只能问第 0 章：这一本的第 0 章是前言，第 1 章才是「第一章」，
// 拿第 1 章断言 hasPrev===false 会把前言算漏，得到一个假的 bug
t_eq(false, $res['json']['chapter']['hasPrev'], '第 0 章没有上一章');
t_eq(true, $res['json']['chapter']['hasNext'], '第 0 章有下一章');

$res = t_request('GET', '/api/novel/chapter?id=' . $nvId . '&seq=1', ['jar' => $nvA]);
t_contains($res['json']['chapter']['content'], '1、这一行只是正文里的编号',
    '编号行被当成普通文字收进本章，没有把章劈开');
t_eq(4, $res['json']['chapter']['total'], '取章时顺手带回总章数，前端才知道能不能往后翻');
t_eq(true, $res['json']['chapter']['hasPrev'], '前言前面还有第 0 章，所以这里能往回翻');
t_eq(true, $res['json']['chapter']['hasNext'], '第 1 章有下一章');
$res = t_request('GET', '/api/novel/chapter?id=' . $nvId . '&seq=3', ['jar' => $nvA]);
t_eq(false, $res['json']['chapter']['hasNext'], '最后一章没有下一章');

$res = t_request('GET', '/api/novel?id=' . $nvId, ['jar' => $nvA]);
t_eq(4, count($res['json']['toc']), '整份目录一次给全（翻页与「跳到第 N 章」都靠它）');
t_assert(!isset($res['json']['toc'][0]['content']), '目录行里没有正文字段');
t_assert(strpos($res['body'], '"content"') === false, '正文只在取单章时才出去：开书接口的响应里根本没有这个键');

// 整本都没有章节标记：就是一章「前言」，而不是「识别失败」
$res = t_request('POST', '/api/novels', [
    'json' => ['text' => "这是一段没有任何章节标记的练习文本。\n" . $nvBody(12)],
    'jar' => $nvA,
]);
t_eq(201, $res['status'], '没有任何标题标记时也能存进来');
t_eq(1, $res['json']['novel']['chapterCount'], '整本成一章');
t_eq('前言', array_column($res['json']['toc'], 'title')[0], '那一章叫「前言」');

t_section('小说阅读：书名从哪来');

// 用户没填书名时才猜。猜只看「第一个标题之前」那几行——一开篇就是「第一章」的文本
// 没有书名页，硬拿正文第一行当书名是最难查的错。
$nvG1 = $nvAccount('nv_g1');
$res = t_request('POST', '/api/novels', [
    'json' => ['text' => "《雾中灯塔》\n一部没有章节标记的练习文本，用来测试猜书名的第二条路。\n\n第一章 起雾\n" . $nvBody(6)],
    'jar' => $nvG1,
]);
t_eq(201, $res['status'], '没填书名也能存');
t_eq('雾中灯塔', $res['json']['novel']['title'], '书名页上的《…》被认出来了（书名号本身不进标题）');

$nvG2 = $nvAccount('nv_g2');
$res = t_request('POST', '/api/novels', [
    'json' => ['text' => "第一章 起雾\n" . $nvBody(6) . "\n第二章 落潮\n" . $nvBody(6)],
    'jar' => $nvG2,
]);
t_eq(201, $res['status'], '开篇就是「第一章」的文本也能存');
t_eq('未命名小说', $res['json']['novel']['title'], '没有书名页时给「未命名小说」，不拿正文冒充书名');

$nvG3 = $nvAccount('nv_g3');
$res = t_request('POST', '/api/novels', [
    'json' => ['text' => "一部没有章节标记也没有书名号的长文本，第一行本身就够短。\n" . $nvBody(8)],
    'jar' => $nvG3,
]);
t_eq(201, $res['status'], '连《》都没有时也能存');
t_eq('一部没有章节标记也没有书名号的长文本，第一行本身就够短。', $res['json']['novel']['title'],
    '退一步用第一个短行当书名：猜错至少能改，比「未命名」有用');

t_section('小说阅读：文件名是书名的最后一道兜底');

/*
 * 从「选一个本地 txt」这条路上来的书名有三个来源，优先级必须是：
 *   用户填的 > 正文里的书名页 > 文件名。
 * 前两条上面已经有用例了，这里钉第三条。顺序错反的表现很具体：
 * 下载站存的文件名叫「剑影残章全文无删减.txt」，正文里明明写着《剑影残章》，
 * 书架上却跟着文件名走——文件名是别人起的，正文是作者写的。
 */
$nvG4 = $nvAccount('nv_g4');
$res = t_request('POST', '/api/novels', [
    'json' => ['text' => "第一章 起雾\n" . $nvBody(6), 'filename' => '雾中灯塔.txt'],
    'jar' => $nvG4,
]);
t_eq(201, $res['status'], '开篇就是「第一章」的书也能靠文件名存进来');
t_eq('雾中灯塔', $res['json']['novel']['title'], '没有书名页时用文件名，扩展名跟着去掉');

$res = t_request('POST', '/api/novels', [
    'json' => [
        'text' => "《剑影残章》\n一部练习文本，用来看书名页和文件名谁赢。\n\n第一章 起\n" . $nvBody(6),
        'filename' => '随便下的一个名字.txt',
    ],
    'jar' => $nvG4,
]);
t_eq(201, $res['status'], '带书名页的文件也能存');
t_eq('剑影残章', $res['json']['novel']['title'], '正文里的书名页赢过文件名：文件是谁起的名不重要，书本身写着什么才重要');

$res = t_request('POST', '/api/novels', [
    'json' => ['title' => '我自己写', 'text' => "第一章 起\n" . $nvBody(6), 'filename' => '别的名字.txt'],
    'jar' => $nvG4,
]);
t_eq('我自己写', $res['json']['novel']['title'], '用户亲手填的书名压过一切');

/*
 * novel_title_from_file 的规矩是「一律不报错」：它是兜底，一个再荒唐的文件名
 * 也不该把一次本来能成的导入掀掉。这几条测的是纯字符串规则，不必走 HTTP，
 * 也就不必再占一个账号的书架名额（测试库里每人只有三本）。
 */
t_eq('雾中灯塔', novel_title_from_file('/tmp/雾中灯塔.txt'),
    '带路径也只取最后一段：浏览器不会送路径，手工构造的请求会');
t_eq('a b', novel_title_from_file("C:\\dir\\a b.TXT"),
    'Windows 的反斜杠与大小写扩展名一起处理');
t_eq('剑影残章.终章', novel_title_from_file('剑影残章.终章'),
    '只剥认得的扩展名：「.终章」不是 txt，不该被当成扩展名吃掉');
t_eq(str_repeat('名', 120), novel_title_from_file(str_repeat('名', 300) . '.txt'),
    '三百字长的文件名截到列宽，而不是报 422 把导入整个掀掉');
t_eq('', novel_title_from_file(''), '没有文件名就给空串，让「未命名小说」上场');
t_eq('', novel_title_from_file('   '), '全是空白等于没有');
t_eq('', novel_title_from_file("\x01\x02"), '控制字符拼出来的文件名当没有');

t_section('小说阅读：字数与条数的边界');

$nvLim = $nvAccount('nv_lim');

$res = t_request('POST', '/api/novels', ['json' => ['text' => "第一章 短\n只有这么几个字。"], 'jar' => $nvLim]);
t_eq(422, $res['status'], '短到不像一本书的正文被拒绝');
t_contains($res['json']['error'], '太短', '拒绝时说明的是「太短」');

// 单次不设字数上限了：这一本四万多字，比改版前那道「单次上限」还大，
// 只要书架位置够就该一次进来。现在能挡住一个人的只有账号总量，不是「一次能塞多少」。
$nvJumbo = $nvBody(100) . "\n" . str_repeat('字', 40001);
$res = t_request('POST', '/api/novels', ['json' => ['title' => '厚', 'text' => $nvJumbo], 'jar' => $nvLim]);
t_eq(201, $res['status'], '四万多字的一本直接存进来：单次导入不再限字数');
t_eq(mb_strlen(preg_replace('/\s+/u', '', $nvJumbo), 'UTF-8'), $res['json']['novel']['charCount'],
    '四万字的正文一个字没丢（字数按接口回来的是实际算出来的那个）');

// 每一行都像标题：那正是「这个文件本身就是一份目录」的形状，章数上限要拦住而不是写爆库
$nvMany = [];
for ($nvI = 1; $nvI <= 41; $nvI++) {
    $nvMany[] = '第' . $nvI . '章 试炼';
    $nvMany[] = $nvPara;
}
$res = t_request('POST', '/api/novels', ['json' => ['text' => implode("\n", $nvMany)], 'jar' => $nvLim]);
t_eq(422, $res['status'], '章数超过单本上限被拒绝');
t_contains($res['json']['error'], '章的上限', '拒绝时说的是章数，不是字数');

$res = t_request('POST', '/api/novels', ['json' => ['text' => 123456], 'jar' => $nvLim]);
t_eq(422, $res['status'], 'text 送数字也按字符串处理，不会崩在类型上');

$res = t_request('POST', '/api/novels', ['json' => ['title' => str_repeat('名', 121), 'text' => "第一章 起\n" . $nvBody(6)], 'jar' => $nvLim]);
t_eq(422, $res['status'], '书名超长被拒绝');

$res = t_request('GET', '/api/novels', ['jar' => $nvLim]);
t_eq(120000, $res['json']['limits']['maxTotalChars'], '总量配额随接口下发，前端不必自己抄一份常量');
t_assert(!isset($res['json']['limits']['maxChars']), '下发里没有「单次字数上限」这一项了');
t_eq(30000, $res['json']['limits']['chapterMaxChars'], '单章切分阈值也下发');
t_eq(3, $res['json']['stats']['limit'], '书架本数上限随书架下发（页面上要显示「已存 0/3」）');
t_eq(mb_strlen(preg_replace('/\s+/u', '', $nvJumbo), 'UTF-8'), $res['json']['stats']['chars'],
    '已用字数跟着书架走：前端选文件那一刻就是按它算还剩多少位置的');

// 单章过长必须在段落边界切开：整章五十万字一次给前端，翻页、渲染、离线副本会一起卡死。
// 期望字数在这里按段落实际长度算出来，不写死：改样例文案时这条断言跟着变，而不是变成一条红字
$nvParaChars = mb_strlen(preg_replace('/\s+/u', '', $nvPara), 'UTF-8');
$nvLongChars = $nvParaChars * 900;
t_assert($nvLongChars > 30000 && $nvLongChars <= 120000,
    '样例总长 ' . $nvLongChars . ' 字：刚好跨过单章阈值 30000，又没顶到账号总量 120000');

$nvLong = $nvAccount('nv_long');
$res = t_request('POST', '/api/novels', [
    'json' => ['title' => '长夜', 'text' => implode("\n", array_merge(['第一章 长夜'], array_fill(0, 900, $nvPara)))],
    'jar' => $nvLong,
]);
t_eq(201, $res['status'], '三万多字挤在一章里也能存');
t_eq(2, $res['json']['novel']['chapterCount'], '超过 30000 的那一章被切成 2 份');
$nvParts = array_column($res['json']['toc'], 'charCount');
t_eq($nvLongChars, array_sum($nvParts), '切开之后总字数一个字都没少');
t_assert($nvParts[0] <= 30000 && $nvParts[1] <= 30000, '每一份都不超过阈值');
t_contains(array_column($res['json']['toc'], 'title')[0], ' · 1/2',
    '标题上写明「1/2」：读者知道这是一章的两份之一，不是两章');
$nvPart1 = t_request('GET', '/api/novel/chapter?id=' . (int) $res['json']['novel']['id'] . '&seq=0', ['jar' => $nvLong]);
t_contains($nvPart1['json']['chapter']['content'], '袖口。', '切点落在段落边界：第一份的结尾是一个完整段落');

t_section('小说阅读：账号总量才是上限');

/*
 * 配额算的是「还在书架上的书一共多少字」，不是「历史上导过多少」，
 * 所以这一段要跑三步：灌到超限被拒 → 删一本 → 同样的一本又能进来。
 * 少了第三步，「删了不退配额」这种错就测不出来，而它恰恰最招骂。
 *
 * 一本四万字出头，造三次：两本还在 120,000 的配额以内，第三本顶过去。
 * 本数上限是 3，所以这里先撞的是字数而不是本数——配额那条检查排在前面。
 */
$nvQuota = $nvAccount('nv_quota');
$nvBigBody = $nvBody(1200);
$nvBigChars = mb_strlen(preg_replace('/\s+/u', '', $nvBigBody), 'UTF-8');
t_eq($nvParaChars * 1200, $nvBigChars, '样例一本 ' . $nvBigChars . ' 字：两本装得下，三本装不下');

$res = t_request('POST', '/api/novels', ['json' => ['title' => '第一本', 'text' => $nvBigBody], 'jar' => $nvQuota]);
t_eq(201, $res['status'], '空书架上一本 ' . $nvBigChars . ' 字的书直接进来（单次没有上限）');
$nvFirstId = (int) $res['json']['novel']['id'];
// 存书的响应里只有这一本（novel + toc + limits），已用字数是书架的事，得回书架取
$res = t_request('GET', '/api/novels', ['jar' => $nvQuota]);
t_eq($nvBigChars, $res['json']['stats']['chars'], '已用字数跟着书架报出来：前端就是按它算还剩多少位置');

$res = t_request('POST', '/api/novels', ['json' => ['title' => '第二本', 'text' => $nvBigBody], 'jar' => $nvQuota]);
t_eq(201, $res['status'], '第二本也进得来：两本合起来还没顶到配额');

$res = t_request('POST', '/api/novels', ['json' => ['title' => '第三本', 'text' => $nvBigBody], 'jar' => $nvQuota]);
t_eq(409, $res['status'], '第三本会把总量顶过配额，被拒');
t_contains($res['json']['error'], '总量', '说的是账号总量，不是「这个文件太大」');
t_contains($res['json']['error'], '120000', '把配额的具体数字告诉用户');
t_contains($res['json']['error'], '删掉不再读', '顺手说清楚下一步该干什么');

// 被拒的那一次不许留下半个字：书目没写进去，章节也就不会有孤儿
$res = t_request('GET', '/api/novels', ['jar' => $nvQuota]);
t_eq(2, count($res['json']['items']), '被拒的那本没在书架上留下壳');
t_eq($nvBigChars * 2, $res['json']['stats']['chars'], '已用字数还是两本的：被拒不掉配额');

$res = t_request('DELETE', '/api/novels', ['json' => ['id' => $nvFirstId], 'jar' => $nvQuota]);
t_eq(200, $res['status'], '删掉一本');
$res = t_request('POST', '/api/novels', ['json' => ['title' => '第三本', 'text' => $nvBigBody], 'jar' => $nvQuota]);
t_eq(201, $res['status'], '删掉之后同样的一本又能进来：配额数的是「还在书架上的书」');

t_section('小说阅读：改成 .txt 的 exe 进不来');

/*
 * 这道验的是「进来的得是一段文本」，不是「这段文本里藏没藏病毒」——
 * 后者要特征库，本站没有，也不打算把用户的书原样发给外部服务去扫。
 * 改成选文件之后，服务器收到的一直只是 JSON 里的一个字符串：不落盘、不解压、不执行，
 * 所以「传个木马打进服务器」这条路从结构上就不存在。这几条用例守的是两件更实在的事：
 * 别把几十兆垃圾写进别人的库，以及**判断必须排在清洗之前**。
 * novel_normalize_text() 是把控制字符删掉的，先删再验等于把证据擦了再查指纹，
 * 所以「一份满是控制字符的输入」必须撞上 422，而不是被洗干净变成一本挺干净的书。
 */
$nvBin = $nvAccount('nv_bin');

/*
 * 走 HTTP 的这一组只用「JSON 送得进来」的字节：0x80 以上的高字节根本到不了这道检查，
 * 因为 json_decode 先一步把整个请求体判非法（下面单独钉一条）。
 * 剩下的 ASCII 加控制字节，足够摆出 exe / zip / ELF / rar / PDF 的开头。
 */
$nvHeads = [
    ["MZ\x00\x00\x00\x00\x00\x00", 'exe / dll'],
    ["PK\x03\x04\x14\x00\x00\x00", 'zip（docx / epub / apk 都是它换皮）'],
    ["\x7fELF\x02\x01\x01\x00", 'Linux 可执行文件'],
    ["Rar!\x1a\x07\x01\x00", 'rar'],
    ["%PDF-1.7\n", 'PDF'],
];
foreach ($nvHeads as $nvRow) {
    $res = t_request('POST', '/api/novels', [
        'json' => ['text' => $nvRow[0] . "\n" . $nvBody(20)],
        'jar' => $nvBin,
    ]);
    t_eq(422, $res['status'], $nvRow[1] . ' 改名成 .txt 也被拒');
    t_contains($res['json']['error'], '只收纯文本', '拒的时候说清它认出的是什么东西');
}

/*
 * 高字节那条要分开钉：真 exe 的开头是 4D 5A 90 00，那个 \x90 送不进来——JSON 要求合法
 * UTF-8，一个裸的 \x90 让 json_decode 直接判请求体非法，于是它在纯文本闸之前就被 400 掉了。
 * 这不是「签名白写」，是两道闸一前一后：JSON 层挡掉一切不是合法 UTF-8 的东西，
 * 纯文本闸挡掉「是合法 UTF-8、但根本不是文章」的东西。
 */
$res = t_request('POST', '/api/novels', [
    'raw_body' => '{"title":"ole","text":"' . "\xd0\xcf\x11\xe0" . '第一章 起\\n' . str_replace("'", '', $nvPara) . '"}',
    'content_type' => 'application/json',
    'jar' => $nvBin,
]);
t_eq(400, $res['status'], '老版 Office 的文件头带着高字节：JSON 层先拒，到不了正文检查');
t_contains($res['json']['error'], '不是合法的 JSON', '说的是请求体本身，不是内容不合规');

// 那三个高字节签名因此不是死代码（库里已有的数据、别的路径送进来的字符串都靠它兜住），
// 但只能直接调用一次来钉住行为——和上面 novel_normalize_text 那个直接调用同一个理由
$nvSigOk = true;
foreach ([
    ["7z\xbc\xaf\x27\x1c\x00\x04\x00", '7z'],
    ["\x1f\x8b\x08\x00\x00\x00\x00\x00", 'gzip'],
    ["\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1", '老版 Office'],
] as $nvRow) {
    $nvCaught = null;
    try {
        novel_assert_plain_text($nvRow[0] . "\n" . $nvBody(20));
    } catch (ApiException $nvE) {
        $nvCaught = $nvE;
    }
    if ($nvCaught === null || $nvCaught->status() !== 422 || strpos($nvCaught->getMessage(), '只收纯文本') === false) {
        $nvSigOk = false;
    }
}
t_assert($nvSigOk, '7z / gzip / Office 这三个高字节头：直接送进这道闸一样是 422「只收纯文本」');

$res = t_request('POST', '/api/novels', [
    'json' => ['text' => "第一章 起\n" . $nvBody(6) . "\x00" . $nvBody(6)],
    'jar' => $nvBin,
]);
t_eq(422, $res['status'], '正文中间夹一个 NUL 字节就拒：txt 里不会有它');
t_contains($res['json']['error'], '二进制', '说的是「这是二进制」，不是含糊的「内容不合规」');

// 整屏都是控制字符：清洗会把它删干净，所以这条同时证明「判断在清洗之前」
$nvNoise = '';
for ($nvI = 0; $nvI < 600; $nvI++) {
    $nvNoise .= "\x01\x02\x03";
}
$res = t_request('POST', '/api/novels', ['json' => ['text' => "第一章 噪声\n" . $nvNoise], 'jar' => $nvBin]);
t_eq(422, $res['status'], '控制字符占大头的内容被拒，而不是被洗干净之后收下');
t_contains($res['json']['error'], '不可打印', '拒的时候说清是因为不可打印字符');

/*
 * 反方向那条更要紧：老 DOS 文本用 \x1A 当文件结尾记号，网页另存也常留下几个杂点，
 * 这种必须放行（并且由清洗顺手删掉），不然就是拿安全当借口把正常用户挡在门外。
 * 判的是占比，不是「有没有」。
 */
$nvPaged = "第一章 起\n";
for ($nvI = 0; $nvI < 40; $nvI++) {
    $nvPaged .= $nvPara . "\x1a\n";
}
$res = t_request('POST', '/api/novels', ['json' => ['title' => '带分页杂点', 'text' => $nvPaged], 'jar' => $nvBin]);
t_eq(201, $res['status'], '每隔几行一个 \x1A 照样导得进来：按占比判');
$nvPagedText = t_request('GET', '/api/novel/chapter?id=' . (int) $res['json']['novel']['id'] . '&seq=0', ['jar' => $nvBin]);
t_assert(strpos($nvPagedText['json']['chapter']['content'], "\x1a") === false,
    '放行的那几个杂点由清洗顺手删掉：进门宽松、库里干净');
$res = t_request('GET', '/api/novels', ['jar' => $nvBin]);
t_eq(1, count($res['json']['items']), '上面那几次被拒的一点没留，书架上只有真进来的那本');

t_section('小说阅读：文件里留下的脏字符');

$nvDirtyAcct = $nvAccount('nv_dirty');
$nvDirty = "《脏文本》\r\n第一章 起\r\n" . $nvPara . "\t  \n\n\n\n"
    . "旁白里夹着一个不可见字符：\u{FEFF}" . $nvPara . "\n\n" . $nvBody(6);
$res = t_request('POST', '/api/novels', ['json' => ['title' => '脏文本', 'text' => $nvDirty], 'jar' => $nvDirtyAcct]);
t_eq(201, $res['status'], '带 CRLF、行尾空白、BOM 与多余空行的文本照样收');
$nvDirtyId = (int) $res['json']['novel']['id'];
$nvDirtyText = t_request('GET', '/api/novel/chapter?id=' . $nvDirtyId . '&seq=1', ['jar' => $nvDirtyAcct])['json']['chapter']['content'];
t_assert(strpos($nvDirtyText, "\r") === false, '换行统一成了 \n');
t_assert(strpos($nvDirtyText, "\n\n\n") === false, '三个以上空行被压成一个空行');
t_assert(strpos($nvDirtyText, "\u{FEFF}") === false, 'BOM 被清掉');
t_assert(preg_match('/[ \t]\n/', $nvDirtyText) === 0, '行尾空白被清掉');
t_contains($nvDirtyText, $nvPara, '句子本身一个字没动');

/*
 * 非法编码在这里被挡两次，两道都得钉住：
 *   第一道是 JSON 解析器。PHP 的 json_decode 会拒绝坏字节和落单的代理对
 *   （JSON_ERROR_UTF8 / JSON_ERROR_UTF16），所以脏数据根本进不到业务层——
 *   上一轮我把这条期望写成了 422，实际拿到的是 400：请求体压根没被解析成数组。
 *   第二道是 novel_normalize_text 里的 /u 检查。它走 HTTP 测不到（就是被第一道挡住了），
 *   但不是死代码：库里已有的数据、以及任何别的路径送进来的字符串都要靠它兜住，
 *   所以直接调用它一次，把「返回 null 而不是默默放行」这条行为钉住。
 */
$res = t_request('POST', '/api/novels', [
    'raw_body' => '{"title":"非法编码","text":"' . "\xC3\x28" . '第一章 起\\n' . str_replace("'", '', $nvPara) . '"}',
    'content_type' => 'application/json',
    'jar' => $nvDirtyAcct,
]);
t_eq(400, $res['status'], '请求体里带坏字节：JSON 层先拒了，不落库');
t_contains($res['json']['error'], '不是合法的 JSON', '说的是请求体本身，不是正文太长太短');

$nvThrew = null;
try {
    novel_normalize_text("第一章 起\n" . "\xC3\x28");
} catch (ApiException $nvE) {
    $nvThrew = $nvE;
}
t_assert($nvThrew !== null, '坏 UTF-8 直接交给清洗函数时它会抛错，而不是返回一个带病的字符串');
t_eq(422, $nvThrew ? $nvThrew->status() : null, '状态码是 422（数据不合规，不是没登录）');
t_contains($nvThrew ? $nvThrew->getMessage() : '', 'UTF-8', '说的是编码问题');

$res = t_request('POST', '/api/novels', [
    'json' => ['title' => '标签', 'text' => "第一章 有标签\n<img src=x onerror=alert(1)>\n" . $nvBody(6)],
    'jar' => $nvDirtyAcct,
]);
t_eq(201, $res['status'], '正文里带 HTML 标签也能存');
$nvRaw = t_request('GET', '/api/novel/chapter?id=' . (int) $res['json']['novel']['id'] . '&seq=0', ['jar' => $nvDirtyAcct]);
t_contains($nvRaw['json']['chapter']['content'], '<img src=x onerror=alert(1)>',
    '后端不转义、不去标签：库里就是用户那一份，防 XSS 由渲染侧负责');

t_section('小说阅读：阅读进度');

$res = t_request('POST', '/api/novel/progress', ['json' => ['id' => $nvId, 'chapter' => 1, 'paragraph' => 3], 'jar' => $nvA]);
t_eq(200, $res['status'], '存进度成功');
t_eq(1, $res['json']['chapter'], '回的是章号');
t_eq(3, $res['json']['paragraph'], '回的是段号：段号不随字号和屏幕变化，比滚动比例可靠');

$res = t_request('GET', '/api/novels', ['jar' => $nvA]);
$nvOnShelf = null;
foreach ($res['json']['items'] as $nvItem) {
    if ((int) $nvItem['id'] === $nvId) {
        $nvOnShelf = $nvItem;
    }
}
t_assert($nvOnShelf !== null, '书架里有这本书');
t_eq(25, $nvOnShelf['progressPercent'], '4 章读到第 2 章 = 25%');
t_eq(1, $nvOnShelf['progressChapter'], '章号存住了');
t_eq(3, $nvOnShelf['progressParagraph'], '段号也存住了');

$res = t_request('POST', '/api/novel/progress', ['json' => ['id' => $nvId, 'chapter' => 9, 'paragraph' => 0], 'jar' => $nvA]);
t_eq(422, $res['status'], '章号超出这本书的章数被拒绝');

$res = t_request('POST', '/api/novel/progress', ['json' => ['id' => $nvId, 'chapter' => 0, 'paragraph' => -1], 'jar' => $nvA]);
t_eq(422, $res['status'], '负段号被拒绝（不是被夹成 0 之后照样写进去）');

$res = t_request('POST', '/api/novel/progress', ['json' => ['id' => $nvId, 'chapter' => 0, 'paragraph' => 5001], 'jar' => $nvA]);
t_eq(422, $res['status'], '段号大到不像话时拒绝');

$res = t_request('POST', '/api/novel/progress', ['json' => ['id' => $nvId, 'chapter' => 2], 'jar' => $nvA]);
t_eq(200, $res['status'], '不送 paragraph 时按 0 处理：翻到一章开头就是第 0 段');

$res = t_request('POST', '/api/novel/progress', ['json' => ['chapter' => 0, 'paragraph' => 0], 'jar' => $nvA]);
t_eq(422, $res['status'], '缺少 id 的进度请求被拒绝');

$res = t_request('GET', '/api/novel/chapter?id=' . $nvId . '&seq=99', ['jar' => $nvA]);
t_eq(404, $res['status'], '取一本四章的书的第 100 章：404');
$res = t_request('GET', '/api/novel/chapter?id=' . $nvId . '&seq=-1', ['jar' => $nvA]);
t_eq(404, $res['status'], 'seq=-1 是 404 而不是第 0 章（负数没被夹成 0，那样会悄悄读到错的一章）');
$res = t_request('GET', '/api/novel/chapter', ['jar' => $nvA]);
t_eq(422, $res['status'], '缺少 id 的取章请求被拒绝');
$res = t_request('GET', '/api/novel', ['jar' => $nvA]);
t_eq(422, $res['status'], '开书也要 id');

t_section('小说阅读：改名');

$res = t_request('POST', '/api/novels/update', ['json' => ['id' => $nvId, 'title' => '剑影残章（修订本）'], 'jar' => $nvA]);
t_eq(200, $res['status'], '改书名成功');
t_eq('剑影残章（修订本）', $res['json']['novel']['title'], '返回改完的书');

$res = t_request('POST', '/api/novels/update', ['json' => ['id' => $nvId, 'title' => '   '], 'jar' => $nvA]);
t_eq(422, $res['status'], '书名不能改成空白');

$res = t_request('POST', '/api/novels/update', ['json' => ['id' => $nvId, 'title' => "两行\n书名"], 'jar' => $nvA]);
t_eq(200, $res['status'], '书名里带换行也收');
t_eq('两行 书名', $res['json']['novel']['title'], '换行被压成空格：标题只是个标签，不该占两行');

t_section('小说阅读：账号隔离');

$nvB = $nvAccount('nv_b');
$res = t_request('GET', '/api/novels', ['jar' => $nvB]);
t_eq(200, $res['status'], 'B 能打开自己的书架');
t_eq(0, count($res['json']['items']), 'B 的书架是空的：看不到 A 的一本书');
t_eq(0, $res['json']['stats']['total'], 'B 的统计也是 0');

$nvCross = [
    ['GET', '/api/novel?id=' . $nvId, '开书'],
    ['GET', '/api/novel/chapter?id=' . $nvId . '&seq=0', '取章'],
    ['POST', '/api/novel/progress', '存进度'],
    ['POST', '/api/novels/update', '改名'],
    ['DELETE', '/api/novels', '删书'],
];
foreach ($nvCross as $nvRow) {
    $res = t_request($nvRow[0], $nvRow[1], [
        'json' => ['id' => $nvId, 'chapter' => 0, 'paragraph' => 0, 'title' => '偷改'],
        'jar' => $nvB,
    ]);
    t_eq(404, $res['status'], 'B 拿 A 的书 id 去' . $nvRow[2] . '：404 而不是 403（连存在都不透露）');
    t_contains($res['json']['error'], '不在你的书架上', '话术与「这本书不存在」是同一句');
}

$res = t_request('GET', '/api/novel/chapter?id=' . $nvId . '&seq=0', ['jar' => $nvA]);
t_eq(200, $res['status'], 'A 自己读还是好的：上面那几次越权一点没碰到内容');
t_contains($res['json']['chapter']['content'], '《剑影残章》', '正文原样还在');

t_section('小说阅读：书架满员与删除');

$nvCap = $nvAccount('nv_cap');
for ($nvI = 1; $nvI <= 3; $nvI++) {
    $res = t_request('POST', '/api/novels', [
        'json' => ['title' => '书 ' . $nvI, 'text' => "第一章 开头\n" . $nvBody(6)],
        'jar' => $nvCap,
    ]);
    t_eq(201, $res['status'], '上限之内第 ' . $nvI . ' 本能存');
}
$res = t_request('POST', '/api/novels', ['json' => ['text' => "第一章 开头\n" . $nvBody(6)], 'jar' => $nvCap]);
t_eq(409, $res['status'], '到上限后拒绝继续存');
t_contains($res['json']['error'], '先删一本', '拒绝时顺带说清楚下一步该干什么');

$res = t_request('GET', '/api/novels', ['jar' => $nvCap]);
$nvDelId = (int) $res['json']['items'][0]['id'];
$res = t_request('DELETE', '/api/novels', ['json' => ['id' => $nvDelId], 'jar' => $nvCap]);
t_eq(200, $res['status'], '删一本书成功');
t_eq($nvDelId, (int) $res['json']['deleted'], '响应回的是被删的 id');

$nvStmt = db()->prepare('SELECT COUNT(*) FROM novel_chapters WHERE novel_id = ?');
$nvStmt->execute([$nvDelId]);
t_eq(0, (int) $nvStmt->fetchColumn(), '章节行随书一起没了：删了书不该留下没人能读到的正文');
$res = t_request('DELETE', '/api/novels', ['json' => ['id' => $nvDelId], 'jar' => $nvCap]);
t_eq(404, $res['status'], '同一本书删第二次 404');

$res = t_request('POST', '/api/novels', ['json' => ['text' => "第一章 开头\n" . $nvBody(6)], 'jar' => $nvCap]);
t_eq(201, $res['status'], '删掉一本之后又能存了：上限数的是「还在书架上的书」');

// 书架按 updated_at 倒序：存一次进度，这本书就该浮到最前面。
// TIMESTAMP 只到秒，两次写挤在同一秒时顺序由 id 兜底——所以这里真的等过一秒。
sleep(2);
$res = t_request('GET', '/api/novels', ['jar' => $nvCap]);
$nvOldest = $res['json']['items'][count($res['json']['items']) - 1];
t_request('POST', '/api/novel/progress', [
    'json' => ['id' => (int) $nvOldest['id'], 'chapter' => 0, 'paragraph' => 1],
    'jar' => $nvCap,
]);
$res = t_request('GET', '/api/novels', ['jar' => $nvCap]);
t_eq((int) $nvOldest['id'], (int) $res['json']['items'][0]['id'], '刚读过的那本浮到了最前面');

t_section('小说阅读：样式与前端前提');

$nvCss = file_get_contents($nvRoot . '/public/assets/css/style.css');
t_contains($nvCss, '第 17 部分', '样式表里有第 17 部分（阅读排版）');
$nvPart17 = substr($nvCss, (int) strpos($nvCss, '第 17 部分'));
t_contains($nvPart17, 'font-size: var(--read-font)', '字号走一个 CSS 变量：A− / A+ 只改这一个值');
t_contains($nvPart17, 'text-indent: 2em', '中文排版的首行缩进');
t_contains($nvPart17, 'position: sticky', '工具条吸顶：读到一半也能翻章、改字号');
t_contains($nvPart17, 'min-width: 0', 'flex 子项给了 min-width:0（长书名不会把工具区挤出屏幕）');
t_contains($nvPart17, '.reader-sepia', '有米黄那一档底色');
t_contains($nvPart17, '.reader-light', '有白纸那一档底色');
t_contains($nvPart17, '--reader-fg', '换底色时连文字色一起换：只换底会让对比度掉下来');
t_contains($nvPart17, 'prefers-reduced-motion', '减少动态偏好下进度条不做宽度过渡');
t_contains($nvPart17, 'max-width: 34em', '行宽有上限：再宽眼睛要回头找下一行');
t_contains($nvPart17, 'backdrop-filter', '吸顶的工具条带模糊：正文从它底下滚过时不串字');
t_contains($nvPart17, '.reader-text p {', '正文段落由 CSS 管：JS 只建 <p>，不管样式');
t_contains($nvPart17, '.file-input {', '原生文件框整条规则都在：它只负责能聚焦、能开文件框，不画出来');
t_contains($nvPart17, 'opacity: 0;', '看不见靠 opacity，不用 display:none（那样就连 Tab 都到不了它）');
t_contains($nvPart17, '.file-pick:focus-within .file-pick-btn',
    '焦点落在那个看不见的 input 上时，按钮必须把焦点框画出来：否则键盘用户不知道自己在哪');
