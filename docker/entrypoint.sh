#!/bin/bash
set -e

echo "Waiting for PostgreSQL to be ready..."
until php -r "new PDO('pgsql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_DATABASE', '$DB_USERNAME', '$DB_PASSWORD');" 2>/dev/null; do
  sleep 1
done
echo "PostgreSQL is ready."

# Run pending migrations (safe to run on every startup — already-run ones are skipped)
php artisan migrate --force

# Seed only on first boot (when the users table is empty)
USER_COUNT=$(php artisan tinker --execute="echo \App\Models\User::count();" 2>/dev/null | tail -1)
if [ "$USER_COUNT" = "0" ]; then
  echo "Seeding database..."
  php artisan db:seed --force
fi

# Clear config cache so env variables are always fresh
php artisan config:clear

echo "Starting Laravel development server on port 8000..."
exec php artisan serve --host=0.0.0.0 --port=8000
