# OVHCloud VPS deployment — design

**Date:** 2026-07-07
**Status:** Approved design → implementation plan pending
**Goal:** Deploy EventPhotos as a **fresh** production instance on an OVHCloud VPS (2 vCPU / 4 GB RAM / 40 GB disk), directly internet-facing, with HTTPS. No data migration from the existing TrueNAS deployment; both may run independently.

## Context

The repo already ships a portable production stack — `compose.prod.yaml`, `docker/Dockerfile.prod`, `deploy.sh`, `.env.prod.example` — currently running on TrueNAS SCALE **behind Nginx Proxy Manager**, which terminates TLS. The container definitions are host-agnostic; the compose header comment already says "TrueNAS SCALE or any Docker host."

Therefore this is **not** a rebuild. The deltas for a bare VPS are:

1. **TLS termination** — the VPS has no external proxy. Something must provision + renew Let's Encrypt certs and reverse-proxy to the app's internal nginx.
2. **Resource sizing** — the current caps are tuned for a large NAS (~11 GB RAM total requested, `cpus: 4` on several services). They exceed a 2 vCPU / 4 GB box and must be retuned down.
3. **Host operations** — firewall, SSH hardening, Docker install, DNS now land on us, not on TrueNAS.

### Storage reality (corrected during design)

`MessageHandler\ProcessPhotoHandler::deleteOriginalQuietly` deletes each uploaded original **after** derivatives are generated (both on success and on domain rejection). Originals are therefore transient — only **thumbs + previews** persist (~200 KB/photo combined). After ~10 GB of OS/Docker/Postgres overhead, ~25–30 GB of derivatives holds **100k+ photos**. Object-storage offload is a far-future concern, explicitly out of scope here.

> Side note (out of scope): because the original is deleted post-ingest, `Failed → retry` cannot actually reprocess (the handler re-reads a now-absent original). Pre-existing app behavior; flagged, not addressed here.

## Architecture

Add exactly **one** new container — **Caddy** — as the only service bound to the public network. Caddy auto-provisions and renews Let's Encrypt certs (no cron) and reverse-proxies to the app's internal nginx over the Docker network.

```
Internet ──► :80 / :443  Caddy ──(docker network)──► nginx:80 ──► php-fpm (FastCGI)
                │ Let's Encrypt (ACME)                 │
                └ HTTP→HTTPS redirect                  └ X-Accel-Redirect serves thumbs/previews
```

The app's `nginx` **stops publishing a host port**. Today it maps `${HOST_HTTP_PORT}:80`; on the VPS, Caddy reaches it in-network as `nginx:80`. Only Caddy exposes 80/443 to the internet. The host firewall then needs just 22/80/443.

**TLS chain / trusted proxies:** requests traverse Caddy → nginx → php-fpm. Caddy sets `X-Forwarded-*`; nginx passes them through (already proven on TrueNAS behind NPM). `TRUSTED_PROXIES=REMOTE_ADDR` trusts the immediate hop (nginx). To verify during implementation: correct client scheme/IP in generated URLs and secure-cookie behavior.

## Components / deliverables

### 1. `compose.vps.yaml` (overlay on `compose.prod.yaml`)
Layered via `docker compose -f compose.prod.yaml -f compose.vps.yaml`. Keeps the base valid for TrueNAS (DRY, no duplicated service definitions). It:
- **adds** the `caddy` service (image `caddy:2-alpine`), publishing `80:80` and `443:443`, mounting the Caddyfile and named volumes `caddy_data` (certs/ACME account — must persist) and `caddy_config`; `depends_on: nginx`; `restart: unless-stopped`; `mem_limit: 64m`.
- **removes** nginx's published host port (override `ports: []`).
- **overrides** resource caps and the Postgres tuning command (see §2).

### 2. Retuned resources (4 GB budget)
| Service | Base (TrueNAS) | VPS override |
|---|---|---|
| database | 6 GB, cpus 4; shared_buffers 1GB, eff_cache 4GB, work_mem 16MB, maint 256MB, max_conn 200 | 1 GB, cpus 1.5; shared_buffers 256MB, eff_cache 1GB, work_mem 8MB, maint 128MB, max_conn 50, random_page_cost 1.1, checkpoint_completion_target 0.9 |
| php | 3 GB, cpus 4 | 1 GB, cpus 1.5 |
| worker | 512 MB, cpus 2, ×3 replicas | 384 MB, cpus 1, ×**1** replica |
| nginx | 256 MB, cpus 2 | 128 MB, cpus 0.5 |
| caddy | — | 64 MB, cpus 0.5 |

Sum ≈ 2.6 GB, leaving headroom for the OS + Docker daemon. `cpus` are limits (not reservations), so overlap across the 2 vCPU is fine.

**FPM pool:** the base image sets a `pm.max_children` sized for the NAS (~24). Serving 24 × ~80 MB would blow the 1 GB php cap. Lower it (target ~6, `pm = dynamic`) via the config under `docker/php/`. Exact file + current value to be confirmed in the plan phase; may be parameterized by env or a VPS-specific pool file.

### 3. `docker/caddy/Caddyfile`
Minimal, domain-driven:
```
{$DOMAIN} {
    encode zstd gzip
    reverse_proxy nginx:80
}
```
`{$DOMAIN}` sourced from `.env.prod`. Caddy handles ACME + HTTP→HTTPS automatically. Request-body size limits stay in the app nginx (already enforces the 25 MB upload cap) — Caddy does not re-cap. A plain `caddy:2-alpine` image with the Caddyfile mounted is sufficient; no custom Dockerfile unless a plugin is later needed.

### 4. `.env.vps.example`
VPS-flavored copy of `.env.prod.example`:
- `DATA_DIR=/opt/eventphotos/data`
- `DOMAIN=<your-domain>` (consumed by the Caddyfile)
- `DEFAULT_URI=https://<your-domain>`
- `TRUSTED_PROXIES=REMOTE_ADDR`
- real `APP_SECRET`, `POSTGRES_PASSWORD`/`DATABASE_URL`, `MAILER_DSN`
- `HOST_HTTP_PORT` removed/ignored (nginx no longer publishes)

### 5. Deploy invocation
Either extend `deploy.sh` to accept the overlay + worker scale via env (e.g. `COMPOSE_FILES`, `WORKER_SCALE`), or add a thin `deploy.vps.sh`. Requirements: use both compose files, `--scale worker=1`, `mkdir -p ${DATA_DIR}/{postgres,uploads,share}` with the right ownership (uploads/share `33:33`, postgres `70:70`), `migrate` runs to completion before php/worker/nginx. Decision deferred to the plan; leaning toward parameterizing `deploy.sh` to avoid a second script drifting.

### 6. `docs/setup/ovh-vps-deploy.md` — runbook
Repeatable host bring-up:
1. **DNS** — A record (+ AAAA if IPv6) for the domain → VPS IP. Must resolve before first deploy so ACME succeeds.
2. **OS baseline** — updates; create non-root `deploy` user in the `docker` group.
3. **SSH hardening** — key-only auth; disable root login and password auth.
4. **Firewall** — `ufw`: allow 22/80/443, deny inbound otherwise.
5. **Docker** — Engine + compose plugin (official convenience script / apt repo).
6. **App** — clone repo; `cp .env.vps.example .env.prod`; `chmod 600 .env.prod`; fill secrets; deploy.
7. **First run** — Caddy issues the cert on first HTTPS hit; verify.
8. **Bootstrap** — create the first admin via `app:create-user` (through `docker compose ... exec php`).
9. **Verify** — HTTPS loads, cert valid, upload → worker processes → thumb/preview serve; check `docker compose ps` + worker logs.

## Out of scope (explicit)
- **Backups** — deferred to a follow-up (daily `pg_dump` + uploads tar). Whole persistent state is `${DATA_DIR}` + the Postgres DB.
- **Data migration** from TrueNAS — fresh start, none.
- **Object storage** offload — not needed at current disk headroom.
- **CI/CD auto-deploy** — deploy stays manual (`ssh` + deploy script), matching the current TrueNAS workflow.
- The `Failed → retry` original-absence bug — pre-existing, unrelated.

## Testing / verification
Deployment is infra, not unit-testable in the suite. Verification is the runbook's step 9 (end-to-end on the VPS): HTTPS + valid cert, admin login, photo upload → async processing → derivative serving, and `docker stats` staying within the retuned caps under a small upload burst.

## Open implementation-phase items
- Confirm the exact FPM config file under `docker/php/` and its current `pm.max_children`; decide env-param vs VPS pool file.
- Decide `deploy.sh` parameterization vs `deploy.vps.sh`.
- Verify `TRUSTED_PROXIES` gives correct scheme/IP through the Caddy→nginx chain.

## Note on branch/issue
This work is unrelated to the current `feature/93-event-banner-hero` branch (which has uncommitted banner work). It is tracked by GitHub issue #100 and should live on a `feature/100-ovh-vps-deploy` branch before committing, per the repo's branch-name + commit-message gates. Per user preference, commits are the user's to make — this design doc is written but not committed by the assistant.
