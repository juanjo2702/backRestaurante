@echo off
title Instalar Dependencias - Restaurante
chcp 65001 > nul

echo ===================================================
echo   Instalador de Dependencias (Backend y Frontend)
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

:: 2. Instalar dependencias del Backend (Laravel)
echo.
echo ---------------------------------------------------
echo 📦 Configurando Backend (Laravel)...
echo ---------------------------------------------------

:: Detectar Composer
set COMPOSER_CMD=composer
where composer >nul 2>nul
if %errorlevel% neq 0 (
    if exist "composer.phar" (
        echo [OK] Composer portátil (composer.phar) ya existe.
        set COMPOSER_CMD=%PHP_BIN% composer.phar
    ) else (
        echo [INFO] Composer global no detectado. Descargando composer.phar portátil...
        powershell -Command "[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12; Invoke-WebRequest -Uri 'https://getcomposer.org/download/latest-stable/composer.phar' -OutFile 'composer.phar'"
        if exist "composer.phar" (
            echo [OK] Composer portátil descargado con éxito.
            set COMPOSER_CMD=%PHP_BIN% composer.phar
        ) else (
            echo [ERROR] No se pudo descargar composer.phar. Instala Composer manualmente desde https://getcomposer.org/
            pause
            exit /b 1
        )
    )
) else (
    echo [OK] Composer global detectado.
)

echo.
echo Ejecutando: %COMPOSER_CMD% install
call %COMPOSER_CMD% install

:: 3. Instalar dependencias del Frontend (React + Vite)
echo.
echo ---------------------------------------------------
echo 🎨 Configurando Frontend (React + Vite)...
echo ---------------------------------------------------

set FRONT_DIR=
if exist "..\frontRestaurante" (
    set FRONT_DIR=..\frontRestaurante
) else if exist "..\frontrestaurante" (
    set FRONT_DIR=..\frontrestaurante
)

if "%FRONT_DIR%"=="" (
    echo [WARNING] No se encontró la carpeta del frontend (frontRestaurante) al lado de esta carpeta.
    echo Asegúrate de que ambas carpetas estén en el mismo directorio.
    echo Se omitirá la instalación de dependencias del frontend.
) else (
    echo [OK] Carpeta frontend encontrada en %FRONT_DIR%
    cd %FRONT_DIR%

    where npm >nul 2>nul
    if %errorlevel% neq 0 (
        echo [ERROR] No se encontró 'npm' (Node.js).
        echo Por favor, instala Node.js (versión recomendada LTS) desde https://nodejs.org/
    ) else (
        echo [OK] Node.js / NPM detectado.
        echo Ejecutando: npm install
        call npm install
    )
    cd ..\backRestaurante
)

echo.
echo ===================================================
echo ✅ ¡Instalación de dependencias completada!
echo ===================================================
echo.
pause
