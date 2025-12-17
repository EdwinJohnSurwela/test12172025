@echo off
REM ============================================================
REM  OBS ESP32-CAM One-Click Setup
REM  - Starts Python QR Scanner API server
REM  - Configures OBS with ESP32-CAM browser source
REM  - Starts virtual camera automatically
REM ============================================================

setlocal EnableDelayedExpansion

echo ============================================================
echo   OBS ESP32-CAM One-Click Setup
echo ============================================================
echo.

REM --- CONFIGURATION ---
REM Change these to match your setup:
set ESP32_IP=http://192.168.89.203/
set OBS_PORT=4455
set OBS_PASSWORD=Library_Hub_Tambo_2025
set SCRIPT_DIR=%~dp0

REM --- Check if Python is available ---
where python >nul 2>&1
if errorlevel 1 (
    echo [ERROR] Python not found in PATH!
    echo Please install Python and add it to your PATH.
    pause
    exit /b 1
)

REM --- Install required packages ---
echo [1/4] Checking Python dependencies...
pip show obsws-python >nul 2>&1
if errorlevel 1 (
    echo      Installing obsws-python...
    pip install obsws-python --quiet
)

pip show requests >nul 2>&1
if errorlevel 1 (
    echo      Installing requests...
    pip install requests --quiet
)

pip show flask >nul 2>&1
if errorlevel 1 (
    echo      Installing flask...
    pip install flask flask-cors --quiet
)

echo      Dependencies OK!
echo.

REM --- Check if OBS is running ---
echo [2/4] Checking if OBS Studio is running...
tasklist /FI "IMAGENAME eq obs64.exe" 2>NUL | find /I "obs64.exe" >NUL
if errorlevel 1 (
    echo      OBS not running, attempting to start...
    
    REM Try common OBS installation paths - MUST start from OBS directory for locale files
    if exist "C:\Program Files\obs-studio\bin\64bit\obs64.exe" (
        pushd "C:\Program Files\obs-studio\bin\64bit"
        start "" "obs64.exe" --minimize-to-tray
        popd
        echo      Started OBS Studio. Waiting 5 seconds for it to initialize...
        timeout /t 5 /nobreak >nul
    ) else if exist "C:\Program Files (x86)\obs-studio\bin\64bit\obs64.exe" (
        pushd "C:\Program Files (x86)\obs-studio\bin\64bit"
        start "" "obs64.exe" --minimize-to-tray
        popd
        echo      Started OBS Studio. Waiting 5 seconds for it to initialize...
        timeout /t 5 /nobreak >nul
    ) else (
        echo [WARNING] Could not find OBS Studio installation.
        echo           Please start OBS manually before continuing.
        echo.
        pause
    )
) else (
    echo      OBS is already running!
)
echo.

REM --- Start Python QR Scanner API in background ---
echo [3/4] Starting Python QR Scanner API server...
cd /d "%SCRIPT_DIR%"

REM Check if qr_scanner_api.py exists
if exist "qr_scanner_api.py" (
    start "QR Scanner API" /min cmd /c "python qr_scanner_api.py"
    echo      QR Scanner API started in background
    timeout /t 2 /nobreak >nul
) else (
    echo      [INFO] qr_scanner_api.py not found, skipping...
)
echo.

REM --- Run OBS setup script ---
echo [4/4] Configuring OBS for ESP32-CAM...
REM --- Sanitize ESP32_IP: remove protocol and trailing slash ---
set "SANITIZED_ESP32=%ESP32_IP%"
set "SANITIZED_ESP32=%SANITIZED_ESP32:http://=%"
set "SANITIZED_ESP32=%SANITIZED_ESP32:https://=%"
if "%SANITIZED_ESP32:~-1%"=="/" set "SANITIZED_ESP32=%SANITIZED_ESP32:~0,-1%"
echo      ESP32-CAM IP: %SANITIZED_ESP32%
echo      OBS Port: %OBS_PORT%
echo.

if "%OBS_PASSWORD%"=="" (
    python obs_esp32cam_setup.py --esp32-ip %SANITIZED_ESP32% --obs-port %OBS_PORT%
) else (
    python obs_esp32cam_setup.py --esp32-ip %SANITIZED_ESP32% --obs-port %OBS_PORT% --obs-password %OBS_PASSWORD%
)

echo.
echo ============================================================
echo   Script finished. Check the output above for status.
echo ============================================================
pause
