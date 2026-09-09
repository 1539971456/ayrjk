<?php
/**
 * 安忆软件库 - 轻量访问统计接口（无数据库，文件存储）
 *
 * 统计口径：
 *   total_uv  累计访客数（按日 UV 累加，近似累计独立访客）
 *   total_pv  累计访问次数
 *   today_uv  今日独立访客数
 *   today_pv  今日访问次数
 *   online    当前在线人数（最近 10 分钟内有访问的不同 IP 数）
 *
 * 数据文件存放于站点根目录 .stats/（文件名以 .php 结尾并带 exit 头，
 * 即使被直接 URL 访问也只会返回空内容，不会泄露数据）。
 *
 * 调用方式：GET visit.php  →  JSON
 * 该接口同时完成"记录一次访问"与"返回统计数字"，页面加载即计数。
 */

@header('Content-Type: application/json; charset=utf-8');
@header('Cache-Control: no-store, no-cache, must-revalidate');
@header('Pragma: no-cache');

$dir = __DIR__ . '/.stats';
if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
}
if (!is_dir($dir)) {
    echo json_encode(array('ok' => 0, 'msg' => 'stats dir unavailable'));
    exit;
}

/**
 * 在文件锁内执行 读-改-写，避免并发写坏文件。
 * $fn($data) 返回修改后的数组（或原样）。
 */
function locked_update($file, $fn)
{
    $h = @fopen($file, 'c+');
    if (!$h) return false;
    if (!flock($h, LOCK_EX)) { fclose($h); return false; }
    // 读取（跳过 PHP exit 头）
    rewind($h);
    $raw = stream_get_contents($h);
    $p = strpos($raw, '?>');
    if ($p !== false) $raw = substr($raw, $p + 2);
    $raw = trim($raw);
    $data = ($raw !== '') ? json_decode($raw, true) : null;
    if (!is_array($data)) $data = array();
    $data = $fn($data);
    // 写回
    $out = "<?php exit;?>\n" . json_encode($data);
    ftruncate($h, 0);
    rewind($h);
    fwrite($h, $out);
    fflush($h);
    flock($h, LOCK_UN);
    fclose($h);
    return true;
}

/** 读取文件内容（无锁，跳过 exit 头），失败返回 null */
function read_raw($file)
{
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') return null;
    $p = strpos($raw, '?>');
    if ($p !== false) $raw = substr($raw, $p + 2);
    $raw = trim($raw);
    if ($raw === '') return null;
    $j = json_decode($raw, true);
    return is_array($j) ? $j : null;
}

/** 统计一个 JSON 文件里的键数量（IP 集合大小） */
function count_keys($file)
{
    for ($try = 0; $try < 3; $try++) {
        $d = read_raw($file);
        if ($d !== null) return count($d);
        usleep(60000); // 并发写者可能在写，稍等重试
    }
    return 0;
}

$now    = time();
$today  = date('Y-m-d', $now);
$ip     = isset($_SERVER['REMOTE_ADDR']) ? trim($_SERVER['REMOTE_ADDR']) : '';
if ($ip === '') $ip = 'unknown';

$metaFile   = $dir . '/meta.php';
$dailyFile  = $dir . '/daily_' . $today . '.php';

// ---- 1) meta：跨日翻转 / 今日 PV / 在线表 ----
$okMeta = locked_update($metaFile, function ($m) use ($today, $ip, $now, $dir) {
    if (!isset($m['prev_uv']))  $m['prev_uv']  = 0;   // 截至昨天的累计 UV
    if (!isset($m['prev_pv']))  $m['prev_pv']  = 0;   // 截至昨天的累计 PV
    if (!isset($m['today']))    $m['today']    = $today;
    if (!isset($m['today_pv'])) $m['today_pv'] = 0;
    if (!isset($m['online']) || !is_array($m['online'])) $m['online'] = array();

    // 跨日翻转：把昨天 daily 文件的 UV 数与今日之前的 PV 并入累计
    if ($m['today'] !== $today) {
        $yFile = $dir . '/daily_' . $m['today'] . '.php';
        $m['prev_uv'] += count_keys($yFile);
        $m['prev_pv'] += $m['today_pv'];
        $m['today']    = $today;
        $m['today_pv'] = 0;
    }

    $m['today_pv'] = (int)$m['today_pv'] + 1;          // 今日访问次数 +1

    // 在线表：写入/刷新当前 IP，清掉超过 10 分钟未活动的 IP
    $online = $m['online'];
    $online[$ip] = $now;
    $expire = $now - 600;
    foreach ($online as $k => $ts) {
        if ($ts < $expire) unset($online[$k]);
    }
    if (count($online) > 3000) {                        // 防爆上限，保留最近记录
        $online = array_slice($online, -3000, null, true);
    }
    $m['online'] = $online;
    return $m;
});

if (!$okMeta) {
    echo json_encode(array('ok' => 0, 'msg' => 'meta lock failed'));
    exit;
}

// ---- 2) 当日 UV：把当前 IP 写入当日文件 ----
locked_update($dailyFile, function ($d) use ($ip) {
    $d[$ip] = 1;   // 同日同 IP 只记一次
    return $d;
});

// ---- 3) 汇总输出 ----
$meta      = read_raw($metaFile);
$today_ips = count_keys($dailyFile);
$today_uv  = $today_ips;

// 防御：今日文件首次创建瞬间可能尚未落盘，重读一次
if ($today_uv === 0 && !file_exists($dailyFile)) {
    usleep(50000);
    $today_uv = count_keys($dailyFile);
}

$onlineCnt = isset($meta['online']) ? count($meta['online']) : 0;

echo json_encode(array(
    'ok'       => 1,
    'total_uv' => (int)$meta['prev_uv'] + $today_uv, // 累计访客 = 昨日累计 + 今日 UV
    'total_pv' => (int)$meta['prev_pv'] + (int)$meta['today_pv'],
    'today_uv' => $today_uv,
    'today_pv' => (int)$meta['today_pv'],
    'online'   => $onlineCnt,
    'day'      => $today
), JSON_UNESCAPED_UNICODE);
exit;
