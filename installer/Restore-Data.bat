@echo off
rem Pulihkan data dari file .sql (hasil export phpMyAdmin / backup).
rem Cara pakai: tarik file .sql ke ikon file ini, atau klik dua kali lalu ketik lokasi file.
set "F=%~1"
if "%F%"=="" set /p F=Lokasi file .sql: 
powershell -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "%~dp0launcher.ps1" -Restore "%F%"
