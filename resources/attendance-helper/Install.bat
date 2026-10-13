@echo off
rem Sets up the Nepsus attendance helper on this PC (asks for administrator rights).
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0setup.ps1"
