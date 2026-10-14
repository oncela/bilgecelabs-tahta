#!/usr/bin/env bash
# Acı Tahta sunucu kurulumu / güncellemesi.
#
#   sudo ACI_BASE_URL=https://tahta.bilgecelabs.com deploy/install.sh
#
# Tekrar çalıştırılabilir: kurulu olanı atlar, .env ve veritabanını korur,
# panel dosyalarını günceller ve veritabanı güncellemelerini uygular.
# Diğer sitelere (otel portalları) dokunmaz: ayrı kullanıcı, PHP havuzu,
# nginx sitesi ve veritabanı kullanır.
set -euo pipefail

REPO="$(cd "$(dirname "$0")/.." && pwd)"
APP=/var/www/aci-tahta
APPUSER=aci-tahta
DB=aci_tahta
PORT=8082
BASE_URL="${ACI_BASE_URL:-https://tahta.bilgecelabs.com}"
PHPV=8.1

step() { printf '\n\033[1;34m==> %s\033[0m\n' "$1"; }
info() { printf '    %s\n' "$1"; }
die()  { printf '\033[31mHATA: %s\033[0m\n' "$1" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "root olarak çalıştırın (sudo)."

step "Önkoşullar"
for c in nginx "php-fpm$PHPV" php mariadb rsync curl; do
  command -v "$c" >/dev/null || die "$c bulunamadı."
done
PHPMODS="$(php -m)"   # önce al: grep -q ile boru kullanmak pipefail'de yanlış hata verir
for e in pdo_mysql openssl curl mbstring; do
  grep -qix "$e" <<<"$PHPMODS" || die "PHP eklentisi eksik: $e"
done
[[ -x /opt/node/bin/node ]] || info "Uyarı: /opt/node yok; uzaktan erişim komut kanalı çalışmaz."
[[ -f /opt/meshcentral/node_modules/meshcentral/meshctrl.js ]] || info "Uyarı: MeshCentral kurulu değil."
[[ -f "$REPO/panel/storage/aci-ajan.exe" ]] || die "Ajan derlenmemiş: önce 'make release' çalıştırın."
info "tamam"

step "Sistem kullanıcısı ($APPUSER)"
if id "$APPUSER" >/dev/null 2>&1; then info "zaten var"; else
  useradd --system --home "$APP" --shell /usr/sbin/nologin "$APPUSER"; info "oluşturuldu"
fi
install -d -o "$APPUSER" -g "$APPUSER" -m 0750 /var/lib/aci-tahta /var/lib/aci-tahta/sessions /var/log/aci-tahta

step "Panel dosyaları ($APP)"
install -d -o root -g "$APPUSER" -m 0750 "$APP"
rsync -a --delete --exclude '.env' --exclude 'storage/' --exclude 'tests/' "$REPO/panel/" "$APP/"
install -d -o "$APPUSER" -g "$APPUSER" -m 0750 "$APP/storage"
install -o root -g "$APPUSER" -m 0640 "$REPO/panel/storage/aci-ajan.exe" "$APP/storage/aci-ajan.exe"
[[ -f "$REPO/panel/storage/aci-ajan.version" ]] && install -o root -g "$APPUSER" -m 0640 "$REPO/panel/storage/aci-ajan.version" "$APP/storage/"
chown -R root:"$APPUSER" "$APP"
find "$APP" -path "$APP/storage" -prune -o -type d -exec chmod 0750 {} + -o -type f -exec chmod 0640 {} +
chown -R "$APPUSER":"$APPUSER" "$APP/storage"
# nginx (www-data) statik dosyaları doğrudan sunar: yalnız public/ herkese okunur,
# .env ve kaynak kod panel kullanıcısına özel kalır.
chmod 0751 "$APP"
find "$APP/public" -type d -exec chmod 0755 {} + -o -type f -exec chmod 0644 {} +
info "güncellendi ($(cat "$APP/storage/aci-ajan.version" 2>/dev/null || echo '?') sürüm ajan)"

step "Veritabanı ($DB)"
if [[ -f "$APP/.env" ]]; then
  info ".env var, veritabanı ayarları korunuyor"
else
  DBPASS="$(openssl rand -base64 30 | tr -d '/+=' | cut -c1-32)"
  mariadb -e "CREATE DATABASE IF NOT EXISTS \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_turkish_ci;
              CREATE USER IF NOT EXISTS '$APPUSER'@'localhost' IDENTIFIED BY '$DBPASS';
              ALTER USER '$APPUSER'@'localhost' IDENTIFIED BY '$DBPASS';
              GRANT ALL PRIVILEGES ON \`$DB\`.* TO '$APPUSER'@'localhost';"
  umask 027
  cat > "$APP/.env" <<ENV
ACI_DB_DSN=mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=$DB;charset=utf8mb4
ACI_DB_USER=$APPUSER
ACI_DB_PASS=$DBPASS
ACI_BASE_URL=$BASE_URL
ACI_COOKIE_SECURE=1
# Uzaktan erişim (MeshCentral) ayarları için panel/.env.example dosyasına bakın.
ENV
  chown root:"$APPUSER" "$APP/.env"; chmod 0640 "$APP/.env"
  info "veritabanı ve .env oluşturuldu"
fi
sudo -u "$APPUSER" php "$APP/tools/migrate.php" | sed 's/^/    /'

step "PHP havuzu"
POOL="/etc/php/$PHPV/fpm/pool.d/aci-tahta.conf"
install -m 0644 "$REPO/deploy/php-fpm/aci-tahta.conf" "$POOL"
if ! OUT="$("php-fpm$PHPV" -t 2>&1)"; then
  rm -f "$POOL"   # geçersiz ayar bırakma: diğer siteler etkilenmesin
  die "PHP havuzu ayarı geçersiz, geri alındı: $OUT"
fi
info "ayar testi geçti"
systemctl reload "php$PHPV-fpm"

step "nginx sitesi (127.0.0.1:$PORT)"
install -m 0644 "$REPO/deploy/nginx/aci-tahta.conf" /etc/nginx/sites-available/aci-tahta
ln -sf /etc/nginx/sites-available/aci-tahta /etc/nginx/sites-enabled/aci-tahta
if ! OUT="$(nginx -t 2>&1)"; then
  rm -f /etc/nginx/sites-enabled/aci-tahta   # geçersiz ayar bırakma: otel siteleri etkilenmesin
  die "nginx ayarı geçersiz, site devre dışı bırakıldı: $OUT"
fi
info "ayar testi geçti"
systemctl reload nginx

step "Kontrol"
sleep 1
HEALTH="$(curl -s "http://127.0.0.1:$PORT/api/health.php" || true)"
info "sağlık: ${HEALTH:-yanıt yok}"
CODE="$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/login.php")"
[[ $CODE == 200 ]] && info "giriş sayfası açılıyor" || die "giriş sayfası açılmadı (HTTP $CODE)"

step "Bitti"
cat <<NEXT
    Sıradaki adımlar (ilk kurulumda):
      1. Yönetici:  sudo -u $APPUSER php $APP/tools/create_admin.php KULLANICI
      2. Uzaktan erişim ayarları: $APP/.env  (panel/.env.example'daki ACI_MESH_* satırları)
      3. Cloudflare Tunnel: deploy/cloudflared/config.yml.example
      4. Ayrıntılı durum: panelde Ayarlar > Sistem durumu
NEXT
