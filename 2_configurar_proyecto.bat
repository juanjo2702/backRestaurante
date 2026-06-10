@echo off
title Configurar Proyecto y Base de Datos - Restaurante
chcp 65001 > nul

echo ===================================================
echo     Configurador del Proyecto y Base de Datos
echo ===================================================
echo.

:: 1. Detectar PHP
set PHP_BIN=php
where php >nul 2>nul
if %errorlevel% neq 0 (
    if exist "C:\xampp\php\php.exe" (
        set PHP_BIN="C:\xampp\php\php.exe"
        echo [OK] PHP detectado en XAMPP: C:\xampp\php\php.exe
    ) else (
        echo [ERROR] No se encontró PHP.
        echo Asegúrate de tener XAMPP instalado en la ruta por defecto o PHP en el PATH de Windows.
        echo.
        pause
        exit /b 1
    )
) else (
    echo [OK] PHP detectado globalmente en el sistema.
)

:: 2. Configurar el Backend (Laravel)
echo.
echo ---------------------------------------------------
echo ⚙️ Configurando archivo .env y Key de Laravel...
echo ---------------------------------------------------

if not exist ".env" (
    echo [INFO] Creando archivo .env desde la plantilla (.env.example)...
    copy .env.example .env > nul
) else (
    echo [OK] El archivo .env del backend ya existe.
)

echo.
echo Generando clave de aplicación de Laravel...
call %PHP_BIN% artisan key:generate

:: 3. Configurar el Frontend .env
set FRONT_DIR=
if exist "..\frontRestaurante" (
    set FRONT_DIR=..\frontRestaurante
) else if exist "..\frontrestaurante" (
    set FRONT_DIR=..\frontrestaurante
)

if not "%FRONT_DIR%"=="" (
    if not exist "%FRONT_DIR%\.env" (
        echo [INFO] Configurando archivo .env por defecto en el frontend...
        echo VITE_API_URL=http://localhost:8000> "%FRONT_DIR%\.env"
    ) else (
        echo [OK] El archivo .env del frontend ya existe.
    )
)

:: 4. Base de Datos
echo.
echo ---------------------------------------------------
echo 🗄️ Configuración de Base de Datos (MySQL - XAMPP)
echo ---------------------------------------------------
echo.
echo ¡IMPORTANTE! Antes de continuar:
echo 1. Abre el Panel de Control de XAMPP y presiona "Start" en Apache y MySQL.
echo 2. Entra a tu navegador a: http://localhost/phpmyadmin/
echo 3. Crea una base de datos con el nombre: restaurante
echo.

set /p confirmar="¿Ya iniciaste MySQL y creaste la base de datos 'restaurante'? (S/N): "

if /i "%confirmar%"=="S" (
    echo.
    echo Ejecutando migraciones y cargando datos de prueba (Seeders)...
    call %PHP_BIN% artisan migrate --seed
    echo.
    echo [OK] Base de datos configurada correctamente.
) else (
    echo.
    echo [INFO] Se omitió la migración. Crea la base de datos en phpMyAdmin
    echo y luego vuelve a ejecutar este script para inicializar las tablas.
)

echo.
echo ===================================================
echo ✅ ¡Configuración inicial completada con éxito!
echo ===================================================
echo.
pause
