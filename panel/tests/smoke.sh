#!/usr/bin/env bash
# Uçtan uca duman testi: sahte bir tahta ve yönetici gibi davranarak panelin
# temel akışlarını dener. Kullanım:
#   ACI_TEST_URL=http://127.0.0.1:8099 ACI_TEST_USER=test ACI_TEST_PASS=... \
#   ACI_TEST_ENROLL_KEY=... bash panel/tests/smoke.sh
# Test veritabanında çalıştırın: "SMOKE-" ile başlayan sahte bir tahta oluşturur
# ve sonunda panelden siler.
set -u
B="${ACI_TEST_URL:?ACI_TEST_URL gerekli}"
USER="${ACI_TEST_USER:?}"; PASS="${ACI_TEST_PASS:?}"; KEY="${ACI_TEST_ENROLL_KEY:?}"
J='Content-Type: application/json'
TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT
CJ="$TMP/cookies"
PASSED=0; FAILED=0

ok()   { PASSED=$((PASSED+1)); printf '  \033[32m✓\033[0m %s\n' "$1"; }
fail() { FAILED=$((FAILED+1)); printf '  \033[31m✗\033[0m %s\n    %s\n' "$1" "${2:-}"; }
check() { # check "açıklama" "beklenen alt metin" "gerçek çıktı"
  if [[ "$3" == *"$2"* ]]; then ok "$1"; else fail "$1" "beklenen: $2 | gelen: ${3:0:200}"; fi
}
jget() { python3 -c "import sys,json;d=json.load(sys.stdin);print(d$1)" 2>/dev/null; }

echo "Tahta API'si"
R=$(curl -s -X POST "$B/api/enroll.php" -H "$J" -d '{"enroll_key":"yanlis"}')
check "yanlış kayıt anahtarı reddedilir" "geçersiz" "$R"
MAC="5e:00:00:00:$(printf '%02x:%02x' $((RANDOM%256)) $((RANDOM%256)))"
R=$(curl -s -X POST "$B/api/enroll.php" -H "$J" -d "{\"enroll_key\":\"$KEY\",\"hostname\":\"SMOKE-TEST\",\"mac\":\"$MAC\",\"os\":\"Windows 11 Pro\",\"version\":\"test\"}")
TOK=$(echo "$R" | jget "['token']"); DID=$(echo "$R" | jget "['device_id']")
[[ ${#TOK} == 64 ]] && ok "kayıt token verir" || fail "kayıt token verir" "$R"
R=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/api/poll.php" -H "$J" -H "Authorization: Bearer $(printf '0%.0s' {1..64})" -d '{}')
check "geçersiz token 401 alır" "401" "$R"
R=$(curl -s -X POST "$B/api/poll.php" -H "$J" -H "Authorization: Bearer $TOK" -d '{"hostname":"SMOKE-TEST","user":"Ogrenci","defender":{"enabled":true,"realtime":true,"sig_age_days":0,"threats":0}}')
check "bağlantı yanıtı komut listesi döner" '"commands"' "$R"
R=$(curl -s -o /dev/null -w '%{http_code}' "$B/api/meshagent.php")
check "uzaktan erişim ajanı tokensız indirilemez" "401" "$R"

echo "Panel girişi ve sayfalar"
R=$(curl -s -o /dev/null -w '%{http_code}' "$B/dashboard.php")
check "girişsiz sayfa yönlendirir" "302" "$R"
curl -s -c "$CJ" -b "$CJ" -o /dev/null "$B/login.php"
R=$(curl -s -c "$CJ" -b "$CJ" -o /dev/null -w '%{http_code} %{redirect_url}' --data-urlencode "username=$USER" --data-urlencode "password=$PASS" "$B/login.php")
check "giriş Genel bakış'a yönlendirir" "dashboard.php" "$R"
for p in dashboard rooms devices network history settings; do
  R=$(curl -s -b "$CJ" -o "$TMP/p.html" -w '%{http_code}' "$B/$p.php")
  if [[ $R == 200 ]] && grep -q 'aci-sidebar' "$TMP/p.html" && ! grep -qiE 'fatal error|warning:|notice:' "$TMP/p.html"; then ok "$p.php açılır"; else fail "$p.php açılır" "HTTP $R"; fi
done
CSRF=$(curl -s -b "$CJ" "$B/devices.php" | grep -o 'name="csrf" content="[^"]*"' | cut -d'"' -f4)
api() { curl -s -b "$CJ" -H "$J" -H 'Accept: application/json' -H "X-CSRF: $CSRF" "$@"; }

echo "Komutlar"
R=$(curl -s -b "$CJ" -H "$J" -H 'Accept: application/json' -X POST "$B/admin_api.php?action=send" -d "{\"type\":\"message\",\"device_ids\":[$DID],\"args\":{\"text\":\"x\"}}")
check "CSRF olmadan komut reddedilir" "Güvenlik doğrulaması" "$R"
R=$(api -X POST "$B/admin_api.php?action=update_device" -d "{\"id\":$DID,\"name\":\"Smoke Tahta\",\"room\":\"SMOKE\"}")
check "tahtaya ad ve sınıf verilir" '"ok":true' "$R"
R=$(api -X POST "$B/admin_api.php?action=send" -d "{\"type\":\"message\",\"device_ids\":[$DID],\"args\":{\"text\":\"Test mesajı\"}}")
BATCH=$(echo "$R" | jget "['batch_id']")
check "mesaj komutu oluşturulur" '"count":1' "$R"
R=$(curl -s -X POST "$B/api/poll.php" -H "$J" -H "Authorization: Bearer $TOK" -d '{"hostname":"SMOKE-TEST"}')
CID=$(echo "$R" | jget "['commands'][0]['id']")
check "tahta komutu alır" '"type":"message"' "$R"
curl -s -X POST "$B/api/poll.php" -H "$J" -H "Authorization: Bearer $TOK" -d "{\"hostname\":\"SMOKE-TEST\",\"results\":[{\"id\":$CID,\"ok\":true,\"output\":\"tamam\"}]}" > /dev/null
R=$(api "$B/admin_api.php?action=batch&id=$BATCH")
check "sonuç panele döner" '"status":"done"' "$R"
R=$(api -X POST "$B/admin_api.php?action=send" -d "{\"type\":\"format_c\",\"device_ids\":[$DID]}")
check "bilinmeyen komut reddedilir" "Bilinmeyen" "$R"
R=$(api -X POST "$B/admin_api.php?action=mesh_action" -d "{\"action\":\"open_url\",\"device_ids\":[$DID],\"args\":{\"url\":\"javascript:alert(1)\"}}")
check "uzaktan erişim kimliği olmayan tahtaya MeshCentral komutu gitmez" "kimliği yok" "$R"

echo "İstatistik ve sağlık"
R=$(api "$B/admin_api.php?action=stats")
check "istatistik 7 günlük veri döner" '"labels"' "$R"
R=$(curl -s "$B/api/health.php")
check "sağlık kontrolü yanıt verir" '"checks"' "$R"

echo "Temizlik"
R=$(api -X POST "$B/admin_api.php?action=delete_device" -d "{\"id\":$DID}")
check "sahte tahta silinir" '"ok":true' "$R"

echo
echo "Sonuç: $PASSED geçti, $FAILED başarısız"
[[ $FAILED == 0 ]]
