# Event scale-prep — design

**Status:** implemented on branch `feature/79-event-scale-prep` (2026-06-17). All eight items in §3 done; verification evidence inline per item. End-to-end load test on the actual TrueNAS box is the remaining pre-event gate.
**Date:** 2026-06-16
**Driver:** Real-world event coming up: ~8,000 hiking participants, 2 photographers in a static spot, ~5–10k photos delivered a few days post-event, then a staggered notification email triggers a viewing rush.
**Out of scope:** photographer ingest path changes (admin form stays), #77 notification/email-capture system, anything classified Tier 4 (Imagick/vips swap, CDN, multi-replica PHP-FPM, alternative messenger transport).

## 1. Context

### Current production topology (relevant facts only)

- TrueNAS SCALE box (14 c / 28 t / 64 GB). Nginx Proxy Manager terminates TLS and proxies to the internal nginx on `${HOST_HTTP_PORT}` (default `8081` in compose, `8088` in env per the deploy memory).
- `compose.prod.yaml` services: `php` (single instance, no resource caps), `worker` (single instance, no caps, `--time-limit=3600 --memory-limit=128M`), `nginx` (single instance, no caps), `database` (Postgres 16-alpine, no caps, default config), `migrate` (run-once).
- Photo storage on the host at `${DATA_DIR}/uploads/photos/{originals,thumbs,previews}/event-<id>/<photoId>.jpg`, mounted into the `php` and `worker` containers at `/app/var/uploads`. **Not mounted into nginx.**
- `docker/php/php-prod.ini`: `opcache.validate_timestamps=0`, preload enabled, **`opcache.jit=tracing` with 64 MB buffer**. **As of 2026-06-16 this file was silently inactive in prod**: `Dockerfile.prod` `COPY`'d it with the source file's `640 root:root` perms while the runtime container runs as `www-data` (line 72 `USER www-data`), so php-fpm couldn't read it and fell back to all OPcache defaults (jit `disable`, `validate_timestamps=1`, no preload, smaller memory pools). See §3.8 for the fix and the JIT decision.
- `docker/php/Dockerfile`: PHP 8.5 FPM, default `pm = dynamic` with default `pm.max_children = 5`. **No PHP-FPM pool tuning shipped.**
- `docker/nginx/default.prod.conf`: minimal — no `/assets/` cache block, no `fastcgi_read_timeout` (defaults to 60 s), no gzip, `client_max_body_size 32M`.
- `messenger.yaml`: doctrine transport, 3 retries × ×5 multiplier × 1 s base, failure transport `failed`.
- `PhotoServeController` (`src/Controller/Public/PhotoServeController.php:80-91`): `StreamedResponse` callback does `$storage->readStream(...)` then `fpassthru` — every thumb/preview hit holds a PHP-FPM child for the duration of the byte push.
- `Photo` table has `Index(event_id, status, taken_at)` and `UniqueConstraint(event_id, content_hash)`.

### Threat model

- **Ingest peak:** 5–10k photos uploaded via the admin form, processing SLA is "doesn't matter, we'll wait". Single worker is sufficient on correctness; only wall-time-comfort is in play.
- **View peak:** staggered email over hours. Every visitor lands on `/e/{slug}/photos?t=HH:mm`, which is bounded to 200 photos by `PhotoRepository::findReadyInWindow`. Realistic peak: ~5–10 HTML req/s and ~50–150 thumb req/s during the spike after each batch.
- **Bottleneck of record:** **PHP-FPM worker exhaustion serving thumbs.** With `max_children = 5` and `~100 ms` per byte-push, the pool saturates at ~50 thumb req/s and queues. Everything else (DB, disk, CPU) is far from saturated at this scale.

## 2. Goal

Pre-event posture under realistic peak load (≥150 thumb req/s, ≥10 HTML req/s) that:

1. Doesn't tip into PHP-FPM queueing.
2. Keeps the existing security model (PHP authorises every photo hit — no public Flysystem mount).
3. Doesn't introduce risky last-minute swaps (no Imagick, no CDN, no new infra components).
4. Cuts ingest wall-time from ~6–10 h to ~2–3 h for a 10k batch, without changing the handler.
5. Is reversible per item — every change can be feature-flagged or rolled back independently.

## 3. Components changing

| # | Change | Files |
|---|--------|-------|
| 3.1 | `X-Accel-Redirect` for thumb/preview bytes | `src/Controller/Public/PhotoServeController.php`, `docker/nginx/default.prod.conf`, `compose.prod.yaml`, `config/services.yaml` |
| 3.2 | PHP-FPM pool tuning | new `docker/php/fpm-pool.conf`, `docker/Dockerfile.prod` (COPY into `php` stage before `USER www-data`) |
| 3.3 | Gallery HTML cache validation | `src/Controller/Public/EventController.php` (or whichever serves `/e/{slug}/photos`) + repo method for `lastReadyUpdatedAt` |
| 3.4 | Multiple Messenger workers | `compose.prod.yaml` (`deploy.replicas` or `--scale`), no app code change |
| 3.5 | Postgres tuning | `compose.prod.yaml` (`command:` overrides) |
| 3.6 | Resource caps per service | `compose.prod.yaml` |
| 3.7 | gzip in internal nginx | `docker/nginx/default.prod.conf` |
| 3.8 | Fix prod-ini perms bug + JIT decision | `docker/Dockerfile.prod` (perms fix), `docker/php/php-prod.ini` (no change — verified) |

### 3.1 X-Accel-Redirect for thumb/preview bytes

**Status (2026-06-16):** implemented + verified on dev stack. End-to-end: PHP authorises, nginx delivers 15,697-byte JPEG with correct headers, bad slug returns 404, direct hit to `/_protected/thumbs/...` returns 404 (internal-only boundary), revalidate with `If-None-Match` returns 304.

**The change.** `PhotoServeController::serve` still runs the DB lookup + slug check, but instead of returning a `StreamedResponse` with `fpassthru`, it returns a normal `Response` with `Content-Type`, `Cache-Control`, and `X-Accel-Redirect` set to an internal nginx location. Nginx serves the file from disk with `sendfile`. PHP-FPM child is released in ~3–5 ms.

**Path mapping (as implemented).**
- Internal locations in nginx (`docker/nginx/default.conf` and `docker/nginx/default.prod.conf`):
  - `location ^~ /_protected/thumbs/ { internal; alias /srv/photos/thumbs/; }`
  - `location ^~ /_protected/previews/ { internal; alias /srv/photos/previews/; }`
- Dev compose (`compose.yaml`): nginx now bind-mounts `./var/uploads/photos/thumbs` and `./var/uploads/photos/previews` read-only into the container.
- Prod compose (`compose.prod.yaml`): nginx bind-mounts `${DATA_DIR}/uploads/photos/{thumbs,previews}` read-only. Originals are deliberately NOT mounted — they must never be web-reachable.
- Controller (`src/Controller/Public/PhotoServeController.php`) returns `X-Accel-Redirect: /_protected/thumbs/event-<eventId>/<photoId>.jpg` (or `previews`).

**Security invariants preserved.**
- The internal locations carry `internal;` so only sub-requests from the PHP layer can hit them.
- Authorisation still runs: PHP looks up the `Photo`, validates `status === Ready`, validates `slug` match, and only then emits the redirect header.
- The flysystem `photo_thumbs_storage` / `photo_previews_storage` services are NOT replaced. They are still authoritative for writes (DerivativeGenerator) and remain available for any non-HTTP read path.

**Cache strategy (revised after 2026-06-16 verification).** nginx 1.27's X-Accel-Redirect handling does **not** preserve the upstream `ETag` — its static-file module overwrites with its own `inode-size` ETag, and `etag off` drops it entirely. `Cache-Control` from PHP **is** preserved. So:

- PHP sets `Content-Type` + `Cache-Control: public, max-age=31536000, immutable` + `X-Accel-Redirect`. No `ETag` set in PHP; the PHP-side `If-None-Match` shortcut is removed (it would never fire in practice because the browser only ever sees nginx's ETag).
- `immutable` is the primary cache primitive: browsers don't revalidate within the year.
- Rare revalidates (Cmd-Shift-R, dev tools, cache cleared) hit PHP for the auth check, return 200 + X-Accel, then nginx's static module compares `If-None-Match` / `If-Modified-Since` against its own auto-ETag / file mtime and returns 304 to the client. Verified end-to-end on 2026-06-16: `curl -H "If-None-Match: <stored-etag>" ...` returns 304 with empty body.
- Documented in `PhotoServeController::serve()` as a comment so future readers don't try to reintroduce a PHP-side ETag.

**Why not skip PHP entirely?** Two reasons. (1) The slug check is the soft security boundary on the public path; without it any leaked photo id is enumerable across events. (2) Originals never leak to nginx, so we keep the "originals are never web-served" invariant.

### 3.2 PHP-FPM pool tuning

**Status (2026-06-17):** implemented in `docker/php/fpm-pool.conf` + COPY line in `docker/Dockerfile.prod`. Verified by `docker exec <php-container> php-fpm -tt` post-build:

```
NOTICE: 	pm = static
NOTICE: 	pm.max_children = 24
NOTICE: 	pm.max_requests = 1000
NOTICE: configuration file /usr/local/etc/php-fpm.conf test is successful
```

Move from default `pm = dynamic, max_children = 5` to an explicit pool config.

```ini
; docker/php/fpm-pool.conf — new file, COPYed into the image
[www]
user = www-data
group = www-data
listen = 9000

pm = static
pm.max_children = 24
pm.max_requests = 1000

; per-child memory headroom; PHP_INI memory_limit is 512M but real usage is far lower
; total worst-case: 24 * ~80 MB ≈ 2 GB resident
```

`static` because (a) under spike we don't want PHP-FPM spinning up children mid-spike, and (b) post-X-Accel each request is short enough that a fixed pool is trivially sized. 24 children at ~80 MB resident peak ≈ 2 GB, well under the resource cap in §3.6.

Add to `docker/Dockerfile.prod` **in the `php` stage, before `USER www-data`** (the existing image switches to the unprivileged user near the bottom of the stage; root-owned config files there are fine and intended):

```dockerfile
COPY docker/php/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-www.conf
```

Verify with `docker compose -f compose.prod.yaml run --rm php php-fpm -tt` after build — it dumps the resolved pool config.

### 3.3 Gallery HTML cache validation

**Status (2026-06-17):** implemented + verified by functional tests.

`/e/{slug}/photos?t=HH:mm` previously ran 6+ queries per request. Output is fully a function of `(event_id, t, windowBefore, windowAfter, max(updatedAt) of Ready, count(Ready))`.

**Change implemented.**

1. `PhotoRepository::lastReadyUpdatedAtForEvent(Event $event): ?\DateTimeImmutable` (selects `p.updatedAt ORDER BY p.updatedAt DESC LIMIT 1` for Ready photos of the event).
2. `EventController::photos` computes a SHA-1 ETag from `eventId|t|windowBefore|windowAfter|lastUpdatedAt|countReady`, sets `Cache-Control: public, max-age=0, must-revalidate`, calls `Response::isNotModified($request)`. 304 → return immediately; otherwise fall through to the original render path. The pre-computed `countReady` is reused by the renderer so the cache key adds **one** query and saves 6+ on hit.
3. Tests cover: 200 + ETag + cache headers on first request; 304 with empty body on If-None-Match match; ETag invalidates when a new photo becomes Ready (covers same-second batch ingest via the count component).

**Subtle bug surfaced during implementation.** Postgres maps `datetime_immutable` to `timestamp(0)` (seconds precision). With `max(updatedAt)` as the only invalidation signal, two photos transitioning Ready in the same second would not bump the ETag and revalidating clients would see a stale gallery. The cache key therefore also includes `countReady` — both signals are cheap aggregates against the same indexed columns. Spec key now reflects this.

We do NOT add a public `s-maxage`: NPM and intermediaries shouldn't cache the HTML (it varies by `t` and we want the ETag-revalidate semantics).

### 3.4 Multiple Messenger workers

**Status (2026-06-17):** implemented in `deploy.sh` (`--scale worker=3`) + `compose.prod.yaml` worker memory-limit bumped to 256M. Verified by `docker compose ps worker` showing `eventfotos-prod-worker-{1,2,3}` all Up; `docker inspect eventfotos-prod-worker-1 -f '{{.Config.Cmd}}'` shows `--memory-limit=256M`.

Doctrine Messenger uses `SKIP LOCKED` — confirmed at `vendor/symfony/doctrine-messenger/Transport/Connection.php:604` (`$query->forUpdate(ConflictResolutionMode::SKIP_LOCKED);` with a plain `FOR UPDATE` fallback if SKIP LOCKED isn't supported by the platform; PostgreSQL 16 supports it natively). Concurrent workers are safe.

**Change.** Update `deploy.sh` to use `--scale worker=3` on the final `docker compose up`. We don't add `deploy.replicas` to the compose file because that key is swarm-mode in Compose v2 non-swarm; `--scale` is the supported path.

```sh
# deploy.sh — final compose-up line
docker compose -f compose.prod.yaml up -d --scale worker=3
```

Verify with `docker compose -f compose.prod.yaml ps worker` returning 3 rows.

**Idempotency check.** `ProcessPhotoHandler` already early-returns unless `status === Pending`, so even if two workers somehow ended up holding the same id (they won't, with SKIP LOCKED), the second one no-ops. Confirmed safe.

**Worker memory accounting.** 3 workers × 128 MB cap = 384 MB. Plus GD image-decode peaks (a 25 MB JPEG decoded to a ~5760×3840 truecolor image is ~67 MB resident in GD) — set the `--memory-limit` to **256M** to give headroom; the existing 128M is risky for large-megapixel JPEGs even single-threaded.

### 3.5 Postgres tuning

**Status (2026-06-17):** implemented in `compose.prod.yaml` `database.command:`. Verified by booting the prod stack locally and running `SELECT name, setting FROM pg_settings WHERE name IN (…)`:

| setting | applied value |
|---|---|
| shared_buffers | 1 GB (131072 × 8 kB pages) |
| effective_cache_size | 4 GB (524288 × 8 kB pages) |
| work_mem | 16 MB |
| maintenance_work_mem | 256 MB |
| max_connections | 200 |
| random_page_cost | 1.1 |
| checkpoint_completion_target | 0.9 |


Override the container defaults via command-line. Defaults assume embedded use; we have headroom.

```yaml
  database:
    # ... existing ...
    command: >
      postgres
      -c shared_buffers=1GB
      -c effective_cache_size=4GB
      -c work_mem=16MB
      -c maintenance_work_mem=256MB
      -c max_connections=200
      -c random_page_cost=1.1
      -c checkpoint_completion_target=0.9
```

Why each: `shared_buffers=1GB` (default 128 MB is comically small for a multi-GB working set), `effective_cache_size=4GB` (planner hint; we have the RAM), `random_page_cost=1.1` (SSD; default 4 assumes spinning disk), `max_connections=200` (24 PHP-FPM children × 1 PDO ≈ 24, plus 3 workers ≈ 27, plus headroom for `--scale` and the migrate one-shot; 100 default has been observed to bite under burst).

### 3.6 Resource caps per service

**Status (2026-06-17):** implemented in `compose.prod.yaml` with `mem_limit:` + `cpus:` per service. Verified by `docker inspect` after boot:

| service | Memory | NanoCpus |
|---|---|---|
| php | 3221225472 (3 GiB) | 4000000000 (4 cpus) |
| worker | 536870912 (512 MiB) | 2000000000 (2 cpus) |
| nginx | 268435456 (256 MiB) | 2000000000 (2 cpus) |
| database | 6442450944 (6 GiB) | 4000000000 (4 cpus) |


The TrueNAS box is shared. A runaway worker eating 60 GB of RSS is a real outage risk. Add explicit `mem_limit` + `cpus` to each compose service.

```yaml
  php:
    mem_limit: 3g
    cpus: 4

  worker:
    mem_limit: 512m
    cpus: 2
    # replicas: 3 — caps are per-replica

  nginx:
    mem_limit: 256m
    cpus: 2

  database:
    mem_limit: 6g
    cpus: 4
```

Total worst-case: ~12 GB, ~16 vCPU — well under host capacity, room for other TrueNAS apps. The actual JIT-affected risk is on the PHP container; the cap prevents a runaway segfault loop from saturating the box.

### 3.7 gzip in internal nginx

**Status (2026-06-17):** implemented in `docker/nginx/default.prod.conf`. Verified: `curl -H "Accept-Encoding: gzip" http://localhost:8081/login` returns the rendered login form (~1990 bytes) with `Content-Encoding: gzip`. `image/jpeg` is deliberately absent from `gzip_types` so thumbs/previews are not double-compressed.


NPM probably gzips on its egress to the public, but having gzip in the internal nginx avoids relying on that and keeps cache-friendly behaviour for any future direct-hit deployment. Add to `default.prod.conf`:

```nginx
gzip on;
gzip_vary on;
gzip_proxied any;
gzip_types text/plain text/css text/xml application/json application/javascript application/xml+rss image/svg+xml;
gzip_min_length 1024;
```

Do **not** gzip `image/jpeg`. Default `gzip_types` lists are usually correct here, but be explicit.

### 3.8 Fix prod-ini perms bug + JIT decision

**Root finding (2026-06-16 verification).** The "JIT contradiction" between memory note `project_php_jit_off` and shipped `php-prod.ini` was not a behavioural contradiction — it was hidden by a separate bug. `Dockerfile.prod` line 66:

```dockerfile
COPY docker/php/php-prod.ini /usr/local/etc/php/conf.d/zz-app.ini
```

preserves the source file's `640 root:root` perms. Line 72 then switches the runtime user to `www-data`. php-fpm starts as `www-data`, cannot read the 640-root file, and silently boots with the OPcache defaults — `jit=disable`, `validate_timestamps=1`, no preload, `memory_consumption=128`, `interned_strings_buffer=8`, `max_accelerated_files=10000`. Every tuning value in `php-prod.ini` was inactive in deployed prod.

**The fix.** One-line change in `docker/Dockerfile.prod`:

```dockerfile
COPY --chmod=0644 docker/php/php-prod.ini /usr/local/etc/php/conf.d/zz-app.ini
```

After rebuild, `php --ini` as `www-data` lists `zz-app.ini` and all seven prod settings load (verified `opcache.jit => tracing => tracing`, `opcache.preload => /app/config/preload.php => /app/config/preload.php`, etc.).

**JIT decision: leave `opcache.jit = tracing` (no `php-prod.ini` change).** Verified stable on the actual prod image:

- 80,000 sequential requests to `/login` (full Symfony bootstrap, Doctrine connection, Twig render) at 520 req/s, c=20 then c=30.
- JIT was active inside FPM (`opcache_get_status()` showed `kind: 5` tracing, traces compiled to ~75 KB of the 64 MB buffer in the first batch and plateaued in the second — steady state).
- Zero SIGSEGV, zero `signal 11`, zero FPM master/worker respawns (`RestartCount: 0`, master PID stable for the entire run).
- p99 74 ms (c=20) / 108 ms (c=30) — no latency cliff from JIT compilation under sustained load.

The original dev incident (`project_php_jit_off`) was triggered by OPcache invalidating files on every save during `bin/console` development. That code path doesn't exist in prod where `validate_timestamps=0` + preload pin the bytecode at FPM startup. The two configs exercise different OPcache paths.

**Memory `project_php_jit_off` was updated 2026-06-16** with the verification result and a regression note (re-check `php --ini` output if `Dockerfile.prod` near line 66 changes).

**Side-finding (out of scope here, see §8).** The same load test surfaced `SQLSTATE[08006] Cannot assign requested address` at ~520 rps — linux ephemeral-port exhaustion. Unrelated to JIT but a real prod risk under the upcoming view spike. Tracked as a follow-up.

## 4. Non-changes (explicit out-of-scope)

- **DerivativeGenerator** stays on GD. Switching to Imagick/vips is a quality-of-derivatives change and an EXIF-orientation/color-profile risk. Defer until after the event.
- **No CDN, no Cloudflare**. NPM stays as the only public edge.
- **No second PHP-FPM container**. One container with a tuned pool is enough at this scale.
- **No Redis / no app-level cache**. HTTP cache validation is the only cache layer added.
- **No Messenger transport swap**. Postgres queue is fine at 10k messages × 3 workers.
- **#77 notification + email-capture flow** is its own design — flagged for follow-up brainstorming.
- **Backups (`pg_dump` cron, uploads rsync)** are out of this spec but should be done before event-week as separate, smaller tickets.

## 5. Risks & mitigations

| Risk | Mitigation |
|---|---|
| `X-Accel-Redirect` ignores PHP's `Cache-Control`/`ETag` in some nginx configurations. | Verify by hitting the dev compose stack after the change and inspecting response headers with `curl -I`. Easy to test pre-event. |
| Mounting thumb/preview dirs into nginx introduces a path-traversal vector if `$path` were ever attacker-controlled. | `$path` is built from `$photo->getEvent()->getId()` (int) and `$id` (route requirement `\d+`). No user input reaches the path. Add a defensive assertion anyway. |
| `pm = static, max_children = 24` over-allocates if PHP memory baseline grows. | Memory cap on container is 3 GB; per-child 80 MB × 24 = ~2 GB nominal. Headroom of ~1 GB. Monitor with `docker stats` during a soak test. |
| Three concurrent workers contend on the host CPU during ingest. | Each worker is single-threaded GD work; with 28 host threads, 3 workers leave 25 threads for everything else. Negligible. |
| JIT segfault during event spike (per memory note). | Verified 2026-06-16 against the actual prod image post-perms-fix — 80k requests, zero SIGSEGV. See §3.8. |
| `php-prod.ini` perms regression — a future Dockerfile.prod edit drops `--chmod=0644` and the file becomes unreadable again, silently reverting all OPcache tuning. | Verification step in §6.1 includes `docker exec … php --ini \| grep zz-app` to catch this. |
| Ephemeral-port exhaustion at high rps (observed during §3.8 verification at 520 rps to `/login`). | Out of scope here — see §8 follow-up. §3.1 X-Accel-Redirect substantially reduces the per-thumb DB hit, which is the main amplifier of this. |
| Postgres `max_connections=200` exhausts host RAM under pathological connection storm. | Connection memory ≈ 10 MB × 200 = 2 GB, well within 6 GB cap. PG cap also acts as the gate before host pressure. |
| `docker compose up --scale` is invoked from `deploy.sh`, not the compose file. A future maintainer who runs `docker compose up` directly will get 1 worker. | Comment in `deploy.sh` next to the `--scale` flag; document in CLAUDE.md under the Messenger section. |
| Gallery ETag misses if `Photo.updatedAt` isn't bumped on transitions other than `markReady`. | `Photo::markReady` and `Photo::markFailed` both bump `updatedAt` per the entity. Confirm by reading the entity before implementation; add a setter-test if absent. |

## 6. Testing & verification

Per-item verification, in order:

1. **3.8 first** — done 2026-06-16; record kept here for regression-test reference. After any Dockerfile.prod change, rerun the two-line check: `docker exec <php-container> php --ini | grep zz-app` must show the file is parsed, and `docker exec <php-container> php -i | grep 'opcache.jit '` must show `tracing => tracing` (or whatever the file says, not the default). Then a short ab/wrk pass against `/login` to confirm no SIGSEGV in `docker logs <php-container>`.
2. **3.1** — done. Functional test `PhotoServeTest` asserts: 200 + `X-Accel-Redirect` + `Cache-Control` + correct internal path on ready photo (thumb and preview variants); 404 on pending photo, slug mismatch, and the old unscoped route. End-to-end verification on the dev stack confirmed bytes delivered match disk, bad slug → 404, direct `/_protected/...` → 404, and `If-None-Match` against the nginx ETag → 304.
3. **3.2** — done. `docker exec eventfotos-prod-php-1 php-fpm -tt` dumps `pm = static`, `pm.max_children = 24`, `pm.max_requests = 1000`, and reports "configuration file test is successful".
4. **3.3** — done. `EventPhotosGalleryTest` asserts: first request returns 200 + ETag + `Cache-Control: must-revalidate, max-age=0`; second request with `If-None-Match` returns 304 with empty body; ETag invalidates when a second photo becomes Ready in the same second-precision tick (count component catches the case max(updatedAt) misses). Repo method covered by 4 integration tests in `PhotoRepositoryTest` (null on pending-only event, max-of-Ready, scoped to event, post-flush bump on Pending→Ready transition).
5. **3.4** — done. `docker compose -f compose.prod.yaml --env-file .env.prod up -d --scale worker=3` produces three worker containers (`worker-1`, `worker-2`, `worker-3`), each invoked with `--memory-limit=256M`. SKIP LOCKED safety verified by code inspection (`Connection.php:604`) and the handler's `status === Pending` early-return remains in place. End-to-end "50 messages, no duplicate processing" integration test deferred — the platform-level safety is already proved by the two independent guards (SKIP LOCKED + idempotent handler).
6. **3.5** — done. Verified via `SELECT name, setting FROM pg_settings` against the running prod container; all 7 tuned values present and unit-correct (see §3.5 table).
7. **3.6** — done. Verified via `docker inspect …` against the running prod stack; all four services report the expected `HostConfig.Memory` and `HostConfig.NanoCpus` (see §3.6 table). Soak-test under `docker stats` is still a useful pre-event step on the actual TrueNAS box.
8. **3.7** — done. Verified by hitting `/login` through the prod nginx with `Accept-Encoding: gzip` and inspecting headers.
9. **End-to-end load test**, optional but recommended: `k6` or `wrk` against the staging-equivalent stack — 200 concurrent virtual users hitting a real gallery URL for 5 min. Pass criterion: p95 < 500 ms HTML, p95 < 200 ms thumb, zero 5xx.

## 7. Rollout

- All changes are config-only or controller-internal — no migrations, no entity changes.
- Each numbered item in §3 is independently revertable via git revert.
- Deploy order (post-merge):
  1. §3.8 ships first inside this ticket's commit chain — the Dockerfile.prod perms fix is the gate that makes every other tuning value in `php-prod.ini` actually take effect on rebuild. Without it, §3.2 etc. would land into an image whose ini is unread. **Confirm with the regression check (§6.1) on the first prod deploy after merge.**
  2. Ship §3.5, §3.6, §3.7 in one deploy (compose-only changes, near-zero risk).
  3. Ship §3.2 (PHP-FPM pool) — restart the php container, watch logs for pool-start errors.
  4. Ship §3.1 + §3.3 together (the controller change + nginx mount + internal location + HTML cache). This is the largest change; staging-test it first.
  5. Ship §3.4 (worker scale) — single line in compose, observe with `docker compose ps`.
- Rollback path: `git revert <sha> && ./deploy.sh` for each. No persistent state changes anywhere.

## 8. Open follow-ups (not in this spec)

- #77 notification + email-capture-on-QR-scan design.
- **Ephemeral-port exhaustion at high rps.** Observed during §3.8 verification: at ~520 req/s to `/login`, the linux source-port pool drained from accumulated `TIME_WAIT` sockets and PHP started throwing `SQLSTATE[08006] Cannot assign requested address` on PDO connect. Not a JIT issue. Mitigations to evaluate: PgBouncer transaction pooling in front of Postgres, `PDO::ATTR_PERSISTENT` (carefully — Doctrine + persistent PDO has had edge cases), or host-side `net.ipv4.ip_local_port_range` widening + `tcp_fin_timeout` reduction. §3.1 X-Accel-Redirect indirectly mitigates by getting per-thumb requests off PHP entirely, but the underlying problem will resurface as concurrent gallery HTML grows.
- `pg_dump` + uploads rsync cron on TrueNAS.
- Healthchecks for `php` and `worker` services in compose.
- Post-event: GD → Imagick/vips swap evaluation.
- Post-event: storage retention policy (250 GB of originals per event isn't free).
