#!/usr/bin/env bash
#
# Zippi School zero-downtime deploy. Runs ON the server, invoked over SSH by
# .github/workflows/deploy-*.yml. Do not run this by hand on production unless
# you are recovering from a failed deploy -- use the workflow so there is an
# audit trail of who deployed what.
#
#   Usage: deploy.sh <environment> <release-id> <artifact-path>
#     environment  : staging | production
#     release-id   : usually the git SHA; becomes the release directory name
#     artifact-path: tarball uploaded by CI, containing the built application
#
# Layout it maintains:
#   /var/www/html/<app>            -> symlink to the live release  (nginx root)
#   /var/www/html/<app>-releases/  -> timestamped release directories
#   /var/www/html/<app>-shared/    -> .env + storage, survives every deploy
#
# The nginx config must use
#   fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
# which resolves the symlink per request. That is what makes the atomic swap
# safe without touching nginx at all.
#
# ⚠ This is a SEPARATE application from zippi-backend, which already lives on
# this host at /var/www/html/staging and /var/www/html/zippi. The APP_DIR values
# below must never collide with those.

set -euo pipefail

ENVIRONMENT="${1:?usage: deploy.sh <staging|production> <release-id> <artifact>}"
RELEASE_ID="${2:?missing release id}"
ARTIFACT="${3:?missing artifact path}"

# -----------------------------------------------------------------------------
# ⚠ THE ONLY LINES THAT ENCODE WHERE THIS APP LIVES.
#
# Confirm both against the actual server before the first deploy. staging.zippi.in
# and /var/www/html/staging are ALREADY TAKEN by the ride backend; deploying this
# app to those would replace it.
# -----------------------------------------------------------------------------
case "$ENVIRONMENT" in
  production) APP_DIR="school";         HEALTH_HOST="school.zippi.in";         WORKER="zippi-school-worker" ;;
  staging)    APP_DIR="school-staging"; HEALTH_HOST="school-staging.zippi.in"; WORKER="zippi-school-staging-worker" ;;
  *) echo "FATAL: unknown environment '$ENVIRONMENT' (expected staging|production)" >&2; exit 2 ;;
esac

# Both hostnames sit behind Cloudflare, which serves a managed bot challenge to
# curl: every request returns "HTTP 403, cf-mitigated: challenge" no matter how
# healthy the application is. Polling the public URL would therefore fail the
# health check on EVERY deploy and roll back a perfectly good release.
#
# --resolve pins the hostname to the loopback address, so the request goes
# straight to nginx on this box and skips Cloudflare entirely. TLS still gets the
# right SNI and Host header, so nginx picks the correct server block.
HEALTH_URL="https://${HEALTH_HOST}/up"
CURL_RESOLVE=(--resolve "${HEALTH_HOST}:443:127.0.0.1")

WEB_ROOT="/var/www/html"
CURRENT="${WEB_ROOT}/${APP_DIR}"
RELEASES="${WEB_ROOT}/${APP_DIR}-releases"
SHARED="${WEB_ROOT}/${APP_DIR}-shared"
RELEASE_DIR="${RELEASES}/${RELEASE_ID}"
KEEP_RELEASES=5
PHP_FPM="php8.4-fpm"
OWNER="www-data:www-data"

log()  { printf '[deploy %s] %s\n' "$ENVIRONMENT" "$*"; }
fail() { printf '[deploy %s FAILED] %s\n' "$ENVIRONMENT" "$*" >&2; exit 1; }

# The release we can fall back to. Empty on a first-ever deploy.
PREVIOUS_RELEASE=""
if [ -L "$CURRENT" ]; then
  PREVIOUS_RELEASE="$(readlink -f "$CURRENT")"
  log "current live release: $PREVIOUS_RELEASE"
fi

rollback() {
  if [ -n "$PREVIOUS_RELEASE" ] && [ -d "$PREVIOUS_RELEASE" ]; then
    log "ROLLING BACK to $PREVIOUS_RELEASE"
    sudo ln -sfn "$PREVIOUS_RELEASE" "${CURRENT}.tmp"
    sudo mv -Tf "${CURRENT}.tmp" "$CURRENT"
    sudo systemctl reload "$PHP_FPM" || true
    restart_workers "rollback"
    log "rollback complete -- previous release is live again"
  else
    log "no previous release to roll back to; leaving as-is for manual inspection"
  fi
  sudo rm -rf "$RELEASE_DIR"
}

# Workers hold the OLD code in memory until restarted. The target MUST be
# "${WORKER}:*" and not "$WORKER": with numprocs>1 supervisor creates a process
# GROUP, and the bare group name is not addressable --
#     supervisorctl restart zippi-school-worker      -> exit 1, "no such process"
#     supervisorctl restart 'zippi-school-worker:*'  -> restarts all of them
# ":*" is also correct for numprocs=1.
#
# ⚠ Unlike zippi-backend this is tolerant of the group not existing at all.
# QUEUE_CONNECTION=database is configured, but a worker may not be provisioned on
# this box yet. A missing worker must not fail a deploy of an app whose
# background work is a nightly artisan schedule, not a queue.
restart_workers() {
  local context="${1:-deploy}"
  if ! sudo supervisorctl status "${WORKER}:*" >/dev/null 2>&1; then
    log "no supervisor group ${WORKER}:* on this host -- skipping worker restart"
    return 0
  fi
  log "restarting queue workers ${WORKER}:*"
  if sudo supervisorctl restart "${WORKER}:*"; then
    RUNNING="$(sudo supervisorctl status "${WORKER}:*" 2>/dev/null | grep -c RUNNING || true)"
    if [ "${RUNNING:-0}" -gt 0 ]; then
      log "queue workers restarted ($RUNNING running)"
    else
      log "WARNING: ${WORKER}:* restarted but no process is RUNNING."
    fi
  else
    log "WARNING [$context]: could not restart ${WORKER}:* -- workers may STILL BE"
    log "         RUNNING THE PREVIOUS RELEASE. Check:"
    log "         sudo supervisorctl status '${WORKER}:*'"
  fi
}

# -----------------------------------------------------------------------------
# 0. Preconditions. Fail before touching anything if the host is not ready.
# -----------------------------------------------------------------------------
[ -f "$ARTIFACT" ] || fail "artifact not found: $ARTIFACT"
[ -d "$SHARED" ]   || fail "shared dir missing: $SHARED -- run deploy/bootstrap-server.sh first"
[ -f "${SHARED}/.env" ]    || fail "no .env in $SHARED -- create it before deploying"
[ -d "${SHARED}/storage" ] || fail "no storage/ in $SHARED -- run bootstrap-server.sh first"

sudo mkdir -p "$RELEASES"

# -----------------------------------------------------------------------------
# 1. Unpack the new release alongside the live one. Nothing is live yet, so a
#    failure here cannot affect the running site.
# -----------------------------------------------------------------------------
log "unpacking release $RELEASE_ID"
sudo rm -rf "$RELEASE_DIR"
sudo mkdir -p "$RELEASE_DIR"
sudo tar xzf "$ARTIFACT" -C "$RELEASE_DIR"

# -----------------------------------------------------------------------------
# 2. Wire in shared state. .env and storage must NOT live inside a release, or
#    every deploy would discard uploads and reset configuration.
#
#    ⚠ storage/app/private/sweeps holds the Invariant #3 sweep photographs --
#    the timestamped, geo-stamped, photo-backed evidence that a bus was checked
#    before its trip closed. Those are a safety record. If storage were not
#    shared, every deploy would destroy them.
# -----------------------------------------------------------------------------
log "linking shared .env and storage"
sudo ln -sfn "${SHARED}/.env" "${RELEASE_DIR}/.env"
sudo rm -rf "${RELEASE_DIR}/storage"
sudo ln -sfn "${SHARED}/storage" "${RELEASE_DIR}/storage"
sudo mkdir -p "${RELEASE_DIR}/bootstrap/cache"

# public/storage -> shared storage/app/public, so uploaded files keep resolving
# across releases.
sudo ln -sfn "${SHARED}/storage/app/public" "${RELEASE_DIR}/public/storage"

sudo chown -R "$OWNER" "$RELEASE_DIR"
sudo find "$RELEASE_DIR" -type d -exec chmod 755 {} +
sudo find "$RELEASE_DIR" -type f -exec chmod 644 {} +
sudo chmod 755 "${RELEASE_DIR}/artisan"

# -----------------------------------------------------------------------------
# 3. Warm caches INSIDE the new release, before it goes live. If caching fails
#    (a bad .env key, a blade syntax error) we find out now -- while the old
#    release is still serving traffic.
# -----------------------------------------------------------------------------
log "warming caches"
run_artisan() { sudo -u www-data php "${RELEASE_DIR}/artisan" "$@"; }

# ⚠⚠ DO NOT ADD `cache:clear` TO THIS SCRIPT. Read this before "tidying up".
#
# CACHE_STORE=database, and the application cache is not a performance detail
# here -- it holds child-safety state for the current day:
#
#   * app/Models/Child.php:88 puts the day's HANDOVER CODE PLAINTEXT in the
#     cache until 23:59. The database stores only a hash. That code is what a
#     guardian reads out at the kerb to collect their child (PART A7), and it is
#     the receiver verification behind Invariant #1.
#
#   * app/Services/FleetTripService.php:1200-1211 keeps the wrong-code attempt
#     counter there. Clearing it resets every keypad lockout.
#
#   * OtpService keeps issued login codes there.
#
# A deploy at 14:30 that ran `cache:clear` would delete every child's handover
# code mid-afternoon. Every drop stop would then fail its receiver check and
# fall through to the escalation ladder, and a bus full of children would be
# unable to release any of them. This is a four-line command away from being a
# genuine incident, which is why it is called out at this length.
#
# Config, route and view caches below are a DIFFERENT thing -- they live in
# bootstrap/cache inside the release directory, not in the application cache
# store, and clearing or rebuilding them touches no child data.

# ⚠ config:cache IS safe in this application, unlike zippi-backend where it is
# deliberately skipped.
#
# Laravel stops loading .env once config is cached: env() then returns NULL
# everywhere outside config/. zippi-backend has 23 such calls, which is why
# caching config there would silently break SMS, payments and every S3 URL.
#
# This codebase has ZERO env() calls outside config/ -- verified across app/,
# routes/, resources/views/ and bootstrap/. So the cache is both safe and worth
# having. If that ever stops being true, this line breaks the app silently and
# the /up health check will NOT catch it, because /up touches none of it.
#
# Re-check before assuming, with:
#   grep -rn "env(" app/ routes/ resources/views/ bootstrap/ | wc -l
run_artisan config:cache || { rollback; fail "config:cache failed -- bad .env or config syntax"; }

# route:cache works cleanly here. zippi-backend cannot cache routes because it
# has duplicate route names; this application has none, so a failure is a real
# regression and is treated as fatal.
run_artisan route:cache || { rollback; fail "route:cache failed -- duplicate route names?"; }

run_artisan view:cache  || { rollback; fail "view:cache failed -- blade syntax error"; }
run_artisan event:cache || true

# -----------------------------------------------------------------------------
# 4. Migrations -- the one genuinely irreversible step, which is why it runs only
#    after the caches proved the release is loadable.
#
#    ⚠ PART L1. service_date, school_calendars.date and every effective_from /
#    effective_to are DATE columns holding raw Y-m-d strings. A migration that
#    converts one to DATETIME, or a model change that adds a 'date' cast, shifts
#    every IST date back one calendar day -- silently. CI greps for the cast;
#    nothing here can catch it, so review date-column migrations by hand.
# -----------------------------------------------------------------------------
if [ "${SKIP_MIGRATIONS:-0}" = "1" ]; then
  log "SKIP_MIGRATIONS=1 -- not running migrations"
else
  log "running migrations"
  run_artisan migrate --force --no-interaction \
    || { rollback; fail "migration failed -- DB may be partially migrated, inspect before retrying"; }
fi

# -----------------------------------------------------------------------------
# 5. The atomic swap. `ln -sfn` to a temp name then `mv -Tf` is atomic at the
#    syscall level: no request ever observes a missing or half-written symlink.
# -----------------------------------------------------------------------------
log "switching live symlink -> $RELEASE_ID"
sudo ln -sfn "$RELEASE_DIR" "${CURRENT}.tmp"
sudo mv -Tf "${CURRENT}.tmp" "$CURRENT"

# php-fpm caches compiled files against the resolved path, so it must be told the
# path changed. `reload` finishes in-flight requests first, so no connection is
# dropped. This also clears OPcache for the retired release.
log "reloading $PHP_FPM"
sudo systemctl reload "$PHP_FPM" || { rollback; fail "php-fpm reload failed"; }

restart_workers "deploy"

# -----------------------------------------------------------------------------
# 6. Verify. A deploy that returns 500 is a failed deploy, not a finished one.
# -----------------------------------------------------------------------------
log "health check: $HEALTH_URL"
HEALTH_OK=0
for attempt in 1 2 3 4 5; do
  CODE="$(curl -sk "${CURL_RESOLVE[@]}" -o /dev/null -w '%{http_code}' --max-time 10 "$HEALTH_URL" || echo 000)"
  if [ "$CODE" = "200" ]; then
    HEALTH_OK=1
    log "health check passed (200)"
    break
  fi
  log "attempt $attempt: HTTP $CODE -- retrying in 3s"
  sleep 3
done

if [ "$HEALTH_OK" != "1" ]; then
  rollback
  fail "health check never returned 200 -- rolled back"
fi

# -----------------------------------------------------------------------------
# 6b. The scheduler. NOT fatal, but loud.
#
# PART M2 generates the coming school day's trips at 02:30 Asia/Kolkata via
# routes/console.php. That only fires if something on this box runs
# `schedule:run` every minute. If it is missing, the first anyone knows is an
# empty live board at 07:00 -- PART M11's just-in-time generation, the designed
# safety net for a missed cron, is NOT BUILT YET.
# -----------------------------------------------------------------------------
if sudo crontab -l 2>/dev/null | grep -q "${CURRENT}/artisan schedule:run"; then
  log "scheduler cron present"
else
  log "WARNING: no 'schedule:run' cron found for ${CURRENT}."
  log "         PART M2 trip generation will NOT fire at 02:30 IST, and there is"
  log "         no JIT fallback yet -- a missed run means no trips until someone"
  log "         runs 'php artisan school:generate-trips' by hand."
  log "         Fix: deploy/bootstrap-server.sh installs this cron."
fi

# -----------------------------------------------------------------------------
# 7. Prune. Keep enough history to roll back through a bad run of deploys.
# -----------------------------------------------------------------------------
log "pruning old releases (keeping $KEEP_RELEASES)"
LIVE="$(readlink -f "$CURRENT")"
cd "$RELEASES"
ls -1dt */ 2>/dev/null | tail -n +$((KEEP_RELEASES + 1)) | while read -r old; do
  # Never delete the release that is currently live.
  [ "$(readlink -f "$old")" = "$LIVE" ] && continue
  log "  removing $old"
  sudo rm -rf "$old"
done

log "SUCCESS -- $ENVIRONMENT is now running $RELEASE_ID"
