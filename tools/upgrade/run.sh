#!/bin/bash
# Install / upgrade / rollback check of local_lticompletionsync on a SEPARATE scratch database (never the shared DBs):
#   1. install the last delivered release (0.1.0, 2026102601, from $OLD) on a fresh site and seed both roles,
#   2. dump the database, upgrade to the working copy (0.2.0), check the schema against install.xml,
#   3. roll back: restore the dump with the 0.1.0 code; the site must report 0.1.0 and need no upgrade.
# run.sh <45|53> <pgsql|mariadb>   env: OLD=/home/claude/gh/moodle-local_lticompletionsync OUT=/tmp/csync-upgrade
# Holds BOTH runner locks of the tree (the plugin code of the shared tree is swapped) and always restores the new code.
set -uo pipefail
TREE=$1; db=$2
HERE=$(cd "$(dirname "$0")" && pwd); NEW=$(cd "$HERE/../.." && pwd)
OLD=${OLD:-/home/claude/gh/moodle-local_lticompletionsync}
case $TREE in 45) ROOT=/home/claude/moodle; WEB=$ROOT; PGPORT=5432; PGBIN=/usr/lib/postgresql/16/bin;;
  53) ROOT=/home/claude/moodle53; WEB=$ROOT/public; PGPORT=5433; PGBIN=/usr/lib/postgresql/17/bin;; *) exit 2;; esac
DEST=$WEB/local/lticompletionsync
OUT=${OUT:-/tmp/csync-upgrade}/${TREE}_$db; rm -rf "$OUT"; mkdir -p "$OUT"
export CSYNC_ROOT=$ROOT COMPOSER_ALLOW_SUPERUSER=1 LTI_DB=$db LTI_DBNAME=csync_upg_$TREE LTI_BASE=/home/claude/data/csync_upg_${TREE}_$db
exec 8>/tmp/ltirun_${TREE}_pgsql.lock; exec 9>/tmp/ltirun_${TREE}_mariadb.lock
echo "waiting for the locks of tree $TREE"; flock 8; flock 9; echo "locks acquired"
put() { rsync -a --delete --exclude=.git --exclude=node_modules "$1/" "$DEST/"; }
trap 'put "$NEW"; echo "new code restored"' EXIT
mkdb() {
  if [ $db = pgsql ]; then
    PGPASSWORD=moodle psql -h 127.0.0.1 -p $PGPORT -U moodle -d postgres -Atc "DROP DATABASE IF EXISTS $LTI_DBNAME" >/dev/null 2>&1
    PGPASSWORD=moodle psql -h 127.0.0.1 -p $PGPORT -U moodle -d postgres -Atc "CREATE DATABASE $LTI_DBNAME OWNER moodle ENCODING 'UTF8' LC_COLLATE 'C.UTF-8' LC_CTYPE 'C.UTF-8' TEMPLATE template0" >/dev/null
  else mariadb -h127.0.0.1 -umoodle -pmoodle -e "DROP DATABASE IF EXISTS $LTI_DBNAME; CREATE DATABASE $LTI_DBNAME DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"; fi
}
dump() { if [ $db = pgsql ]; then PGPASSWORD=moodle $PGBIN/pg_dump -h 127.0.0.1 -p $PGPORT -U moodle -Fc -f "$OUT/db.dump" $LTI_DBNAME
  else mariadb-dump -h127.0.0.1 -umoodle -pmoodle --single-transaction $LTI_DBNAME > "$OUT/db.sql"; fi; }
restore() { mkdb; if [ $db = pgsql ]; then PGPASSWORD=moodle $PGBIN/pg_restore -h 127.0.0.1 -p $PGPORT -U moodle -d $LTI_DBNAME "$OUT/db.dump"
  else mariadb -h127.0.0.1 -umoodle -pmoodle $LTI_DBNAME < "$OUT/db.sql"; fi; }
state() { php "$HERE/state.php" > "$OUT/$1.json" 2> "$OUT/$1.err"; echo "$1: $(cat "$OUT/$1.json")"; }

put "$OLD"; mkdb; rm -rf "$LTI_BASE"; mkdir -p "$LTI_BASE/data"; chmod -R 777 "$LTI_BASE"
(cd $ROOT && php admin/cli/install_database.php --agree-license --lang=en --adminuser=admin --adminpass='Upg#Admin2026' \
  --adminemail=admin@example.org --fullname='csync upgrade check' --shortname=csyncupg > "$OUT/install.log" 2>&1); echo "install 0.1.0 rc=$?"
php "$OLD/tools/uninstall/seed.php" > "$OUT/seed.json" 2> "$OUT/seed.err"; echo "seed rc=$? $(tail -1 "$OUT/seed.json")"
state before
cp -a "$LTI_BASE" "$OUT/dataroot.bak"; dump; echo "dump rc=$?"
put "$NEW"
(cd $ROOT && php admin/cli/upgrade.php --non-interactive > "$OUT/upgrade.log" 2>&1); echo "upgrade rc=$?"; grep -i "lticompletionsync\|error" "$OUT/upgrade.log" | head -5
(cd $ROOT && php admin/cli/check_database_schema.php > "$OUT/schema.log" 2>&1); echo "schema check rc=$? ($(grep -c . "$OUT/schema.log") lines)"; head -5 "$OUT/schema.log"
state after
restore; echo "restore rc=$?"; rm -rf "$LTI_BASE"; cp -a "$OUT/dataroot.bak" "$LTI_BASE"
put "$OLD"
(cd $ROOT && php admin/cli/upgrade.php --non-interactive > "$OUT/rollback-upgrade.log" 2>&1); echo "rollback upgrade.php rc=$?: $(grep -v '^$' "$OUT/rollback-upgrade.log" | tail -1)"
state rollback
(cd $ROOT && php "$DEST/cli/sync.php" --status > "$OUT/rollback-status.txt" 2>&1); echo "0.1.0 CLI after rollback rc=$?: $(head -3 "$OUT/rollback-status.txt" | tr '\n' ' ')"
put "$NEW"
if [ $db = pgsql ]; then PGPASSWORD=moodle psql -h 127.0.0.1 -p $PGPORT -U moodle -d postgres -Atc "DROP DATABASE IF EXISTS $LTI_DBNAME" >/dev/null
else mariadb -h127.0.0.1 -umoodle -pmoodle -e "DROP DATABASE IF EXISTS $LTI_DBNAME"; fi
rm -rf "$LTI_BASE" "$OUT/dataroot.bak"
