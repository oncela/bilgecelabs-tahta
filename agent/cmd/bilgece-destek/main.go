//go:build windows

// BilgeceLabs Destek: kullanıcı oturumunda çalışan self servis penceresi.
// Saatin yanında simge olarak durur; tıklanınca destek talebi açma ve izin
// verilen programları kurma penceresi açılır.
//
// Ağ bağlantısı yapmaz, port açmaz: BilgeceLabs Tahta ajanıyla (SYSTEM servisi)
// ProgramData\BilgeceLabs\Tahta\destek klasörü üzerinden haberleşir:
//   - durum.json      ajan yazar: izinli programlar, talepler, son kurulumlar
//   - istek-*.json    bu program yazar: yeni talep / kurulum isteği
//
// Ajan istekleri panele iletir; panel doğrular (yalnız izinli programlar kurulur).
package main

import (
	"bytes"
	_ "embed"
	"encoding/json"
	"fmt"
	"image"
	"image/draw"
	"image/png"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"time"
	"unsafe"

	"golang.org/x/sys/windows"
)

var version = "dev"

//go:embed logo.png
var logoPNG []byte

const (
	appTitle  = "BilgeceLabs Destek"
	className = "BilgeceDestekPencere"

	idSubject = 101
	idMessage = 102
	idSend    = 103
	idCatalog = 104
	idInstall = 105
	idList    = 106
	idStatus  = 107
	idHello   = 108
	idOK      = 1 // Enter tuşu (IsDialogMessage varsayılan düğme)
	menuOpen  = 201
	menuAbout = 202

	clientW, clientH = 680, 452 // mantıksal (96 dpi) pencere içi boyut
)

type state struct {
	Catalog []struct {
		ID   int    `json:"id"`
		Name string `json:"name"`
	} `json:"catalog"`
	Tickets []struct {
		ID        int    `json:"id"`
		Subject   string `json:"subject"`
		Status    string `json:"status"`
		Reply     string `json:"reply"`
		CreatedAt string `json:"created_at"`
	} `json:"tickets"`
	Installs []struct {
		Name      string `json:"name"`
		Status    string `json:"status"`
		CreatedAt string `json:"created_at"`
	} `json:"installs"`
}

type app struct {
	hwnd, hinst    uintptr
	dpi            int
	font, bold     uintptr
	icon           uintptr
	ctl            map[int]uintptr
	grayLabels     map[uintptr]bool
	lineBrush      uintptr
	logo           []byte
	logoW, logoH   int
	st             state
	catalogIDs     []int
	seenReplies    map[int]string
	loaded         bool
	taskbarCreated uint32
	webHwnd        uintptr // açık modern pencere
	wantWeb        bool    // ana döngü modern pencereyi açsın
}

var a = &app{ctl: map[int]uintptr{}, grayLabels: map[uintptr]bool{}, seenReplies: map[int]string{}}

func dir() string {
	pd := os.Getenv("ProgramData")
	if pd == "" {
		pd = `C:\ProgramData`
	}
	return filepath.Join(pd, "BilgeceLabs", "Tahta", "destek")
}

func main() {
	runtime.LockOSThread()

	// Kurulum programı bu modu kullanır: logo + yükleme çubuğu (splash.go)
	if len(os.Args) > 2 && os.Args[1] == "kurulum-ekrani" {
		runSplash(os.Args[2])
		return
	}

	// Tek kopya: ikinci çalıştırma mevcut pencereyi öne getirir.
	pCreateMutexW.Call(0, 0, uintptr(unsafe.Pointer(utf16(`Local\BilgeceDestek`))))
	if windows.GetLastError() == windows.ERROR_ALREADY_EXISTS {
		if h, _, _ := pFindWindowW.Call(uintptr(unsafe.Pointer(utf16(className))), 0); h != 0 {
			pPostMessageW.Call(h, wmShowMain, 0, 0)
		}
		return
	}

	a.hinst, _, _ = pGetModuleHandleW.Call(0)
	dc, _, _ := pGetDC.Call(0)
	d, _, _ := pGetDeviceCaps.Call(dc, logPixelsY)
	pReleaseDC.Call(0, dc)
	a.dpi = int(d)
	if a.dpi == 0 {
		a.dpi = 96
	}
	a.decodeLogo()
	a.lineBrush, _, _ = pCreateSolidBrush.Call(rgb(226, 230, 237))
	a.font = a.makeFont(10, 400)
	a.bold = a.makeFont(11, 600)
	cx, _, _ := pGetSystemMetrics.Call(smCxSmIcon)
	cy, _, _ := pGetSystemMetrics.Call(smCySmIcon)
	a.icon, _, _ = pLoadImageW.Call(a.hinst, uintptr(unsafe.Pointer(utf16("APP"))), imageIcon, cx, cy, lrDefaultColor)
	bigIcon, _, _ := pLoadImageW.Call(a.hinst, uintptr(unsafe.Pointer(utf16("APP"))), imageIcon, 0, 0, lrDefaultColor)
	a.taskbarCreated = registerMsg("TaskbarCreated")

	cursor, _, _ := pLoadCursorW.Call(0, 32512)
	white, _, _ := pGetStockObject.Call(whiteBrush)
	wc := wndClassEx{
		lpfnWndProc:   windows.NewCallback(wndProc),
		hInstance:     a.hinst,
		hIcon:         bigIcon,
		hIconSm:       a.icon,
		hCursor:       cursor,
		hbrBackground: white,
		lpszClass:     utf16(className),
	}
	wc.cbSize = uint32(unsafe.Sizeof(wc))
	pRegisterClassExW.Call(uintptr(unsafe.Pointer(&wc)))

	style := uintptr(wsCaption | wsSysMenu | wsMinimizeBox)
	r := rect{0, 0, int32(a.s(clientW)), int32(a.s(clientH))}
	pAdjustWindowRectEx.Call(uintptr(unsafe.Pointer(&r)), style, 0, 0)
	h, _, _ := pCreateWindowExW.Call(0, uintptr(unsafe.Pointer(utf16(className))), uintptr(unsafe.Pointer(utf16(appTitle))),
		style, 0, 0, uintptr(r.right-r.left), uintptr(r.bottom-r.top), 0, 0, a.hinst, 0)
	if h == 0 {
		return
	}
	a.hwnd = h
	logf("başladı: sürüm %s, kullanıcı %s, dpi %d", version, os.Getenv("USERNAME"), a.dpi)
	a.buildControls()
	a.addTrayIcon()
	a.reload(false)
	pSetTimer.Call(h, 1, 15000, 0) // durum.json'u yenile, yanıt geldiyse haber ver

	var m winMsg
	for {
		r, _, _ := pGetMessageW.Call(uintptr(unsafe.Pointer(&m)), 0, 0, 0)
		if int32(r) <= 0 {
			break
		}
		if ok, _, _ := pIsDialogMessageW.Call(a.hwnd, uintptr(unsafe.Pointer(&m))); ok == 0 {
			pTranslateMessage.Call(uintptr(unsafe.Pointer(&m)))
			pDispatchMessageW.Call(uintptr(unsafe.Pointer(&m)))
		}
		// Modern pencere geri çağrı içinde değil, burada açılır (kendi döngüsünü yürütür)
		if a.wantWeb {
			a.wantWeb = false
			a.runWebUI()
		}
	}
	a.removeTrayIcon()
}

func registerMsg(name string) uint32 {
	r, _, _ := pRegisterWindowMessageW.Call(uintptr(unsafe.Pointer(utf16(name))))
	return uint32(r)
}

// s mantıksal pikseli ekran ölçeğine çevirir.
func (a *app) s(v int) int { return v * a.dpi / 96 }

func (a *app) makeFont(pt, weight int) uintptr {
	h := -(pt * a.dpi / 72)
	f, _, _ := pCreateFontW.Call(uintptr(h), 0, 0, 0, uintptr(weight), 0, 0, 0, 1, 0, 0, 5, 0,
		uintptr(unsafe.Pointer(utf16("Segoe UI"))))
	return f
}

func (a *app) decodeLogo() {
	img, err := png.Decode(bytes.NewReader(logoPNG))
	if err != nil {
		return
	}
	b := img.Bounds()
	rgba := image.NewRGBA(b)
	draw.Draw(rgba, b, image.White, image.Point{}, draw.Src) // saydamlığı beyaz zeminde birleştir
	draw.Draw(rgba, b, img, b.Min, draw.Over)
	out := make([]byte, len(rgba.Pix))
	for i := 0; i < len(out); i += 4 {
		out[i], out[i+1], out[i+2], out[i+3] = rgba.Pix[i+2], rgba.Pix[i+1], rgba.Pix[i], 255
	}
	a.logo, a.logoW, a.logoH = out, b.Dx(), b.Dy()
}

func (a *app) child(cls string, text string, style uintptr, exStyle uintptr, x, y, w, h, id int) uintptr {
	hw, _, _ := pCreateWindowExW.Call(exStyle, uintptr(unsafe.Pointer(utf16(cls))), uintptr(unsafe.Pointer(utf16(text))),
		wsChild|wsVisible|style, uintptr(a.s(x)), uintptr(a.s(y)), uintptr(a.s(w)), uintptr(a.s(h)),
		a.hwnd, uintptr(id), a.hinst, 0)
	sendMsg(hw, wmSetFont, a.font, 1)
	if id > 0 {
		a.ctl[id] = hw
	}
	return hw
}

func (a *app) label(text string, x, y, w, h int, bold, gray bool) uintptr {
	hw := a.child("STATIC", text, ssLeftNoWrap, 0, x, y, w, h, 0)
	if bold {
		sendMsg(hw, wmSetFont, a.bold, 1)
	}
	if gray {
		a.grayLabels[hw] = true
	}
	return hw
}

func (a *app) buildControls() {
	user := os.Getenv("USERNAME")
	hello := a.child("STATIC", "Merhaba "+user+" · Size nasıl yardımcı olabiliriz?", ssRight, 0, 300, 30, 356, 20, idHello)
	a.grayLabels[hello] = true

	// Sol: destek talebi
	a.label("Destek talebi aç", 24, 88, 300, 22, true, false)
	a.label("Konu", 24, 116, 300, 18, false, true)
	subj := a.child("EDIT", "", wsTabStop|esAutoHScroll, wsExClientEdge, 24, 134, 306, 26, idSubject)
	sendMsg(subj, emLimitText, 150, 0)
	sendMsg(subj, emSetCueBanner, 1, uintptr(unsafe.Pointer(utf16("Ör. Projeksiyon görüntü vermiyor"))))
	a.label("Açıklama", 24, 168, 300, 18, false, true)
	msg := a.child("EDIT", "", wsTabStop|wsVScroll|esMultiline|esAutoVScroll|esWantReturn, wsExClientEdge, 24, 186, 306, 108, idMessage)
	sendMsg(msg, emLimitText, 3900, 0)
	a.child("BUTTON", "Talebi gönder", wsTabStop|bsDefPushButton, 0, 190, 304, 140, 32, idSend)

	// Sağ: izinli programlar
	a.label("Programlar", 354, 88, 300, 22, true, false)
	a.label("BT biriminin izin verdiği programlar", 354, 116, 302, 18, false, true)
	a.child("LISTBOX", "", wsTabStop|wsVScroll|lbsNotify|lbsNoIntegral, wsExClientEdge, 354, 134, 302, 160, idCatalog)
	a.child("BUTTON", "Seçili programı yükle", wsTabStop|bsPushButton, 0, 476, 304, 180, 32, idInstall)

	// Alt: taleplerim ve kurulumlar
	a.label("Taleplerim ve kurulumlar", 24, 350, 400, 22, true, false)
	a.child("LISTBOX", "", wsVScroll|lbsNoIntegral, wsExClientEdge, 24, 374, 632, 50, idList)
	st := a.child("STATIC", "", ssLeftNoWrap, 0, 24, 428, 632, 18, idStatus)
	a.grayLabels[st] = true
}

func (a *app) addTrayIcon() {
	a.trayIcon(nimAdd, "", "")
}

func (a *app) removeTrayIcon() {
	nid := notifyIconData{hWnd: a.hwnd, uID: 1}
	nid.cbSize = uint32(unsafe.Sizeof(nid))
	pShellNotifyIconW.Call(nimDelete, uintptr(unsafe.Pointer(&nid)))
}

// trayIcon simgeyi ekler/günceller; title boş değilse balon bildirimi gösterir.
func (a *app) trayIcon(op uintptr, title, text string) {
	nid := notifyIconData{hWnd: a.hwnd, uID: 1, uFlags: nifMessage | nifIcon | nifTip,
		uCallbackMessage: wmTray, hIcon: a.icon}
	nid.cbSize = uint32(unsafe.Sizeof(nid))
	copyUTF16(nid.szTip[:], appTitle+" — destek ve programlar")
	if title != "" {
		nid.uFlags |= nifInfo
		copyUTF16(nid.szInfoTitle[:], title)
		copyUTF16(nid.szInfo[:], text)
		nid.dwInfoFlags = niifInfo
	}
	pShellNotifyIconW.Call(op, uintptr(unsafe.Pointer(&nid)))
}

func (a *app) notify(title, text string) { a.trayIcon(nimModify, title, text) }

// showMain pencereyi saatin üstünde (çalışma alanının sağ altı) açar.
// openUI: modern pencere (WebView2) varsa onu, yoksa yerel pencereyi açar.
func (a *app) openUI() {
	if a.webHwnd != 0 {
		pShowWindow.Call(a.webHwnd, swRestore)
		pSetForegroundWindow.Call(a.webHwnd)
		return
	}
	if webUIAvailable() {
		a.wantWeb = true
		pPostMessageW.Call(a.hwnd, wmNull, 0, 0) // döngüyü uyandır
		return
	}
	a.showMain()
}

func (a *app) showMain() {
	logf("pencere açıldı")
	a.reload(false)
	var wa rect
	pSystemParametersInfoW.Call(spiGetWorkArea, 0, uintptr(unsafe.Pointer(&wa)), 0)
	style := uintptr(wsCaption | wsSysMenu | wsMinimizeBox)
	r := rect{0, 0, int32(a.s(clientW)), int32(a.s(clientH))}
	pAdjustWindowRectEx.Call(uintptr(unsafe.Pointer(&r)), style, 0, 0)
	w, h := r.right-r.left, r.bottom-r.top
	x, y := wa.right-w-int32(a.s(12)), wa.bottom-h-int32(a.s(12))
	if x < wa.left {
		x = wa.left
	}
	if y < wa.top {
		y = wa.top
	}
	pSetWindowPos.Call(a.hwnd, 0, uintptr(x), uintptr(y), 0, 0, swpNoZOrder|0x0001) // SWP_NOSIZE
	pShowWindow.Call(a.hwnd, swRestore)
	pShowWindow.Call(a.hwnd, swShow)
	pSetForegroundWindow.Call(a.hwnd)
}

func (a *app) setStatus(s string) { setText(a.ctl[idStatus], s) }

// reload durum.json'u okur, listeleri doldurur; yeni yanıt varsa haber verir.
func (a *app) reload(announce bool) {
	b, err := os.ReadFile(filepath.Join(dir(), "durum.json"))
	if err != nil {
		a.setStatus("Bağlantı bekleniyor… Bilgisayar internete bağlanınca liste gelecek.")
		return
	}
	var st state
	if json.Unmarshal(b, &st) != nil {
		return
	}
	a.st = st

	// Program listesi (seçimi koru)
	cat := a.ctl[idCatalog]
	sel := int(sendMsg(cat, lbGetCurSel, 0, 0))
	sendMsg(cat, lbResetContent, 0, 0)
	a.catalogIDs = a.catalogIDs[:0]
	for _, c := range st.Catalog {
		sendMsg(cat, lbAddString, 0, uintptr(unsafe.Pointer(utf16(c.Name))))
		a.catalogIDs = append(a.catalogIDs, c.ID)
	}
	if len(st.Catalog) == 0 {
		sendMsg(cat, lbAddString, 0, uintptr(unsafe.Pointer(utf16("(Henüz izinli program yok)"))))
	} else if sel >= 0 && sel < len(st.Catalog) {
		sendMsg(cat, lbSetCurSel, uintptr(sel), 0)
	}

	// Taleplerim ve kurulumlar
	list := a.ctl[idList]
	sendMsg(list, lbResetContent, 0, 0)
	add := func(s string) { sendMsg(list, lbAddString, 0, uintptr(unsafe.Pointer(utf16(s)))) }
	for _, t := range st.Tickets {
		durum := "Açık"
		if t.Status == "closed" {
			durum = "Çözüldü"
		}
		add(fmt.Sprintf("#%d  %s  —  %s  (%s)", t.ID, t.Subject, durum, shortDate(t.CreatedAt)))
		if t.Reply != "" {
			add("        Yanıt: " + strings.ReplaceAll(t.Reply, "\n", " "))
		}
		if announce && a.loaded && t.Reply != "" && a.seenReplies[t.ID] != t.Reply {
			a.notify("Talebinize yanıt geldi", fmt.Sprintf("#%d %s: %s", t.ID, t.Subject, t.Reply))
		}
		a.seenReplies[t.ID] = t.Reply
	}
	names := map[string]string{"pending": "sırada", "sent": "kuruluyor", "done": "kuruldu", "failed": "kurulamadı", "expired": "zaman aşımı"}
	for _, in := range st.Installs {
		s := names[in.Status]
		if s == "" {
			s = in.Status
		}
		add(fmt.Sprintf("%s kurulumu: %s  (%s)", in.Name, s, shortDate(in.CreatedAt)))
	}
	if len(st.Tickets) == 0 && len(st.Installs) == 0 {
		add("Henüz talep veya kurulum yok.")
	}
	if !a.loaded || strings.HasPrefix(windowText(a.ctl[idStatus]), "Bağlantı bekleniyor") {
		a.setStatus("Hazır. Talebiniz BilgeceLabs destek ekibine iletilir.")
	}
	a.loaded = true
}

func shortDate(s string) string {
	t, err := time.ParseInLocation("2006-01-02 15:04:05", s, time.Local)
	if err != nil {
		return s
	}
	return t.Format("02.01 15:04")
}

// logf pencere günlüğü (destek\pencere.log): ajan ve yönetici uzaktan okuyabilir.
func logf(format string, args ...any) {
	p := filepath.Join(dir(), "pencere.log")
	if st, err := os.Stat(p); err == nil && st.Size() > 256<<10 {
		os.Rename(p, p+".1")
	}
	f, err := os.OpenFile(p, os.O_CREATE|os.O_APPEND|os.O_WRONLY, 0644)
	if err != nil {
		return
	}
	fmt.Fprintf(f, "%s %s\n", time.Now().Format("2006-01-02 15:04:05"), fmt.Sprintf(format, args...))
	f.Close()
}

// writeRequest isteği ajanın okuyacağı klasöre bırakır (önce .tmp, sonra ad değişimi).
func writeRequest(v any) error {
	b, err := json.Marshal(v)
	if err != nil {
		return err
	}
	if err := os.MkdirAll(dir(), 0755); err != nil {
		return err
	}
	p := filepath.Join(dir(), fmt.Sprintf("istek-%d.json", time.Now().UnixNano()))
	if err := os.WriteFile(p+".tmp", b, 0644); err != nil {
		return err
	}
	return os.Rename(p+".tmp", p)
}

func (a *app) sendTicket() {
	a.setStatus("Gönderiliyor…")
	subj := strings.TrimSpace(windowText(a.ctl[idSubject]))
	msg := strings.TrimSpace(windowText(a.ctl[idMessage]))
	logf("talep: konu=%d karakter, açıklama=%d karakter", len(subj), len(msg))
	if subj == "" && msg == "" {
		a.setStatus("Lütfen konu veya açıklama yazın.")
		return
	}
	if err := writeRequest(map[string]string{"type": "ticket", "subject": subj, "message": msg}); err != nil {
		logf("talep yazılamadı: %v", err)
		a.setStatus("Talep kaydedilemedi: " + err.Error())
		return
	}
	setText(a.ctl[idSubject], "")
	setText(a.ctl[idMessage], "")
	a.setStatus("Talebiniz iletildi. Birkaç saniye içinde listede görünecek. Teşekkürler!")
	a.notify("Talebiniz alındı", "BilgeceLabs destek ekibine iletildi.")
}

func (a *app) installSelected() {
	sel := int(sendMsg(a.ctl[idCatalog], lbGetCurSel, 0, 0))
	if sel < 0 || sel >= len(a.catalogIDs) {
		a.setStatus("Önce listeden bir program seçin.")
		return
	}
	name := a.st.Catalog[sel].Name
	if err := writeRequest(map[string]any{"type": "install", "catalog_id": a.catalogIDs[sel]}); err != nil {
		logf("kurulum isteği yazılamadı: %v", err)
		a.setStatus("İstek kaydedilemedi: " + err.Error())
		return
	}
	a.setStatus(name + " kurulum isteği gönderildi. Birkaç dakika içinde arka planda kurulacak.")
	a.notify(name+" kuruluyor", "Kurulum arka planda yapılacak, çalışmaya devam edebilirsiniz.")
}

func (a *app) trayMenu() {
	m, _, _ := pCreatePopupMenu.Call()
	pAppendMenuW.Call(m, mfString, menuOpen, uintptr(unsafe.Pointer(utf16("Destek penceresini aç"))))
	pAppendMenuW.Call(m, mfSeparator, 0, 0)
	pAppendMenuW.Call(m, mfString, menuAbout, uintptr(unsafe.Pointer(utf16(appTitle+" "+version))))
	var pt point
	pGetCursorPos.Call(uintptr(unsafe.Pointer(&pt)))
	pSetForegroundWindow.Call(a.hwnd)
	cmd, _, _ := pTrackPopupMenu.Call(m, tpmRightButton|tpmReturnCmd, uintptr(pt.x), uintptr(pt.y), 0, a.hwnd, 0)
	pPostMessageW.Call(a.hwnd, wmNull, 0, 0)
	pDestroyMenu.Call(m)
	if cmd == menuOpen {
		a.openUI()
	}
}

func (a *app) paint(hdc uintptr) {
	// Üst şerit: logo + ince ayraç çizgisi
	if a.logo != nil {
		h := a.s(34)
		w := a.logoW * h / a.logoH
		bmi := bitmapInfoHeader{biWidth: int32(a.logoW), biHeight: -int32(a.logoH), biPlanes: 1, biBitCount: 32}
		bmi.biSize = uint32(unsafe.Sizeof(bmi))
		pSetStretchBltMode.Call(hdc, halftone)
		pStretchDIBits.Call(hdc, uintptr(a.s(24)), uintptr(a.s(20)), uintptr(w), uintptr(h), 0, 0,
			uintptr(a.logoW), uintptr(a.logoH), uintptr(unsafe.Pointer(&a.logo[0])), uintptr(unsafe.Pointer(&bmi)), 0, srcCopy)
	}
	for _, y := range []int{72, 342} {
		r := rect{int32(a.s(24)), int32(a.s(y)), int32(a.s(clientW - 24)), int32(a.s(y) + 1)}
		pFillRect.Call(hdc, uintptr(unsafe.Pointer(&r)), a.lineBrush)
	}
}

func wndProc(hwnd, msg, wParam, lParam uintptr) uintptr {
	switch uint32(msg) {
	case wmTray:
		switch uint32(lParam) {
		case wmLButtonUp:
			a.openUI()
		case wmRButtonUp:
			a.trayMenu()
		}
		return 0
	case wmShowMain:
		a.openUI()
		return 0
	case wmCommand:
		id, code := int(wParam&0xFFFF), int(wParam>>16&0xFFFF)
		if id == idSend || id == idInstall || id == idOK {
			logf("komut: id=%d kod=%d", id, code)
		}
		if code == bnClicked {
			switch id {
			case idSend, idOK: // düğme ya da Konu alanında Enter
				a.sendTicket()
			case idInstall:
				a.installSelected()
			}
		}
		return 0
	case wmTimer:
		a.reload(true)
		return 0
	case wmCtlColorStatic:
		white, _, _ := pGetStockObject.Call(whiteBrush)
		pSetBkColor.Call(wParam, rgb(255, 255, 255))
		if a.grayLabels[lParam] {
			pSetTextColor.Call(wParam, rgb(98, 108, 124))
		}
		return white
	case wmPaint:
		var ps paintStruct
		hdc, _, _ := pBeginPaint.Call(hwnd, uintptr(unsafe.Pointer(&ps)))
		a.paint(hdc)
		pEndPaint.Call(hwnd, uintptr(unsafe.Pointer(&ps)))
		return 0
	case wmClose:
		pShowWindow.Call(hwnd, swHide) // kapatınca simgeye küçülür, program çalışmaya devam eder
		return 0
	case wmDestroy:
		pPostQuitMessage.Call(0)
		return 0
	}
	if a.taskbarCreated != 0 && uint32(msg) == a.taskbarCreated {
		a.addTrayIcon() // Explorer yeniden başladı: simgeyi geri koy
		return 0
	}
	r, _, _ := pDefWindowProcW.Call(hwnd, msg, wParam, lParam)
	return r
}
