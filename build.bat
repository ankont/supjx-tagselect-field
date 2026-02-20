@echo off
setlocal

set "ROOT=%~dp0"
set "SRC=%ROOT%src"
set "BUILD=%ROOT%build"
set "ZIP=%BUILD%\plg_fields_tagselect.zip"

if not exist "%SRC%\tagselect.xml" (
    echo [ERROR] Could not find %SRC%\tagselect.xml
    exit /b 1
)

if not exist "%BUILD%" mkdir "%BUILD%"

if exist "%ZIP%" del /f /q "%ZIP%"

tar -a -c -f "%ZIP%" -C "%SRC%" .
if errorlevel 1 (
    echo [ERROR] Build failed.
    exit /b 1
)

powershell -NoProfile -ExecutionPolicy Bypass -Command "$ErrorActionPreference = 'Stop'; Add-Type -AssemblyName System.IO.Compression.FileSystem; $zip = [System.IO.Compression.ZipFile]::OpenRead('%ZIP%'); $hasManifest = $false; foreach ($entry in $zip.Entries) { if ($entry.FullName -eq 'tagselect.xml' -or $entry.FullName -eq './tagselect.xml') { $hasManifest = $true; break } }; $zip.Dispose(); if (-not $hasManifest) { throw 'tagselect.xml was not found at zip root.' }"
if errorlevel 1 (
    echo [ERROR] Build verification failed.
    exit /b 1
)

echo [OK] Created %ZIP%
echo [OK] Manifest found at zip root: tagselect.xml
exit /b 0
