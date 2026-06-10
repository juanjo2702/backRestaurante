# 🚀 Guía de Despliegue con XAMPP y Terminal (Windows)

Esta guía explica detalladamente cómo configurar y ejecutar el proyecto localmente en Windows utilizando **XAMPP** para la base de datos MySQL y la **Terminal de VS Code** (a la antigua) para ejecutar los servidores.

Para hacerte la vida más fácil, hemos creado tres scripts automatizados (`.bat`) **dentro de la carpeta `backRestaurante`** que realizan casi todo el trabajo por ti. También se detallan los comandos manuales si prefieres hacerlo paso a paso.

---

## 📋 Requisitos Previos

Antes de comenzar, asegúrate de tener instalado en tu computadora:

1.  **XAMPP**: Descárgalo de [apachefriends.org](https://www.apachefriends.org/). Debe tener **PHP >= 8.2** (Recomendado 8.2 u 8.3).
2.  **Node.js**: Descarga la versión LTS desde [nodejs.org](https://nodejs.org/).
3.  **Visual Studio Code**: O tu editor de código preferido.

---

## 🗄️ Paso 1: Configurar la Base de Datos en XAMPP

1.  Abre el **Panel de Control de XAMPP** en Windows.
2.  Inicia los módulos de **Apache** y **MySQL** haciendo clic en sus respectivos botones **"Start"**.
3.  Abre tu navegador de preferencia e ingresa a: **[http://localhost/phpmyadmin](http://localhost/phpmyadmin)**.
4.  Haz clic en **"Nueva"** (menú izquierdo) para crear una base de datos.
5.  Escribe el nombre: `restaurante` y selecciona el cotejamiento por defecto (`utf8mb4_general_ci`).
6.  Haz clic en el botón **"Crear"**.

---

## ⚡ Método A: Despliegue Rápido Automatizado (Recomendado)

Abre la carpeta **`backRestaurante`**. Encontrarás 3 scripts de Windows (`.bat`) que puedes ejecutar haciendo doble clic en ellos en el siguiente orden:

### 1. Instalar Dependencias
Haz doble clic sobre **`1_instalar_dependencias.bat`**.
*   **¿Qué hace?**: Detectará PHP en tu sistema (o en XAMPP), descargará de forma automática Composer portátil (`composer.phar`) si no lo tienes instalado de forma global, e instalará todas las dependencias PHP en `backRestaurante` y los módulos de Node en `frontRestaurante`.

### 2. Configurar Archivos e Iniciar Base de Datos
Haz doble clic sobre **`2_configurar_proyecto.bat`**.
*   **¿Qué hace?**: Creará el archivo `.env` del backend, generará la clave de seguridad de Laravel, creará el `.env` del frontend y te preguntará si ya creaste la base de datos `restaurante` en phpMyAdmin. Al presionar **S**, creará automáticamente todas las tablas del sistema e inyectará los datos de prueba iniciales (seeders).

### 3. Iniciar el Proyecto
Haz doble clic sobre **`3_iniciar_proyecto.bat`**.
*   **¿Qué hace?**: Abrirá de forma automática dos ventanas de comandos:
    *   Una ejecutando el Backend Laravel (`http://127.0.0.1:8000`).
    *   Otra ejecutando el Frontend React/Vite (`http://localhost:5173`).
*   ¡Listo! Ya puedes ingresar a `http://localhost:5173` en tu navegador para usar la aplicación.

---

## 💻 Método B: Despliegue Manual Paso a Paso (A la Antigua)

Si prefieres ejecutar los comandos tú mismo en la terminal de Visual Studio Code:

### 1. Configurar el Backend (Laravel)
Abre una terminal en VS Code y navega al directorio del backend:
```bash
cd backRestaurante
```

1.  **Instalar dependencias de PHP**:
    *   Si tienes **Composer instalado globalmente**:
        ```bash
        composer install
        ```
    *   Si **NO tienes Composer instalado**:
        Descarga el archivo portátil `composer.phar` ejecutando este comando en PowerShell:
        ```powershell
        powershell -Command "[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12; Invoke-WebRequest -Uri 'https://getcomposer.org/download/latest-stable/composer.phar' -OutFile 'composer.phar'"
        ```
        Y luego ejecuta la instalación usando PHP:
        ```bash
        php composer.phar install
        ```

2.  **Configurar Variables de Entorno**:
    Copia el archivo de plantilla a un archivo `.env`:
    ```bash
    copy .env.example .env
    ```
    *Asegúrate de que las variables de base de datos apunten a XAMPP en `.env`:*
    ```env
    DB_CONNECTION=mysql
    DB_HOST=127.0.0.1
    DB_PORT=3306
    DB_DATABASE=restaurante
    DB_USERNAME=root
    DB_PASSWORD=
    ```

3.  **Generar la clave de la aplicación**:
    ```bash
    php artisan key:generate
    ```

4.  **Ejecutar Migraciones y Datos Semilla**:
    ```bash
    php artisan migrate --seed
    ```

5.  **Iniciar el Servidor Backend**:
    ```bash
    php artisan serve
    ```
    *(El backend quedará ejecutándose en `http://127.0.0.1:8000`)*.

---

### 2. Configurar el Frontend (React + Vite)
Abre otra pestaña o ventana de la terminal en VS Code y sitúate en el directorio del frontend:
```bash
cd frontRestaurante
```

1.  **Instalar dependencias de Node.js**:
    ```bash
    npm install
    ```

2.  **Configurar archivo .env**:
    Crea un archivo llamado `.env` en la raíz de `frontRestaurante/` y agrégale la siguiente línea:
    ```env
    VITE_API_URL=http://localhost:8000
    ```

3.  **Iniciar el Servidor Frontend**:
    ```bash
    npm run dev
    ```
    *(El frontend se iniciará en `http://localhost:5173` o en el puerto libre que te indique la terminal)*.

---

## 🛠️ Resolución de Problemas Comunes

### ❌ Error: 'composer' no se reconoce como un comando interno o externo
*   **Causa**: Composer no está instalado en tu computadora o no está agregado al PATH de Windows.
*   **Solución**: Utiliza nuestro script **`1_instalar_dependencias.bat`**, el cual descarga y usa automáticamente una versión portátil de Composer (`composer.phar`) sin requerir instalación global.

### ❌ Error: 'php' no se reconoce como un comando interno o externo
*   **Causa**: Windows no sabe dónde está ubicado el ejecutable de PHP.
*   **Solución**:
    1.  Presiona la tecla `Windows` y busca **"Variables de entorno"**.
    2.  Haz clic en **"Variables de entorno..."** (abajo a la derecha).
    3.  En "Variables del sistema", busca la variable llamada **`Path`** y haz doble clic en ella.
    4.  Haz clic en **"Nuevo"** y escribe la ruta de PHP en XAMPP: `C:\xampp\php`.
    5.  Haz clic en **Aceptar** en todas las ventanas.
    6.  **Cierra y vuelve a abrir Visual Studio Code** para que tome los cambios.
    *(Nota: Nuestros scripts `.bat` buscan automáticamente `C:\xampp\php\php.exe` por defecto, por lo que si usas los archivos `.bat` no es obligatorio realizar este paso).*

### ❌ Error: Conexión rechazada a la Base de Datos (SQLSTATE[HY000] [2002])
*   **Causa**: El servidor MySQL en XAMPP no está encendido.
*   **Solución**: Abre el Panel de Control de XAMPP y asegúrate de que el módulo **MySQL** tenga el fondo en color verde y diga **"Start"** (o esté corriendo).
