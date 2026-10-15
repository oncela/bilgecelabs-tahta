//go:build windows

package main

import (
	"unsafe"

	"golang.org/x/sys/windows"
)

// Win32 bağlantıları (cgo yok). Yalnız bu programın kullandıkları.

var (
	user32   = windows.NewLazySystemDLL("user32.dll")
	gdi32    = windows.NewLazySystemDLL("gdi32.dll")
	shell32  = windows.NewLazySystemDLL("shell32.dll")
	kernel32 = windows.NewLazySystemDLL("kernel32.dll")

	pRegisterClassExW       = user32.NewProc("RegisterClassExW")
	pCreateWindowExW        = user32.NewProc("CreateWindowExW")
	pDefWindowProcW         = user32.NewProc("DefWindowProcW")
	pGetMessageW            = user32.NewProc("GetMessageW")
	pTranslateMessage       = user32.NewProc("TranslateMessage")
	pDispatchMessageW       = user32.NewProc("DispatchMessageW")
	pIsDialogMessageW       = user32.NewProc("IsDialogMessageW")
	pPostQuitMessage        = user32.NewProc("PostQuitMessage")
	pPostMessageW           = user32.NewProc("PostMessageW")
	pSendMessageW           = user32.NewProc("SendMessageW")
	pShowWindow             = user32.NewProc("ShowWindow")
	pSetForegroundWindow    = user32.NewProc("SetForegroundWindow")
	pSetWindowPos           = user32.NewProc("SetWindowPos")
	pSetWindowTextW         = user32.NewProc("SetWindowTextW")
	pGetWindowTextW         = user32.NewProc("GetWindowTextW")
	pGetWindowTextLengthW   = user32.NewProc("GetWindowTextLengthW")
	pSetTimer               = user32.NewProc("SetTimer")
	pBeginPaint             = user32.NewProc("BeginPaint")
	pEndPaint               = user32.NewProc("EndPaint")
	pFillRect               = user32.NewProc("FillRect")
	pGetDC                  = user32.NewProc("GetDC")
	pReleaseDC              = user32.NewProc("ReleaseDC")
	pLoadImageW             = user32.NewProc("LoadImageW")
	pLoadCursorW            = user32.NewProc("LoadCursorW")
	pCreatePopupMenu        = user32.NewProc("CreatePopupMenu")
	pAppendMenuW            = user32.NewProc("AppendMenuW")
	pTrackPopupMenu         = user32.NewProc("TrackPopupMenu")
	pDestroyMenu            = user32.NewProc("DestroyMenu")
	pGetCursorPos           = user32.NewProc("GetCursorPos")
	pRegisterWindowMessageW = user32.NewProc("RegisterWindowMessageW")
	pFindWindowW            = user32.NewProc("FindWindowW")
	pSystemParametersInfoW  = user32.NewProc("SystemParametersInfoW")
	pAdjustWindowRectEx     = user32.NewProc("AdjustWindowRectEx")
	pIsWindowVisible        = user32.NewProc("IsWindowVisible")
	pEnableWindow           = user32.NewProc("EnableWindow")
	pGetSystemMetrics       = user32.NewProc("GetSystemMetrics")
	pGetDeviceCaps          = gdi32.NewProc("GetDeviceCaps")
	pCreateFontW            = gdi32.NewProc("CreateFontW")
	pCreateSolidBrush       = gdi32.NewProc("CreateSolidBrush")
	pGetStockObject         = gdi32.NewProc("GetStockObject")
	pSetBkColor             = gdi32.NewProc("SetBkColor")
	pSetTextColor           = gdi32.NewProc("SetTextColor")
	pStretchDIBits          = gdi32.NewProc("StretchDIBits")
	pSetStretchBltMode      = gdi32.NewProc("SetStretchBltMode")
	pShellNotifyIconW       = shell32.NewProc("Shell_NotifyIconW")
	pGetModuleHandleW       = kernel32.NewProc("GetModuleHandleW")
	pCreateMutexW           = kernel32.NewProc("CreateMutexW")
)

const (
	wmCreate         = 0x0001
	wmDestroy        = 0x0002
	wmPaint          = 0x000F
	wmClose          = 0x0010
	wmSetFont        = 0x0030
	wmCommand        = 0x0111
	wmTimer          = 0x0113
	wmCtlColorEdit   = 0x0133
	wmCtlColorStatic = 0x0138
	wmLButtonUp      = 0x0202
	wmRButtonUp      = 0x0205
	wmNull           = 0x0000
	wmApp            = 0x8000
	wmTray           = wmApp + 1 // tepsi simgesi olayları
	wmShowMain       = wmApp + 2 // ikinci kopya "pencereyi göster" der

	wsChild         = 0x40000000
	wsVisible       = 0x10000000
	wsTabStop       = 0x00010000
	wsVScroll       = 0x00200000
	wsCaption       = 0x00C00000
	wsSysMenu       = 0x00080000
	wsMinimizeBox   = 0x00020000
	wsExClientEdge  = 0x00000200
	esAutoHScroll   = 0x0080
	esAutoVScroll   = 0x0040
	esMultiline     = 0x0004
	esWantReturn    = 0x1000
	ssRight         = 0x0002
	ssLeftNoWrap    = 0x000C
	bsPushButton    = 0x0000
	bsDefPushButton = 0x0001
	lbsNotify       = 0x0001
	lbsNoIntegral   = 0x0100
	lbAddString     = 0x0180
	lbResetContent  = 0x0184
	lbGetCurSel     = 0x0188
	lbSetCurSel     = 0x0186
	emSetCueBanner  = 0x1501
	emLimitText     = 0x00C5
	bnClicked       = 0
	swHide          = 0
	swShow          = 5
	swRestore       = 9
	swpNoZOrder     = 0x0004
	logPixelsY      = 90
	spiGetWorkArea  = 0x0030
	imageIcon       = 1
	lrDefaultColor  = 0
	mfString        = 0x0000
	mfSeparator     = 0x0800
	tpmRightButton  = 0x0002
	tpmReturnCmd    = 0x0100
	whiteBrush      = 0
	halftone        = 4
	srcCopy         = 0x00CC0020
	smCxSmIcon      = 49
	smCySmIcon      = 50

	nimAdd     = 0
	nimModify  = 1
	nimDelete  = 2
	nifMessage = 0x01
	nifIcon    = 0x02
	nifTip     = 0x04
	nifInfo    = 0x10
	niifInfo   = 0x01
	niifUser   = 0x04
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
	rcPaint     rect
	fRestore    int32
	fIncUpdate  int32
	rgbReserved [32]byte
}

type rect struct{ left, top, right, bottom int32 }
type point struct{ x, y int32 }

type bitmapInfoHeader struct {
	biSize                       uint32
	biWidth, biHeight            int32
	biPlanes, biBitCount         uint16
	biCompression, biSizeImage   uint32
	biXPelsPerMeter, biYPelsPerM int32
	biClrUsed, biClrImportant    uint32
}

// notifyIconData: NOTIFYICONDATAW (Vista+ boyutu, 64 bit'te 976 bayt).
type notifyIconData struct {
	cbSize           uint32
	hWnd             uintptr
	uID              uint32
	uFlags           uint32
	uCallbackMessage uint32
	hIcon            uintptr
	szTip            [128]uint16
	dwState          uint32
	dwStateMask      uint32
	szInfo           [256]uint16
	uVersion         uint32
	szInfoTitle      [64]uint16
	dwInfoFlags      uint32
	guidItem         windows.GUID
	hBalloonIcon     uintptr
}

func utf16(s string) *uint16 { p, _ := windows.UTF16PtrFromString(s); return p }

func copyUTF16(dst []uint16, s string) {
	u, _ := windows.UTF16FromString(s)
	if len(u) > len(dst) {
		u = u[:len(dst)-1]
		u = append(u, 0)
	}
	copy(dst, u)
}

func sendMsg(hwnd uintptr, msg uint32, w, l uintptr) uintptr {
	r, _, _ := pSendMessageW.Call(hwnd, uintptr(msg), w, l)
	return r
}

func windowText(hwnd uintptr) string {
	n, _, _ := pGetWindowTextLengthW.Call(hwnd)
	if n == 0 {
		return ""
	}
	buf := make([]uint16, n+1)
	pGetWindowTextW.Call(hwnd, uintptr(unsafe.Pointer(&buf[0])), n+1)
	return windows.UTF16ToString(buf)
}

func setText(hwnd uintptr, s string) {
	pSetWindowTextW.Call(hwnd, uintptr(unsafe.Pointer(utf16(s))))
}

func rgb(r, g, b byte) uintptr { return uintptr(r) | uintptr(g)<<8 | uintptr(b)<<16 }
