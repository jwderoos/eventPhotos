#!/usr/bin/env bash
#
# Production deploy script for TrueNAS SCALE (or any Docker host).
#
# First-time setup:
#   1. ssh into the NAS, clone the repo into the chosen dataset.
#   2. cp .env.prod.example .env.prod && chmod 600 .env.prod && vim .env.prod
#   3. ./deploy.sh
#
# Subsequent deploys: just ./deploy.sh

set -euo pipefail

cd "$(dirname "$0")"

if [[ ! -f .env.prod ]]; then
    echo "ERROR: .env.prod not found." >&2
    echo "       cp .env.prod.example .env.prod && chmod 600 .env.prod && edit it." >&2
    exit 1
fi

# Load DATA_DIR for the mkdir below (compose itself reads .env.prod via --env-file).
# shellcheck disable=SC1091
DATA_DIR="$(grep -E '^DATA_DIR=' .env.prod | cut -d= -f2-)"
if [[ -z "${DATA_DIR}" ]]; then
    echo "ERROR: DATA_DIR is empty in .env.prod" >&2
    exit 1
fi

# Fail fast if the per-organizer mail encryption key is missing or not 32 bytes.
# An empty/invalid key decodes to <32 bytes and DsnVault throws on construction,
# 500-ing every mail-touching page (admin events, account mail, public landing).
# Catching it here turns a runtime 500 into a clear deploy-time error.
MAIL_KEY="$(grep -E '^MAIL_CONFIG_ENCRYPTION_KEY=' .env.prod | cut -d= -f2-)"
MAIL_KEY_BYTES="$(printf '%s' "${MAIL_KEY}" | base64 -d 2>/dev/null | wc -c | tr -d ' ')"
if [[ "${MAIL_KEY_BYTES}" != "32" ]]; then
    echo "ERROR: MAIL_CONFIG_ENCRYPTION_KEY must base64-decode to 32 bytes (got ${MAIL_KEY_BYTES:-0})." >&2
    echo "       Generate one with: openssl rand -base64 32" >&2
    exit 1
fi

echo ">>> ensuring persistent data dirs exist under ${DATA_DIR}"
mkdir -p "${DATA_DIR}/postgres" "${DATA_DIR}/uploads" "${DATA_DIR}/share"

echo ">>> git pull"
git pull --ff-only

# Compose file list + worker scale are env-overridable so the same script serves
# TrueNAS (defaults) and the VPS overlay:
#   COMPOSE_FILES="compose.prod.yaml compose.vps.yaml" WORKER_SCALE=1 ./deploy.sh
COMPOSE_FILES="${COMPOSE_FILES:-compose.prod.yaml}"
WORKER_SCALE="${WORKER_SCALE:-3}"

COMPOSE=(docker compose)
# shellcheck disable=SC2086 # intentional word-splitting of the space-separated list
for f in ${COMPOSE_FILES}; do COMPOSE+=(-f "$f"); done
COMPOSE+=(--env-file .env.prod)

echo ">>> using compose files: ${COMPOSE_FILES} (worker scale ${WORKER_SCALE})"

echo ">>> docker compose build"
"${COMPOSE[@]}" build

echo ">>> docker compose up -d"
# migrate runs to completion first; php/worker/nginx start after.
# --scale worker=3 (§3.4) runs three concurrent Messenger consumers. Doctrine
# messenger uses SKIP LOCKED on PostgreSQL (verified in
# vendor/symfony/doctrine-messenger/Transport/Connection.php), so concurrent
# workers can drain the queue without duplicating work. The handler itself
# (ProcessPhotoHandler) is also idempotent — no-ops unless status === Pending.
# Anyone running `docker compose up` directly (outside this script) will get
# only 1 worker; that's intentional for ad-hoc maintenance but means deploys
# MUST go through this script to keep the 3-worker fleet active.
"${COMPOSE[@]}" up -d --remove-orphans --scale worker="${WORKER_SCALE}"

echo ">>> done"
"${COMPOSE[@]}" ps
