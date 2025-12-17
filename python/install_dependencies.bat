@echo off
title Installing QR Scanner Dependencies
color 0E

echo ========================================
echo   Installing QR Scanner Dependencies
echo ========================================
echo.

cd /d "%~dp0"

echo Checking Python version...
python --version
if errorlevel 1 (
    echo ERROR: Python not found!
    echo Install from https://www.python.org/
    pause
    exit /b 1
)

echo.
echo Upgrading pip...
python -m pip install --upgrade pip

echo.
echo Installing Flask (web server)...
pip install flask flask-cors

echo.
echo Installing Pillow (image processing)...
pip install Pillow

echo.
echo Installing pyzbar (QR code decoder)...
pip install pyzbar

echo.
echo ========================================
echo   IMPORTANT: pyzbar needs zbar DLL
echo ========================================
echo.
echo If pyzbar doesn't work, you need to install
echo the zbar library for Windows:
echo.
echo Option 1: Download from
echo   https://sourceforge.net/projects/zbar/files/zbar/0.10/zbar-0.10-setup.exe/download
echo.
echo Option 2: If you have Visual Studio, try:
echo   pip install pyzbar[scripts]
echo.
echo ========================================
echo   Installation Complete!
echo ========================================
echo.
echo Now run: start_scanner.bat
echo.
pause