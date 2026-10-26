@echo off
setlocal
for /d %%D in ("%~dp0..\storage\tooling\node\node-v*-win-x64") do set "GROCERY_NODE=%%~fD"
if not defined GROCERY_NODE (
  echo Install Node.js 24 LTS or the documented workspace-local runtime.
  exit /b 1
)
set "PATH=%GROCERY_NODE%;%PATH%"
set "npm_config_cache=%~dp0..\storage\tooling\npm-cache"
call "%GROCERY_NODE%\npm.cmd" %*
