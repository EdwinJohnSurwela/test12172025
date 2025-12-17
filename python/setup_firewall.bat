@echo off
echo Adding Windows Firewall rule for Python QR Scanner API...

REM Run as administrator
net session >nul 2>&1
if %errorLevel% neq 0 (
    echo This script requires Administrator privileges.
    echo Right-click and select "Run as administrator"
    pause
    exit /b 1
)

REM Add firewall rule for Python on port 5000
netsh advfirewall firewall add rule name="Python QR Scanner API" dir=in action=allow protocol=TCP localport=5000

echo.
echo Firewall rule added successfully!
echo Other computers can now access: http://YOUR_IP:5000
echo.
pause
