//go:build windows

package main

import (
	"bytes"
	_ "embed"
	"image"
	"image/draw"
	"image/png"
	"math"
	"os"
	"strings"
	"time"
	"unsafe"

	"golang.org/x/sys/windows"
)

// Kurulum ekranı: çerçevesiz pencerede BilgeceLabs logosu ve altında yükleme
// çubuğu. Kurulum programı (NSIS) bunu "bilgece-destek.exe kurulum-ekrani
// <durum-dosyası>" ile başlatır; işi bitince durum dosyasına "tamam" ya da
// "hata" yazar. Görseller installer/make-splash.py ile 2x üretilir.

//go:embed kurulum.png
var splashPNG []byte

//go:embed kurulum-tamam.png
var splashDonePNG []byte

const (
	splashW, splashH = 480, 260 // mantıksal boyut (96 dpi)
	splashScale      = 2        // görseller 2x
	barX0, barX1     = 60, 420  // yükleme çubuğu yolu (make-splash.py ile aynı)
	barY0, barY1     = 214, 220

	wsPopup        = 0x80000000
	wsExLayered    = 0x00080000
	wsExTopmost    = 0x00000008
	wsExToolWindow = 0x00000080
	lwaAlpha       = 0x2
	swShowNoActive = 4
	wmEraseBkgnd   = 0x0014
	logPixelsX     = 88

	fadeIn        = 400 * time.Millisecond
	fadeOut       = 500 * time.Millisecond
	holdDone      = 4 * time.Second
	splashMaxLife = 3 * time.Minute
)

var (
	pSetLayeredWindowAttrs = user32.NewProc("SetLayeredWindowAttributes")
	pInvalidateRect        = user32.NewProc("InvalidateRect")
	pDestroyWindow         = user32.NewProc("DestroyWindow")
	pMoveFileExW           = kernel32.NewProc("MoveFileExW")
)

type splashState struct {
	statusFile string
	base, done []byte // BGRA, 2x
	frame      []byte
	pw, ph     int
	winW, winH int
	start      time.Time
	doneAt     time.Time
	closeAt    time.Time
	progress   float64
	failed     bool
}

var splash *splashState

func decodeBGRA(b []byte) ([]byte, int, int) {
	img, err := png.Decode(bytes.NewReader(b))
	if err != nil {
		return nil, 0, 0
	}
	r := img.Bounds()
	rgba := image.NewRGBA(r)
	draw.Draw(rgba, r, img, r.Min, draw.Src)
	out := make([]byte, len(rgba.Pix))
	for i := 0; i < len(out); i += 4 {
		out[i], out[i+1], out[i+2], out[i+3] = rgba.Pix[i+2], rgba.Pix[i+1], rgba.Pix[i], 255
	}
	return out, r.Dx(), r.Dy()
}

// runSplash kurulum ekranını gösterir; durum dosyası gelince kapanır.
func runSplash(statusFile string) {
	defer selfCleanup()
	base, pw, ph := decodeBGRA(splashPNG)
	done, _, _ := decodeBGRA(splashDonePNG)
	if base == nil || done == nil {
		return
	}
	dc, _, _ := pGetDC.Call(0)
	dpi, _, _ := pGetDeviceCaps.Call(dc, logPixelsX)
	pReleaseDC.Call(0, dc)
	if dpi == 0 {
		dpi = 96
	}
	s := &splashState{
		statusFile: statusFile, base: base, done: done,
		frame: make([]byte, len(base)), pw: pw, ph: ph,
		winW: splashW * int(dpi) / 96, winH: splashH * int(dpi) / 96,
		start: time.Now(),
	}
	splash = s

	hinst, _, _ := pGetModuleHandleW.Call(0)
	cursor, _, _ := pLoadCursorW.Call(0, 32512)
	cls := utf16("BilgeceKurulumEkrani")
	wc := wndClassEx{lpfnWndProc: windows.NewCallback(splashWndProc), hInstance: hinst, hCursor: cursor, lpszClass: cls}
	wc.cbSize = uint32(unsafe.Sizeof(wc))
	pRegisterClassExW.Call(uintptr(unsafe.Pointer(&wc)))

	sw, _, _ := pGetSystemMetrics.Call(0) // SM_CXSCREEN
	sh, _, _ := pGetSystemMetrics.Call(1) // SM_CYSCREEN
	x, y := (int(sw)-s.winW)/2, (int(sh)-s.winH)/2
	hwnd, _, _ := pCreateWindowExW.Call(wsExLayered|wsExTopmost|wsExToolWindow,
		uintptr(unsafe.Pointer(cls)), uintptr(unsafe.Pointer(utf16("BilgeceLabs Tahta"))),
		wsPopup, uintptr(x), uintptr(y), uintptr(s.winW), uintptr(s.winH), 0, 0, hinst, 0)
	if hwnd == 0 {
		return
	}
	pSetLayeredWindowAttrs.Call(hwnd, 0, 0, lwaAlpha) // görünmez başla, sonra belir
	pShowWindow.Call(hwnd, swShowNoActive)
	pSetTimer.Call(hwnd, 1, 33, 0) // ~30 kare/sn

	var m winMsg
	for {
		r, _, _ := pGetMessageW.Call(uintptr(unsafe.Pointer(&m)), 0, 0, 0)
		if int32(r) <= 0 {
			break
		}
		pTranslateMessage.Call(uintptr(unsafe.Pointer(&m)))
		pDispatchMessageW.Call(uintptr(unsafe.Pointer(&m)))
	}
}

func splashWndProc(hwnd, msg, wParam, lParam uintptr) uintptr {
	s := splash
	switch uint32(msg) {
	case wmTimer:
		s.tick(hwnd)
		return 0
	case wmEraseBkgnd:
		return 1
	case wmPaint:
		var ps paintStruct
		hdc, _, _ := pBeginPaint.Call(hwnd, uintptr(unsafe.Pointer(&ps)))
		s.paint(hdc)
		pEndPaint.Call(hwnd, uintptr(unsafe.Pointer(&ps)))
		return 0
	case wmDestroy:
		pPostQuitMessage.Call(0)
		return 0
	}
	r, _, _ := pDefWindowProcW.Call(hwnd, msg, wParam, lParam)
	return r
}

func (s *splashState) tick(hwnd uintptr) {
	now := time.Now()
	t := now.Sub(s.start)

	// Durum dosyası: kurulum bitti mi?
	if s.doneAt.IsZero() && s.closeAt.IsZero() {
		if b, err := os.ReadFile(s.statusFile); err == nil {
			os.Remove(s.statusFile)
			if strings.Contains(string(b), "hata") {
				s.failed = true
				s.closeAt = now
			} else {
				s.doneAt = now
			}
		} else if t > splashMaxLife {
			s.closeAt = now
		}
	}

	// İlerleme: beklerken %90'a yavaşça yaklaşır, bitince %100'e akar.
	if s.doneAt.IsZero() {
		s.progress = 0.9 * (1 - math.Exp(-t.Seconds()/4))
	} else {
		s.progress += (1 - s.progress) * 0.25
		if s.closeAt.IsZero() && now.Sub(s.doneAt) > holdDone {
			s.closeAt = now
		}
	}

	// Saydamlık: belirme / solma
	alpha := 1.0
	if t < fadeIn {
		alpha = t.Seconds() / fadeIn.Seconds()
	}
	if !s.closeAt.IsZero() {
		f := now.Sub(s.closeAt)
		if f >= fadeOut || s.failed {
			pDestroyWindow.Call(hwnd)
			return
		}
		alpha = 1 - f.Seconds()/fadeOut.Seconds()
	}
	pSetLayeredWindowAttrs.Call(hwnd, 0, uintptr(alpha*255), lwaAlpha)
	pInvalidateRect.Call(hwnd, 0, 0)
}

// paint: arka plan görseli + logonun kırmızısıyla dolan yükleme çubuğu.
func (s *splashState) paint(hdc uintptr) {
	src := s.base
	if !s.doneAt.IsZero() {
		src = s.done
	}
	copy(s.frame, src)
	k := splashScale
	x0, x1 := barX0*k, barX0*k+int(float64((barX1-barX0)*k)*s.progress)
	y0, y1 := barY0*k, barY1*k
	rad := float64(y1-y0) / 2
	for y := y0; y < y1; y++ {
		for x := x0; x < x1; x++ {
			cy := float64(y-y0) + 0.5 - rad // yuvarlak uçlar
			if dx := float64(x-x0) + 0.5 - rad; dx < 0 && dx*dx+cy*cy > rad*rad {
				continue
			}
			if dx := float64(x1-x) - 0.5 - rad; dx < 0 && dx*dx+cy*cy > rad*rad {
				continue
			}
			i := (y*s.pw + x) * 4
			s.frame[i], s.frame[i+1], s.frame[i+2] = 36, 30, 227 // BGR: #E31E24
		}
	}
	bmi := bitmapInfoHeader{biWidth: int32(s.pw), biHeight: -int32(s.ph), biPlanes: 1, biBitCount: 32}
	bmi.biSize = uint32(unsafe.Sizeof(bmi))
	pSetStretchBltMode.Call(hdc, halftone)
	pStretchDIBits.Call(hdc, 0, 0, uintptr(s.winW), uintptr(s.winH), 0, 0, uintptr(s.pw), uintptr(s.ph),
		uintptr(unsafe.Pointer(&s.frame[0])), uintptr(unsafe.Pointer(&bmi)), 0, srcCopy)
}

// selfCleanup: kurulumun geçici klasöründen çalıştıysa bu kopya yeniden
// başlatmada silinsin (çalışan exe hemen silinemez).
func selfCleanup() {
	self, err := os.Executable()
	if err != nil || !strings.HasPrefix(strings.ToLower(self), strings.ToLower(os.TempDir())) {
		return
	}
	p, _ := windows.UTF16PtrFromString(self)
	pMoveFileExW.Call(uintptr(unsafe.Pointer(p)), 0, 0x4) // MOVEFILE_DELAY_UNTIL_REBOOT
}
