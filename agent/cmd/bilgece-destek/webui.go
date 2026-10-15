//go:build windows

package main

import (
	_ "embed"
	"encoding/base64"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"time"
	"unsafe"

	webview2 "github.com/jchv/go-webview2"
	"github.com/jchv/go-webview2/webviewloader"
	"golang.org/x/sys/windows"
)

// Modern pencere: arayüz HTML/CSS (ui/index.html) olarak çizilir ve Windows'un
// kendi Edge WebView2 motorunda gösterilir. Ağ bağlantısı yapmaz: sayfa,
// yazı tipleri ve logo program içinden gelir; JavaScript yalnız aşağıdaki üç
// Go fonksiyonunu çağırabilir. WebView2 yoksa eski (yerel) pencere açılır.
// WebView2Loader.dll (Microsoft imzalı) exe'nin yanında durur, gömülmez.

var (
	//go:embed ui/index.html
	uiHTML string
	//go:embed ui/mark-light.svg
	uiMark []byte
	//go:embed ui/fonts/onest-latin-wght-normal.woff2
	uiOnest []byte
	//go:embed ui/fonts/onest-latin-ext-wght-normal.woff2
	uiOnestExt []byte
	//go:embed ui/fonts/bricolage-grotesque-latin-wght-normal.woff2
	uiBric []byte
	//go:embed ui/fonts/bricolage-grotesque-latin-ext-wght-normal.woff2
	uiBricExt []byte

	secur32               = windows.NewLazySystemDLL("secur32.dll")
	pGetUserNameExW       = secur32.NewProc("GetUserNameExW")
	webUIChecked, webUIOK bool
)

const (
	webW, webH = 460, 660 // mantıksal boyut
	wmSetIcon  = 0x0080
)

// webUIAvailable: WebView2 çalışma ortamı ve yükleyici DLL hazır mı (bir kez bakılır).
func webUIAvailable() bool {
	if !webUIChecked {
		webUIChecked = true
		v, err := webviewloader.GetAvailableCoreWebView2BrowserVersionString("")
		webUIOK = err == nil && v != ""
		logf("WebView2: sürüm=%q hata=%v", v, err)
	}
	return webUIOK
}

func dataURI(mime string, b []byte) string {
	return "data:" + mime + ";base64," + base64.StdEncoding.EncodeToString(b)
}

func buildUI() string {
	return strings.NewReplacer(
		"{{FONT_ONEST}}", dataURI("font/woff2", uiOnest),
		"{{FONT_ONEST_EXT}}", dataURI("font/woff2", uiOnestExt),
		"{{FONT_BRIC}}", dataURI("font/woff2", uiBric),
		"{{FONT_BRIC_EXT}}", dataURI("font/woff2", uiBricExt),
		"{{MARK}}", dataURI("image/svg+xml", uiMark),
	).Replace(uiHTML)
}

// displayName oturumdaki kullanıcının görünen adı (ör. "Ayşe Yılmaz"), yoksa hesap adı.
func displayName() string {
	var n uint32 = 256
	buf := make([]uint16, n)
	if r, _, _ := pGetUserNameExW.Call(3, uintptr(unsafe.Pointer(&buf[0])), uintptr(unsafe.Pointer(&n))); r != 0 { // NameDisplay
		if s := strings.TrimSpace(windows.UTF16ToString(buf)); s != "" {
			return s
		}
	}
	return os.Getenv("USERNAME")
}

// runWebUI pencereyi açar ve kapanana kadar mesaj döngüsünü yürütür
// (tepsi simgesi de bu sırada çalışmaya devam eder). Kapanınca bellek bırakılır.
func (a *app) runWebUI() {
	local := os.Getenv("LOCALAPPDATA")
	if local == "" {
		local = os.TempDir()
	}
	w := webview2.NewWithOptions(webview2.WebViewOptions{
		AutoFocus: true,
		DataPath:  filepath.Join(local, "BilgeceLabs", "Destek"),
		WindowOptions: webview2.WindowOptions{
			Title:  appTitle,
			Width:  uint(a.s(webW)),
			Height: uint(a.s(webH)),
		},
	})
	if w == nil {
		logf("WebView2 penceresi oluşturulamadı, yerel pencere açılıyor")
		webUIOK = false
		a.showMain()
		return
	}
	logf("modern pencere açıldı")
	hwnd := uintptr(w.Window())
	sendMsg(hwnd, wmSetIcon, 0, a.icon)
	sendMsg(hwnd, wmSetIcon, 1, a.icon)
	a.webHwnd = hwnd

	user := displayName()
	w.Bind("destekDurum", func() map[string]any {
		st, connected := readState()
		return map[string]any{"user": user, "connected": connected, "state": st}
	})
	w.Bind("destekTalep", func(subject, message string) map[string]any {
		subject, message = strings.TrimSpace(subject), strings.TrimSpace(message)
		logf("talep (modern pencere): konu=%d, açıklama=%d karakter", len(subject), len(message))
		if subject == "" && message == "" {
			return map[string]any{"ok": false, "error": "Lütfen konu veya açıklama yazın."}
		}
		if err := writeRequest(map[string]string{"type": "ticket", "subject": subject, "message": message}); err != nil {
			logf("talep yazılamadı: %v", err)
			return map[string]any{"ok": false, "error": "Talep kaydedilemedi: " + err.Error()}
		}
		return map[string]any{"ok": true}
	})
	w.Bind("destekKur", func(id int) map[string]any {
		logf("kurulum isteği (modern pencere): katalog #%d", id)
		if err := writeRequest(map[string]any{"type": "install", "catalog_id": id}); err != nil {
			return map[string]any{"ok": false, "error": "İstek kaydedilemedi: " + err.Error()}
		}
		return map[string]any{"ok": true}
	})
	w.SetHtml(buildUI())
	a.placeBottomRight(hwnd, a.s(webW), a.s(webH))
	pShowWindow.Call(hwnd, swShow)
	pSetForegroundWindow.Call(hwnd)

	w.Run() // pencere kapanınca döner
	a.webHwnd = 0
	logf("modern pencere kapandı")
}

// readState ajanın yazdığı durum dosyasını okur; dosya 3 dakikadan yeniyse "bağlı".
func readState() (any, bool) {
	p := filepath.Join(dir(), "durum.json")
	st, err := os.Stat(p)
	if err != nil {
		return nil, false
	}
	b, err := os.ReadFile(p)
	if err != nil {
		return nil, false
	}
	var v any
	if json.Unmarshal(b, &v) != nil {
		return nil, false
	}
	return v, time.Since(st.ModTime()) < 3*time.Minute
}

// placeBottomRight pencereyi çalışma alanının sağ altına (saatin üstüne) koyar.
func (a *app) placeBottomRight(hwnd uintptr, w, h int) {
	var wa rect
	pSystemParametersInfoW.Call(spiGetWorkArea, 0, uintptr(unsafe.Pointer(&wa)), 0)
	x, y := int(wa.right)-w-a.s(12), int(wa.bottom)-h-a.s(12)
	if x < int(wa.left) {
		x = int(wa.left)
	}
	if y < int(wa.top) {
		y = int(wa.top)
	}
	pSetWindowPos.Call(hwnd, 0, uintptr(x), uintptr(y), uintptr(w), uintptr(h), swpNoZOrder)
}
