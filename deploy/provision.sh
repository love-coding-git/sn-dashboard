#!/usr/bin/env bash
# One-time server setup for Subnet Watch (dashboard.reservakit.com) on the reservakit server. Run as root:
#   bash deploy/provision.sh            (from a clone at /var/www/sn-dashboard)
# It creates the MySQL database and user, the nginx site, the systemd units and the deploy user's sudo rights for them.
# It never prints the database password: it writes it into /var/www/sn-dashboard/.env (created from the example if missing).
set -euo pipefail

APP=/var/www/sn-dashboard
DB=sn_dashboard
DBUSER=sn_dashboard
DEPLOY=deploy

[ "$(id -u)" = 0 ] || { echo "run as root"; exit 1; }
[ -f "$APP/artisan" ] || { echo "clone the repo to $APP first"; exit 1; }

echo "[1/5] MySQL database and user"
if [ ! -f "$APP/.env" ]; then
    cp "$APP/deploy/env.production.example" "$APP/.env"
    chown "$DEPLOY":www-data "$APP/.env"; chmod 640 "$APP/.env"
fi
if ! grep -q '^DB_PASSWORD=.\+' "$APP/.env"; then
    PW=$(openssl rand -base64 24 | tr -d '/+=' | cut -c1-28)
    sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=$PW|" "$APP/.env"
fi
PW=$(grep '^DB_PASSWORD=' "$APP/.env" | cut -d= -f2-)
mysql -e "CREATE DATABASE IF NOT EXISTS $DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER IF NOT EXISTS '$DBUSER'@'localhost' IDENTIFIED BY '$PW'; ALTER USER '$DBUSER'@'localhost' IDENTIFIED BY '$PW';"
mysql -e "GRANT ALL PRIVILEGES ON $DB.* TO '$DBUSER'@'localhost'; FLUSH PRIVILEGES;"
unset PW

echo "[2/5] files and permissions"
chown -R "$DEPLOY":www-data "$APP"
chmod -R ug+rwX "$APP/storage" "$APP/bootstrap/cache"

echo "[3/5] nginx site"
cp "$APP/deploy/nginx/dashboard.reservakit.com.conf" /etc/nginx/sites-available/sn-dashboard
ln -sf /etc/nginx/sites-available/sn-dashboard /etc/nginx/sites-enabled/sn-dashboard
nginx -t && systemctl reload nginx

echo "[4/5] systemd units (enabled; started by deploy/update.sh after the first build)"
cp "$APP"/deploy/systemd/sn-dashboard-*.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable sn-dashboard-queue-default sn-dashboard-queue-profile sn-dashboard-queue-sn34 sn-dashboard-schedule

echo "[5/5] sudo rights for the deploy user (restart the units, reload php-fpm)"
cat > /etc/sudoers.d/sn-dashboard <<SUDO
$DEPLOY ALL=(ALL) NOPASSWD: /bin/systemctl restart sn-dashboard-queue-default, /bin/systemctl restart sn-dashboard-queue-profile, /bin/systemctl restart sn-dashboard-queue-sn34, /bin/systemctl restart sn-dashboard-schedule, /bin/systemctl reload php8.4-fpm
SUDO
chmod 440 /etc/sudoers.d/sn-dashboard
visudo -cf /etc/sudoers.d/sn-dashboard

echo "done. Next: fill TAO_API_KEY and DASHBOARD_ADMIN_* in $APP/.env, import the database dump, run deploy/update.sh as $DEPLOY,"
echo "      then certbot --nginx -d dashboard.reservakit.com once the DNS record points here."
