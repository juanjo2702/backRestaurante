#!/bin/sh
set -e

cd /var/www/html

if [ ! -f vendor/autoload.php ]; then
  composer install --no-interaction --prefer-dist
fi

if [ -f .env.docker ] && [ ! -f .env ]; then
  cp .env.docker .env
fi

if [ -f .env ] && grep -q '^APP_KEY=$' .env; then
  php artisan key:generate --force
fi

mkdir -p \
  storage/framework/cache/data \
  storage/framework/sessions \
  storage/framework/views \
  storage/logs \
  bootstrap/cache

chmod -R 0777 storage bootstrap/cache || true

if [ ! -L public/storage ]; then
  php artisan storage:link || true
fi

php -r '
$driver = getenv("DB_CONNECTION") ?: "sqlite";
if ($driver === "sqlite") {
    exit(0);
}
$host = getenv("DB_HOST") ?: "mysql";
$port = getenv("DB_PORT") ?: "3306";
$database = getenv("DB_DATABASE") ?: "restaurante";
$username = getenv("DB_USERNAME") ?: "restaurant";
$password = getenv("DB_PASSWORD") ?: "restaurant";
for ($attempt = 1; $attempt <= 30; $attempt++) {
    try {
        new PDO("mysql:host={$host};port={$port};dbname={$database}", $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        exit(0);
    } catch (Throwable $exception) {
        fwrite(STDOUT, "Waiting for MySQL ({$attempt}/30)\n");
        sleep(2);
    }
}
fwrite(STDERR, "MySQL was not ready in time.\n");
exit(1);
'

php artisan migrate --force

php -r '
$driver = getenv("DB_CONNECTION") ?: "sqlite";
if ($driver !== "mysql") {
    exit(0);
}
$host = getenv("DB_HOST") ?: "mysql";
$port = getenv("DB_PORT") ?: "3306";
$database = getenv("DB_DATABASE") ?: "restaurante";
$username = getenv("DB_USERNAME") ?: "restaurant";
$password = getenv("DB_PASSWORD") ?: "restaurant";
$pdo = new PDO("mysql:host={$host};port={$port};dbname={$database}", $username, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$roles = (int) $pdo->query("select count(*) from roles")->fetchColumn();
$users = (int) $pdo->query("select count(*) from usuarios")->fetchColumn();
$tables = (int) $pdo->query("select count(*) from mesas")->fetchColumn();
if ($roles === 0 || $users === 0 || $tables === 0) {
    fwrite(STDOUT, "Demo seed required: roles={$roles}, users={$users}, tables={$tables}\n");
    exit(10);
}
'

if [ $? -eq 10 ]; then
  php artisan db:seed --force
fi

exec php-fpm
