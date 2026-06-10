@echo off
title Iniciar Proyecto - Restaurante
chcp 65001 > nul

echo ===================================================
echo     Iniciando Servidores (Backend y Frontend)
echo ===================================================
echo.

:: 1. Detectar PHP en múltiples discos y rutas comunes
set PHP_BIN=
where php >nul 2>nul
if %errorlevel% equ 0 (
    set PHP_BIN=php
) else (
    for %%d in (C D E F G) do (
        if exist "%%d:\xampp\php\php.exe" (
            set PHP_BIN="%%d:\xampp\php\php.exe"
            goto php_found
        )
        if exist "%%d:\laragon\bin\php" (
            for /f "delims=" %%f in ('dir /b /s "%%d:\laragon\bin\php\php.exe" 2^>nul') do (
                set PHP_BIN="%%f"
                goto php_found
            )
        )
    )
    
    echo [ERROR] No se encontró PHP. Instala XAMPP antes de continuar.
    pause
    exit /b 1
)

:php_found
echo [OK] PHP detectado: %PHP_BIN%
echo.

:: 2. Levantar el Backend
echo Iniciando backend (Laravel) en una nueva ventana...
start "Restaurante - API Backend" cmd /c "title Restaurante - API Backend && %PHP_BIN% artisan serve"

:: 3. Levantar el Frontend
set FRONT_DIR=
if exist "..\frontRestaurante" (
    set FRONT_DIR=..\frontRestaurante
) else if exist "..\frontrestaurante" (
    set FRONT_DIR=..\frontrestaurante
)

if "%FRONT_DIR%"=="" (
    echo [WARNING] No se encontró la carpeta del frontend (frontRestaurante) para iniciar el servidor.
    echo Asegúrate de iniciar el frontend manualmente.
) else (
    echo Iniciando frontend (Vite/React) en una nueva ventana...
    start "Restaurante - Web Frontend" cmd /c "title Restaurante - Web Frontend && cd %FRONT_DIR% && npm run dev"
)

echo.
echo ===================================================
echo 🚀 ¡Servidores en ejecución!
echo ===================================================
echo.
echo 👉 API Backend: http://127.0.0.1:8000
echo 👉 Frontend Web: http://localhost:5173 (o el que indique la consola)
echo.
echo Puedes cerrar esta ventana. Deja abiertas las otras dos.
echo ===================================================
echo.
pause
