@echo off
chcp 65001 >nul
rem ============================================================
rem  web-one stop server
rem
rem  Double-click to stop the server started by start.bat.
rem
rem  Logic lives in tools\stop.php -- see start.bat for why this
rem  file is ASCII-only. That script only terminates php.exe and
rem  refuses to touch a port that belongs to some other program.
rem ============================================================
setlocal
cd /d "%~dp0"

set "PHP=php"
where php >nul 2>nul
if errorlevel 1 set "PHP=E:\phpstudy_pro\Extensions\php\php7.3.4nts\php.exe"

"%PHP%" tools\stop.php
pause
exit /b 0
