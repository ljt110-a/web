@echo off
chcp 65001 >nul
rem ============================================================
rem  web-one one-click launcher
rem
rem  Double-click this file, and it will:
rem    check env - upgrade database - pick a free port -
rem    start the server - wait until ready - open the browser
rem
rem  This file is intentionally ASCII-only, not a single Chinese character:
rem  cmd.exe reads a batch file byte by byte using the CURRENT code page,
rem  so any multibyte text can make it mis-read the following lines and
rem  run garbage -- switching to 65001 does not fix that either.
rem  All Chinese messages and every decision live in tools\launch.php
rem  (UTF-8, and it can be run and verified on its own).
rem ============================================================
setlocal
cd /d "%~dp0"

rem Prefer php from PATH; fall back to phpStudy's copy if not found.
rem Change the fallback path below if yours is somewhere else.
set "PHP=php"
where php >nul 2>nul
if errorlevel 1 set "PHP=E:\phpstudy_pro\Extensions\php\php7.3.4nts\php.exe"

"%PHP%" tools\launch.php
if errorlevel 1 pause
exit /b 0
