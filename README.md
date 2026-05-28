# backRestaurante

Backend Laravel del ecosistema de gestion de restaurante Bolivia-ready.

## Stack

- Laravel 12
- Laravel Sanctum
- MySQL o SQLite para desarrollo
- Dompdf para exportacion PDF
- Bacon QR Code para QR SVG
- ZipStream para exportaciones masivas

## Contrato API

- Canonico: `/api/v1/*`
- Compatibilidad temporal: varias rutas legacy en `/api/*`
- Rutas publicas: `/api/public/*`

El frontend debe consumir rutas protegidas desde `/api/v1` y reservar `/api/public` solo para el flujo cliente por QR.

## Arranque local recomendado

1. Crear entorno:

```powershell
copy .env.example .env
php artisan key:generate
```

2. Configurar base de datos en `.env`.

3. Migrar y sembrar:

```powershell
php artisan migrate:fresh --seed
```

4. Levantar el backend:

```powershell
php artisan serve
```

## Defaults defensivos para local

El proyecto usa estos defaults en `.env.example` para evitar errores de bootstrap en equipos nuevos:

- `SESSION_DRIVER=file`
- `CACHE_STORE=file`
- `QUEUE_CONNECTION=sync`

Si se cambia a `database`, tambien deben crearse y migrarse las tablas correspondientes para cache, sesiones o colas.

## Verificacion

```powershell
php artisan test
```

## Hardening actual

- Login versionado en `/api/v1/auth/login`
- Rate limiting en login, sesiones publicas y mock checkout
- Reportes y analitica preparados para MySQL
- OpenAPI versionado en `openapi/openapi.yaml`
