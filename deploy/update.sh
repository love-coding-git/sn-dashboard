#!/usr/bin/env bash
# Build and (re)start Subnet Watch on the server. Run as the deploy user from the app folder after every git pull:
#   cd /var/www/sn-dashboard && git pull && bash deploy/update.sh
set -euo pipefail
cd "$(dirname "$0")/.."

composer install --no-dev --optimize-autoloader --no-interaction
grep -q '^APP_KEY=base64:' .env || php artisan key:generate --force
npm ci --no-audit --no-fund
npm run build

php artisan migrate --force
# the login account from .env (skipped with a warning until DASHBOARD_ADMIN_EMAIL / _PASSWORD are filled in)
php artisan dashboard:admin || echo 'warning: login account not written - fill DASHBOARD_ADMIN_* in .env and run php artisan dashboard:admin'
php artisan optimize:clear
php artisan config:cache
php artisan view:cache

sudo /bin/systemctl reload php8.4-fpm
for u in sn-dashboard-queue-default sn-dashboard-queue-profile sn-dashboard-queue-sn34 sn-dashboard-schedule; do
    sudo /bin/systemctl restart "$u"
done
echo "updated: $(git log -1 --format='%h %s')"
