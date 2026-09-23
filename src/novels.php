<?php
/**
 * 小说阅读：把用户选中的一个 txt 文件「认」成一本有章节的书，按账号隔离。
 *
 * 三条贯穿全文的规矩：
 *
 * 1. **归属写在 SQL 的 WHERE 里**（id 与 user_id 永远一起出现），和 memos / pomodoros 同一套理由：
 *    忘了加条件只会查不到数据（功能坏掉，立刻就发现），忘了判断是能改别人的数据（越权，
 *    往往很久都没人发现）。
 * 2. **认章节这件事全在后端做。** 放前端就有两份规则、而且没人能测；放后端做一次，
 *    规则能被 tests/cases/16-novel.php 逐条断言，存进库的章节结构还是唯一的那一份。
 *    前端只负责把拿到的正文一段一段建节点。
 * 3. **正文原样存、原样返回**，不转义也不去标签：它是要给作者本人读的小说，
 *    在库里就该是原样。防 XSS 靠渲染侧（reader.js 只用 textContent 建 <p>，从不写 innerHTML），
 *    不是在存储侧偷偷改用户的文字。
 */

/** 交给前端的书名列。刻意不含 content：正文只在取单章时才出去 */
const NOVEL_COLUMNS = [
    'id', 'title', 'chapter_count', 'char_count',
    'progress_chapter', 'progress_paragraph', 'created_at', 'updated_at',
];

/** 目录行的列：同样不含正文 */
const NOVEL_CHAPTER_COLUMNS = ['id', 'novel_id', 'seq', 'title', 'char_count'];

/** 章节标题的三种形态。命名式最可靠，纯数字式只在前面两种都没影时才认 */
const NOVEL_HEAD_NAMED = 'named';    // 第三章 / 第 3 节 / 第一百零五回 / Chapter 7
const NOVEL_HEAD_SECTION = 'section';// 序章 / 楔子 / 番外 / 尾声 / 后记 …
const NOVEL_HEAD_NUMBER = 'number';  // 3、 / 12. / 001 —— 最容易和正文里的编号列表混

const NOVEL_UNTITLED = '未命名小说'; // 正文里猜不出书名、文件名也用不上时的名字

/* ============================================================
   一、文本规范化与计数
   ============================================================ */

/**
 * 导入的文本规范化：统一换行、去掉 BOM 与不可见控制字符、压掉多余空行。
 *
 * 和备忘录的「见到控制字符就报 422」刻意不同：这些文字来自别人做的网页 / 文档，
 * 夹带不可见字符是他的错吗？不是，也不该因此让他导不进去。所以**清掉**而不是拒绝。
 * 除此之外一律不碰：标点、缩进、段落里的空格全部原样保留。
 *
 * 也正因为这里会删证据，「这到底是不是文本」必须问在前面，见 novel_assert_plain_text()。
 */
function novel_normalize_text($text)
{
    $text = str_replace(["\r\n", "\r"], "\n", (string) $text);
    $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{FEFF}\x{FFFE}\x{FFFF}]/u', '', $text);
    if ($clean === null) {
        // /u 模式遇到非法 UTF-8 会返回 null 而不是报错——不检查就会一路带病往下走
        throw new ApiException('这个文件的正文不是可识别的文本编码，请另存为 UTF-8 的 txt 再导入', 422);
    }
    // 行尾空白（含全角空格与 NBSP）：网页排版留下的，读起来只会多出一截
    $clean = preg_replace('/[ \t\x{00A0}\x{3000}]+$/mu', '', $clean);
    $clean = preg_replace('/\n{3,}/', "\n\n", $clean);
    return trim($clean);
}

/** 一眼就不是正文的文件头：exe / dll、各类压缩包、老版 Office。小说文本不会以它们开头 */
const NOVEL_BINARY_SIGNATURES = [
    ["\x4d\x5a", '可执行文件（exe / dll）'],
    ["PK\x03\x04", '压缩包（zip / docx / epub / apk 都是它换皮）'],
    ["\x1f\x8b", 'gzip 压缩文件'],
    ["7z\xbc\xaf", '7z 压缩文件'],
    ["Rar!\x1a", 'rar 压缩文件'],
    ["\x7fELF", 'Linux 可执行文件'],
    ["\xd0\xcf\x11\xe0", '老版 Office 文档（宏病毒常藏在这里）'],
    ["%PDF", 'PDF 文件'],
];

/**
 * 「这是不是一份纯文本」——必须在 novel_normalize_text() 之前问。
 *
 * 顺序很要紧：下面那个清洗函数是把控制字符**删掉**的（网页另存下来的 txt 夹一点零碎字符，
 * 不该因此让人家导不进去）。先删再验，等于把证据擦了再查指纹。
 *
 * 三道都只针对「把 exe / 压缩包改名成 .txt 塞进来」这一件事：
 *   · 开头是已知的可执行文件或压缩包文件头
 *   · 整份里出现 NUL 字节：文本文件不会有 \0，二进制文件几乎必然有
 *   · 控制字符占比过高。取头 / 中 / 尾三段而不是整本扫，是因为真二进制的密度是「每一屏都是」，
 *     采样足够把它和「夹了几个杂点」分开，而整本扫要为一本 500 万字的书多复制一份 15MB 字符串。
 *
 * **这不是病毒扫描。** 认出木马要特征库，本站没有，也不打算把用户的书原样发给外部服务去扫。
 * 真正的防线是结构性的：接口收到的是 JSON 里的一段字符串，全程不落盘、不解压、不执行，
 * 也永远不会有哪条路径把它当文件读回去；正文进浏览器只走 textContent，配合 CSP 的 script-src 'self'。
 * 这三道要挡的其实是另一件更实在的事：别让人把五十兆垃圾存进你的书架、占掉你的磁盘。
 */
function novel_assert_plain_text($text)
{
    $text = (string) $text;
    $len = strlen($text);
    if ($len === 0) {
        return;// 空的东西交给「太短了不像一本书」那条提示，这里不叠第二句
    }

    $head = substr($text, 0, 8);
    foreach (NOVEL_BINARY_SIGNATURES as $sig) {
        if (strncmp($head, $sig[0], strlen($sig[0])) === 0) {
            throw new ApiException('这个文件是' . $sig[1] . '，不是小说正文：本站只收纯文本的 txt', 422);
        }
    }

    if (strpos($text, "\x00") !== false) {
        throw new ApiException('这个文件里有 \\0 字节，是二进制文件而不是文本：请确认导的是 txt 正文', 422);
    }

    $sample = substr($text, 0, 65536) . substr($text, intdiv($len, 2), 32768) . substr($text, -65536);
    $cleaned = preg_replace('/[\x00-\x08\x0e-\x1f\x7f]/', '', $sample);
    $bad = strlen($sample) - strlen((string) $cleaned);
    if ($bad * 100 > strlen($sample)) {
        // 比例报出来：撞上这条的人能立刻明白是「文件本身不对」而不是「我们把它弄坏了」
        throw new ApiException(
            '这个文件里有 ' . $bad . ' 个不可打印字符（在抽查的头、中、尾三段里占一成以上），不像小说正文：'
            . '多半是把别的格式改名成了 .txt，用记事本「另存为 → 编码 UTF-8」再导一次',
            422
        );
    }
}

/** 字数：不含空白。中文按字符算，换行和空格不算「字数」 */
function novel_count_chars($text)
{
    $stripped = preg_replace('/\s+/u', '', (string) $text);
    return mb_strlen($stripped === null ? '' : $stripped, 'UTF-8');
}

/* ============================================================
   二、章节识别
   ============================================================ */

/**
 * 这一行像不像章节标题？像就返回它的种类，不像返回 null。
 *
 * 三道共同的门槛（宁可少切，不可把正文切两刀）：
 *   · 整行不超过 40 字——标题不会写成一句话
 *   · 不以句末标点结尾——「第三章的内容让我想到。」是正文
 *   · 必须从行首开始匹配——段落中间出现「第 3 章」不算
 */
function novel_heading_kind($line)
{
    $line = trim((string) $line);
    $len = mb_strlen($line, 'UTF-8');
    if ($len === 0 || $len > 40) {
        return null;
    }
    if (preg_match('/[。！？!?，,；;…]$/u', $line)) {
        return null;
    }

    // 中文数量词做序号：既有「第 37 章」，也有「第一百零五回」
    $num = '[0-9０-９零〇一二三四五六七八九十百千万两]{1,12}';
    if (preg_match('/^第\s*' . $num . '\s*[章节回卷篇集幕]/u', $line)) {
        return NOVEL_HEAD_NAMED;
    }
    if (preg_match('/^chapter\s*[0-9]{1,4}/iu', $line)) {
        return NOVEL_HEAD_NAMED;
    }
    if (preg_match('/^(序章|楔子|引子|前言|序言|尾声|终章|大结局|后记|后序|番外|外传|附录|致谢)/u', $line)) {
        return NOVEL_HEAD_SECTION;
    }
    // 纯数字式：分隔符后面不能再紧跟数字，否则「2020.09 月」这种日期也算标题
    if (preg_match('/^[0-9]{1,3}\s*[、.．](?![0-9])\s*\S/u', $line)) {
        return NOVEL_HEAD_NUMBER;
    }
    return null;
}

/**
 * 线性扫描切章。两条关键规矩：
 *
 * 1. **标题后面必须跟着正文**，否则这一行只是「长得像标题」。
 *    连续几十行都像标题、中间一个字正文都没有——那是书前头的目录，
 *    整块留在上一章里当普通文字，而不是切出 200 个空章节。
 * 2. **有命名式或栏目式标题就彻底不认纯数字式。**
 *    正文里一句「1、先说结论」不该把一章劈成两半；真用「3、」当章节号的文本
 *    （整本没有「第X章」）才会走这条路。
 *
 * @return array [['title' => 标题, 'lines' => [正文行…]], …]，一行正文都没有的章不会出现
 */
function novel_scan_chapters(array $lines)
{
    $candidates = [];
    $strong = false;
    foreach ($lines as $i => $line) {
        $kind = novel_heading_kind($line);
        if ($kind === null) {
            continue;
        }
        $candidates[$i] = $kind;
        if ($kind !== NOVEL_HEAD_NUMBER) {
            $strong = true;
        }
    }
    if ($strong) {
        foreach ($candidates as $i => $kind) {
            if ($kind === NOVEL_HEAD_NUMBER) {
                unset($candidates[$i]);
            }
        }
    }

    $chapters = [];
    $preamble = [];
    $pending = null;// 还没等到正文的候选标题

    // 降级回去的行要落到「当前该落的地方」：还没有任何一章时是前言，否则是最后一章
    $pushLine = function ($line) use (&$chapters, &$preamble) {
        if ($chapters === []) {
            $preamble[] = $line;
            return;
        }
        $last = count($chapters) - 1;
        $chapters[$last]['lines'][] = $line;
    };

    foreach ($lines as $i => $line) {
        if (isset($candidates[$i])) {
            if ($pending !== null) {
                // 上一个候选没等到正文就被顶掉：它只是像标题，当普通文字收回去
                $pushLine($pending);
                foreach ($pendingBlank as $blank) {
                    $pushLine($blank);
                }
            }
            $pending = $line;
            $pendingBlank = [];
            continue;
        }
        if ($pending !== null) {
            if (trim($line) === '') {
                // 标题与正文之间的空行不算正文，先攒着
                $pendingBlank[] = $line;
                continue;
            }
            $chapters[] = [
                'title' => trim($pending),
                'lines' => array_merge($pendingBlank, [$line]),
            ];
            $pending = null;
            $pendingBlank = [];
            continue;
        }
        if ($chapters === []) {
            $preamble[] = $line;
            continue;
        }
        $last = count($chapters) - 1;
        $chapters[$last]['lines'][] = $line;
    }

    // 收尾：挂着没等到正文的候选（连同后面的空行）不是标题，是文末的普通文字
    if ($pending !== null) {
        $pushLine($pending);
        foreach ($pendingBlank as $blank) {
            $pushLine($blank);
        }
    }

    // 正文全空的章一律不要（可能出现在「标题紧跟标题」的降级之后）
    $kept = [];
    foreach ($chapters as $chapter) {
        $content = trim(implode("\n", $chapter['lines']));
        if ($content === '') {
            continue;
        }
        $kept[] = ['title' => $chapter['title'], 'content' => $content];
    }

    // 第一个标题之前的文字：那是书名页、题记或者目录，给它一章叫「前言」，
    // 而不是像某些做法那样直接丢掉——用户文件里的东西一个字都不该凭空消失。
    $head = trim(implode("\n", $preamble));
    if ($head !== '') {
        array_unshift($kept, ['title' => '前言', 'content' => $head]);
    }

    return $kept;
}

/**
 * 单章过长就在段落边界再切一刀。
 *
 * 为什么必须切：整本没有章节标记时就是一章五十万字，接口一次把它全给前端，
 * 翻页、渲染、离线副本全都会在那一屏上卡住。
 * 切在段落边界、并且给标题补上「· 2/4」，读者才知道这不是四章而是一章的四份。
 */
function novel_split_oversized(array $chapters, $max)
{
    $result = [];
    foreach ($chapters as $chapter) {
        $chars = novel_count_chars($chapter['content']);
        if ($chars <= $max) {
            $result[] = ['title' => $chapter['title'], 'content' => $chapter['content'], 'charCount' => $chars];
            continue;
        }

        $pieces = [];
        $current = [];
        $currentChars = 0;
        foreach (explode("\n", $chapter['content']) as $line) {
            $lineChars = novel_count_chars($line);
            if ($current !== [] && $currentChars + $lineChars > $max) {
                $pieces[] = trim(implode("\n", $current));
                $current = [];
                $currentChars = 0;
            }
            $current[] = $line;
            $currentChars += $lineChars;
        }
        if ($current !== []) {
            $pieces[] = trim(implode("\n", $current));
        }

        $total = count($pieces);
        foreach ($pieces as $index => $piece) {
            $result[] = [
                'title' => $chapter['title'] . ' · ' . ($index + 1) . '/' . $total,
                'content' => $piece,
                'charCount' => novel_count_chars($piece),
            ];
        }
    }
    return $result;
}

/**
 * 书名从哪来（用户没填时）：**只看第一个章节标题之前的那几行**——
 * 那里才是书名页该在的地方。先找《书名》样式，再退一步用一个不超 40 字的短行，
 * 一开篇就是「第一章」的文本没有书名页，就退到调用方给的兜底（文件名），
 * 连文件名也没有时才是「未命名小说」。
 *
 * 刻意**不**把猜出来的那一行从正文里删掉：猜错了也不该让用户的文字凭空少一行，
 * 它会留在「前言」里，书架上的名字随后可以改。
 */
function novel_guess_title(array $lines, $fallback = NOVEL_UNTITLED)
{
    $checked = 0;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (novel_heading_kind($line) !== null || ++$checked > 20) {
            break;
        }
        if (preg_match('/^《\s*(.{1,60}?)\s*》/u', $line, $match)) {
            return trim($match[1]);
        }
    }

    $checked = 0;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (novel_heading_kind($line) !== null || ++$checked > 5) {
            break;
        }
        if (mb_strlen($line, 'UTF-8') <= 40) {
            return $line;
        }
    }

    return $fallback;
}

/* ============================================================
   三、校验
   ============================================================ */

function novel_max_title()
{
    return (int) cfg('novel_max_title');
}

/** 书名：可留空（则由正文推断），填了就要合规 */
function validate_novel_title($title)
{
    $title = str_replace(["\r\n", "\r", "\n"], ' ', trim((string) $title));
    $len = mb_strlen($title, 'UTF-8');
    if ($len > novel_max_title()) {
        throw new ApiException('书名最多 ' . novel_max_title() . ' 个字（当前 ' . $len . ' 个）', 422);
    }
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $title)) {
        throw new ApiException('书名包含非法字符', 422);
    }
    return $title;
}

/**
 * 文件名当书名用（用户没填、正文里也猜不出来时的兜底）：
 * 去掉路径与扩展名、清掉控制字符、超了就截断。
 *
 * 这是兜底，不是用户亲手写的书名，所以**一律不报错**：一个两百字长的文件名
 * 不该让一次本来能成的导入失败；宁可给个截短的名字，书架上还能改。
 * 认不出的（空、编码坏了、全是非法字符）就返回空串，让「未命名小说」上场。
 */
function novel_title_from_file($name)
{
    $name = (string) $name;
    // 浏览器的 File.name 不带路径；真带了（手工构造的请求）就只取最后一段。
    // 这条正则刻意不加 /u：只找 ASCII 的斜杠，输入是不是合法 UTF-8 都要能切
    $name = preg_replace('~^.*[/\\\\]~', '', $name);
    // 只剥这几种纯文本扩展名：「剑影残章.终章」不是 .txt，不该被当成扩展名吃掉
    $name = preg_replace('~\.(?:txt|text|md|markdown)$~i', '', $name);
    $max = novel_max_title();
    if (mb_strlen($name, 'UTF-8') > $max) {
        // 截断在 validate 之前：太长的书名在那里面是要抛 422 的，而这里是兜底，不许抛
        $name = rtrim(mb_substr($name, 0, $max, 'UTF-8'));
    }
    try {
        $name = validate_novel_title($name);
    } catch (ApiException $e) {
        return '';// 非法字符之类一律「这个文件名不能用」处理，交给「未命名小说」
    }
    return $name;
}

/** 章节标题列宽有限，识别出来的行再长也要截断而不是报错——那不是用户的错 */
function novel_chapter_title($title)
{
    $title = trim(str_replace(["\r\n", "\r", "\n"], ' ', (string) $title));
    $max = (int) cfg('novel_max_chapter_title');
    if (mb_strlen($title, 'UTF-8') > $max) {
        $title = mb_substr($title, 0, $max - 1, 'UTF-8') . '…';
    }
    return $title === '' ? '未命名' : $title;
}

/* ============================================================
   四、行 → 前端视图
   ============================================================ */

/** 阅读进度百分比：按「读完整章才算读过」算，所以分子是当前章号 */
function novel_percent($chapter, $total)
{
    $total = (int) $total;
    if ($total <= 0) {
        return 0;
    }
    return (int) max(0, min(100, round(((int) $chapter / $total) * 100)));
}

function novel_to_item(array $row)
{
    return [
        'id' => (int) $row['id'],
        'title' => $row['title'],
        'chapterCount' => (int) $row['chapter_count'],
        'charCount' => (int) $row['char_count'],
        'progressChapter' => (int) $row['progress_chapter'],
        'progressParagraph' => (int) $row['progress_paragraph'],
        'progressPercent' => novel_percent($row['progress_chapter'], $row['chapter_count']),
        'createdAt' => format_datetime($row['created_at']),
        'updatedAt' => format_datetime($row['updated_at']),
    ];
}

function novel_to_chapter(array $row)
{
    return [
        'seq' => (int) $row['seq'],
        'title' => $row['title'],
        'charCount' => (int) $row['char_count'],
    ];
}

/* ============================================================
   五、取数：全部以 id + user_id 为条件
   ============================================================ */

function novel_find($id, $userId)
{
    return db_table('novels')
        ->select(NOVEL_COLUMNS)
        ->where('id', (int) $id)
        ->where('user_id', (int) $userId)
        ->first();
}

/** 这本书存在就返回行，不存在就抛 404。「不是你的」与「不存在」给同一句提示，不透露别人的数据 */
function novel_owned($id, $userId)
{
    $row = novel_find($id, $userId);
    if ($row === null) {
        throw new ApiException('这本书不在你的书架上（可能已被删除）', 404);
    }
    return $row;
}

function novel_count_for($userId)
{
    return db_table('novels')->where('user_id', (int) $userId)->count();
}

/** 这个账号的书架一共占了多少字。配额按它算，所以删掉一本就退回来（不是「历史上导过多少」） */
function novel_chars_for($userId)
{
    return db_table('novels')->where('user_id', (int) $userId)->sum('char_count');
}

/** 目录：不含正文，两千章也只有标题与字数，一次给全才换得起「跳到第 N 章」 */
function novel_toc($novelId)
{
    $items = [];
    $rows = db_table('novel_chapters')
        ->select(NOVEL_CHAPTER_COLUMNS)
        ->where('novel_id', (int) $novelId)
        ->orderBy('seq')
        ->get();
    foreach ($rows as $row) {
        $items[] = novel_to_chapter($row);
    }
    return $items;
}

/* ============================================================
   六、用例级入口：书架、导入、开书、取章、进度、改名、删除
   ============================================================ */

/** 书架：按「最近阅读」倒序。updated_at 由 MySQL 的 ON UPDATE 维护，进度一存就浮到最前 */
function novel_shelf($userId)
{
    $rows = db_table('novels')
        ->select(NOVEL_COLUMNS)
        ->where('user_id', (int) $userId)
        ->orderBy('updated_at', 'desc')
        ->orderBy('id', 'desc')
        ->get();

    $items = [];
    foreach ($rows as $row) {
        $items[] = novel_to_item($row);
    }

    return [
        'items' => $items,
        'stats' => [
            'total' => count($items),
            'limit' => (int) cfg('novel_max_count'),
            // 前端要在选文件的那一刻就算出「这本塞不塞得下」，所以已用字数与总配额都得给它
            'chars' => novel_chars_for($userId),
            'charLimit' => (int) cfg('novel_max_total_chars'),
        ],
        'limits' => novel_limits(),
    ];
}

/** 前端要靠这些数设 maxlength 和提示文案，所以随每个接口一起给，不在 JS 里抄一份常量 */
function novel_limits()
{
    return [
        'maxTitle' => novel_max_title(),
        'minChars' => (int) cfg('novel_min_chars'),
        // 没有「单次上限」这一项了：能导多大由账号还剩多少配额决定
        'maxTotalChars' => (int) cfg('novel_max_total_chars'),
        'maxChapters' => (int) cfg('novel_max_chapters'),
        'chapterMaxChars' => (int) cfg('novel_chapter_max_chars'),
    ];
}

/**
 * 导入一本：先验「是不是文本」→ 规范化 → 认章节 → 切超长章 → 一个事务写进库。
 *
 * 事务不是「顺手加的」：一本五百万字的书要插上千行章节，
 * 中途任何一次失败（包括超时）都会留下「书目在、正文缺了一半」的残本，
 * 那种书在书架上既读不了又删不干净。
 */
function novel_import($userId, $title, $text, $filename = '')
{
    novel_assert_plain_text($text);
    $text = novel_normalize_text($text);
    $chars = novel_count_chars($text);
    $minChars = (int) cfg('novel_min_chars');
    if ($chars < $minChars) {
        throw new ApiException('正文只有 ' . $chars . ' 字，太短了不像一本书（至少 ' . $minChars . ' 字）', 422);
    }

    // 单次导入刻意不再限字数：一本连载完的长篇就是三五百万字，按「一次能塞多少」设上限
    // 会把真实需求挡在门外。边界线改到「这个账号一共占了多少字」——它挡的是拿注册位刷磁盘，
    // 而那正是这种按账号隔离的私有内容唯一能被刷的东西。
    $maxTotal = (int) cfg('novel_max_total_chars');
    if ($maxTotal > 0) {
        $used = novel_chars_for($userId);
        if ($used + $chars > $maxTotal) {
            throw new ApiException(
                '书架上已经存了 ' . $used . ' 字，这本还要放 ' . $chars . ' 字，超过每个账号 ' . $maxTotal
                . ' 字的总量：删掉不再读的几本就能继续导',
                409
            );
        }
    }

    $limit = (int) cfg('novel_max_count');
    $owned = novel_count_for($userId);
    if ($limit > 0 && $owned >= $limit) {
        throw new ApiException('书架已经放了 ' . $owned . ' 本，达到上限（' . $limit . ' 本），先删一本再导入', 409);
    }

    $lines = explode("\n", $text);
    $chapters = novel_split_oversized(
        novel_scan_chapters($lines),
        (int) cfg('novel_chapter_max_chars')
    );
    if ($chapters === []) {
        throw new ApiException('没能从这个文件里认出正文，请确认它是小说的正文而不是一个空文件', 422);
    }
    $maxChapters = (int) cfg('novel_max_chapters');
    if (count($chapters) > $maxChapters) {
        throw new ApiException(
            '认出了 ' . count($chapters) . ' 章，超过单本 ' . $maxChapters . ' 章的上限：'
            . '多半是正文里每行都像标题（比如这个文件本身就是一份目录），换成正文再试',
            422
        );
    }

    $title = validate_novel_title($title);
    if ($title === '') {
        // 猜出来的名字天然在列宽之内（《》里最多 60 字，短行最多 40 字），不用再校验一遍
        // 正文里什么都没有时用文件名兜底：从本地选文件的人，十次有九次文件名就是书名
        $title = novel_guess_title($lines, novel_title_from_file($filename) ?: NOVEL_UNTITLED);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $novelId = db_table('novels')->insert([
            'user_id' => (int) $userId,
            'title' => $title,
            'chapter_count' => count($chapters),
            'char_count' => $chars,
        ]);

        // 这里绕开查询构造器，用一条自己 prepare 的语句：
        //   · 构造器每次 insert() 都要 prepare 一次，两千章就是两千次服务端预处理往返
        //   · 而且它没有「同一个事务里批量插」的形态
        // 列名是源码里的字面量，值全部走占位符，注入面与构造器等同。
        $stmt = $pdo->prepare(
            'INSERT INTO novel_chapters (novel_id, seq, title, content, char_count) VALUES (?, ?, ?, ?, ?)'
        );
        $seq = 0;
        foreach ($chapters as $chapter) {
            $stmt->execute([
                $novelId,
                $seq,
                novel_chapter_title($chapter['title']),
                $chapter['content'],
                (int) $chapter['charCount'],
            ]);
            $seq++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return novel_open($userId, $novelId);
}

/** 开一本书：元信息 + 目录 + 上次读到的位置。正文一个字都不在这里 */
function novel_open($userId, $id)
{
    $row = novel_owned($id, $userId);
    return [
        'novel' => novel_to_item($row),
        'toc' => novel_toc((int) $row['id']),
        'limits' => novel_limits(),
    ];
}

/**
 * 取一章正文。
 *
 * 一次只给一章是这块的核心取舍：接口永远不会把一本五十万字的书整个吐出去，
 * 前端也因此能「先渲染这一章，再顺手把下一章取回来」。
 */
function novel_read($userId, $id, $seq)
{
    $row = novel_owned($id, $userId);
    $seq = (int) $seq;
    $total = (int) $row['chapter_count'];
    if ($seq < 0 || $seq >= $total) {
        throw new ApiException('这本书没有第 ' . ($seq + 1) . ' 章（共 ' . $total . ' 章）', 404);
    }

    $chapter = db_table('novel_chapters')
        ->select(['title', 'content', 'char_count'])
        ->where('novel_id', (int) $row['id'])
        ->where('seq', $seq)
        ->first();
    if ($chapter === null) {
        // 目录里有、行却取不到：说明表被外部改坏了，如实报 500 而不是假装读不到
        throw new ApiException('这一章的正文缺失，请删除后重新导入', 500);
    }

    return [
        'chapter' => [
            'seq' => $seq,
            'title' => $chapter['title'],
            'content' => $chapter['content'],
            'charCount' => (int) $chapter['char_count'],
            'total' => $total,
            'hasPrev' => $seq > 0,
            'hasNext' => $seq < $total - 1,
        ],
        'novel' => novel_to_item($row),
    ];
}

/**
 * 存阅读进度：章号 + 章内第几段。
 *
 * 存「第几段」而不是滚动比例：比例在换设备、改字号、换屏幕时全都会变，
 * 段落序号不会。上限 5000 段只是防客户端把明显越界的数送进来。
 */
function novel_progress($userId, $id, $chapter, $paragraph)
{
    $row = novel_owned($id, $userId);
    $total = (int) $row['chapter_count'];
    $chapter = (int) $chapter;
    $paragraph = (int) $paragraph;
    if ($total > 0 && $chapter >= $total) {
        throw new ApiException('章号超出范围（这本书共 ' . $total . ' 章）', 422);
    }
    if ($chapter < 0 || $paragraph < 0 || $paragraph > 5000) {
        throw new ApiException('阅读进度不合法', 422);
    }

    db_table('novels')
        ->where('id', (int) $row['id'])
        ->where('user_id', (int) $userId)
        ->update([
            'progress_chapter' => $chapter,
            'progress_paragraph' => $paragraph,
        ]);

    return ['saved' => true, 'chapter' => $chapter, 'paragraph' => $paragraph];
}

/** 改书名。识别出来的名字不一定是想要的，这一条值 15 行代码 */
function novel_rename($userId, $id, $title)
{
    $row = novel_owned($id, $userId);
    $title = validate_novel_title($title);
    if ($title === '') {
        throw new ApiException('书名不能为空', 422);
    }

    db_table('novels')
        ->where('id', (int) $row['id'])
        ->where('user_id', (int) $userId)
        ->update(['title' => $title]);

    return ['novel' => novel_to_item(novel_owned($id, $userId))];
}

/**
 * 删一本书。章节靠外键级联删，所以这里只动一行——
 * 「删了书还剩一堆没人能读到的正文」这种脏数据不会因为漏写条件而出现。
 */
function novel_delete($userId, $id)
{
    $row = novel_owned($id, $userId);
    $affected = db_table('novels')
        ->where('id', (int) $row['id'])
        ->where('user_id', (int) $userId)
        ->delete();
    if ($affected === 0) {
        throw new ApiException('这本书不在你的书架上（可能已被删除）', 404);
    }
    return ['deleted' => (int) $row['id'], 'title' => $row['title']];
}
