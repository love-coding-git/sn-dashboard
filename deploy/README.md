# Deploying Subnet Watch to dashboard.reservakit.com

The dashboard runs on its own on the reservakit server (`142.248.82.243`, Ubuntu 24.04, nginx, PHP 8.4-FPM, MySQL 8,
Node 22, certbot - all already there for reservakit.com). It reads nothing outside its folder: its own `.env`, its own
database, its own API calls (thesoma.ai, Taostats, Yugos). Every page needs the app's login.

| What | Where |
|---|---|
| App (git clone) | `/var/www/sn-dashboard`, owned by `deploy:www-data` |
| Web | nginx site `sn-dashboard` -> `public/`, PHP 8.4-FPM |
| Database | MySQL `sn_dashboard` (user `sn_dashboard`, password generated into `.env`) |
| Workers | systemd `sn-dashboard-queue-default`, `-queue-profile`, `-queue-sn34`, `-schedule` (restart on failure and after reboot) |
| Login | `.env` `DASHBOARD_ADMIN_EMAIL` / `DASHBOARD_ADMIN_PASSWORD` -> `php artisan dashboard:admin` |

## One-time

1. **DNS** (Namecheap): an `A` record `dashboard` -> `142.248.82.243`.
2. **Repo access for the server**: the `deploy` user gets its own key for this repo (GitHub deploy keys are one per repo):
   ```bash
   sudo -u deploy ssh-keygen -t ed25519 -f /home/deploy/.ssh/sn_dashboard -N '' -C sn-dashboard-deploy
   sudo -u deploy tee -a /home/deploy/.ssh/config <<'CFG'
   Host github-sn-dashboard
       HostName github.com
       User git
       IdentityFile ~/.ssh/sn_dashboard
       IdentitiesOnly yes
   CFG
   cat /home/deploy/.ssh/sn_dashboard.pub   # -> GitHub repo -> Settings -> Deploy keys (read-only)
   ```
3. **Clone** (as deploy): `git clone git@github-sn-dashboard:love-coding-git/sn-dashboard.git /var/www/sn-dashboard`
4. **Provision** (as root): `bash /var/www/sn-dashboard/deploy/provision.sh` - database and user, nginx site, systemd units, sudo rights.
5. **Data**: on the machine that has the history, `bash deploy/export-db.sh`, copy `storage/app/sn_dashboard.sql.gz` to the
   server, then `gunzip -c sn_dashboard.sql.gz | mysql sn_dashboard` (as root).
6. **Settings**: in `/var/www/sn-dashboard/.env` fill `TAO_API_KEY`, `DASHBOARD_ADMIN_EMAIL`, `DASHBOARD_ADMIN_PASSWORD` (12+ characters).
7. **Build and start** (as deploy): `cd /var/www/sn-dashboard && bash deploy/update.sh`
8. **TLS** (as root, after DNS resolves): `certbot --nginx -d dashboard.reservakit.com`

## Automatic deploys (GitHub Actions)

`.github/workflows/deploy.yml`: every push to `main` runs the tests and the build, then logs in as the server's `deploy` user
with a key that can only deploy - its `authorized_keys` entry forces
`cd /var/www/sn-dashboard && git pull --ff-only -q && bash deploy/update.sh` - and finally checks `https://dashboard.reservakit.com/up`.
Repo secrets: `SERVER_IP` = `142.248.82.243`, `SSH_PRIVATE_KEY` = the private key whose public half is that entry
(`sn-dashboard-github-actions`). Run it by hand from Actions -> Deploy -> Run workflow.

After certbot, the live nginx site holds the TLS blocks: do not copy `deploy/nginx/…conf` over it again (or rerun certbot).

## Updating by hand

```bash
sudo -u deploy bash -c 'cd /var/www/sn-dashboard && git pull && bash deploy/update.sh'
```

## Notes

- Two copies polling at once (this server and a local copy) share the Taostats rate limit when they use the same key: stop
  the local watcher (`bin/soma-watch stop`) or give each copy its own key.
- The server works alone: no other machine sends it anything. The SN34 Watch log is written from its own reads; the
  Discord tab is the Yugos channel that `SyncDiscord` reads every minute with `DISCORD_USER_TOKEN` from the server's
  `.env` (a user token: reading a user account by script is against Discord's terms, so it only reads, at most one call
  every 1.2 s). Without that token the Discord tab stays as it is.
