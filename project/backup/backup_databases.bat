@echo off
REM =====================================================
REM DATABASE BACKUP - Windows Task Scheduler entry point
REM ------------------------------------------------------
REM Set PHP_EXE below to match your XAMPP install if it isn't the
REM default path. This just calls backup_databases.php, which does
REM all the real work (see that file + README.md in this folder).
REM =====================================================

set PHP_EXE=C:\xampp\php\php.exe
set BACKUP_DIR=C:\xampp\private\siaa-backups

if not exist "%BACKUP_DIR%" mkdir "%BACKUP_DIR%"

if not exist "%PHP_EXE%" (
    echo ERROR: PHP not found at %PHP_EXE%
    echo Edit backup_databases.bat and set PHP_EXE to your XAMPP php.exe location.
    exit /b 1
)

"%PHP_EXE%" "%~dp0backup_databases.php" >> "%BACKUP_DIR%\backup_log.txt" 2>&1
