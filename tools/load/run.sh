#!/bin/bash
# Load test of local_lticompletionsync (CS-04) on SEPARATE scratch databases and dataroots (never the shared main DBs).
# run.sh <45|53> [pgsql|mariadb ...]   env: USERS=10000 RESOURCES=100 LINKS=2 OUT=/tmp/csync-load KEEP=0
# Holds the runner lock of the tree/db (the tree's plugin code must not change during the run).
set -uo pipefail
TREE=$1; shift; DBS=("$@"); [ ${#DBS[@]} = 0 ] && DBS=(pgsql mariadb)
HERE=$(cd "$(dirname "$0")" && pwd)
case $TREE in 45) ROOT=/home/claude/moodle; PGPORT=5432;; 53) ROOT=/home/claude/moodle53; PGPORT=5433;; *) exit 2;; esac
OUT=${OUT:-/tmp/csync-load}; mkdir -p "$OUT"
export CSYNC_ROOT=$ROOT COMPOSER_ALLOW_SUPERUSER=1
for db in "${DBS[@]}"; do
  export LTI_DB=$db LTI_DBNAME=csync_load_$TREE LTI_BASE=/home/claude/data/csync_load_${TREE}_$db
  R=$OUT/${TREE}_$db; rm -rf "$R"; mkdir -p "$R"
  exec 9>/tmp/ltirun_${TREE}_${db}.lock; echo "[$TREE/$db] waiting for lock"; flock 9; echo "[$TREE/$db] lock acquired"
  if [ $db = pgsql ]; then
    PGPASSWORD=moodle psql -h 127.0.0.1 -p $PGPORT -U moodle -d postgres -Atc "DROP DATABASE IF EXISTS $LTI_DBNAME" >/dev/null
    PGPASSWORD=moodle psql -h 127.0.0.1 -p $PGPORT -U moodle -d postgres -Atc "CREATE DATABASE $LTI_DBNAME OWNER moodle ENCODING 'UTF8' LC_COLLATE 'C.UTF-8' LC_CTYPE 'C.UTF-8' TEMPLATE template0" >/dev/null
  else
    mariadb -h127.0.0.1 -umoodle -pmoodle -e "DROP DATABASE IF EXISTS $LTI_DBNAME; CREATE DATABASE $LTI_DBNAME DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
  fi
  rm -rf "$LTI_BASE"; mkdir -p "$LTI_BASE/data"; chmod -R 777 "$LTI_BASE"
  t0=$(date +%s)
  (cd $ROOT && php admin/cli/install_database.php --agree-license --lang=en --adminuser=admin --adminpass='Load#Admin2026' \
    --adminemail=admin@example.org --fullname='csync load test' --shortname=csyncload > "$R/install.log" 2>&1); echo "[$TREE/$db] install rc=$? ($(( $(date +%s) - t0 )) s)"
  php "$HERE/seed.php" --users=${USERS:-10000} --resources=${RESOURCES:-100} --links=${LINKS:-2} > "$R/seed.json" 2> "$R/seed.err"
  echo "[$TREE/$db] seed: $(tail -1 "$R/seed.json")"
  CM=$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo (int)($d["platformcmid"] ?? 0);' "$R/seed.json")
  if [ $db = pgsql ]; then PGPASSWORD=moodle psql -h 127.0.0.1 -p $PGPORT -U moodle -d $LTI_DBNAME -Atc "VACUUM ANALYZE" >/dev/null
  else mariadb -h127.0.0.1 -umoodle -pmoodle $LTI_DBNAME -e "ANALYZE TABLE mdl_enrol_lti_users, mdl_enrol_lti_user_resource_link, mdl_course_modules_completion, mdl_local_lticompletionsync_out" >/dev/null; fi
  php "$HERE/measure.php" --out="$R" --platformcmid=$CM > "$R/measure.txt" 2> "$R/measure.err"; echo "[$TREE/$db] measure rc=$?"
  cat "$R/measure.txt"
  exec 9>&-
  if [ "${KEEP:-0}" != 1 ]; then
    if [ $db = pgsql ]; then PGPASSWORD=moodle psql -h 127.0.0.1 -p $PGPORT -U moodle -d postgres -Atc "DROP DATABASE IF EXISTS $LTI_DBNAME" >/dev/null
    else mariadb -h127.0.0.1 -umoodle -pmoodle -e "DROP DATABASE IF EXISTS $LTI_DBNAME"; fi
    rm -rf "$LTI_BASE"
  fi
done
