#!/bin/bash
# Uninstall check of local_lticompletionsync on SEPARATE scratch databases and dataroots (never the shared main DBs).
# run.sh <45|53> [pgsql|mariadb ...]  -> writes JSON results to $OUT (default /home/claude/work/uninstall-check)
set -uo pipefail
TREE=$1; shift; DBS=("$@"); [ ${#DBS[@]} = 0 ] && DBS=(pgsql mariadb)
HERE=$(cd "$(dirname "$0")" && pwd)
case $TREE in 45) ROOT=/home/claude/moodle; PGPORT=5432;; 53) ROOT=/home/claude/moodle53; PGPORT=5433;; *) exit 2;; esac
OUT=${OUT:-/tmp/csync-uninstall}; mkdir -p $OUT
export CSYNC_ROOT=$ROOT COMPOSER_ALLOW_SUPERUSER=1
for db in "${DBS[@]}"; do
  export LTI_DB=$db LTI_DBNAME=csync_unin_$TREE LTI_BASE=/home/claude/data/csync_unin_${TREE}_$db
  if [ $db = pgsql ]; then
    PGPASSWORD=moodle psql -h 127.0.0.1 -p $PGPORT -U moodle -d postgres -Atc "DROP DATABASE IF EXISTS $LTI_DBNAME" >/dev/null
    PGPASSWORD=moodle psql -h 127.0.0.1 -p $PGPORT -U moodle -d postgres -Atc "CREATE DATABASE $LTI_DBNAME OWNER moodle ENCODING 'UTF8' LC_COLLATE 'C.UTF-8' LC_CTYPE 'C.UTF-8' TEMPLATE template0" >/dev/null
  else
    mariadb -h127.0.0.1 -umoodle -pmoodle -e "DROP DATABASE IF EXISTS $LTI_DBNAME; CREATE DATABASE $LTI_DBNAME DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
  fi
  rm -rf $LTI_BASE; mkdir -p $LTI_BASE/data; chmod -R 777 $LTI_BASE
  # The tree's plugin code must not change during the check: hold the runner lock of this tree/db.
  exec 9>/tmp/ltirun_${TREE}_${db}.lock; flock 9
  R=$OUT/${TREE}_$db; mkdir -p $R
  (cd $ROOT && php admin/cli/install_database.php --agree-license --lang=en --adminuser=admin --adminpass='Unin#Admin2026' \
    --adminemail=admin@example.org --fullname='csync uninstall check' --shortname=csyncsite > $R/install.log 2>&1); echo "install rc=$?"
  php $HERE/seed.php > $R/seed.json 2> $R/seed.err; echo "seed: $(tail -1 $R/seed.json)"
  CM=$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo implode(",", $d["cmids"]);' $R/seed.json)
  ST=$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo $d["student"];' $R/seed.json)
  (cd $ROOT && php admin/cli/uninstall_plugins.php --plugins=local_lticompletionsync --run > $R/uninstall.log 2>&1); echo "uninstall rc=$?"
  php $HERE/leftovers.php $CM $ST > $R/leftovers.json 2> $R/leftovers.err; echo "leftovers: $(tail -1 $R/leftovers.json)"
  exec 9>&-
  if [ $db = pgsql ]; then PGPASSWORD=moodle psql -h 127.0.0.1 -p $PGPORT -U moodle -d postgres -Atc "DROP DATABASE IF EXISTS $LTI_DBNAME" >/dev/null
  else mariadb -h127.0.0.1 -umoodle -pmoodle -e "DROP DATABASE IF EXISTS $LTI_DBNAME"; fi
  rm -rf $LTI_BASE
done
