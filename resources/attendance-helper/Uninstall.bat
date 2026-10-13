@echo off
rem Removes the Nepsus attendance helper from this PC (run as administrator).
schtasks /Delete /TN "Nepsus Attendance Sync" /F
rmdir /S /Q "%ProgramData%\NepsusAttendance"
echo The attendance helper has been removed.
pause
