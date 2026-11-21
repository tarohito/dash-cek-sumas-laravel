#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

export PORT="${PORT:-10000}"
export APP_ENV="${APP_ENV:-production}"
export APP_DEBUG="${APP_DEBUG:-false}"
export LOG_CHANNEL="${LOG_CHANNEL:-stderr}"
export SESSION_DRIVER="${SESSION_DRIVER:-file}"
export CACHE_STORE="${CACHE_STORE:-file}"
export QUEUE_CONNECTION="${QUEUE_CONNECTION:-sync}"

# Auto-use platform external HTTPS URL if APP_URL is not set yet (Render / Railway)
if [ -z "${APP_URL:-}" ]; then
    if [ -n "${RENDER_EXTERNAL_URL:-}" ]; then
        export APP_URL="${RENDER_EXTERNAL_URL}"
    elif [ -n "${RAILWAY_PUBLIC_DOMAIN:-}" ]; then
        export APP_URL="https://${RAILWAY_PUBLIC_DOMAIN}"
    fi
fi

# Bind Apache to platform dynamic $PORT and ensure ONLY mpm_prefork is active
echo "Listen ${PORT}" > /etc/apache2/ports.conf
rm -f /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf
a2enmod mpm_prefork >/dev/null 2>&1 || true

# Ensure runtime directories exist and are writable by Apache
mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

# Auto-detect DB_CONNECTION if not explicitly provided
if [ -z "${DB_CONNECTION:-}" ]; then
    RAW_DB_URL="${DB_URL:-${DATABASE_URL:-${MYSQL_URL:-}}}"
    if [[ "${RAW_DB_URL}" == postgres* ]]; then
        export DB_CONNECTION="pgsql"
    elif [[ "${RAW_DB_URL}" == mysql* ]] || [[ "${RAW_DB_URL}" == mariadb* ]]; then
        export DB_CONNECTION="mysql"
    elif [ -n "${DB_HOST:-}" ] && [ "${DB_HOST}" != "127.0.0.1" ]; then
        export DB_CONNECTION="mysql"
    else
        export DB_CONNECTION="sqlite"
    fi
fi

if [ "${DB_CONNECTION}" = "sqlite" ]; then
    DB_FILE="${DB_DATABASE:-/var/www/html/database/database.sqlite}"
    export DB_DATABASE="${DB_FILE}"
    mkdir -p "$(dirname "${DB_FILE}")"
    touch "${DB_FILE}"
    chown -R www-data:www-data "$(dirname "${DB_FILE}")"
    chmod -R ug+rwX "$(dirname "${DB_FILE}")"
fi

# Provide a fallback APP_KEY if not configured yet so the app can boot cleanly
if [ -z "${APP_KEY:-}" ]; then
    echo "[Entrypoint] WARNING: APP_KEY is not set. Generating an ephemeral key for this boot. Set APP_KEY in Environment Variables for persistent sessions."
    export APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
fi

chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache

# Clear stale caches, migrate database, and seed initial Sumas data if empty
php artisan config:clear --no-interaction || true
php artisan migrate --force --no-interaction

AUTO_SEED="${AUTO_SEED_IF_EMPTY:-true}"
if [ "${RUN_SEEDER:-false}" = "true" ]; then
    echo "[Entrypoint] RUN_SEEDER=true -> running SumasSeeder..."
    php artisan db:seed --class=SumasSeeder --force --no-interaction
elif [ "${AUTO_SEED}" = "true" ]; then
    USER_COUNT="$(php -r '
        require "vendor/autoload.php";
        $app = require "bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        try {
            echo Illuminate\Support\Facades\DB::table("users")->count();
        } catch (Throwable $e) {
            echo "-1";
        }
    ' 2>/dev/null || echo "-1")"
    if [ "${USER_COUNT}" = "0" ]; then
        echo "[Entrypoint] Empty users table detected -> running SumasSeeder..."
        php artisan db:seed --class=SumasSeeder --force --no-interaction
    fi
fi

# Cache configuration, routes, and views for production performance
php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
php artisan view:cache --no-interaction

chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache
if [ "${DB_CONNECTION}" = "sqlite" ]; then
    chown -R www-data:www-data "$(dirname "${DB_DATABASE}")"
    chmod -R ug+rwX "$(dirname "${DB_DATABASE}")"
fi

echo "[Entrypoint] Starting Apache on port ${PORT} (DB_CONNECTION=${DB_CONNECTION}, SESSION_DRIVER=${SESSION_DRIVER}, APP_URL=${APP_URL:-unset})..."
exec apache2-foreground
