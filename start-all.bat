@echo off
setlocal EnableExtensions EnableDelayedExpansion
rem ============================================================
rem  sinan local stack launcher (MySQL 3308 / backend 8080 / admin 5174 / h5 5173)
rem  safe to re-run: services already listening are skipped
rem  fail fast: prints "done" only when all four ports really listen
rem  ports are hardcoded on purpose, no auto port picking
rem ============================================================
set "SYSDIR=%SystemRoot%\System32"
rem System32 first: netstat/findstr/taskkill/timeout must resolve to the real ones even when
rem the PATH inherited from the launching shell is polluted by unrelated bin dirs.
set "PATH=%SYSDIR%;%PATH%"
set "MYSQLD=E:\mysql8-portable\mysql-8.0.28-winx64\bin\mysqld.exe"
set "INI=E:\mysql8-portable\my-portable.ini"
set "BINDIR=E:\mysql8-portable\mysql-8.0.28-winx64\bin"
set "BACKEND=E:\sinan\sinan-nft-backend"
set "PORTS=3308 8080 5174 5173"
set "LASTPIDF=E:\sinan\.lastpids"
set "STARTLOG=E:\sinan\.startup.log"

rem ---------- [0/4] preflight: is anything already on our ports ----------
echo [0/4] preflight port scan
for %%P in (%PORTS%) do (
  call :pidof %%P
  if "!PIDH!"=="-" (echo   port %%P free) else (echo   port %%P LISTENING by pid !PIDH!)
)
call :pidof 8080
if "!PIDH!"=="-" goto :pre_ok
call :own8080 !PIDH!
if defined OURS8080 (
    echo   that 8080 is this project's own backend, docroot match ok - will skip it
    goto :pre_ok
)
echo.
echo FATAL: 8080 is held by a process that is NOT this repo's backend.
echo   its command line:
echo     !CMD8080!
echo   verify it yourself:
echo     powershell -NoProfile -Command "Get-CimInstance Win32_Process -Filter 'ProcessId=!PIDH!' | Select ProcessId,CommandLine"
echo   if the -t docroot is not E:\sinan\sinan-nft-backend\public, an older copy of this
echo   project is serving stale code. Either free 8080, or run this stack on 8095:
echo     cd /d %BACKEND% ^&^& set "PATH=%BINDIR%;%%PATH%%" ^&^& php think run --host 127.0.0.1 --port 8095
echo   and then point both Vite proxies at 8095 (sinan-admin and sinan-art-source vite.config.js).
call :audit "aborted: 8080 owned by a foreign backend"
pause
exit /b 1
:pre_ok

echo [1/4] MySQL @3308
call :running 3308
if "%errorlevel%"=="0" (echo   already running, skip) else (
  start "sinan-mysql-3308" cmd /k ""%MYSQLD%" --defaults-file="%INI%" --console"
  call :waitport 3308 60
  if errorlevel 1 (
    echo   FATAL: MySQL @3308 did not come up in 60s, it is NOT listening:
    call :evidence
    call :audit "aborted: mysql 3308 not listening"
    pause
    exit /b 1
  )
)

echo [2/4] Backend @8080
rem 127.0.0.1 and --port are both spelled out: "php think run -p 8080" is easy to misread as host+port
call :running 8080
if "%errorlevel%"=="0" (echo   already running, skip) else (
  start "sinan-backend-8080" cmd /k "cd /d %BACKEND% && set "PATH=%BINDIR%;%PATH%" && php think run --host 127.0.0.1 --port 8080"
  call :waitport 8080 30
  if errorlevel 1 (
    echo   FATAL: backend @8080 did not come up in 30s. evidence:
    call :evidence
    echo   read the sinan-backend-8080 window for the real php error.
    call :audit "aborted: backend 8080 not listening"
    pause
    exit /b 1
  )
)

echo [3/4] Admin UI @5174
call :running 5174
if "%errorlevel%"=="0" (echo   already running, skip) else (
  start "sinan-admin-5174" cmd /k "cd /d E:\sinan\sinan-admin && npm run dev"
)

echo [4/4] H5 UI @5173
call :running 5173
if "%errorlevel%"=="0" (echo   already running, skip) else (
  start "sinan-h5-5173" cmd /k "cd /d E:\sinan\sinan-art-source && npm run dev"
)

rem vite cold start is slow; give both up to 60s
call :waitport 5174 60
call :waitport 5173 60

rem ---------- final check: re-measure, never trust the echo above ----------
set "MISSING="
for %%P in (%PORTS%) do (
  call :pidof %%P
  if "!PIDH!"=="-" set "MISSING=!MISSING! %%P"
)
call :audit ""
if defined MISSING (
  echo.
  echo FAILED: not listening after launch:%MISSING%
  call :evidence
  echo   open the matching sinan-* window, it holds the real error.
  echo   ports are fixed to %PORTS%, this script will not pick another one.
  pause
  exit /b 1
)
echo.
echo   all four ports confirmed LISTENING, see %LASTPIDF% for pid mapping
echo   admin : http://localhost:5174  admin / admin123
echo   h5    : http://localhost:5173
start "" http://localhost:5174
start "" http://localhost:5173
echo   done. keep the 4 sinan-* windows open while using the stack.
"%SYSDIR%\timeout.exe" /t 5 >nul
exit /b 0

rem ---------- helpers ----------
:pidof
set "PIDH=-"
for /f "tokens=5" %%I in ('%SYSDIR%\netstat.exe -ano ^| %SYSDIR%\findstr.exe /c:":%~1 " ^| %SYSDIR%\findstr.exe /c:"LISTENING"') do set "PIDH=%%I"
exit /b 0

:own8080
rem usage: call :own8080 [pid]  -+ sets OURS8080=1 only if that pid serves E:\sinan\sinan-nft-backend\public
set "OURS8080="
set "CMD8080="
for /f "usebackq delims=" %%I in (`powershell -NoProfile -Command "(Get-CimInstance Win32_Process -Filter 'ProcessId=%~1').CommandLine"`) do set "CMD8080=%%I"
if not defined CMD8080 exit /b 0
set "TMP8080=!CMD8080:E:\sinan\sinan-nft-backend\public=!"
if not "!TMP8080!"=="!CMD8080!" set "OURS8080=1"
exit /b 0

:evidence
rem dumps what our four ports and every php process actually look like right now
echo   --- netstat lines for our ports ---
for %%P in (%PORTS%) do %SYSDIR%\netstat.exe -ano | %SYSDIR%\findstr.exe /c:":%%P " | %SYSDIR%\findstr.exe /c:"LISTENING"
echo   --- php processes, read the -t docroot ---
powershell -NoProfile -Command "Get-CimInstance Win32_Process | Where-Object { $_.Name -like '*php*' } | ForEach-Object { 'pid=' + $_.ProcessId + '  ' + $_.CommandLine }"
exit /b 0

:running
%SYSDIR%\netstat.exe -ano | %SYSDIR%\findstr.exe /c:":%~1 " | %SYSDIR%\findstr.exe /c:"LISTENING" >nul
exit /b %errorlevel%

:audit
rem usage: call :audit [abort-reason]   - writes .lastpids, appends one line to .startup.log
set "LINE=[%date% %time%]"
if not "%~1"=="" set "LINE=!LINE! ABORT %~1 |"
for %%P in (%PORTS%) do (
  call :pidof %%P
  set "LINE=!LINE! %%P=!PIDH!"
)
>> "%STARTLOG%" echo !LINE!
> "%LASTPIDF%" echo # sinan stack port to pid, written by start-all.bat
>> "%LASTPIDF%" echo !LINE!
for %%P in (%PORTS%) do (
  call :pidof %%P
  >> "%LASTPIDF%" echo %%P=!PIDH!
)
exit /b 0

:waitport
set /a _n=0
:wp_loop
call :running %~1
if "%errorlevel%"=="0" exit /b 0
set /a _n+=1
if %_n% geq %~2 exit /b 1
"%SYSDIR%\timeout.exe" /t 1 /nobreak >nul
goto wp_loop
