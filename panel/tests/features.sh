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

echo "Self servis (destek penceresi)"
R=$(api -X POST "$B/admin_api.php?action=catalog_save" -d '{"name":"FEAT WinRAR","method":"winget","winget_id":"RARLab.WinRAR","self_service":1}'); SID=$(echo "$R" | jget "['id']")
R=$(api -X POST "$B/admin_api.php?action=catalog_save" -d '{"name":"FEAT Gizli","method":"winget","winget_id":"Gizli.Paket","self_service":0}'); HID=$(echo "$R" | jget "['id']")
R=$(dev '{"version":"0.0.1","results":[]}'); check "durum yalnız izinli programları içerir" "FEAT WinRAR" "$R"
[[ "$R" != *"FEAT Gizli"* ]] && ok "self servise kapalı program görünmez" || fail "self servise kapalı program görünmez" "$R"
R=$(dev '{"version":"0.0.1","user":"ogretmen1","results":[],"requests":[{"type":"ticket","subject":"Projeksiyon yok","message":"Görüntü gelmiyor"}]}')
check "talep pencerenin durumuna eklenir" "Projeksiyon yok" "$R"
R=$(api "$B/admin_api.php?action=tickets"); check "talep panelde kullanıcıyla görünür" '"user_name":"ogretmen1"' "$R"
TID=$(echo "$R" | jget "['tickets'][0]['id']")
R=$(api "$B/admin_api.php?action=tickets_count"); check "açık talep rozeti sayar" '"open":1' "$R"
R=$(api -X POST "$B/admin_api.php?action=ticket_update" -d "{\"id\":$TID,\"reply\":\"Kabloyu kontrol ediyoruz\",\"status\":\"closed\"}"); check "talebe yanıt yazılır" '"ok":true' "$R"
R=$(dev '{"version":"0.0.1","results":[]}'); check "yanıt destek penceresine gider" "Kabloyu kontrol ediyoruz" "$R"
dev "{\"version\":\"0.0.1\",\"results\":[],\"requests\":[{\"type\":\"install\",\"catalog_id\":$HID}]}" >/dev/null
R=$(dev '{"version":"0.0.1","results":[]}'); [[ "$R" != *"Gizli.Paket"* ]] && ok "izinsiz program kurulamaz" || fail "izinsiz program kurulamaz" "$R"
dev "{\"version\":\"0.0.1\",\"results\":[],\"requests\":[{\"type\":\"install\",\"catalog_id\":$SID},{\"type\":\"install\",\"catalog_id\":$SID}]}" >/dev/null
R=$(dev '{"version":"0.0.1","results":[]}'); check "izinli program kurulum komutu gelir" "RARLab.WinRAR" "$R"
N=$(echo "$R" | python3 -c "import sys,json;print(sum(1 for c in json.load(sys.stdin)['commands'] if c['type']=='install_software'))")
[[ "$N" == 1 ]] && ok "aynı kurulum iki kez sıraya girmez" || fail "aynı kurulum iki kez sıraya girmez" "komut: $N"
R=$(dev '{"version":"0.0.1","results":[]}'); check "kurulum durumu pencerede görünür" '"installs":[{"name":"FEAT WinRAR"' "$R"
api -X POST "$B/admin_api.php?action=catalog_delete" -d "{\"id\":$SID}" >/dev/null
api -X POST "$B/admin_api.php?action=catalog_delete" -d "{\"id\":$HID}" >/dev/null
R=$(curl -s -b "$CJ" -o "$TMP/p.html" -w '%{http_code}' "$B/tickets.php"); [[ $R == 200 ]] && ! grep -qiE 'fatal error|warning:' "$TMP/p.html" && ok "tickets.php açılır" || fail "tickets.php açılır" "HTTP $R"

echo "Tehditler ve PUA"
DET='{"enabled":true,"realtime":true,"sig_age_days":0,"quick_scan_age_days":1,"threats":1,"threats_history":2,"pua":2,"detections":[{"id":"{AAA-1}","threat_id":"2147","name":"HackTool:Win32/AutoKMS","severity":5,"category":34,"active":true,"status":1,"source":3,"detected":"'$(date +%Y-%m-%dT%H:%M:%S)'","resources":["file:_C:\\Users\\ogr\\Downloads\\kms.exe"],"process":"explorer.exe","user":"OKUL\\ogr"},{"id":"{AAA-2}","threat_id":"9999","name":"Trojan:Win32/Wacatac.B!ml","severity":5,"category":8,"active":false,"status":3,"source":4,"detected":"'$(date +%Y-%m-%dT%H:%M:%S)'","resources":["file:_E:\\oyun.exe"]}]}'
dev "{\"version\":\"0.0.1\",\"defender\":$DET,\"results\":[]}" >/dev/null
R=$(api "$B/admin_api.php?action=threats"); check "tespitler Tehditler sayfasına gelir" "AutoKMS" "$R"
check "tehdit türü Türkçe" "Saldırı aracı" "$R"
check "karantinadaki tehdit durumu" "Karantinada" "$R"
check "etkin tehdit sayılır" '"active":1' "$R"
check "PUA izleme modu sayılır" '"audit":1' "$R"
R=$(api "$B/admin_api.php?action=devices"); [[ "$R" != *"detections"* ]] && ok "tespit listesi cihaz kaydını şişirmez" || fail "tespit listesi cihaz kaydını şişirmez" "${R:0:200}"
dev "{\"version\":\"0.0.1\",\"defender\":$DET,\"results\":[]}" >/dev/null
N=$(api "$B/admin_api.php?action=threats" | python3 -c "import sys,json;print(len(json.load(sys.stdin)['threats']))")
[[ "$N" == 2 ]] && ok "aynı tespit tekrar eklenmez" || fail "aynı tespit tekrar eklenmez" "kayıt: $N"
MAC2="5e:00:00:02:$(printf '%02x:%02x' $((RANDOM%256)) $((RANDOM%256)))"
R=$(curl -s -X POST "$B/api/enroll.php" -H "$J" -d "{\"enroll_key\":\"$KEY\",\"hostname\":\"FEAT-TEST2\",\"mac\":\"$MAC2\",\"version\":\"0.0.1\"}")
TOK2=$(echo "$R" | jget "['token']"); DID2=$(echo "$R" | jget "['device_id']")
curl -s -X POST "$B/api/poll.php" -H "$J" -H "Authorization: Bearer $TOK2" -d "{\"version\":\"0.0.1\",\"defender\":$(echo "$DET" | sed 's/{AAA-/{BBB-/g'),\"results\":[]}" >/dev/null
R=$(api "$B/admin_api.php?action=alerts"); check "aynı tehdit 2 cihazda: salgın uyarısı" '"type":"outbreak"' "$R"
R=$(api -X POST "$B/admin_api.php?action=send" -d "{\"type\":\"pua_on\",\"device_ids\":[$DID]}"); check "PUA aç komutu oluşturulur" '"count":1' "$R"
R=$(dev '{"version":"0.0.1","results":[]}'); check "cihaz PUA betiğini alır" "Set-MpPreference -PUAProtection" "$R"
check "PUA modu betiğe eklenir" "Mod = 'Enabled'" "$R"
dev "{\"version\":\"0.0.1\",\"defender\":{\"threats\":0,\"pua\":1,\"detections\":[]},\"results\":[]}" >/dev/null
N=$(api "$B/admin_api.php?action=threats" | python3 -c "import sys,json;print(sum(1 for t in json.load(sys.stdin)['threats'] if t['device_id']==$DID and t['active']))")
[[ "$N" == 0 ]] && ok "temizlenince cihazda etkin tehdit kalmaz" || fail "temizlenince cihazda etkin tehdit kalmaz" "etkin: $N"
echo "Koruma ayarları ve olayları"
ASRJ='{"be9ba2d9-53ea-4cdc-84e5-9b1eeee46550":2,"d4f940ab-401b-4efc-aadc-ad5f3c50688a":2,"3b576869-a4ec-4529-8536-b80a7769e899":2,"75668c1f-73b5-4cf0-bb93-3ecf5cb7cc84":2,"d3e037e1-3eb8-44c8-a917-57927947596d":2,"5beb7efe-fd9a-4556-801d-275e5ffc04cc":2,"92e97fa1-2edf-4476-bdd6-9dd0b4dddc7b":2,"b2b3f03d-6a65-4f7b-a9c7-1c7ef74a9ba4":2,"9e6c4e1f-7d60-472f-ba1a-a39ef669e4b2":2,"e6db77e5-3df2-4cf1-b95a-636979351e5b":2}'
EVT='[{"time":"'$(date +%Y-%m-%dT%H:%M:%S)'","event":1122,"rule":"b2b3f03d-6a65-4f7b-a9c7-1c7ef74a9ba4","path":"E:\\oyun.exe","process":"C:\\Windows\\explorer.exe","user":"OKUL\\ogr"}]'
dev "{\"version\":\"0.0.1\",\"defender\":{\"enabled\":true,\"realtime\":true,\"threats\":0,\"pua\":2,\"detections\":[],\"harden\":{\"asr\":$ASRJ,\"net\":2,\"cfa\":0,\"autorun\":255,\"quick_time\":750},\"events\":$EVT},\"results\":[]}" >/dev/null
dev "{\"version\":\"0.0.1\",\"defender\":{\"enabled\":true,\"realtime\":true,\"threats\":0,\"pua\":2,\"detections\":[],\"harden\":{\"asr\":$ASRJ,\"net\":2,\"cfa\":0,\"autorun\":255,\"quick_time\":750},\"events\":$EVT},\"results\":[]}" >/dev/null
R=$(api "$B/admin_api.php?action=threats")
P=$(echo "$R" | python3 -c "import sys,json;d=json.load(sys.stdin)['protections'];print(d['asr']['counts']['audit'],d['net']['counts']['audit'],d['cfa']['counts']['off'],d['autorun']['counts']['on'],d['scan']['counts']['on'])")
[[ "$P" == "1 1 1 1 1" ]] && ok "koruma durumları doğru sayılır (ASR/ağ izleme, klasör kapalı, USB ve tarama açık)" || fail "koruma durumları doğru sayılır" "$P"
check "koruma olayı Türkçe kural adıyla gelir" "USB'den imzasız program" "$R"
N=$(echo "$R" | python3 -c "import sys,json;print(len(json.load(sys.stdin)['events']))")
[[ "$N" == 1 ]] && ok "aynı koruma olayı tekrar eklenmez" || fail "aynı koruma olayı tekrar eklenmez" "olay: $N"
R=$(api -X POST "$B/admin_api.php?action=send" -d "{\"type\":\"koruma_paket_izleme\",\"device_ids\":[$DID]}"); check "okul paketi komutu oluşturulur" '"count":1' "$R"
R=$(dev '{"version":"0.0.1","results":[]}'); check "cihaz koruma betiğini işlem adıyla alır" "Islem = 'paket_izleme'" "$R"
check "betik ASR kurallarını içerir" "AttackSurfaceReductionRules_Ids" "$R"
dev '{"version":"0.0.1","defender":{"enabled":false,"realtime":false,"threats":0,"pua":0,"detections":[],"av":[{"name":"Bitdefender Endpoint Security Tools Antimalware","on":true,"uptodate":true}]},"results":[]}' >/dev/null
R=$(api "$B/admin_api.php?action=devices"); check "Bitdefender'lı cihaz korumalı sayılır" "Bitdefender Endpoint Security Tools" "$R"
N=$(api "$B/admin_api.php?action=alerts" | python3 -c "import sys,json;print(sum(1 for a in json.load(sys.stdin)['open'] if a['type']=='defender' and str(a['device_id'])=='$DID'))")
[[ "$N" == 0 ]] && ok "başka antivirüs varken 'koruma kapalı' uyarısı açılmaz" || fail "başka antivirüs varken 'koruma kapalı' uyarısı açılmaz" "uyarı: $N"
R=$(api "$B/admin_api.php?action=threats"); check "Tehditler sayfası diğer antivirüsü bildirir" '"other_av":{"Bitdefender' "$R"
R=$(curl -s -b "$CJ" -o "$TMP/p.html" -w '%{http_code}' "$B/threats.php"); [[ $R == 200 ]] && ! grep -qiE 'fatal error|warning:' "$TMP/p.html" && ok "threats.php açılır" || fail "threats.php açılır" "HTTP $R"
api -X POST "$B/admin_api.php?action=delete_device" -d "{\"id\":$DID2}" >/dev/null

echo "Uzaktan güncelleme"
R=$(dev '{"version":"0.0.1","results":[]}'); check "eski sürüme güncelleme önerilir" '"update":{"version"' "$R"
SV=$(echo "$R" | jget "['update']['version']")
R=$(dev "{\"version\":\"$SV\",\"results\":[]}"); [[ "$R" != *'"update":{'* ]] && ok "güncel sürüme güncelleme önerilmez" || fail "güncel sürüme güncelleme önerilmez" "$R"
H=$(curl -s -D - -o "$TMP/a.exe" -H "Authorization: Bearer $TOK" "$B/api/agent.php" | tr -d '\r' | grep -i '^x-sha256:' | cut -d' ' -f2)
[[ -n "$H" && "$H" == "$(sha256sum "$TMP/a.exe" | cut -d' ' -f1)" ]] && ok "ajan indirilir, sha256 doğru" || fail "ajan indirilir, sha256 doğru" "başlık: $H"
R=$(curl -s -o /dev/null -w '%{http_code}' "$B/api/agent.php"); check "ajan tokensız indirilemez" "401" "$R"
DS=$(dev '{"version":"0.0.1","results":[]}' | jget "['destek_sha']")
if [[ -n "$DS" ]]; then
  curl -s -o "$TMP/d.exe" -H "Authorization: Bearer $TOK" "$B/api/agent.php?dosya=destek"
  [[ "$(sha256sum "$TMP/d.exe" | cut -d' ' -f1)" == "$DS" ]] && ok "self servis programı indirilir, özet doğru" || fail "self servis programı indirilir, özet doğru" "beklenen $DS"
else
  ok "self servis programı yayında değil (atlandı)"
fi
WS=$(dev '{"version":"0.0.1","results":[]}' | jget "['destek_dll_sha']")
if [[ -n "$WS" ]]; then
  curl -s -o "$TMP/w.dll" -H "Authorization: Bearer $TOK" "$B/api/agent.php?dosya=webview2loader"
  [[ "$(sha256sum "$TMP/w.dll" | cut -d' ' -f1)" == "$WS" ]] && ok "WebView2 yükleyicisi indirilir, özet doğru" || fail "WebView2 yükleyicisi indirilir, özet doğru" "beklenen $WS"
fi

echo "Temizlik"
api -X POST "$B/admin_api.php?action=delete_device" -d "{\"id\":$DID}" >/dev/null
R=$(api -X POST "$B/admin_api.php?action=location_delete" -d "{\"id\":$LID}"); check "test kayıtları silinir" '"ok":true' "$R"

echo; echo "Sonuç: $PASSED geçti, $FAILED başarısız"
[[ $FAILED == 0 ]]
