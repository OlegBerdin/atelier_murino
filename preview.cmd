@echo off
cd /d "%~dp0"

set "PHP=php"
where php >nul 2>nul
if errorlevel 1 (
  for /f "usebackq delims=" %%P in (`powershell -NoProfile -Command "$p = Get-ChildItem $env:LOCALAPPDATA\Microsoft\WinGet\Packages -Filter php.exe -Recurse -ErrorAction SilentlyContinue | Select-Object -First 1 -ExpandProperty FullName; if ($p) { $p }"`) do set "PHP=%%P"
)

"%PHP%" -v >nul 2>nul
if errorlevel 1 (
  echo.
  echo   PHP не найден.
  echo   Установите PHP 8 и запустите файл заново:
  echo     winget install PHP.PHP.NTS.8.3
  echo.
  pause
  exit /b 1
)

echo.
echo   Сайт:    http://localhost:8080/
echo   Админка: http://localhost:8080/admin/
echo.
echo   Важно: не открывайте index.html двойным кликом.
echo   При открытии файла напрямую браузер не даёт загрузить цены.
echo.
echo   Остановить сервер: Ctrl+C
echo.

start "" http://localhost:8080/
"%PHP%" -S localhost:8080 -t .
pause
