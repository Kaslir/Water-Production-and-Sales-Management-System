@echo off
setlocal
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0Start-AquaTrack.ps1"
if errorlevel 1 (
    echo.
    echo Press any key to close this window.
    pause >nul
)
