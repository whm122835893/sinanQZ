@echo off
setlocal
rem stop sinan local stack (h5 5173 / admin 5174 / backend 8080 / mysql 3308)
set "PATH=%SystemRoot%\System32;%PATH%"
set "MYSQLADMIN=E:\mysql8-portable\mysql-8.0.28-winx64\bin\mysqladmin.exe"

for %%P in (5173 5174 8080) do call :killport %%P

echo MySQL @3308 graceful shutdown...
"%MYSQLADMIN%" -u root --skip-password -h 127.0.0.1 -P 3308 shutdown 2>nul
if errorlevel 1 call :killport 3308
echo all stopped. close any leftover sinan-* console windows.
timeout /t 3 >nul
exit /b 0

:killport
for /f "tokens=5" %%I in ('netstat -ano ^| findstr /c:":%~1 " ^| findstr /c:"LISTENING"') do (
  echo kill port %~1 pid %%I
  taskkill /f /t /pid %%I >nul 2>nul
)
exit /b 0
