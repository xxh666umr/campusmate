@echo off
chcp 65001 >nul
setlocal EnableExtensions
cd /d "%~dp0"

set "PHP=C:\xampp\php\php.exe"
if not exist "%PHP%" set "PHP=php"
set "MYSQLD=C:\xampp\mysql\bin\mysqld.exe"
set "MYINI=C:\xampp\mysql\bin\my.ini"
set "PORT=8899"

echo ============================================
echo   智服 CampusMate - 校园智能客服
echo   地址: http://127.0.0.1:%PORT%
echo   账号: admin / admin123
echo   停止: Ctrl + C  （MySQL 若由本脚本启动，会另开最小化窗口）
echo ============================================

rem ---------- 0. 端口已被占用则直接开浏览器 ----------
netstat -ano | findstr /C:"LISTENING" | findstr /C:":%PORT%" >nul
if not errorlevel 1 (
    echo [提示] 端口 %PORT% 已在运行，直接打开浏览器 ...
    start "" "http://127.0.0.1:%PORT%/login"
    goto :eof
)

rem ---------- 1. 判断数据库类型 ----------
set "DBDRIVER=sqlite"
"%PHP%" tools\db_driver.php > "%TEMP%\smart_service_driver.txt" 2>nul
if exist "%TEMP%\smart_service_driver.txt" (
    set /p DBDRIVER=<"%TEMP%\smart_service_driver.txt"
    del "%TEMP%\smart_service_driver.txt" >nul 2>nul
)
if /i "%DBDRIVER%"=="mysql" (
    echo [1/3] 数据库：MySQL（检查 3306 端口）
    call :ensure_mysql
) else (
    echo [1/3] 数据库：SQLite（零配置，无需额外启动）
)

rem ---------- 2. 延时打开浏览器 ----------
start "" powershell -NoProfile -WindowStyle Hidden -Command "Start-Sleep -Seconds 2; Start-Process 'http://127.0.0.1:%PORT%/login'"
echo [2/3] 2 秒后自动打开浏览器；若没有打开，请手动访问 http://127.0.0.1:%PORT%

rem ---------- 3. 启动 PHP 内置服务器 ----------
echo [3/3] 启动 PHP 服务器（%PHP%）...
echo.
"%PHP%" -S 127.0.0.1:%PORT% -t public public/index.php
goto :eof

rem ================= 子过程：确保 MySQL 已启动 =================
:ensure_mysql
netstat -ano | findstr /C:"LISTENING" | findstr /C:":3306" >nul
if not errorlevel 1 (
    echo        端口 3306 已在监听，跳过启动
    goto :eof
)
if not exist "%MYSQLD%" (
    echo        [警告] 未找到 %MYSQLD%
    echo        请用 XAMPP Control Panel 手动启动 MySQL 后刷新页面
    goto :eof
)
if not exist "%MYINI%" (
    echo        [警告] 未找到 %MYINI%
    echo        请用 XAMPP Control Panel 手动启动 MySQL 后刷新页面
    goto :eof
)

echo        正在启动 MySQL（最小化窗口）...
start "MySQL - 智服" /min "%MYSQLD%" --defaults-file="%MYINI%" --standalone
set /a _wait=0
:wait_mysql
ping -n 2 127.0.0.1 >nul
set /a _wait+=1
netstat -ano | findstr /C:"LISTENING" | findstr /C:":3306" >nul
if not errorlevel 1 (
    echo        MySQL 已就绪（等待 %_wait% 秒）
    goto :eof
)
if %_wait% lss 20 goto :wait_mysql
echo        [警告] MySQL 20 秒内未就绪，页面会给出中文提示
echo        也可打开 XAMPP Control Panel 点 MySQL 的 Start
goto :eof
