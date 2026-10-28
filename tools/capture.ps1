$ErrorActionPreference = 'Stop'
$root = 'C:\Users\Administrator\Documents\deepseek-harness\default-workspace\smart-service'
$chrome = 'C:\Program Files\Google\Chrome\Application\chrome.exe'
$profile = Join-Path $root 'storage\.chrome-shot'
if (Test-Path $profile) { Remove-Item $profile -Recurse -Force -ErrorAction SilentlyContinue }

$args = @(
    '--headless=new',
    '--no-sandbox',
    '--disable-gpu',
    '--disable-crash-reporter',
    '--disable-breakpad',
    '--no-first-run',
    '--no-default-browser-check',
    "--user-data-dir=$profile",
    '--remote-debugging-port=9223',
    '--window-size=1440,900',
    'about:blank'
)
$proc = Start-Process -FilePath $chrome -ArgumentList $args -PassThru

$ready = $false
for ($i = 0; $i -lt 30; $i++) {
    Start-Sleep -Milliseconds 500
    try {
        $v = Invoke-RestMethod -Uri 'http://127.0.0.1:9223/json/version' -TimeoutSec 2
        if ($v.webSocketDebuggerUrl) { $ready = $true; break }
    } catch { }
}
if (-not $ready) {
    Write-Output 'CDP not ready'
    if (!$proc.HasExited) { taskkill /PID $proc.Id /T /F | Out-Null }
    exit 1
}
Write-Output "CDP ready: $($v.Browser)"

$node = 'C:\Users\Administrator\.dsh\dsh-runtimes\dsh-primary-runtime\dependencies\node\bin\node.exe'
& $node (Join-Path $root 'tools\shot.js') (Join-Path $root 'shots')
$code = $LASTEXITCODE

if (!$proc.HasExited) { taskkill /PID $proc.Id /T /F | Out-Null }
exit $code
