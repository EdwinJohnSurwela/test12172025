@echo off
:: Library Hub QR Scanner - Background Launcher
:: This script starts the scanner minimized in the system tray

cd /d "%~dp0"

:: Check if Python is available
python --version >nul 2>&1
if errorlevel 1 (
    echo ERROR: Python not found!
    echo Please install Python from https://www.python.org/downloads/
    pause
    exit /b 1
)

:: Install pystray if needed (silently)
pip show pystray >nul 2>&1
if errorlevel 1 (
    echo Installing system tray dependencies...
    pip install pystray Pillow --quiet
)

:: Check if scanner is already running on port 5000
netstat -ano | findstr ":5000" | findstr "LISTENING" >nul 2>&1
if not errorlevel 1 (
    echo Scanner is already running.
    echo.
    echo The scanner will continue running in the background.
    timeout /t 2 >nul
    exit /b 0
)

:: Start the tray application (pythonw runs without console window)
start "" pythonw "%~dp0scanner_tray.pyw"

echo.
echo ========================================
echo   Library Hub QR Scanner Started!
echo ========================================
echo.
echo The scanner is now running in your system tray.
echo Look for the icon in the notification area
echo (near the clock, you may need to click the ^ arrow).
echo.
echo Right-click the tray icon for options:
echo   - View status
echo   - Start/Stop scanner
echo   - Open Library Hub
echo   - Exit
echo.
echo This window will close in 5 seconds...
timeout /t 5 >nul
