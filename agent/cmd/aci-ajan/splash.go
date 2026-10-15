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
	"path/filepath"
	"runtime"
	"strings"
	"time"
	"unsafe"

	"golang.org/x/sys/windows"
)

// Kurulum ekranı: çerçevesiz pencerede BilgeceLabs logosu ve altında yükleme
// çubuğu. Kurulum programı (NSIS) bunu "kurulum-ekrani <durum-dosyası>" ile
// başlatır ve işi bitince durum dosyasına "tamam" ya da "hata" yazar.
// Görseller installer/make-splash.py ile 2x çözünürlükte üretilir.

//go:embed assets/kurulum.png
var splashPNG []byte

//go:embed assets/kurulum-tamam.png
var splashDonePNG []byte

const (
	splashW, splashH = 480, 260 // mantıksal boyut (96 dpi)
	splashScale      = 2        // görseller 2x
	barX0, barX1     = 60, 420  // yükleme çubuğu yolu (make-splash.py ile aynı)
	barY0, barY1     = 214, 220
)

var (
	user32                    = windows.NewLazySystemDLL("user32.dll")
	gdi32                     = windows.NewLazySystemDLL("gdi32.dll")
	procRegisterClassExW      = user32.NewProc("RegisterClassExW")
	procCreateWindowExW       = user32.NewProc("CreateWindowExW")
	procDefWindowProcW        = user32.NewProc("DefWindowProcW")
	procGetMessageW           = user32.NewProc("GetMessageW")
	procTranslateMessage      = user32.NewProc("TranslateMessage")
	procDispatchMessageW      = user32.NewProc("DispatchMessageW")
	procPostQuitMessage       = user32.NewProc("PostQuitMessage")
	procSetTimer              = user32.NewProc("SetTimer")
	procInvalidateRect        = user32.NewProc("InvalidateRect")
	procBeginPaint            = user32.NewProc("BeginPaint")
	procEndPaint              = user32.NewProc("EndPaint")
	procSetLayeredWindowAttrs = user32.NewProc("SetLayeredWindowAttributes")
	procGetSystemMetrics      = user32.NewProc("GetSystemMetrics")
	procShowWindow            = user32.NewProc("ShowWindow")
	procDestroyWindow         = user32.NewProc("DestroyWindow")
	procGetDC                 = user32.NewProc("GetDC")
	procReleaseDC             = user32.NewProc("ReleaseDC")
	procGetDeviceCaps         = gdi32.NewProc("GetDeviceCaps")
	procStretchDIBits         = gdi32.NewProc("StretchDIBits")
	procSetStretchBltMode     = gdi32.NewProc("SetStretchBltMode")
	procMoveFileExW           = kernel32.NewProc("MoveFileExW")
	procGetModuleHandleW      = kernel32.NewProc("GetModuleHandleW")
	procLoadCursorW           = user32.NewProc("LoadCursorW")
)

type wndClassEx struct {
	cbSize, style           uint32
	lpfnWndProc             uintptr
	cbClsExtra, cbWndExtra  int32
	hInstance, hIcon        uintptr
	hCursor, hbrBackground  uintptr
	lpszMenuName, lpszClass *uint16
	hIconSm                 uintptr
}

type winMsg struct {
	hwnd           uintptr
	message        uint32
	wParam, lParam uintptr
	time           uint32
	ptX, ptY       int32
	lPrivate       uint32
}

type paintStruct struct {
	hdc         uintptr
	fErase      int32
	rcPaint     [4]int32
	fRestore    int32
	fIncUpdate  int32
	rgbReserved [32]byte
}

type bitmapInfoHeader struct {
	biSize                       uint32
	biWidth, biHeight            int32
	biPlanes, biBitCount         uint16
	biCompression, biSizeImage   uint32
	biXPelsPerMeter, biYPelsPerM int32
	biClrUsed, biClrImportant    uint32
}

// splashState animasyon durumunu tutar (tek pencere, tek iş parçacığı).
type splashState struct {
	statusFile string
	base, done []byte // BGRA, 2x
	frame      []byte
	pw, ph     int // piksel boyutu (2x)
	winW, winH int
	start      time.Time
	doneAt     time.Time // "tamam" geldiği an
	closeAt    time.Time // kapanış (solma) başlangıcı
	progress   float64
	failed     bool
}

var splash *splashState

const (
	wmDestroy       = 0x0002
	wmPaint         = 0x000F
	wmEraseBkgnd    = 0x0014
	wmTimer         = 0x0113
	wsPopup         = 0x80000000
	wsExLayered     = 0x00080000
	wsExTopmost     = 0x00000008
	wsExToolWindow  = 0x00000080
	lwaAlpha        = 0x2
	swShowNoActive  = 4
	halftone        = 4
	srcCopy         = 0x00CC0020
	logPixelsX      = 88
	fadeIn          = 400 * time.Millisecond
	fadeOut         = 500 * time.Millisecond
	holdDone        = 4 * time.Second
	splashMaxLife   = 3 * time.Minute
	moveDelayReboot = 0x4
)

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
	runtime.LockOSThread()
	defer selfCleanup()

	base, pw, ph := decodeBGRA(splashPNG)
	done, _, _ := decodeBGRA(splashDonePNG)
	if base == nil || done == nil {
		return
	}
	dc, _, _ := procGetDC.Call(0)
	dpi, _, _ := procGetDeviceCaps.Call(dc, logPixelsX)
	procReleaseDC.Call(0, dc)
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

	className := windows.StringToUTF16Ptr("BilgeceKurulumEkrani")
	hinst, _, _ := procGetModuleHandleW.Call(0)
	cursor, _, _ := procLoadCursorW.Call(0, 32512) // IDC_ARROW
	wc := wndClassEx{
		lpfnWndProc: windows.NewCallback(splashWndProc),
		hInstance:   hinst,
		hCursor:     cursor,
		lpszClass:   className,
	}
	wc.cbSize = uint32(unsafe.Sizeof(wc))
	procRegisterClassExW.Call(uintptr(unsafe.Pointer(&wc)))

	sw, _, _ := procGetSystemMetrics.Call(0) // SM_CXSCREEN
	sh, _, _ := procGetSystemMetrics.Call(1) // SM_CYSCREEN
	x, y := (int(sw)-s.winW)/2, (int(sh)-s.winH)/2
	hwnd, _, _ := procCreateWindowExW.Call(wsExLayered|wsExTopmost|wsExToolWindow,
		uintptr(unsafe.Pointer(className)), uintptr(unsafe.Pointer(windows.StringToUTF16Ptr(productName))),
		wsPopup, uintptr(x), uintptr(y), uintptr(s.winW), uintptr(s.winH), 0, 0, hinst, 0)
	if hwnd == 0 {
		return
	}
	procSetLayeredWindowAttrs.Call(hwnd, 0, 0, lwaAlpha) // görünmez başla, sonra belir
	procShowWindow.Call(hwnd, swShowNoActive)
	procSetTimer.Call(hwnd, 1, 33, 0) // ~30 kare/sn

	var m winMsg
	for {
		r, _, _ := procGetMessageW.Call(uintptr(unsafe.Pointer(&m)), 0, 0, 0)
		if int32(r) <= 0 {
			break
		}
		procTranslateMessage.Call(uintptr(unsafe.Pointer(&m)))
		procDispatchMessageW.Call(uintptr(unsafe.Pointer(&m)))
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
		hdc, _, _ := procBeginPaint.Call(hwnd, uintptr(unsafe.Pointer(&ps)))
		s.paint(hdc)
		procEndPaint.Call(hwnd, uintptr(unsafe.Pointer(&ps)))
		return 0
	case wmDestroy:
		procPostQuitMessage.Call(0)
		return 0
	}
	r, _, _ := procDefWindowProcW.Call(hwnd, msg, wParam, lParam)
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
			procDestroyWindow.Call(hwnd)
			return
		}
		alpha = 1 - f.Seconds()/fadeOut.Seconds()
	}
	procSetLayeredWindowAttrs.Call(hwnd, 0, uintptr(alpha*255), lwaAlpha)
	procInvalidateRect.Call(hwnd, 0, 0)
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
			// yuvarlak uçlar
			cy := float64(y-y0) + 0.5 - rad
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
	procSetStretchBltMode.Call(hdc, halftone)
	procStretchDIBits.Call(hdc, 0, 0, uintptr(s.winW), uintptr(s.winH), 0, 0, uintptr(s.pw), uintptr(s.ph),
		uintptr(unsafe.Pointer(&s.frame[0])), uintptr(unsafe.Pointer(&bmi)), 0, srcCopy)
}

// selfCleanup: ekran geçici klasördeki bir kopyadan çalıştıysa, o kopya
// yeniden başlatmada silinsin (çalışan exe hemen silinemez).
func selfCleanup() {
	self, err := os.Executable()
	if err != nil {
		return
	}
	tmp := os.TempDir()
	if rel, err := filepath.Rel(tmp, self); err == nil && !strings.HasPrefix(rel, "..") {
		p, _ := windows.UTF16PtrFromString(self)
		procMoveFileExW.Call(uintptr(unsafe.Pointer(p)), 0, moveDelayReboot)
	}
}
