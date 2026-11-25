#!/usr/bin/env bash
# Build and (re)start Subnet Watch on the server. Run as the deploy user from the app folder after every git pull:
#   cd /var/www/sn-dashboard && git pull && bash deploy/update.sh
set -euo pipefail
cd "$(dirname "$0")/.."

grep -q '^APP_KEY=base64:' .env || php artisan key:generate --force

composer install --no-dev --optimize-autoloader --no-interaction
npm ci --no-audit --no-fund
npm run build

php artisan migrate --force
php artisan dashboard:admin
php artisan optimize:clear
php artisan config:cache
php artisan view:cache

sudo /bin/systemctl reload php8.4-fpm
for u in sn-dashboard-queue-default sn-dashboard-queue-profile sn-dashboard-queue-sn34 sn-dashboard-schedule; do
    sudo /bin/systemctl restart "$u"
done
echo "updated: $(git log -1 --format='%h %s')"
