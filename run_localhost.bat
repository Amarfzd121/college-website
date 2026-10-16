@echo off
title SMS College Website - Localhost Launcher
color 0A
echo ============================================================
echo   School of Management Sciences (SMS) - Localhost Launcher
echo ============================================================
echo.

:: Check and start MySQL
tasklist /FI "IMAGENAME eq mysqld.exe" 2>NUL | find /I /N "mysqld.exe">NUL
if "%ERRORLEVEL%"=="0" (
    echo [OK] MySQL is already running.
) else (
    echo [*] Starting MySQL server...
    start "" /B "C:\xampp\mysql\bin\mysqld.exe" --defaults-file="C:\xampp\mysql\bin\my.ini" --standalone
    timeout /t 2 /nobreak >nul
)

:: Check and start Apache
tasklist /FI "IMAGENAME eq httpd.exe" 2>NUL | find /I /N "httpd.exe">NUL
if "%ERRORLEVEL%"=="0" (
    echo [OK] Apache is already running.
) else (
    echo [*] Starting Apache web server...
    start "" /B "C:\xampp\apache\bin\httpd.exe"
    timeout /t 2 /nobreak >nul
)

echo.
echo ============================================================
echo   Website is live!
echo   Main Portal  : http://localhost/college_website/
echo   Attendance   : http://localhost/college_website/attendance.php
echo   Fee Payment  : http://localhost/college_website/payment.php
echo   Admin Panel  : http://localhost/college_website/admin/login.php
echo   Database GUI : http://localhost/phpmyadmin/
echo ============================================================
echo.
echo Opening browser...
start http://localhost/college_website/
pause
