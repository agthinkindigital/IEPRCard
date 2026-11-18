@echo off
setlocal
chcp 65001 >nul
set "MODE=manual"
set "IDLE_MINUTES=15"

if /I "%~1"=="agent" (
    set "MODE=agent"
    if not "%~2"=="" set "IDLE_MINUTES=%~2"
) else if /I not "%~1"=="" if /I not "%~1"=="manual" (
    echo Uso: localhost.cmd [manual^|agent [minutos]]
    exit /b 2
)

powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\localhost-server.ps1" -Mode "%MODE%" -IdleTimeoutMinutes %IDLE_MINUTES%
exit /b %ERRORLEVEL%
