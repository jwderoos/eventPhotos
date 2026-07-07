# OVHCloud VPS deployment (fresh install)

Fresh production deploy of EventPhotos on an OVHCloud VPS (2 vCPU / 4 GB / 40 GB),
directly internet-facing, HTTPS via a Caddy sidecar. Design:
`docs/superpowers/specs/2026-07-07-ovh-vps-deploy-design.md`.

## 0. Prerequisites
- A domain you control. Set an **A** record (and **AAAA** if the VPS has IPv6)
  pointing at the VPS public IP. Confirm it resolves before step 6 — Caddy's
  Let's Encrypt challenge needs it.
- SSH access to the VPS as root (OVHCloud emails initial credentials).

## 1. OS baseline (as root)
```bash
apt-get update && apt-get -y upgrade
timedatectl set-timezone Europe/Amsterdam   # adjust as desired
```

## 2. Non-root deploy user
```bash
adduser --disabled-password --gecos "" deploy
usermod -aG sudo deploy
install -d -m 700 /home/deploy/.ssh
# paste your public key:
vim /home/deploy/.ssh/authorized_keys
chown -R deploy:deploy /home/deploy/.ssh && chmod 600 /home/deploy/.ssh/authorized_keys
```

## 3. SSH hardening (as root)
Edit `/etc/ssh/sshd_config`: `PermitRootLogin no`, `PasswordAuthentication no`,
`PubkeyAuthentication yes`. Then:
```bash
systemctl restart ssh
```
Open a NEW terminal and confirm `ssh deploy@<vps-ip>` works BEFORE closing the
root session.

## 4. Firewall
```bash
apt-get install -y ufw
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable
ufw status
```

## 5. Docker Engine + Compose plugin (as deploy, via sudo)
```bash
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker deploy
# log out/in so group membership applies, then:
docker version && docker compose version   # Compose must be >= 2.24 for the overlay
```

## 6. App
```bash
sudo install -d -o deploy -g deploy /opt/eventphotos
cd /opt/eventphotos
git clone <repo-url> repo && cd repo
git checkout main

cp .env.vps.example .env.prod && chmod 600 .env.prod
# Fill in: DOMAIN, DEFAULT_URI (https://<DOMAIN>), APP_SECRET (openssl rand -hex 32),
# POSTGRES_PASSWORD + matching DATABASE_URL, MAILER_DSN.
vim .env.prod

# Data dir ownership so the containers can write:
#   uploads/share -> www-data (uid 33) inside the php/worker containers.
#   postgres      -> handled by the postgres entrypoint (starts as root, drops).
sudo install -d /opt/eventphotos/data/uploads /opt/eventphotos/data/share /opt/eventphotos/data/postgres
sudo chown -R 33:33 /opt/eventphotos/data/uploads /opt/eventphotos/data/share

# Deploy with the VPS overlay + single worker:
COMPOSE_FILES="compose.prod.yaml compose.vps.yaml" WORKER_SCALE=1 ./deploy.sh
```

## 7. First-run cert
Hit `https://<DOMAIN>` in a browser. Caddy provisions the Let's Encrypt cert on
first request (watch `docker compose ... logs -f caddy`). If it fails, the usual
cause is DNS not yet resolving or ports 80/443 blocked — re-check steps 0 and 4.

## 8. Bootstrap admin
```bash
COMPOSE="docker compose -f compose.prod.yaml -f compose.vps.yaml --env-file .env.prod"
$COMPOSE exec php php bin/console app:create-user admin@example.com "Admin" '<password>' ROLE_ADMIN
```

## 9. Verify (end-to-end)
- `docker compose -f compose.prod.yaml -f compose.vps.yaml --env-file .env.prod ps`
  → all services up; `migrate` exited 0; exactly one `worker`.
- `https://<DOMAIN>` loads with a valid cert (padlock).
- Log in as the admin; create an event; upload a JPEG.
- `... logs -f worker` shows the photo processed to Ready; the thumb/preview
  serve at `/p/<id>/thumb.jpg`.
- `docker stats` under a small upload burst stays within caps (php ≤ 1 GB,
  database ≤ 1 GB, worker ≤ 384 MB).

## Redeploys
```bash
cd /opt/eventphotos/repo
COMPOSE_FILES="compose.prod.yaml compose.vps.yaml" WORKER_SCALE=1 ./deploy.sh
```

## Not yet configured (follow-ups)
- **Backups** — no `pg_dump`/uploads backup is set up yet (separate issue).
  Whole state = the Postgres DB + `/opt/eventphotos/data`.
- **Object storage** — derivatives live on the 40 GB disk (~100k+ photos of
  headroom); revisit only when disk pressure appears.
