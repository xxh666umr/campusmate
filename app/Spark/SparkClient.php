<?php

namespace App\Spark;

/**
 * 讯飞星火大模型 WebSocket 客户端（纯 PHP 实现，无需 composer 依赖）。
 * 协议：wss://spark-api.xf-yun.com/v3.5/chat，HMAC-SHA256 鉴权 + WebSocket 帧。
 */
class SparkClient
{
    private array $cfg;
    public string $lastError = '';

    /** 握手响应中残留的字节（101 之后可能紧跟第一帧），先缓存避免丢帧 */
    private string $readBuffer = '';
    /** 最近一次 close 帧的可读描述，用于拼出更准确的报错 */
    private string $closeInfo = '';

    public function __construct(array $cfg)
    {
        // 控制台复制密钥时容易带上首尾空格，统一去掉，避免签名对不上
        foreach (['app_id', 'api_key', 'api_secret'] as $key) {
            if (isset($cfg[$key]) && is_string($cfg[$key])) {
                $cfg[$key] = trim($cfg[$key]);
            }
        }
        $this->cfg = $cfg;
    }

    public function configured(): bool
    {
        return !empty($this->cfg['app_id']) && !empty($this->cfg['api_key']) && !empty($this->cfg['api_secret']);
    }

    public function chat(array $messages, ?int $uid = null): array
    {
        if (!$this->configured()) {
            throw new \RuntimeException('未配置星火 API 密钥');
        }

        $this->readBuffer = '';
        $this->closeInfo = '';

        $url = $this->buildAuthUrl();
        $parts = parse_url($url);
        $host = $parts['host'];
        $path = ($parts['path'] ?? '/') . '?' . ($parts['query'] ?? '');

        $context = stream_context_create([
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
                'SNI_enabled'      => true,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client(
            'ssl://' . $host . ':443',
            $errno,
            $errstr,
            (int) ($this->cfg['timeout'] ?? 30),
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!$fp) {
            throw new \RuntimeException('无法连接星火服务：' . ($errstr ?: '网络不可用'));
        }

        stream_set_timeout($fp, (int) ($this->cfg['timeout'] ?? 30));

        try {
            $this->handshake($fp, $host, $path);

            $payload = [
                'header'    => [
                    'app_id' => $this->cfg['app_id'],
                    'uid'    => (string) ($uid ?? random_int(100000, 999999)),
                ],
                'parameter' => [
                    'chat' => [
                        'domain'      => $this->cfg['domain'] ?? 'generalv3.5',
                        'temperature' => (float) ($this->cfg['temperature'] ?? 0.5),
                        'max_tokens'  => (int) ($this->cfg['max_tokens'] ?? 2048),
                    ],
                ],
                'payload'   => [
                    'message' => [
                        'text' => $messages,
                    ],
                ],
            ];

            $this->writeFrame($fp, json_encode($payload, JSON_UNESCAPED_UNICODE));
            $content = '';
            $deadline = time() + (int) ($this->cfg['timeout'] ?? 30);

            while (time() < $deadline) {
                [$opcode, $data] = $this->readFrame($fp);
                if ($opcode === 0x8) {
                    $this->closeInfo = $this->describeCloseFrame($data);
                    break;
                }
                if ($opcode === 0x9) {
                    $this->writeFrame($fp, $data, 0xA);
                    continue;
                }
                if ($opcode !== 0x1) {
                    continue;
                }
                $res = json_decode($data, true);
                if (!is_array($res)) {
                    continue;
                }
                $code = (int) ($res['header']['code'] ?? -1);
                if ($code !== 0) {
                    throw new \RuntimeException('星火返回错误 ' . $code . '：' . ($res['header']['message'] ?? '未知错误'));
                }
                foreach (($res['payload']['choices']['text'] ?? []) as $item) {
                    $chunk = (string) ($item['content'] ?? '');
                    if ($chunk === '') {
                        continue;
                    }
                    // 部分版本返回累计文本，避免重复拼接
                    if ($content !== '' && str_starts_with($chunk, $content)) {
                        $content = $chunk;
                    } else {
                        $content .= $chunk;
                    }
                }
                if ((int) ($res['header']['status'] ?? 0) === 2) {
                    break;
                }
            }
        } finally {
            if (is_resource($fp)) {
                fclose($fp);
            }
        }

        if (trim($content) === '') {
            $hint = $this->closeInfo !== '' ? '（' . $this->closeInfo . '）' : '';
            throw new \RuntimeException('星火未返回有效内容' . $hint);
        }

        return ['content' => trim($content), 'raw' => null];
    }

    private function buildAuthUrl(): string
    {
        $url = $this->cfg['url'] ?? 'wss://spark-api.xf-yun.com/v3.5/chat';
        $parts = parse_url($url);
        $host = $parts['host'] ?? 'spark-api.xf-yun.com';
        $path = $parts['path'] ?? '/v3.5/chat';

        $date = gmdate('D, d M Y H:i:s') . ' GMT';
        $origin = "host: {$host}\ndate: {$date}\nGET {$path} HTTP/1.1";
        $signature = base64_encode(hash_hmac('sha256', $origin, $this->cfg['api_secret'], true));
        $authorizationOrigin = sprintf(
            'api_key="%s", algorithm="hmac-sha256", headers="host date request-line", signature="%s"',
            $this->cfg['api_key'],
            $signature
        );
        $authorization = base64_encode($authorizationOrigin);

        return 'wss://' . $host . $path . '?' . http_build_query([
            'authorization' => $authorization,
            'date'          => $date,
            'host'          => $host,
        ]);
    }

    private function handshake($fp, string $host, string $path): void
    {
        $key = base64_encode(random_bytes(16));
        $request = "GET {$path} HTTP/1.1\r\n"
            . "Host: {$host}\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "User-Agent: CampusMate/1.0\r\n\r\n";
        fwrite($fp, $request);

        $response = '';
        while (!str_contains($response, "\r\n\r\n")) {
            $chunk = fread($fp, 1024);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $response .= $chunk;
            if (strlen($response) > 16384) {
                break;
            }
        }

        $headerEnd = strpos($response, "\r\n\r\n");
        if ($headerEnd === false) {
            throw new \RuntimeException('星火 WebSocket 握手无响应或响应不完整');
        }
        $headers = substr($response, 0, $headerEnd + 4);
        $leftover = substr($response, $headerEnd + 4);

        if (!preg_match('#^HTTP/1\.1\s+101#', $headers)) {
            throw new \RuntimeException('星火 WebSocket 握手失败：' . $this->describeHandshakeFailure($fp, $headers, $leftover));
        }

        // RFC 6455：客户端必须校验 Sec-WebSocket-Accept
        $expected = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        if (!preg_match('/^sec-websocket-accept:\s*(\S+)/mi', $headers, $matches) || !hash_equals($expected, $matches[1])) {
            throw new \RuntimeException('星火 WebSocket 握手失败：Sec-WebSocket-Accept 校验不通过');
        }

        // 101 之后可能紧跟第一帧，缓存起来交给 readFrame，避免丢帧
        $this->readBuffer = $leftover;
    }

    /** 把非 101 的握手响应整理成一行可读原因（优先取服务端 JSON 的 message 字段） */
    private function describeHandshakeFailure($fp, string $headers, string $body): string
    {
        $statusLine = (string) strtok($headers, "\r\n");
        if (preg_match('/Content-Length:\s*(\d+)/i', $headers, $matches)) {
            $need = min((int) $matches[1] - strlen($body), 65536);
            while ($need > 0) {
                $chunk = fread($fp, $need);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $body .= $chunk;
                $need -= strlen($chunk);
            }
        }
        $json = json_decode(trim($body), true);
        $detail = is_array($json) && isset($json['message']) ? (string) $json['message'] : trim($body);
        $detail = preg_replace('/\s+/u', ' ', $detail);
        $detail = $detail === null ? '' : trim($detail);
        return $statusLine . ($detail !== '' ? '（' . mb_substr($detail, 0, 300, 'UTF-8') . '）' : '');
    }

    private function describeCloseFrame(string $data): string
    {
        if (strlen($data) < 2) {
            return 'close 帧（无状态码）';
        }
        $code = unpack('n', substr($data, 0, 2))[1];
        $reason = substr($data, 2);
        return 'close 帧 code=' . $code . ($reason !== '' ? ' reason=' . $reason : '');
    }

    private function writeFrame($fp, string $payload, int $opcode = 0x1): void
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
        $masked = '';
        for ($i = 0; $i < $length; $i++) {
            $masked .= $payload[$i] ^ $mask[$i % 4];
        }
        $frame .= $masked;

        $written = 0;
        while ($written < strlen($frame)) {
            $n = fwrite($fp, substr($frame, $written));
            if ($n === false || $n === 0) {
                throw new \RuntimeException('向星火发送数据失败');
            }
            $written += $n;
        }
    }

    /** @return array{0:int,1:string} [opcode, payload] */
    private function readFrame($fp): array
    {
        $header = $this->readBytes($fp, 2);
        $b1 = ord($header[0]);
        $b2 = ord($header[1]);
        $opcode = $b1 & 0x0F;
        $masked = ($b2 & 0x80) !== 0;
        $length = $b2 & 0x7F;

        if ($length === 126) {
            $length = unpack('n', $this->readBytes($fp, 2))[1];
        } elseif ($length === 127) {
            $length = unpack('J', $this->readBytes($fp, 8))[1];
        }

        $mask = '';
        if ($masked) {
            $mask = $this->readBytes($fp, 4);
        }

        $payload = $length > 0 ? $this->readBytes($fp, $length) : '';
        if ($masked && $mask !== '') {
            $unmasked = '';
            for ($i = 0; $i < $length; $i++) {
                $unmasked .= $payload[$i] ^ $mask[$i % 4];
            }
            $payload = $unmasked;
        }

        return [$opcode, $payload];
    }

    private function readBytes($fp, int $n): string
    {
        $buffer = '';
        if ($this->readBuffer !== '') {
            $take = min($n, strlen($this->readBuffer));
            $buffer = substr($this->readBuffer, 0, $take);
            $this->readBuffer = substr($this->readBuffer, $take);
        }
        while (strlen($buffer) < $n) {
            $chunk = fread($fp, $n - strlen($buffer));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($fp);
                if (!empty($meta['timed_out'])) {
                    throw new \RuntimeException('等待星火响应超时');
                }
                throw new \RuntimeException('与星火的连接已断开');
            }
            $buffer .= $chunk;
        }
        return $buffer;
    }
}
