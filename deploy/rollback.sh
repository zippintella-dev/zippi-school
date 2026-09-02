#!/usr/bin/env bash
#
# Roll an environment back to a previous release.
#
# deploy.sh already rolls back automatically when a deploy fails its own health
# check. This script is for the other case: the deploy succeeded, the health
# check passed, and the breakage showed up minutes later in real traffic.
#
#   Usage:
#     ./rollback.sh staging                 # list releases, roll back one step
#     ./rollback.sh production <release-id> # roll back to a specific release
#
# ⚠ WHAT THIS DOES NOT DO: reverse database migrations. If the bad release
# migrated the schema, moving the code back may leave the app running against a
# schema it does not expect. Check what the release migrated BEFORE rolling back
# -- the safest fix for a bad migration is usually a forward fix.
#
# ⚠ PART L1 makes that especially true here. A migration that altered a DATE
# column (service_date, school_calendars.date, any effective_from/effective_to)
# may have rewritten stored values. Rolling the CODE back does not roll the DATA
# back, and a date shifted by one day presents as "I marked her absent and the
# bus still waited" days later.

set -euo pipefail

ENVIRONMENT="${1:?usage: rollback.sh <staging|production> [release-id]}"
TARGET_RELEASE="${2:-}"

# Must match deploy.sh exactly.
case "$ENVIRONMENT" in
  production) APP_DIR="school";         HEALTH_HOST="school.zippi.in";         WORKER="zippi-school-worker" ;;
  staging)    APP_DIR="school-staging"; HEALTH_HOST="school-staging.zippi.in"; WORKER="zippi-school-staging-worker" ;;
  *) echo "FATAL: unknown environment '$ENVIRONMENT'" >&2; exit 2 ;;
esac

# Cloudflare fronts both hostnames and challenges curl with a 403, so resolve the
# name to loopback and hit nginx on this box directly. See deploy.sh.
HEALTH_URL="https://${HEALTH_HOST}/up"
CURL_RESOLVE=(--resolve "${HEALTH_HOST}:443:127.0.0.1")

WEB_ROOT="/var/www/html"
CURRENT="${WEB_ROOT}/${APP_DIR}"
RELEASES="${WEB_ROOT}/${APP_DIR}-releases"
PHP_FPM="php8.4-fpm"

[ -L "$CURRENT" ] || { echo "FATAL: $CURRENT is not a symlink -- host is not on the release layout"; exit 1; }

LIVE="$(readlink -f "$CURRENT")"
LIVE_ID="$(basename "$LIVE")"

echo "Environment : $ENVIRONMENT"
echo "Live now    : $LIVE_ID"
echo ""
echo "Available releases (newest first):"
cd "$RELEASES"
mapfile -t AVAILABLE < <(ls -1dt */ 2>/dev/null | sed 's#/$##')
for r in "${AVAILABLE[@]}"; do
  MARK=" "
  [ "$r" = "$LIVE_ID" ] && MARK="*"
  BUILT="$(head -4 "${RELEASES}/${r}/RELEASE" 2>/dev/null | tr '\n' ' ' || echo '')"
  printf '  %s %-28s %s\n' "$MARK" "$r" "$BUILT"
done
echo "  (* = currently live)"
echo ""

# Default target: the release immediately older than the live one.
if [ -z "$TARGET_RELEASE" ]; then
  FOUND_LIVE=0
  for r in "${AVAILABLE[@]}"; do
    if [ "$FOUND_LIVE" = "1" ]; then TARGET_RELEASE="$r"; break; fi
    [ "$r" = "$LIVE_ID" ] && FOUND_LIVE=1
  done
fi

[ -n "$TARGET_RELEASE" ] || { echo "FATAL: no older release to roll back to"; exit 1; }
[ -d "${RELEASES}/${TARGET_RELEASE}" ] || { echo "FATAL: release not found: $TARGET_RELEASE"; exit 1; }
[ "$TARGET_RELEASE" = "$LIVE_ID" ] && { echo "Target is already live. Nothing to do."; exit 0; }

echo "About to roll back:  $LIVE_ID  ->  $TARGET_RELEASE"
echo ""
echo "Migrations run since the target release will NOT be reversed."
if [ "$ENVIRONMENT" = "production" ]; then
  echo ""
  echo "⚠ Is a run in progress? Rolling back during 07:00-09:30 or 14:00-16:30 IST"
  echo "  means doing this while children are on buses. If a trip is mid-flight,"
  echo "  check the live board first."
fi
read -r -p "Type the target release id to confirm: " CONFIRM
[ "$CONFIRM" = "$TARGET_RELEASE" ] || { echo "Confirmation did not match. Aborted."; exit 1; }

echo "[1/3] switching symlink"
sudo ln -sfn "${RELEASES}/${TARGET_RELEASE}" "${CURRENT}.tmp"
sudo mv -Tf "${CURRENT}.tmp" "$CURRENT"

echo "[2/3] reloading php-fpm and the queue workers"
sudo systemctl reload "$PHP_FPM"

# The target MUST be "${WORKER}:*", not "$WORKER" -- with numprocs>1 supervisor
# creates a process GROUP and the bare name is not addressable. ":*" is also
# correct for numprocs=1.
#
# Tolerant of the group not existing: a worker may not be provisioned for this
# app yet. But if it DOES exist and the restart fails, say so loudly -- this is
# the script that runs during an incident, and silently leaving workers on the
# release you just rolled back FROM is a split state that is harder to diagnose
# than the original fault.
if sudo supervisorctl status "${WORKER}:*" >/dev/null 2>&1; then
  echo "      restarting ${WORKER}:*"
  if sudo supervisorctl restart "${WORKER}:*"; then
    RUNNING="$(sudo supervisorctl status "${WORKER}:*" 2>/dev/null | grep -c RUNNING || true)"
    if [ "${RUNNING:-0}" -gt 0 ]; then
      echo "      $RUNNING worker(s) running on $TARGET_RELEASE"
    else
      echo "  !!  ${WORKER}:* restarted but NO process is RUNNING."
      echo "      Check: sudo supervisorctl status '${WORKER}:*'"
    fi
  else
    echo "  !!  WORKER RESTART FAILED. The workers are probably still running the"
    echo "      release you just rolled back FROM. Web traffic is on"
    echo "      $TARGET_RELEASE but jobs are not. Fix this by hand now:"
    echo "        sudo supervisorctl restart '${WORKER}:*'"
  fi
else
  echo "      no supervisor group ${WORKER}:* on this host -- nothing to restart"
fi

echo "[3/3] health check"
sleep 2
for attempt in 1 2 3 4 5; do
  CODE="$(curl -sk "${CURL_RESOLVE[@]}" -o /dev/null -w '%{http_code}' --max-time 10 "$HEALTH_URL" || echo 000)"
  [ "$CODE" = "200" ] && { echo "  OK (200) -- $TARGET_RELEASE is live"; exit 0; }
  echo "  attempt $attempt: HTTP $CODE"
  sleep 3
done

echo ""
echo "!! Rolled back, but the health check is still failing."
echo "   The problem is probably NOT the application code -- check the database,"
echo "   connectivity, and storage/logs/laravel.log on the server."
exit 1
