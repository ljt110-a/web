<?php
/**
 * 前端启动烟测用例。
 *
 * 存在的理由：这个套件全部走 HTTP，只能证明后端答得对，证明不了页面脚本跑起来了。
 * 2026-09-22 study.js 里一个变量名写错，boot() 在第一次渲染就抛 ReferenceError 中断，
 * 页面留下的是 HTML 里的初始文字，看着像「加载完成」，558 条断言一条没红。
 *
 * 所以真正的检查在 tests/js-smoke.js（假 DOM 里把每个页面的脚本按 <script src> 顺序跑一遍），
 * 这里只负责把它拉起来、把失败原因翻译成断言。
 * 它需要 node：这台机器上没有就跳过，不把「环境缺工具」算成「代码有问题」。
 */

t_section('前端启动烟测：页面脚本要能跑完 boot()');

$script = __DIR__ . '/../js-smoke.js';

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$process = proc_open(t_command(['node', $script]), $descriptors, $pipes, dirname(__DIR__));
$output = '';
$exitCode = -1;
$missingNode = false;

if (is_resource($process)) {
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
} else {
    $output = 'proc_open 失败';
}

// cmd.exe 找不到 node 时给的是这么一句（不同语言环境下措辞不同），统一按「没有 node」处理
if (stripos($output, 'not recognized') !== false || stripos($output, "'node' ") !== false
    || strpos($output, 'command not found') !== false) {
    $missingNode = true;
}

if ($missingNode) {
    echo "  - 跳过：这台机器上没有 node，前端烟测不参与判定（有 node 的环境会自动生效）\n";
} else {
    // 逐行看：FAIL 后面紧跟的「·」行带着「哪个文件、哪一行、什么错」，原样贴进断言最好读
    $lines = preg_split('/\R/', $output);
    $checked = 0;
    for ($i = 0, $n = count($lines); $i < $n; $i++) {
        if (!preg_match('/^(OK|FAIL)\s+(\S+\.html)/', $lines[$i], $m)) {
            continue;
        }
        $checked++;
        $reason = '';
        while ($i + 1 < $n && preg_match('/^\s*·\s*(.+)$/u', $lines[$i + 1], $d)) {
            $i++;
            $reason .= ($reason ? '；' : '') . trim($d[1]);
        }
        t_assert($m[1] === 'OK', '页面脚本启动无异常：' . $m[2], $reason);
    }
    t_assert($checked >= 6, 'public 下的每个页面都被烟测扫到了（当前 ' . $checked . ' 个）');
    // SMOKE_TESTS 是可选钩子：登记了却没被调到，那些断言就悄悄成了死代码。
    // 这里盯一行汇总，保证「至少有一页的规则真的跑过」。
    t_assert(strpos($output, '规则自测跑过的页面：') !== false,
        '规则自测（SMOKE_TESTS）确实被调用过');
    // 汇总行形如「games.html（coreSelfTest、arcadeSelfTest、physicsSelfTest）」，
    // 按页名把括号里的名单拆出来，才能逐页盯住「该跑的都跑了」。
    $summary = '';
    foreach ($lines as $line) {
        if (strpos($line, '规则自测跑过的页面：') === 0) {
            $summary = $line;
            break;
        }
    }
    $testsOf = function ($page) use ($summary) {
        if (!preg_match('/' . preg_quote($page, '/') . '（([^）]*)）/u', $summary, $m)) {
            return '';
        }
        $names = array_filter(array_map('trim', preg_split('/、/u', $m[1])));
        sort($names);
        return implode('、', $names);
    };
    // 一个页面叠几套规则是常态（/games 上 arcade.js 与 physics.js 就是两套），
    // 而普通 <script> 共享全局词法作用域——同名函数声明是**静默覆盖**，
    // 少一组就等于少一组断言，别处还全绿。所以每页的名单逐个字钉死。
    // core.js 那组每页都跑：它一旦在某页缺席，说明 core.js 没被列进该页的 <script src>。
    foreach ([
        'index.html' => 'appSelfTest、coreSelfTest',
        'read.html' => 'coreSelfTest、readerSelfTest',
        'games.html' => 'arcadeSelfTest、coreSelfTest、physicsSelfTest',
        'software.html' => 'coreSelfTest、softwareSelfTest',
    ] as $page => $want) {
        t_eq($want, $testsOf($page), $page . ' 的规则自测一组不缺、一组不多');
    }
    t_eq(0, $exitCode, 'node tests/js-smoke.js 退出码为 0');
    if ($exitCode !== 0) {
        echo "  烟测完整输出：\n";
        foreach (preg_split('/\R/', $output) as $line) {
            echo '    ' . $line . "\n";
        }
    }
}
