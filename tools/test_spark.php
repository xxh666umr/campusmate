<?php
/**
 * 讯飞星火 WebSocket 联通性自测脚本（纯 PHP，不依赖 Composer）
 *
 * 用途：以后拿到 APPID / APIKey / APISecret 时，一条命令验证「本机 + 密钥 +
 *      星火 v3.5 接口」是否能真正跑通；失败时定位到具体环节（DNS / TCP / TLS /
 *      握手 / 鉴权 / 业务错误码），不需要登录网页端。
 *
 * 用法详见同目录 spark_selftest.md：
 *   C:\xampp\php\php.exe tools\test_spark.php --app-id=你的APPID --api-key=你的APIKey --api-secret=你的APISecret
 *
 * 说明：
 *   - 只读 app/config.php / app/config.local.php 作为兜底密钥来源，不会写入任何文件；
 *   - 输出里对密钥做了脱敏，默认不会回显完整 authorization，可直接贴给老师排错；
 *   - 退出码：0 成功；2 用法/缺密钥；3 网络或 TLS 失败；4 握手失败；
 *     5 业务错误码；6 未返回内容。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo '本脚本只能在命令行（CLI）运行。';
    exit(2);
}

error_reporting(E_ALL);
ini_set('display_errors', '1');
mb_internal_encoding('UTF-8');

const EXIT_OK        = 0;
const EXIT_USAGE     = 2;
const EXIT_NETWORK   = 3;
const EXIT_HANDSHAKE = 4;
const EXIT_API_ERROR = 5;
const EXIT_EMPTY     = 6;

const WS_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

$root = dirname(__DIR__);
$opts = parse_options(array_slice($argv, 1));

if ($opts['help']) {
    print_usage();
    exit(EXIT_OK);
}

$projectConfig = load_project_config($root);
$resolved = resolve_config($opts, $projectConfig);

section('0. 运行参数');
echo '密钥来源：' . $resolved['source'] . "\n";
echo 'APPID     ：' . mask_value($resolved['app_id']) . '（长度 ' . strlen($resolved['app_id']) . "）\n";
echo 'APIKey    ：' . mask_value($resolved['api_key']) . '（长度 ' . strlen($resolved['api_key']) . "）\n";
echo 'APISecret ：' . mask_value($resolved['api_secret']) . '（长度 ' . strlen($resolved['api_secret']) . "）\n";
echo 'URL       ：' . $resolved['url'] . "\n";
echo 'domain    ：' . $resolved['domain'] . "\n";
echo '超时      ：' . $resolved['timeout'] . " 秒\n";

foreach (check_key_format($resolved) as $warning) {
    echo '[提示] ' . $warning . "\n";
}
$pairWarning = check_url_domain_pair($resolved['url'], $resolved['domain']);
if ($pairWarning !== '') {
    echo '[警告] ' . $pairWarning . "\n";
}

if ($resolved['missing'] !== []) {
    fwrite(STDERR, "\n缺少密钥字段：" . implode('、', $resolved['missing']) . "\n");
    fwrite(STDERR, "请用命令行参数或环境变量提供：\n");
    fwrite(STDERR, "  --app-id=xxx --api-key=xxx --api-secret=xxx\n");
    fwrite(STDERR, "  或先填好 app/config.local.php，脚本会自动读取。\n");
    exit(EXIT_USAGE);
}

$host = parse_url($resolved['url'], PHP_URL_HOST);
$path = parse_url($resolved['url'], PHP_URL_PATH);
if (!is_string($host) || $host === '' || !is_string($path) || $path === '') {
    fwrite(STDERR, "URL 不合法：{$resolved['url']}\n");
    exit(EXIT_USAGE);
}

section('1. PHP 与 OpenSSL 环境');
$cafile = (string) ini_get('openssl.cafile');
$capath = (string) ini_get('openssl.capath');
echo 'PHP 版本        ：' . PHP_VERSION . '（' . PHP_SAPI . "）\n";
echo 'openssl 扩展    ：' . (extension_loaded('openssl') ? '已加载，' . OPENSSL_VERSION_TEXT : '未加载（无法建立 TLS 连接！）') . "\n";
echo 'allow_url_fopen ：' . ini_get('allow_url_fopen') . "\n";
echo 'openssl.cafile  ：' . ($cafile !== '' ? $cafile : '（空）') . "\n";
echo 'openssl.capath  ：' . ($capath !== '' ? $capath : '（空）') . "\n";
$caUsable = $cafile !== '' && is_file($cafile);
if ($caUsable) {
    echo 'CA 证书文件     ：存在，' . number_format((float) filesize($cafile)) . " 字节\n";
} else {
    echo "CA 证书文件     ：不存在或未配置\n";
    echo "                  → 可在 php.ini 设置 openssl.cafile 指向 cacert.pem / curl-ca-bundle.crt，\n";
    echo "                    或临时用 --insecure 仅供排错（不要用于生产）。\n";
}
if (extension_loaded('openssl') && !$caUsable && !$opts['insecure']) {
    fwrite(STDERR, "\nTLS 证书校验无法进行：openssl.cafile 未配置。若只是排错可加 --insecure。\n");
    exit(EXIT_NETWORK);
}

$context = stream_context_create(['ssl' => [
    'verify_peer'             => !$opts['insecure'],
    'verify_peer_name'        => !$opts['insecure'],
    'SNI_enabled'             => true,
    'capture_peer_cert'       => true,
    'capture_peer_cert_chain' => true,
]]);

section('2. DNS 解析');
$dnsStart = microtime(true);
$ips = resolve_ipv4($host);
$dnsMs = (int) round((microtime(true) - $dnsStart) * 1000);
if ($ips === []) {
    echo "DNS 解析失败：{$host}（检查本机网络或 DNS 设置）\n";
    exit(EXIT_NETWORK);
}
echo 'DNS 解析成功   ：' . implode(', ', $ips) . "（{$dnsMs}ms）\n";

section('3. TCP + TLS 连接（443）');
$connectStart = microtime(true);
$errno = 0;
$errstr = '';
$fp = @stream_socket_client(
    'ssl://' . $host . ':443',
    $errno,
    $errstr,
    $resolved['timeout'],
    STREAM_CLIENT_CONNECT,
    $context
);
$connectMs = (int) round((microtime(true) - $connectStart) * 1000);
if (!$fp) {
    echo "连接失败：errno={$errno} errstr={$errstr}（{$connectMs}ms）\n";
    echo "→ 可能原因：本机防火墙、公司/校园网代理、DNS 污染，或 CA 证书校验失败。\n";
    echo "→ 若怀疑是 CA 问题，可加 --insecure 再跑一次确认（仅排错用）。\n";
    exit(EXIT_NETWORK);
}
stream_set_timeout($fp, $resolved['timeout']);
$meta = stream_get_meta_data($fp);
$crypto = $meta['crypto'] ?? [];
echo 'TLS 握手成功   ：' . ($crypto['protocol'] ?? '?') . ' / ' . ($crypto['cipher_name'] ?? '?')
    . '（' . ($crypto['cipher_bits'] ?? '?') . "bit，{$connectMs}ms）\n";
$peerCert = stream_context_get_params($fp)['options']['ssl']['peer_certificate'] ?? null;
if ($peerCert) {
    $certInfo = openssl_x509_parse($peerCert);
    echo '服务器证书     ：CN=' . ($certInfo['subject']['CN'] ?? '?')
        . '，签发者=' . ($certInfo['issuer']['CN'] ?? '?')
        . '，有效期至 ' . date('Y-m-d', (int) ($certInfo['validTo_time_t'] ?? 0)) . "\n";
}

$totalStart = microtime(true);
$deadline = microtime(true) + (float) $resolved['timeout'];

section('4. 生成鉴权 URL（与 app/Spark/SparkClient.php 一致）');
$auth = build_auth_url($host, $path, $resolved['api_key'], $resolved['api_secret']);
echo "签名原文（3 行，api_secret 不打印）：\n" . $auth['origin'] . "\n";
echo 'signature      ：' . $auth['signature'] . "\n";
$authBase64 = base64_encode($auth['auth_origin']);
echo 'authorization  ：' . ($opts['show-auth'] ? $authBase64 : mask_text($authBase64, 24)) . "\n";
echo '最终 URL       ：' . ($opts['show-auth'] ? $auth['url'] : mask_text($auth['url'], 90)) . "\n";
echo "（--show-auth 可回显完整值，默认脱敏）\n";

$payload = build_payload($resolved['app_id'], $resolved['domain'], $resolved['message']);
echo "\n请求 payload：\n" . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";

if ($opts['dry-run']) {
    echo "\n[dry-run] 只生成鉴权 URL 与请求体，不发起网络请求，也不消耗 token。\n";
    fclose($fp);
    exit(EXIT_OK);
}

section('5. WebSocket 握手');
$wsKey = base64_encode(random_bytes(16));
$request = "GET {$path}?" . http_build_query([
    'authorization' => $authBase64,
    'date'          => $auth['date'],
    'host'          => $host,
]) . " HTTP/1.1\r\n"
    . "Host: {$host}\r\n"
    . "Upgrade: websocket\r\n"
    . "Connection: Upgrade\r\n"
    . "Sec-WebSocket-Key: {$wsKey}\r\n"
    . "Sec-WebSocket-Version: 13\r\n"
    . "User-Agent: CampusMate-SparkSelfTest/1.0\r\n\r\n";
$requestPreview = $opts['show-auth']
    ? $request
    : preg_replace('/authorization=[^&]+/', 'authorization=<已脱敏>', $request, 1);
echo "→ 请求：\n" . $requestPreview;
write_all($fp, $request);

$buffer = '';
$headerEnd = false;
$handshakeStart = microtime(true);
while (($headerEnd = strpos($buffer, "\r\n\r\n")) === false) {
    $remaining = $deadline - microtime(true);
    if ($remaining <= 0) {
        echo "← 等待握手响应超时（{$resolved['timeout']}s）\n";
        fclose($fp);
        exit(EXIT_HANDSHAKE);
    }
    stream_set_timeout($fp, max(1, (int) ceil($remaining)));
    $chunk = fread($fp, 4096);
    if ($chunk === false || $chunk === '') {
        $meta = stream_get_meta_data($fp);
        echo '← 握手响应读取失败：' . (!empty($meta['timed_out']) ? '超时' : '连接已断开') . "\n";
        fclose($fp);
        exit(EXIT_HANDSHAKE);
    }
    $buffer .= $chunk;
    if (strlen($buffer) > 65536) {
        break;
    }
}
$handshakeMs = (int) round((microtime(true) - $handshakeStart) * 1000);
if ($headerEnd === false) {
    echo "← 握手响应不完整\n";
    fclose($fp);
    exit(EXIT_HANDSHAKE);
}
$headers = substr($buffer, 0, $headerEnd + 4);
$buffer = substr($buffer, $headerEnd + 4);
echo "← 响应：\n" . $headers;

$statusOk = preg_match('#^HTTP/1\.1\s+101#', $headers) === 1;
if (!$statusOk) {
    if (preg_match('/Content-Length:\s*(\d+)/i', $headers, $m)) {
        $need = (int) $m[1] - strlen($buffer);
        while ($need > 0) {
            $chunk = fread($fp, $need);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $buffer .= $chunk;
            $need -= strlen($chunk);
        }
    }
    $body = trim($buffer);
    if ($body !== '') {
        echo '← 响应体：' . mb_substr($body, 0, 1000, 'UTF-8') . "\n";
    }
    fclose($fp);
    echo "\n握手失败（{$handshakeMs}ms）。\n";
    if (preg_match('#^HTTP/1\.1\s+401#', $headers)) {
        echo "→ 401 = 鉴权未通过：检查 APPID / APIKey / APISecret 是否属于同一个应用、是否复制完整（32 位）、\n";
        echo "  本机时间偏差是否超过 300 秒，以及 key 是否已被重置。\n";
    } elseif (preg_match('#^HTTP/1\.1\s+404#', $headers)) {
        echo "→ 404 = 地址不对：v3.5 的 URL 是 wss://spark-api.xf-yun.com/v3.5/chat，domain 是 generalv3.5。\n";
    } else {
        echo "→ 检查 URL、网络代理与密钥是否有效。\n";
    }
    exit(EXIT_HANDSHAKE);
}

$expectedAccept = base64_encode(sha1($wsKey . WS_GUID, true));
$acceptOk = preg_match('/^sec-websocket-accept:\s*(\S+)/mi', $headers, $m) === 1
    && hash_equals($expectedAccept, $m[1]);
echo 'Sec-WebSocket-Accept 校验：' . ($acceptOk ? "通过\n" : "不通过（响应可能被代理篡改）\n");
if (!$acceptOk) {
    fclose($fp);
    exit(EXIT_HANDSHAKE);
}

section('6. 发送请求并读取流式响应');
$requestJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
write_ws_frame($fp, $requestJson, 0x1);
echo '→ 已发送 ' . strlen($requestJson) . " 字节的 TEXT 帧\n";

$answer = '';
$finishStatus = 0;
$apiCode = 0;
$apiMessage = '';
$sid = '';
$usage = [];
$frameNo = 0;
$frameCount = 0;
$errorExit = 0;
$closeInfo = '';

while (microtime(true) < $deadline) {
    $remaining = $deadline - microtime(true);
    stream_set_timeout($fp, max(1, (int) ceil($remaining)));
    try {
        $frame = read_frame($fp, $buffer, $deadline);
    } catch (Throwable $e) {
        echo '✗ 读帧失败：' . $e->getMessage() . "\n";
        $errorExit = EXIT_NETWORK;
        break;
    }
    $frameNo++;
    [$opcode, $fin, $isMasked, $data] = $frame;
    $opName = opcode_name($opcode);

    if ($opcode === 0x8) {
        $closeInfo = describe_close($data);
        echo '#' . $frameNo . " {$opName} fin=" . ($fin ? 1 : 0) . ' len=' . strlen($data) . ' ' . $closeInfo . "\n";
        break;
    }
    if ($opcode === 0x9) {
        echo '#' . $frameNo . " {$opName} len=" . strlen($data) . " → 回 PONG\n";
        write_ws_frame($fp, $data, 0xA);
        continue;
    }
    if ($opcode === 0xA) {
        echo '#' . $frameNo . " {$opName} len=" . strlen($data) . "\n";
        continue;
    }
    if ($opcode !== 0x1) {
        echo '#' . $frameNo . ' ' . $opName . ' len=' . strlen($data) . "（忽略）\n";
        continue;
    }
    if ($isMasked) {
        echo "[警告] 服务端返回的帧带掩码，可能经过代理，RFC 6455 不允许。\n";
    }

    $frameCount++;
    $json = json_decode($data, true);
    if (!is_array($json)) {
        echo '#' . $frameNo . ' TEXT 但 JSON 解析失败：' . mb_substr($data, 0, 300, 'UTF-8') . "\n";
        continue;
    }
    $header = $json['header'] ?? [];
    $apiCode = (int) ($header['code'] ?? -1);
    $status = (int) ($header['status'] ?? 0);
    $sid = (string) ($header['sid'] ?? $sid);
    if ($apiCode !== 0) {
        $apiMessage = (string) ($header['message'] ?? '未知错误');
        echo '#' . $frameNo . " TEXT code={$apiCode} status={$status} message={$apiMessage}\n";
        echo "→ 业务错误码说明：\n" . error_code_hint($apiCode) . "\n";
        $errorExit = EXIT_API_ERROR;
        break;
    }

    $delta = '';
    foreach (($json['payload']['choices']['text'] ?? []) as $item) {
        $delta .= (string) ($item['content'] ?? '');
    }
    if (isset($json['payload']['usage']['text'])) {
        $usage = $json['payload']['usage']['text'];
    }
    if ($status === 2) {
        $finishStatus = 2;
    }
    $answer .= $delta;
    echo '#' . $frameNo . " TEXT code=0 status={$status} len=" . strlen($data)
        . ' delta=' . mb_strlen($delta, 'UTF-8') . '字'
        . ($delta !== '' ? ' "' . mb_substr($delta, 0, 40, 'UTF-8') . '"' : '')
        . "\n";
    if ($opts['verbose']) {
        echo '    ' . mb_substr(json_encode($json, JSON_UNESCAPED_UNICODE), 0, 1500, 'UTF-8') . "\n";
    }
    if ($finishStatus === 2) {
        break;
    }
}

$totalMs = (int) round((microtime(true) - $totalStart) * 1000);
fclose($fp);

section('7. 结果');
if ($apiCode !== 0 && $apiCode !== -1) {
    echo "✗ 调用失败：业务错误码 {$apiCode}，{$apiMessage}（握手 {$handshakeMs}ms，总计 {$totalMs}ms）\n";
    echo '  sid：' . ($sid !== '' ? $sid : '（未返回）') . "\n";
    exit(EXIT_API_ERROR);
}
if ($errorExit !== 0) {
    echo "✗ 调用失败：网络/协议层错误（总计 {$totalMs}ms）\n";
    if ($closeInfo !== '') {
        echo '  ' . $closeInfo . "\n";
    }
    exit($errorExit);
}
if (trim($answer) === '') {
    echo '✗ 握手与鉴权通过，但没有收到任何文本内容' . ($closeInfo !== '' ? "（{$closeInfo}）" : '') . "。\n";
    echo "  可能原因：APPID 未开通对应版本、domain 与 URL 不匹配、或输入被内容审核拦截。\n";
    exit(EXIT_EMPTY);
}

echo "✓ 调用成功（总计 {$totalMs}ms，其中 TLS 连接 {$connectMs}ms、握手 {$handshakeMs}ms）\n";
echo '答：' . trim($answer) . "\n";
if ($usage !== []) {
    echo 'token 用量：prompt=' . ($usage['prompt_tokens'] ?? '?')
        . ' completion=' . ($usage['completion_tokens'] ?? '?')
        . ' total=' . ($usage['total_tokens'] ?? '?') . "\n";
}
echo 'sid：' . ($sid !== '' ? $sid : '（未返回）') . '，共收到 ' . $frameCount . " 个数据帧\n";

if (!$opts['no-app-check']) {
    section('8. 用项目里的生产类 App\\Spark\\SparkClient 再跑一次');
    $classFile = $root . '/app/Spark/SparkClient.php';
    if (!is_file($classFile)) {
        echo "跳过：找不到 {$classFile}\n";
        exit(EXIT_OK);
    }
    require_once $classFile;
    $client = new \App\Spark\SparkClient([
        'app_id'     => $resolved['app_id'],
        'api_key'    => $resolved['api_key'],
        'api_secret' => $resolved['api_secret'],
        'url'        => $resolved['url'],
        'domain'     => $resolved['domain'],
        'timeout'    => $resolved['timeout'],
    ]);
    $appStart = microtime(true);
    try {
        $result = $client->chat($payload['payload']['message']['text'], 10001);
        $appMs = (int) round((microtime(true) - $appStart) * 1000);
        echo "✓ 生产类调用成功（{$appMs}ms）：" . mb_substr($result['content'], 0, 200, 'UTF-8') . "\n";
        echo "  结论：app/Spark/SparkClient.php 与上面的自测流程行为一致，填好密钥即可切换到真实星火。\n";
    } catch (Throwable $e) {
        $appMs = (int) round((microtime(true) - $appStart) * 1000);
        echo "✗ 生产类调用失败（{$appMs}ms）：" . get_class($e) . '：' . $e->getMessage() . "\n";
        echo "  结论：自测脚本能通但生产类失败，请把这段输出发给开发同学排查。\n";
        exit(EXIT_HANDSHAKE);
    }
}

echo "\n全部检查通过。\n";
exit(EXIT_OK);

/* ------------------------------------------------------------------ 工具函数 */

function section(string $title): void
{
    echo "\n" . str_repeat('=', 10) . ' ' . $title . ' ' . str_repeat('=', 10) . "\n";
}

function print_usage(): void
{
    echo <<<'TXT'
星火 WebSocket 联通性自测脚本（纯 PHP，无 Composer 依赖）

用法：
  C:\xampp\php\php.exe tools\test_spark.php --app-id=xxx --api-key=xxx --api-secret=xxx
  C:\xampp\php\php.exe tools\test_spark.php --dry-run          # 只检查配置、不联网

参数（也可以用环境变量，优先级：命令行 > 环境变量 > app/config.local.php）：
  --app-id=APPID        讯飞控制台的 APPID
  --api-key=APIKey      讯飞控制台的 APIKey
  --api-secret=APISecret
  --url=wss://...       默认 wss://spark-api.xf-yun.com/v3.5/chat
  --domain=generalv3.5  默认 generalv3.5（必须与 url 版本对应）
  --message="你好"      测试问题，默认「你好，请用一句话介绍你自己。」
  --timeout=15          总超时秒数，默认 15
  --insecure            跳过 TLS 证书校验（仅排错，不要用于生产）
  --show-auth           回显完整鉴权 URL（默认脱敏）
  --verbose             打印每个数据帧的完整 JSON
  --no-app-check        跳过用生产类 App\Spark\SparkClient 复测
  --dry-run / --help

环境变量：SPARK_APP_ID、SPARK_API_KEY、SPARK_API_SECRET、SPARK_URL、SPARK_DOMAIN

退出码：0 成功；2 用法/缺密钥；3 网络或 TLS；4 握手；5 业务错误码；6 未返回内容。

TXT;
}

function parse_options(array $args): array
{
    $opts = [
        'app-id'       => null,
        'api-key'      => null,
        'api-secret'   => null,
        'url'          => null,
        'domain'       => null,
        'message'      => '你好，请用一句话介绍你自己。',
        'timeout'      => 15,
        'insecure'     => false,
        'dry-run'      => false,
        'verbose'      => false,
        'show-auth'    => false,
        'no-app-check' => false,
        'help'         => false,
    ];
    $aliases = [
        'app_id' => 'app-id', 'appid' => 'app-id',
        'api_key' => 'api-key', 'apikey' => 'api-key',
        'api_secret' => 'api-secret', 'apisecret' => 'api-secret',
        't' => 'timeout', 'v' => 'verbose', 'h' => 'help',
    ];
    for ($i = 0; $i < count($args); $i++) {
        $arg = $args[$i];
        if ($arg === '-h' || $arg === '--help') {
            $opts['help'] = true;
            continue;
        }
        if (!str_starts_with($arg, '--')) {
            fwrite(STDERR, "无法识别的参数：{$arg}\n\n");
            print_usage();
            exit(EXIT_USAGE);
        }
        $arg = substr($arg, 2);
        $value = null;
        if (str_contains($arg, '=')) {
            [$arg, $value] = explode('=', $arg, 2);
        }
        $arg = $aliases[$arg] ?? $arg;
        if (!array_key_exists($arg, $opts)) {
            fwrite(STDERR, "未知选项：--{$arg}\n\n");
            print_usage();
            exit(EXIT_USAGE);
        }
        if (is_bool($opts[$arg])) {
            $opts[$arg] = $value === null
                ? true
                : in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
            continue;
        }
        if ($value === null) {
            if ($i + 1 >= count($args)) {
                fwrite(STDERR, "选项 --{$arg} 缺少取值\n");
                exit(EXIT_USAGE);
            }
            $value = $args[++$i];
        }
        $opts[$arg] = $arg === 'timeout' ? max(3, (int) $value) : $value;
    }
    return $opts;
}

function load_project_config(string $root): array
{
    $file = $root . '/app/config.php';
    if (!is_file($file)) {
        return [];
    }
    $config = require $file;
    return is_array($config) ? $config : [];
}

function resolve_config(array $opts, array $projectConfig): array
{
    $spark = $projectConfig['spark'] ?? [];
    $fields = [
        'app_id'     => [$opts['app-id'], 'SPARK_APP_ID', $spark['app_id'] ?? '', 'APPID'],
        'api_key'    => [$opts['api-key'], 'SPARK_API_KEY', $spark['api_key'] ?? '', 'APIKey'],
        'api_secret' => [$opts['api-secret'], 'SPARK_API_SECRET', $spark['api_secret'] ?? '', 'APISecret'],
    ];
    $resolved = ['missing' => []];
    $sourceLabels = [];
    foreach ($fields as $name => [$cliValue, $envName, $configValue, $label]) {
        $value = is_string($cliValue) ? trim($cliValue) : '';
        if ($value !== '') {
            $sourceLabels[] = $label . '（命令行）';
        } else {
            $envValue = getenv($envName);
            $value = is_string($envValue) ? trim($envValue) : '';
            if ($value !== '') {
                $sourceLabels[] = $label . '（环境变量）';
            }
        }
        if ($value === '') {
            $value = is_string($configValue) ? trim($configValue) : '';
            if ($value !== '') {
                $sourceLabels[] = $label . '（项目配置）';
            }
        }
        $resolved[$name] = $value;
        if ($value === '') {
            $resolved['missing'][] = $label;
        }
    }
    $resolved['source'] = $sourceLabels === [] ? '未提供' : implode('、', array_unique($sourceLabels));
    $resolved['url'] = $opts['url'] ?: (getenv('SPARK_URL') ?: ($spark['url'] ?? 'wss://spark-api.xf-yun.com/v3.5/chat'));
    $resolved['domain'] = $opts['domain'] ?: (getenv('SPARK_DOMAIN') ?: ($spark['domain'] ?? 'generalv3.5'));
    $resolved['timeout'] = (int) $opts['timeout'];
    $resolved['message'] = (string) $opts['message'];
    return $resolved;
}

function check_key_format(array $resolved): array
{
    $warnings = [];
    if ($resolved['app_id'] !== '' && !preg_match('/^[0-9a-fA-F]{8}$/', $resolved['app_id'])) {
        $warnings[] = 'APPID 一般是 8 位十六进制，当前是 ' . strlen($resolved['app_id']) . ' 位，请确认没复制错。';
    }
    foreach (['api_key' => 'APIKey', 'api_secret' => 'APISecret'] as $field => $label) {
        $value = $resolved[$field];
        if ($value !== '' && !preg_match('/^[0-9a-fA-F]{32}$/', $value)) {
            $warnings[] = $label . ' 一般是 32 位十六进制，当前是 ' . strlen($value) . ' 位，请确认没复制错/漏字符。';
        }
    }
    return $warnings;
}

function check_url_domain_pair(string $url, string $domain): string
{
    $pairs = [
        '/v3.5/chat'     => 'generalv3.5',
        '/v3.1/chat'     => 'generalv3',
        '/v1.1/chat'     => 'lite',
        '/v4.0/chat'     => '4.0Ultra',
        '/chat/max-32k'  => 'max-32k',
        '/chat/pro-128k' => 'pro-128k',
    ];
    foreach ($pairs as $path => $expectedDomain) {
        if (str_contains($url, $path) && $domain !== $expectedDomain) {
            return "URL 是 {$path} 版本，domain 应为 {$expectedDomain}，当前是 {$domain}（否则会返回 10404 path 与 domain 不匹配）。";
        }
    }
    return '';
}

function resolve_ipv4(string $host): array
{
    $ips = @gethostbynamel($host);
    if (is_array($ips) && $ips !== []) {
        return $ips;
    }
    $ip = @gethostbyname($host);
    return ($ip === $host || $ip === '') ? [] : [$ip];
}

function mask_value(string $value): string
{
    $length = strlen($value);
    if ($length === 0) {
        return '（空）';
    }
    if ($length <= 8) {
        return str_repeat('*', $length);
    }
    return substr($value, 0, 4) . str_repeat('*', max(0, $length - 6)) . substr($value, -2);
}

function mask_text(string $text, int $prefix): string
{
    if (strlen($text) <= $prefix + 8) {
        return $text;
    }
    return substr($text, 0, $prefix) . '…（已脱敏，共 ' . strlen($text) . ' 字节）';
}

function build_auth_url(string $host, string $path, string $apiKey, string $apiSecret): array
{
    $date = gmdate('D, d M Y H:i:s') . ' GMT';
    $origin = "host: {$host}\ndate: {$date}\nGET {$path} HTTP/1.1";
    $signature = base64_encode(hash_hmac('sha256', $origin, $apiSecret, true));
    $authOrigin = sprintf(
        'api_key="%s", algorithm="hmac-sha256", headers="host date request-line", signature="%s"',
        $apiKey,
        $signature
    );
    $url = 'wss://' . $host . $path . '?' . http_build_query([
        'authorization' => base64_encode($authOrigin),
        'date'          => $date,
        'host'          => $host,
    ]);
    return [
        'date'        => $date,
        'origin'      => $origin,
        'signature'   => $signature,
        'auth_origin' => $authOrigin,
        'url'         => $url,
    ];
}

function build_payload(string $appId, string $domain, string $message): array
{
    return [
        'header'    => ['app_id' => $appId, 'uid' => 'selftest-1'],
        'parameter' => [
            'chat' => [
                'domain'      => $domain,
                'temperature' => 0.5,
                'max_tokens'  => 1024,
            ],
        ],
        'payload'   => [
            'message' => [
                'text' => [['role' => 'user', 'content' => $message]],
            ],
        ],
    ];
}

function write_all($fp, string $data): void
{
    $written = 0;
    $length = strlen($data);
    while ($written < $length) {
        $n = fwrite($fp, substr($data, $written));
        if ($n === false || $n === 0) {
            throw new RuntimeException('写入 socket 失败');
        }
        $written += $n;
    }
}

function write_ws_frame($fp, string $payload, int $opcode): void
{
    $length = strlen($payload);
    $frame = chr(0x80 | $opcode);
    if ($length < 126) {
        $frame .= chr(0x80 | $length);
    } elseif ($length < 65536) {
        $frame .= chr(0x80 | 126) . pack('n', $length);
    } else {
        $frame .= chr(0x80 | 127) . pack('J', $length);
    }
    $mask = random_bytes(4);
    $frame .= $mask;
    for ($i = 0; $i < $length; $i++) {
        $frame .= $payload[$i] ^ $mask[$i % 4];
    }
    write_all($fp, $frame);
}

/** @return array{0:int,1:bool,2:bool,3:string} [opcode, fin, masked, payload] */
function read_frame($fp, string &$buffer, float $deadline): array
{
    $header = read_exact($fp, $buffer, 2, $deadline);
    $b1 = ord($header[0]);
    $b2 = ord($header[1]);
    $opcode = $b1 & 0x0F;
    $fin = ($b1 & 0x80) !== 0;
    $masked = ($b2 & 0x80) !== 0;
    $length = $b2 & 0x7F;
    if ($length === 126) {
        $length = unpack('n', read_exact($fp, $buffer, 2, $deadline))[1];
    } elseif ($length === 127) {
        $length = unpack('J', read_exact($fp, $buffer, 8, $deadline))[1];
    }
    $mask = $masked ? read_exact($fp, $buffer, 4, $deadline) : '';
    $payload = $length > 0 ? read_exact($fp, $buffer, $length, $deadline) : '';
    if ($masked) {
        $unmasked = '';
        for ($i = 0; $i < $length; $i++) {
            $unmasked .= $payload[$i] ^ $mask[$i % 4];
        }
        $payload = $unmasked;
    }
    return [$opcode, $fin, $masked, $payload];
}

function read_exact($fp, string &$buffer, int $n, float $deadline): string
{
    while (strlen($buffer) < $n) {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            throw new RuntimeException('总超时');
        }
        stream_set_timeout($fp, max(1, (int) ceil($remaining)));
        $chunk = fread($fp, max(1, $n - strlen($buffer)));
        if ($chunk === false || $chunk === '') {
            $meta = stream_get_meta_data($fp);
            if (!empty($meta['timed_out'])) {
                throw new RuntimeException('读取超时');
            }
            throw new RuntimeException('连接已关闭');
        }
        $buffer .= $chunk;
    }
    $out = substr($buffer, 0, $n);
    $buffer = substr($buffer, $n);
    return $out;
}

function opcode_name(int $opcode): string
{
    return [
        0x0 => 'CONT',
        0x1 => 'TEXT',
        0x2 => 'BINARY',
        0x8 => 'CLOSE',
        0x9 => 'PING',
        0xA => 'PONG',
    ][$opcode] ?? ('0x' . dechex($opcode));
}

function describe_close(string $data): string
{
    if (strlen($data) < 2) {
        return '（无状态码）';
    }
    $code = unpack('n', substr($data, 0, 2))[1];
    $reason = substr($data, 2);
    return 'code=' . $code . ($reason !== '' ? ' reason=' . $reason : '');
}

function error_code_hint(int $code): string
{
    $map = [
        10005 => '用户参数值错误（检查 domain / temperature / max_tokens）。',
        10006 => '同一用户已连接，不能多处同时连接。',
        10013 => '输入内容审核不通过。',
        10014 => '输出内容审核不通过。',
        10016 => 'APPID 授权类错误（未开通对应版本 / token 不足 / 并发超限）。',
        10019 => '会话内容有违规倾向，建议换话题。',
        10110 => '服务忙 / 引擎并发或授权不足，稍后重试。',
        10163 => '请求引擎的参数异常（schema 检查不通过）。',
        10404 => '请求的 path 与 domain 不匹配（v3.5 对应 generalv3.5）。',
        10907 => 'token 数量超过上限，精简对话历史或输入。',
        11200 => '该 APPID 没有对应功能授权，或业务量超限。',
        11201 => '日流控超限。',
        11202 => '秒级流控超限。',
        11203 => '并发流控超限。',
    ];
    return '  ' . $code . '：' . ($map[$code] ?? '未知错误码，请在讯飞文档「星火认知大模型服务说明」中查询。');
}
