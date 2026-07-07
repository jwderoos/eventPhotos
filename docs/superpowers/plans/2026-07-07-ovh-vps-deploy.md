# OVHCloud VPS Deployment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deploy EventPhotos as a fresh, HTTPS-served production instance on an OVHCloud VPS (2 vCPU / 4 GB / 40 GB) by layering a VPS-specific overlay + a Caddy TLS terminator on top of the existing portable prod stack.

**Architecture:** Keep `compose.prod.yaml` (TrueNAS-tuned) as the untouched base. A new `compose.vps.yaml` overlay adds a Caddy sidecar (auto Let's Encrypt), removes nginx's published host port (Caddy reaches it in-network), retunes every service's resource caps for the 4 GB box, and bind-mounts a smaller PHP-FPM pool. A runbook documents host bring-up.

**Tech Stack:** Docker Engine + Compose v2, Caddy 2 (ACME/Let's Encrypt), nginx, php-fpm 8.5, PostgreSQL 16, Symfony Messenger.

## Global Constraints

- **This is infrastructure, not application code** — there are no phpunit tests. Each task's "verification" is a concrete command (config render, file grep, shellcheck) with expected output, or a runbook step executed on the VPS. This is the infra analog of a test; treat a failing verification exactly like a failing test.
- **Commits are the user's to make.** Do NOT run `git commit`. Stage changes if helpful and end each task by proposing a one-line commit message. (Repo gate: branch must match `^(feature|hotfix|bugfix|release)/\d+-`; commit message must contain the GitHub issue number.)
- **Branch:** this work must live on its own branch `feature/100-ovh-vps-deploy` off `main`, NOT on the current `feature/93-event-banner-hero`. Create the issue + branch before task 1.
- **Base compose file `compose.prod.yaml` stays untouched** — all VPS changes are additive (overlay + new files + `deploy.sh` parameterization that preserves current defaults).
- **Compose overlay requires Docker Compose ≥ 2.24** for the `!override` tag.
- Persistent data root on the VPS: `DATA_DIR=/opt/eventphotos/data`.
- Container UIDs for data ownership: uploads/share `33:33` (www-data), postgres data dir handled by the postgres entrypoint.
- Retuned RAM budget (sum ≈ 2.6 GB, leaving headroom for OS + dockerd): database 1 GB, php 1 GB, worker 384 MB ×1, nginx 128 MB, caddy 64 MB.

---

## File structure

- **Create** `docker/php/fpm-pool.vps.conf` — smaller FPM pool, bind-mounted over the baked-in one.
- **Create** `docker/caddy/Caddyfile` — TLS terminator + reverse proxy to `nginx:80`.
- **Create** `compose.vps.yaml` — overlay: caddy service, nginx port removal, resource caps, postgres tuning, php pool mount.
- **Create** `.env.vps.example` — VPS-flavored env template.
- **Create** `docs/setup/ovh-vps-deploy.md` — host bring-up + deploy runbook.
- **Modify** `deploy.sh` — parameterize compose file list + worker scale via env, preserving current TrueNAS defaults.

---

### Task 1: VPS PHP-FPM pool file

**Files:**
- Create: `docker/php/fpm-pool.vps.conf`

**Interfaces:**
- Produces: a php-fpm pool config mounted by `compose.vps.yaml` (Task 3) at `/usr/local/etc/php-fpm.d/zz-www.conf`, overriding the image's baked-in `pm = static / max_children = 24`.

- [ ] **Step 1: Write the pool file**

```ini
; VPS PHP-FPM pool — see docs/superpowers/specs/2026-07-07-ovh-vps-deploy-design.md
; The baked-in prod pool (docker/php/fpm-pool.conf) uses pm=static, max_children=24,
; sized for the TrueNAS box (~2 GB resident). On the 2 vCPU / 4 GB VPS the php
; mem_limit is 1 GB, so 24 static children would OOM. compose.vps.yaml bind-mounts
; this file over zz-www.conf. dynamic keeps idle memory low; 6 × ~80 MB ≈ 480 MB.
[www]
user = www-data
group = www-data
listen = 9000

pm = dynamic
pm.max_children = 6
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
; Recycle children to bound memory growth from leaks.
pm.max_requests = 1000
```

- [ ] **Step 2: Verify the file is valid FPM syntax**

Run: `test -f docker/php/fpm-pool.vps.conf && grep -q 'pm.max_children = 6' docker/php/fpm-pool.vps.conf && echo OK`
Expected: `OK`
(Full syntax validation happens when the container starts in Task 6 / runbook — php-fpm `-t` needs the runtime image.)

- [ ] **Step 3: Stage + propose commit**

Run: `git add docker/php/fpm-pool.vps.conf`
Proposed message: `100 - add VPS-sized php-fpm pool (dynamic, max_children 6)`

---

### Task 2: Caddyfile

**Files:**
- Create: `docker/caddy/Caddyfile`

**Interfaces:**
- Consumes: `{$DOMAIN}` env var (provided to the caddy container via `env_file: .env.prod` in Task 3).
- Produces: HTTPS termination on 80/443, reverse-proxying to `nginx:80` over the compose network.

- [ ] **Step 1: Write the Caddyfile**

```caddyfile
# EventPhotos VPS TLS terminator. {$DOMAIN} comes from .env.prod (env_file on the
# caddy service). Caddy auto-provisions + renews the Let's Encrypt cert and
# redirects HTTP→HTTPS. Upload size / body limits stay in the app nginx
# (client_max_body_size 32M) — Caddy does not re-cap. Certs persist in the
# caddy_data named volume so restarts don't re-hit ACME rate limits.
{$DOMAIN} {
	encode zstd gzip
	reverse_proxy nginx:80
}
```

- [ ] **Step 2: Verify placeholder + upstream are correct**

Run: `grep -q 'reverse_proxy nginx:80' docker/caddy/Caddyfile && grep -q '{$DOMAIN}' docker/caddy/Caddyfile && echo OK`
Expected: `OK`
(Caddy validates the file itself on container start in the runbook; `caddy validate` needs `$DOMAIN` set.)

- [ ] **Step 3: Stage + propose commit**

Run: `git add docker/caddy/Caddyfile`
Proposed message: `100 - add Caddy reverse-proxy config for VPS TLS`

---

### Task 3: Compose overlay `compose.vps.yaml`

**Files:**
- Create: `compose.vps.yaml`
- Reference (unchanged): `compose.prod.yaml`

**Interfaces:**
- Consumes: base services `php`, `worker`, `nginx`, `database` from `compose.prod.yaml`; the pool file from Task 1; the Caddyfile from Task 2; env vars `DATA_DIR`, `DOMAIN` from `.env.prod` (Task 4).
- Produces: a merged config launched via `docker compose -f compose.prod.yaml -f compose.vps.yaml`. Adds service `caddy` (only public-facing container) + named volumes `caddy_data`, `caddy_config`.

- [ ] **Step 1: Write the overlay**

```yaml
# VPS overlay for compose.prod.yaml. Layer with:
#   docker compose -f compose.prod.yaml -f compose.vps.yaml --env-file .env.prod ...
# (deploy.sh does this when COMPOSE_FILES is set — see the runbook.)
# Requires Docker Compose >= 2.24 for the `!override` tag.
name: eventphotos-prod

services:
  # Only the caddy container is bound to the public network; it terminates TLS
  # and reverse-proxies to nginx over the compose network.
  caddy:
    image: caddy:2-alpine
    env_file:
      - .env.prod            # supplies {$DOMAIN} used by the Caddyfile
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./docker/caddy/Caddyfile:/etc/caddy/Caddyfile:ro
      - caddy_data:/data      # ACME account + issued certs — MUST persist
      - caddy_config:/config
    depends_on:
      nginx:
        condition: service_started
    restart: unless-stopped
    mem_limit: 64m
    cpus: 0.5

  # nginx no longer publishes a host port; Caddy reaches it as nginx:80 in-network.
  nginx:
    ports: !override []
    mem_limit: 128m
    cpus: 0.5

  php:
    # Append the VPS pool mount (base already mounts uploads/share; compose
    # concatenates volume lists, so we add ONLY the extra mount here).
    volumes:
      - ./docker/php/fpm-pool.vps.conf:/usr/local/etc/php-fpm.d/zz-www.conf:ro
    mem_limit: 1g
    cpus: 1.5

  worker:
    mem_limit: 384m
    cpus: 1

  database:
    # Retuned for 4 GB total; overrides the base command wholesale.
    command: >
      postgres
      -c shared_buffers=256MB
      -c effective_cache_size=1GB
      -c work_mem=8MB
      -c maintenance_work_mem=128MB
      -c max_connections=50
      -c random_page_cost=1.1
      -c checkpoint_completion_target=0.9
    mem_limit: 1g
    cpus: 1.5

volumes:
  caddy_data:
  caddy_config:
```

- [ ] **Step 2: Create a throwaway env so the config can render**

Run:
```bash
cat > /tmp/vps-render.env <<'EOF'
DATA_DIR=/opt/eventphotos/data
DOMAIN=photos.example.com
HOST_HTTP_PORT=8081
POSTGRES_DB=eventPhotos
POSTGRES_USER=eventPhotos
POSTGRES_PASSWORD=x
APP_SECRET=x
DATABASE_URL=postgresql://eventPhotos:x@database:5432/eventPhotos?serverVersion=16&charset=utf8
EOF
```
Expected: no output (file written).

- [ ] **Step 3: Render the merged config and verify the overlay applied**

Run:
```bash
docker compose -f compose.prod.yaml -f compose.vps.yaml --env-file /tmp/vps-render.env config
```
Expected (all must hold in the rendered YAML):
- a `caddy` service exists, publishing `80` and `443`;
- the `nginx` service has **no** `ports:` (published port removed by `!override []`);
- `php` mounts `fpm-pool.vps.conf` at `/usr/local/etc/php-fpm.d/zz-www.conf` **and** still has the uploads/share mounts (no duplicates);
- `database.command` shows `shared_buffers=256MB`;
- `caddy_data` / `caddy_config` volumes are declared.

If `!override` errors, the installed Compose is < 2.24 — stop and note it (runbook installs a current version).

- [ ] **Step 4: Clean up + stage + propose commit**

Run: `rm -f /tmp/vps-render.env && git add compose.vps.yaml`
Proposed message: `100 - add VPS compose overlay (caddy + retuned caps)`

---

### Task 4: VPS env template `.env.vps.example`

**Files:**
- Create: `.env.vps.example`
- Reference (unchanged): `.env.prod.example`

**Interfaces:**
- Produces: the template copied to `.env.prod` on the VPS. Adds `DOMAIN` (consumed by Caddyfile Task 2), sets `DATA_DIR=/opt/eventphotos/data`, drops the now-unused `HOST_HTTP_PORT`.

- [ ] **Step 1: Write the template**

```bash
# Copy to .env.prod (gitignored) on the VPS and fill in real values.
# Lock down: chmod 600 .env.prod
# Launch with the VPS overlay:
#   COMPOSE_FILES="compose.prod.yaml compose.vps.yaml" WORKER_SCALE=1 ./deploy.sh

# --- Host paths ---
# Persistent state root; deploy.sh creates postgres/ uploads/ share/ under it.
DATA_DIR=/opt/eventphotos/data

# --- Public hostname ---
# DNS A record must point at the VPS before first deploy so Caddy's ACME succeeds.
# Consumed by docker/caddy/Caddyfile.
DOMAIN=photos.example.com

# --- Symfony ---
APP_ENV=prod
APP_DEBUG=0
# Generate with: openssl rand -hex 32
APP_SECRET=__REPLACE_ME__
# Public URL (emails, CLI URL generation). Must match DOMAIN.
DEFAULT_URI=https://photos.example.com
APP_SHARE_DIR=var/share
# Trust the immediate proxy hop (nginx). Caddy→nginx→php-fpm forwards X-Forwarded-*.
TRUSTED_PROXIES=REMOTE_ADDR

# --- Database (Postgres container) ---
POSTGRES_DB=eventPhotos
POSTGRES_USER=eventPhotos
POSTGRES_PASSWORD=__REPLACE_ME__
DATABASE_URL=postgresql://eventPhotos:__REPLACE_ME__@database:5432/eventPhotos?serverVersion=16&charset=utf8

# --- Mailer (platform-level mail: invitations, password reset) ---
# Real SMTP DSN. Organizer-scoped mail is configured per-user in the app.
MAILER_DSN=smtp://localhost:1025

# --- Messenger ---
MESSENGER_TRANSPORT_DSN=doctrine://default?queue_name=async

# --- Google SSO (optional) ---
# Empty disables Google sign-in (buttons hidden, /oauth/google/* → 404).
# Redirect URI to register: https://<DOMAIN>/oauth/google/callback
GOOGLE_OAUTH_CLIENT_ID=
GOOGLE_OAUTH_CLIENT_SECRET=
```

- [ ] **Step 2: Verify required keys present**

Run: `for k in DATA_DIR DOMAIN DEFAULT_URI APP_SECRET POSTGRES_PASSWORD DATABASE_URL TRUSTED_PROXIES; do grep -q "^$k=" .env.vps.example || echo "MISSING $k"; done; echo done`
Expected: `done` (no `MISSING` lines).

- [ ] **Step 3: Stage + propose commit**

Run: `git add .env.vps.example`
Proposed message: `100 - add VPS env template`

---

### Task 5: Parameterize `deploy.sh`

**Files:**
- Modify: `deploy.sh`

**Interfaces:**
- Consumes: optional env vars `COMPOSE_FILES` (default `compose.prod.yaml`) and `WORKER_SCALE` (default `3`).
- Produces: unchanged behavior for TrueNAS (`./deploy.sh`); VPS usage is `COMPOSE_FILES="compose.prod.yaml compose.vps.yaml" WORKER_SCALE=1 ./deploy.sh`.

- [ ] **Step 1: Replace the compose-invocation block**

Replace this exact block:
```bash
COMPOSE=(docker compose -f compose.prod.yaml --env-file .env.prod)

echo ">>> docker compose build"
"${COMPOSE[@]}" build
```
with:
```bash
# Compose file list + worker scale are env-overridable so the same script serves
# TrueNAS (defaults) and the VPS overlay:
#   COMPOSE_FILES="compose.prod.yaml compose.vps.yaml" WORKER_SCALE=1 ./deploy.sh
COMPOSE_FILES="${COMPOSE_FILES:-compose.prod.yaml}"
WORKER_SCALE="${WORKER_SCALE:-3}"

COMPOSE=(docker compose)
for f in ${COMPOSE_FILES}; do COMPOSE+=(-f "$f"); done
COMPOSE+=(--env-file .env.prod)

echo ">>> using compose files: ${COMPOSE_FILES} (worker scale ${WORKER_SCALE})"

echo ">>> docker compose build"
"${COMPOSE[@]}" build
```

- [ ] **Step 2: Replace the `up` line's hardcoded scale**

Replace:
```bash
"${COMPOSE[@]}" up -d --remove-orphans --scale worker=3
```
with:
```bash
"${COMPOSE[@]}" up -d --remove-orphans --scale worker="${WORKER_SCALE}"
```

- [ ] **Step 3: Shellcheck the result**

Run: `shellcheck deploy.sh`
Expected: no new errors. (SC2086 word-splitting on `${COMPOSE_FILES}` in the `for` is intentional — the loop relies on splitting the space-separated list. If flagged, leave a `# shellcheck disable=SC2086` on the `for` line.)

- [ ] **Step 4: Verify default behavior is unchanged**

Run: `bash -n deploy.sh && echo 'syntax OK'`
Expected: `syntax OK`. Confirm by reading that with no env vars, `COMPOSE_FILES` defaults to `compose.prod.yaml` and `WORKER_SCALE` to `3` — identical to the original.

- [ ] **Step 5: Stage + propose commit**

Run: `git add deploy.sh`
Proposed message: `100 - parameterize deploy.sh compose files + worker scale`

---

### Task 6: VPS bring-up runbook

**Files:**
- Create: `docs/setup/ovh-vps-deploy.md`

**Interfaces:**
- Consumes: all artifacts from Tasks 1–5.
- Produces: the human-executed procedure. This is where end-to-end verification lives (no CI covers infra).

- [ ] **Step 1: Write the runbook**

````markdown
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
````

- [ ] **Step 2: Verify the runbook covers the required flow**

Run: `for s in "ufw allow 443" "get.docker.com" "COMPOSE_FILES=" "app:create-user" "chown -R 33:33"; do grep -q "$s" docs/setup/ovh-vps-deploy.md || echo "MISSING: $s"; done; echo done`
Expected: `done` (no `MISSING` lines).

- [ ] **Step 3: Stage + propose commit**

Run: `git add docs/setup/ovh-vps-deploy.md`
Proposed message: `100 - add OVH VPS deployment runbook`

---

## Self-Review

**Spec coverage:**
- §Architecture (Caddy sidecar, nginx port removal, trusted-proxy chain) → Tasks 2, 3; verified in runbook step 7/9.
- §Components 1 (compose.vps.yaml) → Task 3. §2 (retuned resources + FPM) → Tasks 1, 3. §3 (Caddyfile) → Task 2. §4 (.env.vps.example) → Task 4. §5 (deploy invocation) → Task 5. §6 (runbook) → Task 6.
- §Storage reality → documented in runbook "Not yet configured" + design; no code change needed.
- §Out of scope (backups, migration, object storage, CI/CD, retry bug) → not implemented, called out in runbook/plan. ✓
- §Open implementation-phase items → resolved: FPM file confirmed (`fpm-pool.conf`, static/24) and handled via bind-mount (Task 1/3); deploy.sh parameterized rather than forked (Task 5); TRUSTED_PROXIES chain verified against existing nginx config (REMOTE_ADDR, unchanged). ✓

**Placeholder scan:** No TBD/TODO. `__REPLACE_ME__` and `<issue>`/`<repo-url>`/`<vps-ip>`/`<password>` are intentional user-supplied values in templates/runbook, not plan gaps.

**Type/name consistency:** `DOMAIN` used identically in Caddyfile (Task 2), overlay env_file (Task 3), and env template (Task 4). `COMPOSE_FILES`/`WORKER_SCALE` names match between Task 5 and the runbook. Mount target `/usr/local/etc/php-fpm.d/zz-www.conf` matches the Dockerfile's baked path. Overlay `name: eventphotos-prod` matches the base compose project name.
