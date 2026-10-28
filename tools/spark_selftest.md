# 星火联调自测脚本使用说明

`tools/test_spark.php` 用于**以后拿到星火密钥时**，30 秒内验证「本机网络 + 密钥 + 讯飞星火 v3.5 接口」是否能真正跑通。
纯 PHP 实现，不需要 Composer；只读配置、不写任何文件；输出会自动脱敏密钥，可直接把结果发给老师或同学排错。

> 当前项目**未配置密钥**，默认使用本地演示引擎（`app/Spark/MockProvider.php`），这是预期行为，不是故障。
> 本机已实测：`spark-api.xf-yun.com:443` 可连通、TLS 证书校验通过，配上密钥即可切换真实星火。

---

## 1. 前置：拿到三个值

讯飞开放平台控制台 →「星火认知大模型」→ 服务管理 / 我的应用，创建应用后可以拿到：

| 控制台名称 | 本项目字段 | 形态 |
| --- | --- | --- |
| APPID | `app_id` | 8 位十六进制，如 `1a2b3c4d` |
| APIKey | `api_key` | 32 位十六进制 |
| APISecret | `api_secret` | 32 位十六进制（**不对外展示，不要截图/提交**） |

需要开通的版本：**Spark Max（对应 `wss://spark-api.xf-yun.com/v3.5/chat`，domain `generalv3.5`）**。

---

## 2. 三种运行方式

### 方式 A：命令行直接传（推荐，换台电脑也能用）

```bat
cd /d D:\...\smart-service
C:\xampp\php\php.exe tools\test_spark.php ^
  --app-id=你的APPID ^
  --api-key=你的APIKey ^
  --api-secret=你的APISecret
```

### 方式 B：环境变量（避免密钥留在命令历史里）

```bat
set SPARK_APP_ID=你的APPID
set SPARK_API_KEY=你的APIKey
set SPARK_API_SECRET=你的APISecret
C:\xampp\php\php.exe tools\test_spark.php
```

### 方式 C：先用项目配置，再自测

把密钥填进 `app/config.local.php`（见下文第 5 节），然后：

```bat
C:\xampp\php\php.exe tools\test_spark.php
```

脚本按 **命令行 > 环境变量 > `app/config.php` / `app/config.local.php`** 的顺序读取，控制台会显示每个字段的来源。

### 只检查配置、不联网、不消耗 token

```bat
C:\xampp\php\php.exe tools\test_spark.php --dry-run
```

适合先确认密钥有没有复制对（长度、字段配对），再发起真实请求。

---

## 3. 输出怎么读

脚本按 8 个环节输出，任何一步失败都会说明原因和下一步怎么做：

| 环节 | 说明 | 失败含义 |
| --- | --- | --- |
| 0. 运行参数 | 密钥来源、长度、URL/domain 配对 | 长度不对 → 复制漏字符 |
| 1. PHP 与 OpenSSL | PHP 版本、`openssl.cafile` 是否存在 | cafile 空 → TLS 无法校验证书 |
| 2. DNS 解析 | `spark-api.xf-yun.com` 的 A 记录 | 解析失败 → 网络/DNS 问题 |
| 3. TCP + TLS | TLS 版本、加密套件、服务器证书 | 连接失败 → 防火墙/代理/CA 问题 |
| 4. 鉴权 URL | 签名原文、signature、脱敏后的 authorization | `--show-auth` 可看完整值 |
| 5. WebSocket 握手 | 请求行/响应头/响应体、`Sec-WebSocket-Accept` 校验 | `HTTP/1.1 401` → 密钥不对 |
| 6. 流式响应 | 每一帧的 `code/status/delta` 摘要 | 业务错误码会给出中文解释 |
| 7. 结果 | 最终回答、token 用量、sid、耗时 | 空内容 → 版本/审核/domain 问题 |
| 8. 生产类复测 | 用 `app/Spark/SparkClient.php` 再调一次 | 确认网页端与自测行为一致 |

成功时最后会打印：

```
✓ 调用成功（总计 1234ms，其中 TLS 连接 120ms、握手 85ms）
答：……
token 用量：prompt=… completion=… total=…
sid：……，共收到 N 个数据帧
✓ 生产类调用成功……
全部检查通过。
```

### 退出码

| 退出码 | 含义 |
| --- | --- |
| 0 | 成功 |
| 2 | 用法错误 / 缺少密钥 |
| 3 | 网络或 TLS 失败 |
| 4 | WebSocket 握手失败（最常见是 401 密钥不对） |
| 5 | 业务错误码（如 10404、11200、10013） |
| 6 | 握手鉴权通过但没有任何文本内容 |

---

## 4. 常见失败怎么处理

| 现象 | 原因 | 处理 |
| --- | --- | --- |
| `HTTP/1.1 401 Unauthorized` | APPID/APIKey/APISecret 不匹配、复制不全、或本机时间偏差 > 300 秒 | 三个值重新从同一应用复制；校准系统时间 |
| `HTTP/1.1 404` | URL 写错 | v3.5 用 `wss://spark-api.xf-yun.com/v3.5/chat` |
| 业务码 `10404` | path 与 domain 不匹配 | v3.5 ↔ `generalv3.5`；脚本会自动提示配对关系 |
| 业务码 `11200` / `10016` | 该 APPID 未开通 Max 版本 / 额度不足 / 并发超限 | 到控制台开通或换版本（同时改 `url` 与 `domain`） |
| 业务码 `10013` / `10014` | 输入/输出内容审核不通过 | 换测试问题 |
| 连接超时 / 连不上 443 | 防火墙、代理、DNS | 检查网络；校园网/机房可能封外网 |
| `cafile 未配置` | `php.ini` 没配 CA | 设置 `openssl.cafile` 指向 `cacert.pem` 或 `curl-ca-bundle.crt`；仅排错可加 `--insecure` |

> `--insecure` 只跳过证书校验用于判断「是不是 CA 问题」，不要用于生产。

---

## 5. 自测通过后，让网页端真正用上星火

1. 新建 `app/config.local.php`（该文件已在 `.gitignore`，不会被提交）：

```php
<?php
return [
    'spark' => [
        'app_id'     => '你的APPID',
        'api_key'    => '你的APIKey',
        'api_secret' => '你的APISecret',
        'url'        => 'wss://spark-api.xf-yun.com/v3.5/chat',
        'domain'     => 'generalv3.5',
        'timeout'    => 30,
    ],
    'provider' => 'auto',   // auto：有密钥走星火、失败自动降级；spark：只用星火；mock：只用本地
];
```

2. 刷新网页，登录后进入「智能客服」：
   - 右上角引擎标签应显示 **「讯飞星火在线」**；
   - 页内「当前引擎」卡片显示 `provider = spark`；
   - 问一句「你好」，回答会来自真实大模型。
3. 想退回演示模式：删除或清空 `app/config.local.php` 中的 `spark` 三个值，刷新即可，页面会显示「本地演示引擎（未配置密钥）」。
4. 切换其他版本时，**`url` 与 `domain` 必须成对修改**（见下表）。

| 版本 | url | domain |
| --- | --- | --- |
| Spark Lite | `wss://spark-api.xf-yun.com/v1.1/chat` | `lite` |
| Spark Pro | `wss://spark-api.xf-yun.com/v3.1/chat` | `generalv3` |
| Spark Max（当前默认） | `wss://spark-api.xf-yun.com/v3.5/chat` | `generalv3.5` |
| Spark Max-32K | `wss://spark-api.xf-yun.com/chat/max-32k` | `max-32k` |
| Spark Ultra | `wss://spark-api.xf-yun.com/v4.0/chat` | `4.0Ultra` |

---

## 6. 安全注意事项

- 不要把真实密钥写进 `app/config.php`、README、截图或聊天记录；只写在 `app/config.local.php`（已忽略提交）。
- 脚本本身**不写入任何文件**，输出默认脱敏；`--show-auth` 才会回显完整 authorization，分享前请去掉。
- 密钥泄露后到讯飞控制台重置即可，旧密钥立即失效。
- 网页端调用失败会自动降级到本地演示引擎，并写入 `ai.spark.failed` 日志（后台「日志管理」可查），不会白屏或 500。

---

## 7. 本机环境实测结论（2026-10-27）

| 检查项 | 结果 |
| --- | --- |
| PHP | 8.0.30（XAMPP，curl/openssl/mbstring/json 已启用） |
| `openssl.cafile` | `C:\xampp\apache\bin\curl-ca-bundle.crt`（存在，215 KB） |
| DNS | 6 个 A 记录（123.57.x / 101.200.x / 8.140.x 等） |
| TCP 443 | 可连接（约 130ms） |
| TLS | TLSv1.3，证书 `*.xf-yun.com`（vTrus DV SSL CA G2，有效期至 2027-01-01） |
| 冒充密钥探测 | 服务端返回 `HTTP/1.1 401 Unauthorized` + `{"message":"HMAC signature cannot be verified: apikey not found"}` —— 说明鉴权参数格式被服务端正确解析，只差有效密钥 |
| 真实密钥调用 | 当前未配置密钥；按第 5 节填好后运行本脚本即可验证 |
