@echo off
chcp 65001 >nul
cd /d %~dp0

rem 优先用 PATH 里的 php；没有就退回 phpStudy 自带的那一个（按你的实际路径改）
set "PHP=php"
where php >nul 2>nul
if errorlevel 1 set "PHP=E:\phpstudy_pro\Extensions\php\php7.3.4nts\php.exe"

if not exist "src\config.local.php" (
  echo [缺少配置] 请先复制 src\config.example.php 为 src\config.local.php 并填入 MySQL 口令
  pause
  exit /b 1
)

rem 安装脚本是幂等的：库和表已存在就跳过，管理员已存在也不会覆盖密码，
rem 所以每次启动顺手跑一遍，能自动补上缺失的表。
"%PHP%" database\install.php

echo.
echo 后端已启动：http://localhost:8000   （按 Ctrl+C 停止）
"%PHP%" -S localhost:8000 -t public public\index.php
