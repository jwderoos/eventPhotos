# Event scale-prep — design

**Status:** draft
**Date:** 2026-06-16
**Driver:** Real-world event coming up: ~8,000 hiking participants, 2 photographers in a static spot, ~5–10k photos delivered a few days post-event, then a staggered notification email triggers a viewing rush.
**Out of scope:** photographer ingest path changes (admin form stays), #77 notification/email-capture system, anything classified Tier 4 (Imagick/vips swap, CDN, multi-replica PHP-FPM, alternative messenger transport).

## 1. Context

### Current production topology (relevant facts only)

- TrueNAS SCALE box (14 c / 28 t / 64 GB). Nginx Proxy Manager terminates TLS and proxies to the internal nginx on `${HOST_HTTP_PORT}` (default `8081` in compose, `8088` in env per the deploy memory).
- `compose.prod.yaml` services: `php` (single instance, no resource caps), `worker` (single instance, no caps, `--time-limit=3600 --memory-limit=128M`), `nginx` (single instance, no caps), `database` (Postgres 16-alpine, no caps, default config), `migrate` (run-once).
- Photo storage on the host at `${DATA_DIR}/uploads/photos/{originals,thumbs,previews}/event-<id>/<photoId>.jpg`, mounted into the `php` and `worker` containers at `/app/var/uploads`. **Not mounted into nginx.**
- `docker/php/php-prod.ini`: `opcache.validate_timestamps=0`, preload enabled, **`opcache.jit=tracing` with 64 MB buffer** — contradicts memory note `project_php_jit_off`. See §7.
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
| 3.8 | Resolve JIT contradiction | `docker/php/php-prod.ini` (decision needed) |

### 3.1 X-Accel-Redirect for thumb/preview bytes

**The change.** `PhotoServeController::serve` still runs the DB lookup + slug check + ETag handling, but instead of returning a `StreamedResponse`, it returns a normal `Response` with `X-Accel-Redirect` set to an internal nginx location and the headers (`Content-Type`, `Cache-Control`, `ETag`) preserved. Nginx serves the file from disk with `sendfile`. PHP-FPM child is released in ~3–5 ms.

**Path mapping.**
- Internal locations in nginx:
  - `location ^~ /_protected/thumbs/ { internal; alias /srv/photos/thumbs/; }`
  - `location ^~ /_protected/previews/ { internal; alias /srv/photos/previews/; }`
- Compose: mount the host's `${DATA_DIR}/uploads/photos/thumbs` → `/srv/photos/thumbs:ro` and the previews equivalent into the **nginx** container. **Do not** mount originals into nginx — they must never be web-reachable.
- Controller returns `X-Accel-Redirect: /_protected/thumbs/event-<eventId>/<photoId>.jpg` (or `previews`).

**Security invariants preserved.**
- The internal locations carry `internal;` so only sub-requests from the PHP layer can hit them.
- Authorisation still runs: PHP looks up the `Photo`, validates `status === Ready`, validates `slug` match, and only then emits the redirect header.
- The flysystem `photo_thumbs_storage` / `photo_previews_storage` services are NOT replaced. They are still authoritative for writes (DerivativeGenerator) and remain available for any non-HTTP read path.

**ETag/304 behaviour.** Unchanged. If `If-None-Match` matches, PHP returns `304` directly without setting `X-Accel-Redirect`. Browser caching for repeat visitors is identical.

**Headers from PHP that nginx must honour.** With `X-Accel-Redirect`, nginx replaces the body but keeps PHP's response headers by default (this is the documented behaviour of `X-Accel-Redirect`). We rely on this for `Cache-Control` and `ETag`.

**Why not skip PHP entirely?** Two reasons. (1) The slug check is the soft security boundary on the public path; without it any leaked photo id is enumerable across events. (2) Originals never leak to nginx, so we keep the "originals are never web-served" invariant.

### 3.2 PHP-FPM pool tuning

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

`/e/{slug}/photos?t=HH:mm` runs 6+ queries today (`findReadyInWindow`, `countReadyBefore`, `findFirstReadyTakenAt`, `findLastReadyTakenAt`, plus neighbour lookups). The output is fully a function of `(event_id, t, defaultWindowMinutes, last Photo.updatedAt for that event)`.

**Change.** In the public gallery controller action:

1. Add `PhotoRepository::lastReadyUpdatedAtForEvent(Event $event): ?\DateTimeImmutable`.
2. Build an ETag from `eventId|t|windowMinutes|lastUpdatedAt`.
3. Use Symfony's HTTP cache validation:

```php
$response = new Response();
$response->setEtag($etag);
$response->setPublic();
$response->setMaxAge(0);
$response->headers->addCacheControlDirective('must-revalidate');

if ($response->isNotModified($request)) {
    return $response;
}
// ... existing render
```

Effect: refreshes / back-button / re-clicks of the email link return 304 from PHP without running any of the gallery queries. Cheap; no inval risk because the ETag captures `lastUpdatedAt`. No caching of the actual photo data is added — we don't need it.

We do NOT add a public `s-maxage`: NPM and intermediaries shouldn't cache the HTML (it varies by `t` and we want the ETag-revalidate semantics).

### 3.4 Multiple Messenger workers

Doctrine Messenger uses `FOR UPDATE SKIP LOCKED` (Doctrine Messenger transport, since the Symfony Messenger ≥ 5.1 / Doctrine DBAL ≥ 3 era — confirm by `grep` in `vendor/symfony/doctrine-messenger/Transport/Doctrine/Connection.php` before merging). Concurrent workers are safe.

**Change.** Update `deploy.sh` to use `--scale worker=3` on the final `docker compose up`. We don't add `deploy.replicas` to the compose file because that key is swarm-mode in Compose v2 non-swarm; `--scale` is the supported path.

```sh
# deploy.sh — final compose-up line
docker compose -f compose.prod.yaml up -d --scale worker=3
```

Verify with `docker compose -f compose.prod.yaml ps worker` returning 3 rows.

**Idempotency check.** `ProcessPhotoHandler` already early-returns unless `status === Pending`, so even if two workers somehow ended up holding the same id (they won't, with SKIP LOCKED), the second one no-ops. Confirmed safe.

**Worker memory accounting.** 3 workers × 128 MB cap = 384 MB. Plus GD image-decode peaks (a 25 MB JPEG decoded to a ~5760×3840 truecolor image is ~67 MB resident in GD) — set the `--memory-limit` to **256M** to give headroom; the existing 128M is risky for large-megapixel JPEGs even single-threaded.

### 3.5 Postgres tuning

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

NPM probably gzips on its egress to the public, but having gzip in the internal nginx avoids relying on that and keeps cache-friendly behaviour for any future direct-hit deployment. Add to `default.prod.conf`:

```nginx
gzip on;
gzip_vary on;
gzip_proxied any;
gzip_types text/plain text/css text/xml application/json application/javascript application/xml+rss image/svg+xml;
gzip_min_length 1024;
```

Do **not** gzip `image/jpeg`. Default `gzip_types` lists are usually correct here, but be explicit.

### 3.8 Resolve the JIT contradiction

`docker/php/php-prod.ini` ships `opcache.jit = tracing` with 64 MB buffer. Memory note `project_php_jit_off.md` says JIT must stay off because PHP 8.5 + JIT segfaults FPM. **Two-step decision:**

1. **Before any other change in this spec lands**, run the production image locally with the prod ini and exercise the gallery + thumb endpoints under `ab`/`wrk` for a few minutes. If it segfaults, set `opcache.jit = off` and `opcache.jit_buffer_size = 0` in `php-prod.ini` and update the memory.
2. If it does NOT segfault, update the memory note to "JIT works in tracing mode as of 2026-06-16; PHP-FPM under load tested with N requests" — don't leave a stale memory contradicting shipped config.

This is intentionally a verification-not-a-fix in the spec, because we don't know yet which side is wrong.

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
| JIT segfault during event spike (per memory note). | §3.8 mandates pre-event verification. |
| Postgres `max_connections=200` exhausts host RAM under pathological connection storm. | Connection memory ≈ 10 MB × 200 = 2 GB, well within 6 GB cap. PG cap also acts as the gate before host pressure. |
| `docker compose up --scale` is invoked from `deploy.sh`, not the compose file. A future maintainer who runs `docker compose up` directly will get 1 worker. | Comment in `deploy.sh` next to the `--scale` flag; document in CLAUDE.md under the Messenger section. |
| Gallery ETag misses if `Photo.updatedAt` isn't bumped on transitions other than `markReady`. | `Photo::markReady` and `Photo::markFailed` both bump `updatedAt` per the entity. Confirm by reading the entity before implementation; add a setter-test if absent. |

## 6. Testing & verification

Per-item verification, in order:

1. **3.8 first** — boot prod image locally with `php-prod.ini` + JIT tracing, run a 5-minute `wrk` against the gallery + a known thumb. If clean → update memory. If segfault → disable JIT.
2. **3.1** — unit-test `PhotoServeController` returns a `Response` with `X-Accel-Redirect`, `Cache-Control`, and `ETag` headers and a 200 status when the photo is Ready, 304 when `If-None-Match` matches, 404 on slug mismatch / not-Ready. Integration test: run dev stack with the new internal nginx location, `curl -I` a known thumb URL, verify byte body and headers.
3. **3.2** — `php-fpm -tt` in the new image to dump config and confirm pool values.
4. **3.3** — functional test: hit gallery URL twice, second call with `If-None-Match` from the first response → assert 304.
5. **3.4** — integration: enqueue 50 dummy `ProcessPhoto` messages against a test DB, scale workers to 3, assert no duplicate-processing (each `Photo` row's `status` transitions exactly once).
6. **3.5** — `psql -c 'SHOW shared_buffers; SHOW max_connections;'` against the running container.
7. **3.6** — `docker stats` during a soak.
8. **End-to-end load test**, optional but recommended: `k6` or `wrk` against the staging-equivalent stack — 200 concurrent virtual users hitting a real gallery URL for 5 min. Pass criterion: p95 < 500 ms HTML, p95 < 200 ms thumb, zero 5xx.

## 7. Rollout

- All changes are config-only or controller-internal — no migrations, no entity changes.
- Each numbered item in §3 is independently revertable via git revert.
- Deploy order (post-merge):
  1. Resolve §3.8 (JIT).
  2. Ship §3.5, §3.6, §3.7 in one deploy (compose-only changes, near-zero risk).
  3. Ship §3.2 (PHP-FPM pool) — restart the php container, watch logs for pool-start errors.
  4. Ship §3.1 + §3.3 together (the controller change + nginx mount + internal location + HTML cache). This is the largest change; staging-test it first.
  5. Ship §3.4 (worker scale) — single line in compose, observe with `docker compose ps`.
- Rollback path: `git revert <sha> && ./deploy.sh` for each. No persistent state changes anywhere.

## 8. Open follow-ups (not in this spec)

- #77 notification + email-capture-on-QR-scan design.
- `pg_dump` + uploads rsync cron on TrueNAS.
- Healthchecks for `php` and `worker` services in compose.
- Post-event: GD → Imagick/vips swap evaluation.
- Post-event: storage retention policy (250 GB of originals per event isn't free).
