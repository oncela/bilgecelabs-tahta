#!/usr/bin/env bash
# Tüm panel testlerini geçici bir ortamda çalıştırır: ayrı test veritabanı,
# panelin kopyası ve 127.0.0.1'de geçici PHP sunucusu. Canlı sisteme dokunmaz.
# Kullanım: bash panel/tests/run.sh   (veya: make test)  — sudo ile MariaDB'ye erişir.
set -euo pipefail
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
PORT="${ACI_TEST_PORT:-8099}"
DB=aci_tahta_test DBU=aci_test
T="$(mktemp -d)"
DBP="$(openssl rand -hex 12)"; AP="$(openssl rand -hex 10)"
cleanup() {
  [[ -n "${SRV:-}" ]] && kill "$SRV" 2>/dev/null || true
  sudo mysql -e "DROP DATABASE IF EXISTS $DB; DROP USER IF EXISTS '$DBU'@'localhost';" || true
  rm -rf "$T"
}
trap cleanup EXIT

cp -r "$REPO/panel/." "$T/"
sudo mysql -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB CHARACTER SET utf8mb4;
  CREATE USER IF NOT EXISTS '$DBU'@'localhost' IDENTIFIED BY '$DBP'; ALTER USER '$DBU'@'localhost' IDENTIFIED BY '$DBP';
  GRANT ALL ON $DB.* TO '$DBU'@'localhost';"
cat > "$T/.env" <<ENV
ACI_DB_DSN=mysql:host=127.0.0.1;dbname=$DB;charset=utf8mb4
ACI_DB_USER=$DBU
ACI_DB_PASS=$DBP
ACI_BASE_URL=https://tahta.bilgecelabs.com
ACI_COOKIE_SECURE=0
ENV
php "$T/tools/migrate.php" >/dev/null
echo "$AP" | php "$T/tools/create_admin.php" test >/dev/null 2>&1
KEY=$(cd "$T" && php -r 'require "src/bootstrap.php"; echo setting("enroll_key");')
php -S "127.0.0.1:$PORT" -t "$T/public" >/dev/null 2>&1 & SRV=$!
sleep 1

export ACI_TEST_URL="http://127.0.0.1:$PORT" ACI_TEST_USER=test ACI_TEST_PASS="$AP" ACI_TEST_ENROLL_KEY="$KEY"
rc=0
for s in smoke features; do bash "$REPO/panel/tests/$s.sh" || rc=1; echo; done
exit $rc
