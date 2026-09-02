#!/usr/bin/env bash
#
# ONE-TIME provisioning for a Zippi School environment. Run once per environment
# on the server, then never again -- after this, deploy.sh does everything.
#
#   Usage:
#     ./bootstrap-server.sh staging              # DRY RUN, prints the plan only
#     ./bootstrap-server.sh staging   --apply    # actually do it
#     ./bootstrap-server.sh production --apply   # only after staging succeeded
#
# ⚠ This is a GREENFIELD provision, NOT the migration script of the same name in
# zippintella-dev/zippi-backend. That one converts an existing flat deployment
# into the release layout; this app has never been deployed, so there is nothing
# to convert. The layout it creates is identical on purpose.
#
# It creates:
#   /var/www/html/<app>-releases/            release directories
#   /var/www/html/<app>-shared/.env          configuration, survives deploys
#   /var/www/html/<app>-shared/storage/      uploads + sweep photos, ditto
#   an nginx server block                    (printed, not installed -- see below)
#   a schedule:run cron                      PART M2 trip generation
#
# It does NOT create the MySQL database or the TLS certificate. Both are printed
# as commands for you to run and check.

set -euo pipefail

ENVIRONMENT="${1:?usage: bootstrap-server.sh <staging|production> [--apply]}"
APPLY="${2:-}"

# Must match deploy.sh exactly.
case "$ENVIRONMENT" in
  production) APP_DIR="school";         HEALTH_HOST="school.zippi.in";         DB_NAME="zippi_school" ;;
  staging)    APP_DIR="school-staging"; HEALTH_HOST="school-staging.zippi.in"; DB_NAME="zippi_school_staging" ;;
  *) echo "FATAL: unknown environment '$ENVIRONMENT'" >&2; exit 2 ;;
esac

WEB_ROOT="/var/www/html"
CURRENT="${WEB_ROOT}/${APP_DIR}"
RELEASES="${WEB_ROOT}/${APP_DIR}-releases"
SHARED="${WEB_ROOT}/${APP_DIR}-shared"
OWNER="www-data:www-data"
PHP_FPM="php8.4-fpm"

DRY=1
[ "$APPLY" = "--apply" ] && DRY=0

run() {
  if [ "$DRY" = "1" ]; then
    printf '  DRY-RUN would: %s\n' "$*"
  else
    printf '  + %s\n' "$*"
    eval "$@"
  fi
}

echo "=============================================================="
echo " bootstrap $ENVIRONMENT  ($([ "$DRY" = 1 ] && echo 'DRY RUN' || echo 'APPLYING FOR REAL'))"
echo "   app dir : $APP_DIR"
echo "   host    : $HEALTH_HOST"
echo "   database: $DB_NAME"
echo "=============================================================="

# -----------------------------------------------------------------------------
# Refuse to run twice. Re-running would reset the shared .env and could orphan a
# live release.
# -----------------------------------------------------------------------------
if [ -e "$CURRENT" ] || [ -d "$SHARED" ]; then
  echo ""
  echo "ABORT: $CURRENT or $SHARED already exists -- this environment is already"
  echo "       provisioned. deploy.sh handles everything from here."
  echo ""
  echo "       If you genuinely need to re-provision, move the old tree aside by"
  echo "       hand first so it can be restored:"
  echo "         sudo mv $SHARED ${SHARED}.old-$(date +%Y%m%d-%H%M%S)"
  exit 1
fi

# ⚠ Collision guard. zippi-backend already occupies /var/www/html/staging and
# /var/www/html/zippi on this host. Provisioning this app over either of those
# would take down the ride platform.
for taken in "${WEB_ROOT}/staging" "${WEB_ROOT}/zippi"; do
  if [ "$CURRENT" = "$taken" ]; then
    echo "ABORT: $CURRENT belongs to zippi-backend. Change APP_DIR in this script"
    echo "       and in deploy.sh and rollback.sh -- all three must agree."
    exit 1
  fi
done

echo ""
echo "[1/6] release and shared directories"
run "sudo mkdir -p '$RELEASES'"
run "sudo mkdir -p '$SHARED'"

echo ""
echo "[2/6] shared storage skeleton"
# ⚠ storage/app/private/sweeps holds the Invariant #3 sweep photographs: the
# timestamped, geo-stamped evidence that a bus was physically checked before its
# trip closed. It lives in SHARED precisely so a deploy cannot destroy it.
for d in \
  app/public app/private/sweeps app/backups \
  framework/cache/data framework/sessions framework/views framework/testing \
  logs
do
  run "sudo mkdir -p '${SHARED}/storage/${d}'"
done
run "sudo chown -R $OWNER '$SHARED'"
run "sudo find '${SHARED}/storage' -type d -exec chmod 775 {} +"

echo ""
echo "[3/6] shared .env"
if [ "$DRY" = "1" ]; then
  echo "  DRY-RUN would: write ${SHARED}/.env from the template below"
else
  sudo tee "${SHARED}/.env" >/dev/null <<ENVEOF
APP_NAME="Zippi School Mobility"
APP_ENV=$([ "$ENVIRONMENT" = "production" ] && echo production || echo staging)
APP_KEY=
APP_DEBUG=$([ "$ENVIRONMENT" = "production" ] && echo false || echo true)
APP_URL=https://${HEALTH_HOST}

# ⚠ PART L1 AND THE FOUR KEYS BOTH DEPEND ON THIS BEING IST.
#
# Every operational row is keyed by service_date, a raw Y-m-d string. The
# scheduler generates the coming day's trips at 02:30 Asia/Kolkata. A server left
# on UTC fires that at 08:00 IST -- two bells into the morning it was meant to
# plan -- and Controller::today() resolves the wrong school day.
APP_TIMEZONE=Asia/Kolkata

LOG_CHANNEL=stack
LOG_LEVEL=$([ "$ENVIRONMENT" = "production" ] && echo warning || echo debug)

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=${DB_NAME}
DB_USERNAME=CHANGE_ME
DB_PASSWORD=CHANGE_ME

# ⚠ CACHE_STORE IS NOT A PERFORMANCE SETTING IN THIS APPLICATION.
#
# The day's handover-code plaintext lives in the cache (Child.php), as do the
# keypad lockout counters and issued OTPs. Whatever store this points at must be
# persistent for the school day and must NOT be shared with another application
# that might flush it. Do not set this to 'array'.
CACHE_STORE=database

SESSION_DRIVER=database
SESSION_LIFETIME=120
QUEUE_CONNECTION=database

# Not wired yet -- no SMS gateway. OTPs go to storage/logs/laravel.log.
# ⚠ Read PART P2 before wiring one: both carried-forward bugs (empty DLT
# template id, and ltrim(\$mobile,'91') eating leading 9s and 1s) are SILENT and
# the gateway reports success either way.
ENVEOF
  sudo chown "$OWNER" "${SHARED}/.env"
  sudo chmod 640 "${SHARED}/.env"
  echo "  + wrote ${SHARED}/.env  (DB_USERNAME/DB_PASSWORD still CHANGE_ME)"
fi

echo ""
echo "[4/6] PART M2 scheduler cron"
# Without this, trips are never generated. There is no just-in-time fallback yet
# (PART M11 is not built), so a missing cron means an empty live board at 07:00
# and no buses scheduled at all.
CRON_LINE="* * * * * cd ${CURRENT} && php artisan schedule:run >> /dev/null 2>&1"
if [ "$DRY" = "1" ]; then
  echo "  DRY-RUN would: install root cron -> $CRON_LINE"
else
  ( sudo crontab -l 2>/dev/null || true; echo "$CRON_LINE" ) | sudo crontab -
  echo "  + installed: $CRON_LINE"
fi

echo ""
echo "[5/6] things this script will NOT do for you"
cat <<MANUAL

  a) Create the database and its user:

       sudo mysql -e "CREATE DATABASE ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
       sudo mysql -e "CREATE USER '${DB_NAME}'@'127.0.0.1' IDENTIFIED BY '<a real password>';"
       sudo mysql -e "GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_NAME}'@'127.0.0.1';"
       sudo mysql -e "FLUSH PRIVILEGES;"

     Then put that user and password into ${SHARED}/.env.

  b) Generate the app key (ONCE -- changing it later invalidates every session
     and every encrypted value):

       cd ${SHARED} && sudo -u www-data php ${CURRENT}/artisan key:generate --force

     ⚠ On a FIRST provision the release does not exist yet, so run this after
     the first deploy, then re-run the deploy so the config cache picks it up.

  c) An nginx server block for ${HEALTH_HOST}. The one line that matters:

       root ${CURRENT}/public;
       fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;

     \$realpath_root resolves the symlink PER REQUEST. Without it, php-fpm keeps
     serving the old release out of its path cache after the atomic swap and
     deploys appear to do nothing.

  d) A TLS certificate for ${HEALTH_HOST}, and the Cloudflare DNS record.

     deploy.sh health-checks over https with --resolve to loopback, so the cert
     must be valid ON THE BOX, not only at Cloudflare's edge.

MANUAL

echo ""
echo "[6/6] summary"
echo "  layout ready at: $RELEASES  and  $SHARED"
echo "  live symlink   : $CURRENT   (created by the first deploy, not here)"
echo "  php-fpm        : $PHP_FPM"
if [ "$DRY" = "1" ]; then
  echo ""
  echo "  This was a DRY RUN. Nothing changed. Re-run with --apply."
fi
