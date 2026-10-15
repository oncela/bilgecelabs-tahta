#!/usr/bin/env bash
# Gece bakımı: panel veritabanını yedekler (14 gün saklanır, NAS bağlıysa
# oraya da kopyalanır) ve eski kayıtları temizler.
# systemd zamanlayıcısıyla (bilgece-tahta-yedek.timer) her gece çalışır.
set -euo pipefail
DB=aci_tahta
APP=/var/www/aci-tahta
DIR=/var/backups/bilgece-tahta
NAS=/mnt/bilgece-nas/yedek
KEEP_DAYS=14
STAMP=$(date +%F)

install -d -m 0700 "$DIR"
umask 077
# Veritabanı (tutarlı anlık görüntü, tabloları kilitlemeden)
mysqldump --single-transaction --routines --triggers "$DB" | gzip -9 > "$DIR/$DB-$STAMP.sql.gz.tmp"
mv "$DIR/$DB-$STAMP.sql.gz.tmp" "$DIR/$DB-$STAMP.sql.gz"
# Ayarlar (.env: anahtarlar) — geri kurulum için gerekli
cp "$APP/.env" "$DIR/env-$STAMP"
find "$DIR" -type f -mtime +"$KEEP_DAYS" -delete

# NAS bağlıysa kopyala (bağlı değilse sessizce geç)
if mountpoint -q /mnt/bilgece-nas 2>/dev/null; then
    mkdir -p "$NAS" && cp "$DIR/$DB-$STAMP.sql.gz" "$NAS/" \
        && find "$NAS" -name "$DB-*.sql.gz" -mtime +"$KEEP_DAYS" -delete || echo "NAS kopyası başarısız"
fi

# Eski kayıtları temizle: komutlar ve çözülmüş uyarılar 90 gün,
# yönetici işlem geçmişi (denetim) 1 yıl saklanır.
mysql "$DB" -e "
  DELETE FROM commands  WHERE created_at < NOW() - INTERVAL 90 DAY AND status <> 'pending';
  DELETE FROM alerts    WHERE status = 'resolved' AND resolved_at < NOW() - INTERVAL 90 DAY;
  DELETE FROM audit_log WHERE created_at < NOW() - INTERVAL 365 DAY;"

echo "yedek tamam: $DIR/$DB-$STAMP.sql.gz ($(du -h "$DIR/$DB-$STAMP.sql.gz" | cut -f1))"
