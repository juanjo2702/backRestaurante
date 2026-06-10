@echo off
title Instalar Dependencias - Restaurante
chcp 65001 > nul

echo ===================================================
echo   Instalador de Dependencias (Backend y Frontend)
echo ===================================================
echo.

:: 1. Detectar PHP en múltiples discos y rutas comunes
set PHP_BIN=
where php >nul 2>nul
if %errorlevel% equ 0 (
    set PHP_BIN=php
    echo [OK] PHP detectado en el PATH del sistema.
) else (
    echo [INFO] Buscando PHP en directorios comunes...
    for %%d in (C D E F G) do (
        if exist "%%d:\xampp\php\php.exe" (
            set PHP_BIN="%%d:\xampp\php\php.exe"
            echo [OK] PHP detectado en XAMPP: %%d:\xampp\php\php.exe
            goto php_found
        )
        if exist "%%d:\laragon\bin\php" (
            for /f "delims=" %%f in ('dir /b /s "%%d:\laragon\bin\php\php.exe" 2^>nul') do (
                set PHP_BIN="%%f"
                echo [OK] PHP detectado en Laragon: %%f
                goto php_found
            )
        )
    )
    
    echo [ERROR] No se encontró PHP instalado en tu computadora.
    echo Para poder ejecutar este proyecto es OBLIGATORIO tener PHP/XAMPP.
    echo.
    echo Por favor:
    echo 1. Instala XAMPP (con PHP 8.2 o superior) desde:
    echo    https://www.apachefriends.org/es/index.html
    echo 2. Si ya lo instalaste en una ruta personalizada, agrega la carpeta de PHP
    echo    (por ejemplo, C:\xampp\php) a las Variables de Entorno (PATH) de Windows.
    echo.
    pause
    exit /b 1
)

:php_found
echo.

:: 2. Instalar dependencias del Backend (Laravel)
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
