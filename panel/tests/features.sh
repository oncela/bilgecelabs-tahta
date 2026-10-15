#!/usr/bin/env bash
# Yeni özelliklerin uçtan uca testi: lokasyon, metrik/uyarı, güvenlik, envanter,
# yazılım kataloğu, uzaktan güncelleme. smoke.sh ile aynı ortam değişkenleri:
#   ACI_TEST_URL=http://127.0.0.1:8099 ACI_TEST_USER=test ACI_TEST_PASS=... \
#   ACI_TEST_ENROLL_KEY=... bash panel/tests/features.sh
# Test veritabanında çalıştırın; oluşturduğu kayıtları sonunda siler.
set -u
B="${ACI_TEST_URL:?ACI_TEST_URL gerekli}"
USER="${ACI_TEST_USER:?}"; PASS="${ACI_TEST_PASS:?}"; KEY="${ACI_TEST_ENROLL_KEY:?}"
J='Content-Type: application/json'
TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT
CJ="$TMP/cookies"
PASSED=0; FAILED=0

ok()   { PASSED=$((PASSED+1)); printf '  \033[32m✓\033[0m %s\n' "$1"; }
fail() { FAILED=$((FAILED+1)); printf '  \033[31m✗\033[0m %s\n    %s\n' "$1" "${2:-}"; }
check() { if [[ "$3" == *"$2"* ]]; then ok "$1"; else fail "$1" "beklenen: $2 | gelen: ${3:0:200}"; fi; }
jget() { python3 -c "import sys,json;d=json.load(sys.stdin);print(d$1)" 2>/dev/null; }

# Sahte cihaz + yönetici oturumu
MAC="5e:00:00:01:$(printf '%02x:%02x' $((RANDOM%256)) $((RANDOM%256)))"
R=$(curl -s -X POST "$B/api/enroll.php" -H "$J" -d "{\"enroll_key\":\"$KEY\",\"hostname\":\"FEAT-TEST\",\"mac\":\"$MAC\",\"os\":\"Windows 11 Pro\",\"version\":\"0.0.1\",\"dev_type\":\"tahta\",\"room\":\"9-A\"}")
TOK=$(echo "$R" | jget "['token']"); DID=$(echo "$R" | jget "['device_id']")
dev() { curl -s -X POST "$B/api/poll.php" -H "$J" -H "Authorization: Bearer $TOK" -d "$1"; }
curl -s -c "$CJ" -b "$CJ" -o /dev/null "$B/login.php"
curl -s -c "$CJ" -b "$CJ" -o /dev/null --data-urlencode "username=$USER" --data-urlencode "password=$PASS" "$B/login.php"
CSRF=$(curl -s -b "$CJ" "$B/devices.php" | grep -o 'name="csrf" content="[^"]*"' | cut -d'"' -f4)
api() { curl -s -b "$CJ" -H "$J" -H 'Accept: application/json' -H "X-CSRF: $CSRF" "$@"; }

echo "Sayfalar"
for p in alerts locations inventory software security shared remote; do
  R=$(curl -s -b "$CJ" -o "$TMP/p.html" -w '%{http_code}' "$B/$p.php")
  if [[ $R == 200 ]] && ! grep -qiE 'fatal error|warning:|notice:|deprecated:' "$TMP/p.html"; then ok "$p.php açılır"; else fail "$p.php açılır" "HTTP $R"; fi
done

echo "Lokasyonlar"
R=$(api -X POST "$B/admin_api.php?action=location_save" -d '{"name":"FEAT Şube"}'); LID=$(echo "$R" | jget "['id']")
[[ -n "$LID" ]] && ok "lokasyon eklenir" || fail "lokasyon eklenir" "$R"
R=$(api -X POST "$B/admin_api.php?action=location_save" -d '{"name":"  "}'); check "boş lokasyon adı reddedilir" "boş olamaz" "$R"
R=$(api -X POST "$B/admin_api.php?action=assign_location" -d "{\"location_id\":$LID,\"device_ids\":[$DID]}"); check "cihaz lokasyona atanır" '"count":1' "$R"
R=$(api "$B/admin_api.php?action=devices"); check "cihaz listesinde lokasyon görünür" "FEAT Şube" "$R"
R=$(api "$B/admin_api.php?action=locations"); check "lokasyonda 1 cihaz sayılır" '"devices":1' "$R"

echo "Metrik ve uyarılar"
dev '{"version":"0.0.1","metrics":{"cpu":10,"mem_used":50,"mem_mb":8000,"disk_used":97,"disk_free":3,"disk_gb":120},"results":[]}' >/dev/null
R=$(api "$B/admin_api.php?action=alerts"); check "dolu disk kritik uyarı açar" '"type":"disk","severity":"critical"' "$R"
dev '{"version":"0.0.1","metrics":{"cpu":10,"mem_used":50,"mem_mb":8000,"disk_used":97,"disk_free":3,"disk_gb":120},"results":[]}' >/dev/null
N=$(api "$B/admin_api.php?action=alerts" | python3 -c "import sys,json;d=json.load(sys.stdin);print(sum(1 for a in d['open'] if a['type']=='disk' and str(a['device_id'])=='$DID'))")
[[ "$N" == 1 ]] && ok "aynı uyarı tekrar açılmaz" || fail "aynı uyarı tekrar açılmaz" "açık disk uyarısı: $N"
dev '{"version":"0.0.1","metrics":{"cpu":10,"mem_used":50,"mem_mb":8000,"disk_used":40,"disk_free":70,"disk_gb":120},"results":[]}' >/dev/null
R=$(api "$B/admin_api.php?action=alerts"); check "disk düzelince uyarı çözülür" '"resolved":[{' "$R"

echo "Güvenlik"
dev '{"version":"0.0.1","security":{"firewall_on":false,"bitlocker":"Off","uac":true,"reboot_pending":true,"last_update":"2026-10-01","tamper_protect":true},"results":[]}' >/dev/null
R=$(api "$B/admin_api.php?action=alerts"); check "güvenlik duvarı kapalı uyarısı" '"type":"firewall"' "$R"
R=$(api "$B/admin_api.php?action=devices"); check "güvenlik bilgisi cihazda saklanır" '"reboot_pending":true' "$R"
dev '{"version":"0.0.1","security":{"firewall_on":true,"bitlocker":"On","uac":true,"reboot_pending":false,"last_update":"2026-10-01","tamper_protect":true},"results":[]}' >/dev/null
R=$(api "$B/admin_api.php?action=alerts_count"); check "güvenlik duvarı açılınca uyarı kapanır" '"open":0' "$R"

echo "Envanter"
dev '{"version":"0.0.1","inventory":{"serial":"SN-FEAT-1","manufacturer":"HNC","model":"Akıllı Tahta 86","cpu":"Intel i5","ram_gb":8,"os":"Windows 11 Pro","software_count":2,"software":[{"name":"WinRAR","version":"7.0"},{"name":"Adobe Reader","version":"24"}]},"results":[]}' >/dev/null
R=$(api "$B/admin_api.php?action=inventory"); check "envanter listesinde seri no" "SN-FEAT-1" "$R"
R=$(api "$B/admin_api.php?action=device_inventory&id=$DID"); check "cihaz yazılım listesi döner" "WinRAR" "$R"
R=$(curl -s -b "$CJ" "$B/admin_api.php?action=inventory_csv"); check "envanter CSV indirilir" "SN-FEAT-1" "$R"

echo "Yazılım kataloğu"
R=$(api -X POST "$B/admin_api.php?action=catalog_save" -d '{"name":"FEAT 7-Zip","method":"winget","winget_id":"7zip.7zip","self_service":1}'); CID=$(echo "$R" | jget "['id']")
[[ -n "$CID" ]] && ok "katalog programı eklenir" || fail "katalog programı eklenir" "$R"
R=$(api -X POST "$B/admin_api.php?action=catalog_save" -d '{"name":"Kötü","method":"url","url":"http://ornek.com/a.exe"}'); check "https olmayan adres reddedilir" "https" "$R"
R=$(api -X POST "$B/admin_api.php?action=catalog_install" -d "{\"catalog_id\":$CID,\"device_ids\":[$DID]}"); check "kurulum komutu oluşturulur" '"count":1' "$R"
R=$(dev '{"version":"0.0.1","results":[]}'); check "cihaz install_software komutunu alır" '7zip.7zip' "$R"
R=$(api -X POST "$B/admin_api.php?action=catalog_delete" -d "{\"id\":$CID}"); check "katalog programı silinir" '"ok":true' "$R"

echo "Uzaktan güncelleme"
R=$(dev '{"version":"0.0.1","results":[]}'); check "eski sürüme güncelleme önerilir" '"update":{"version"' "$R"
SV=$(echo "$R" | jget "['update']['version']")
R=$(dev "{\"version\":\"$SV\",\"results\":[]}"); [[ "$R" != *'"update":{'* ]] && ok "güncel sürüme güncelleme önerilmez" || fail "güncel sürüme güncelleme önerilmez" "$R"
H=$(curl -s -D - -o "$TMP/a.exe" -H "Authorization: Bearer $TOK" "$B/api/agent.php" | tr -d '\r' | grep -i '^x-sha256:' | cut -d' ' -f2)
[[ -n "$H" && "$H" == "$(sha256sum "$TMP/a.exe" | cut -d' ' -f1)" ]] && ok "ajan indirilir, sha256 doğru" || fail "ajan indirilir, sha256 doğru" "başlık: $H"
R=$(curl -s -o /dev/null -w '%{http_code}' "$B/api/agent.php"); check "ajan tokensız indirilemez" "401" "$R"

echo "Temizlik"
api -X POST "$B/admin_api.php?action=delete_device" -d "{\"id\":$DID}" >/dev/null
R=$(api -X POST "$B/admin_api.php?action=location_delete" -d "{\"id\":$LID}"); check "test kayıtları silinir" '"ok":true' "$R"

echo; echo "Sonuç: $PASSED geçti, $FAILED başarısız"
[[ $FAILED == 0 ]]
