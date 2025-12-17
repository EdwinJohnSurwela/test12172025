@echo off
title Library Hub QR Scanner API
color 0A

echo ========================================
echo   Library Hub QR Scanner API Server
echo ========================================
echo.

cd /d "%~dp0"

echo Checking Python...
python --version
if errorlevel 1 (
    echo ERROR: Python not found!
    echo Install from https://www.python.org/downloads/
    pause
    exit /b 1
)

echo.
echo Installing dependencies...

python -m pip install --upgrade pip

echo Installing Flask...
pip install flask flask-cors

echo Installing Pillow...
pip install Pillow

echo Installing opencv-python (pre-built wheel)...
pip install --only-binary :all: opencv-python

echo.
echo ========================================
echo   OBS VIRTUAL CAMERA SETUP
echo ========================================
echo.
echo To use OBS Virtual Camera:
echo 1. Open OBS Studio
echo 2. Set up your scene (add QR code image/video)
echo 3. Click "Start Virtual Camera" in OBS
echo 4. The camera will appear in the list below
echo.
echo ========================================
echo   Starting Server...
echo   URL: http://localhost:5000
echo   
echo   Keep this window OPEN!
echo   Press Ctrl+C to stop.
echo ========================================
echo.

python qr_scanner_api.py

echo.
echo Server stopped.
pause