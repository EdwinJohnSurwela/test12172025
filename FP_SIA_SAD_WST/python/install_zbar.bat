@echo off
echo Downloading zbar dependencies...
echo.

REM Download zbar binaries for Windows
curl -L "https://github.com/NaturalHistoryMuseum/pyzbar/releases/download/v0.1.9/zbar-0.1.9-win64.zip" -o zbar.zip

echo Extracting files...
tar -xf zbar.zip -C "%LOCALAPPDATA%\Python\pythoncore-3.14-64\Lib\site-packages\pyzbar\"

echo Cleaning up...
del zbar.zip

echo.
echo zbar dependencies installed!
echo Now run start_scanner.bat
pause
