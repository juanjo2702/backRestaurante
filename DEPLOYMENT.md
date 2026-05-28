# 🚀 Guía de Despliegue - Backend (Laravel 12)

Esta guía detalla los pasos necesarios para desplegar y poner en producción el backend de la plataforma de gestión de restaurantes en Bolivia (`backRestaurante`).

---

## 📋 Requisitos del Sistema

Para un despliegue manual en producción (sin Docker), el servidor debe cumplir con:
*   **PHP >= 8.2** (Recomendado PHP 8.3)
*   **Extensiones PHP Obligatorias**: `openssl`, `pdo`, `mbstring`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`, `gd` (requerida por Bacon QR Code), `zip` (requerida por ZipStream).
*   **Gestor de Dependencias**: [Composer 2.x](https://getcomposer.org/)
*   **Base de Datos**: MySQL >= 8.0 o MariaDB >= 10.5
*   **Servidor Web**: Nginx (altamente recomendado) o Apache.

---

## 🛠️ Método 1: Despliegue Automatizado con Docker (Recomendado)

Si estás usando el entorno unificado con Docker Compose provisto en la raíz del proyecto, el despliegue es sumamente sencillo.

### Paso 1: Configurar Variables de Entorno
Asegúrate de que el archivo `backRestaurante/.env.docker` tenga las configuraciones correctas de producción/staging:

```env
APP_NAME="Restaurante API"
APP_ENV=production
APP_DEBUG=false
APP_URL=http://localhost:8000  # Cambiar por tu dominio real del backend

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=restaurante
DB_USERNAME=restaurant
DB_PASSWORD=restaurant  # Cambiar por una contraseña segura en producción
```

### Paso 2: Levantar Contenedores en Producción
Desde el directorio raíz del proyecto (`Restaurante`), ejecuta:

```bash
docker compose up -d --build
```
> [!NOTE]
> La opción `-d` levanta los contenedores en segundo plano (detached mode).

### Paso 3: Inicializar la Base de Datos dentro del Contenedor
Ejecuta la migración y la siembra inicial de datos:

```bash
docker compose exec app php artisan migrate --seed --force
```

> [!WARNING]
> El flag `--force` es obligatorio al ejecutar migraciones en entorno de producción (`APP_ENV=production`) para evitar confirmaciones interactivas.

---

## 🖥️ Método 2: Despliegue Manual en Servidor Linux (Ubuntu/Nginx)

Sigue estos pasos para desplegar de forma tradicional en un VPS o servidor dedicado.

### Paso 1: Clonar y Preparar Archivos
Sube los archivos al directorio del servidor (ej. `/var/www/restaurante-api`) y sitúate allí.

```bash
cd /var/www/restaurante-api
```

### Paso 2: Instalar Dependencias de Composer
Ejecuta la instalación optimizando el cargador automático para producción:

```bash
composer install --no-dev --optimize-autoloader
```

### Paso 3: Configurar Variables de Entorno
Copia la plantilla de entorno y genera la clave de la aplicación:

```bash
cp .env.example .env
php artisan key:generate --ansi
```

Edita el archivo `.env` con tu editor preferido (ej. `nano .env`) y configura:
```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.tudominio.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=nombre_base_datos
DB_USERNAME=usuario_base_datos
DB_PASSWORD=contraseña_segura

# Optimización de sesiones y caché para producción
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
```

> [!IMPORTANT]
> Si cambias `SESSION_DRIVER`, `CACHE_STORE` o `QUEUE_CONNECTION` a `database`, asegúrate de haber creado las tablas correspondientes ejecutando:
> `php artisan session:table`, `php artisan queue:table`, y luego `php artisan migrate`.

### Paso 4: Migraciones y Seeders
Migra y siembra la base de datos con los datos esenciales (roles, usuarios administradores, etc.):

```bash
php artisan migrate --seed --force
```

### Paso 5: Crear Enlace Simbólico de Almacenamiento
Necesario para que las imágenes de platos y códigos QR sean accesibles públicamente:

```bash
php artisan storage:link
```

### Paso 6: Configurar Permisos de Archivos
El servidor web (ej. `www-data` en Ubuntu) debe tener permisos de escritura sobre `storage` y `bootstrap/cache`:

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

### Paso 7: Optimizar Laravel para Producción
Ejecuta los siguientes comandos para compilar y cachear las configuraciones, rutas y vistas:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

---

## 🌐 Configuración del Servidor Web (Nginx)

Crea un archivo de configuración en `/etc/nginx/sites-available/restaurante-api` con el siguiente contenido adaptado:

```nginx
server {
    listen 80;
    server_name api.tudominio.com; # Tu dominio
    root /var/www/restaurante-api/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;

    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock; # Asegura la versión de tu PHP
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        
        # Configuración para SSE (Eventos en Tiempo Real)
        fastcgi_buffering off;
        fastcgi_read_timeout 600s;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Habilita el sitio y reinicia Nginx:
```bash
sudo ln -s /etc/nginx/sites-available/restaurante-api /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl restart nginx
```

---

## 🔄 Configuración de la Cola de Tareas (Queue Worker)

El backend procesa tareas en segundo plano. Configura un supervisor de procesos como **Supervisor** para mantener activo el comando `php artisan queue:work`.

Crear archivo `/etc/supervisor/conf.d/restaurante-worker.conf`:
```ini
[program:restaurante-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/restaurante-api/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/restaurante-api/storage/logs/worker.log
stopwaitsecs=3600
```

Activar Supervisor:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start restaurante-worker:*
```

---

## 🛡️ Verificación Post-Despliegue

Puedes verificar la salud del backend consumiendo el endpoint público de estado o iniciando sesión desde tu cliente API:
- `GET https://api.tudominio.com/api/public/tables` (debería retornar las mesas del restaurante en formato JSON).
