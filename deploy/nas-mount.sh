#!/usr/bin/env bash
# BilgeceLabs Tahta — sunucu Ortak alan depolamasını NAS'a (SMB/CIFS) taşır.
# QNAP, Zyxel ve elle kurulmuş Windows/Samba paylaşımlarıyla çalışır.
#
# Kullanım (root):
#   sudo deploy/nas-mount.sh '//192.168.1.50/Ortak' KULLANICI 'ŞİFRE'
#
# Yaptıkları (hepsi geri alınabilir):
#   - cifs-utils kurar (yoksa)
#   - /etc/bilgece-nas/credentials dosyasına kullanıcı/şifre (yalnız root okur)
#   - /mnt/bilgece-nas bağlama noktası, /etc/fstab'a kalıcı kayıt (açılışta bağlanır)
#   - Panelin .env'ine ACI_SHARED_DIR=/mnt/bilgece-nas/paylasim yazar
#   - Varsa yereldeki eski dosyaları NAS'a taşır
set -euo pipefail

SHARE="${1:?Kullanım: nas-mount.sh //NAS/paylasim KULLANICI SIFRE}"
NASUSER="${2:?kullanıcı gerekli}"
NASPASS="${3:?şifre gerekli}"
MOUNT=/mnt/bilgece-nas
CRED=/etc/bilgece-nas/credentials
SUBDIR=paylasim            # NAS içinde dosyalar bu alt klasörde tutulur
ENV=/var/www/aci-tahta/.env
APPUSER=aci-tahta

[[ $EUID -eq 0 ]] || { echo "root olarak çalıştırın (sudo)"; exit 1; }
command -v mount.cifs >/dev/null || { echo "cifs-utils kuruluyor…"; apt-get update -qq && apt-get install -y -qq cifs-utils; }

# aci-tahta kullanıcısının uid/gid'i (dosyalar panel kullanıcısına ait olsun)
UID_APP=$(id -u "$APPUSER"); GID_APP=$(id -g "$APPUSER")

install -d -m 0700 /etc/bilgece-nas
cat > "$CRED" <<EOF
username=$NASUSER
password=$NASPASS
EOF
chmod 0600 "$CRED"

install -d -m 0755 "$MOUNT"
OPTS="credentials=$CRED,uid=$UID_APP,gid=$GID_APP,file_mode=0640,dir_mode=0750,iocharset=utf8,nofail,_netdev,x-systemd.automount"

# Eski fstab kaydını temizle, yenisini ekle
sed -i "\#[[:space:]]$MOUNT[[:space:]]#d" /etc/fstab
echo "$SHARE  $MOUNT  cifs  $OPTS  0  0" >> /etc/fstab

echo "NAS bağlanıyor…"
systemctl daemon-reload
umount "$MOUNT" 2>/dev/null || true
mount "$MOUNT"
mountpoint -q "$MOUNT" || { echo "HATA: NAS bağlanamadı. Ağ yolu, kullanıcı ve şifreyi kontrol edin."; exit 1; }

install -d -o "$APPUSER" -g "$APPUSER" -m 0750 "$MOUNT/$SUBDIR"

# Yereldeki eski dosyaları taşı
OLD=/var/www/aci-tahta/storage/shared
if [[ -d "$OLD" ]] && [[ -n "$(ls -A "$OLD" 2>/dev/null)" ]]; then
  echo "Eski dosyalar NAS'a taşınıyor…"
  cp -an "$OLD"/* "$MOUNT/$SUBDIR"/ && rm -f "$OLD"/*
fi

# Paneli NAS'a yönlendir
if grep -q '^ACI_SHARED_DIR=' "$ENV"; then
  sed -i "s#^ACI_SHARED_DIR=.*#ACI_SHARED_DIR=$MOUNT/$SUBDIR#" "$ENV"
else
  echo "ACI_SHARED_DIR=$MOUNT/$SUBDIR" >> "$ENV"
fi

echo
echo "Tamam. Ortak alan depolaması NAS'ta: $MOUNT/$SUBDIR"
echo "Panelde Ayarlar > Sistem durumu'ndan 'Ortak alan (NAS)' satırını kontrol edin."
