//go:build windows

package main

import (
	"fmt"
	"syscall"
	"unsafe"

	"golang.org/x/sys/windows"
	"golang.org/x/sys/windows/registry"
)

var (
	wtsapi32                 = windows.NewLazySystemDLL("wtsapi32.dll")
	procWTSSendMessageW      = wtsapi32.NewProc("WTSSendMessageW")
	procWTSQuerySessionInfoW = wtsapi32.NewProc("WTSQuerySessionInformationW")
	procWTSFreeMemory        = wtsapi32.NewProc("WTSFreeMemory")
	kernel32                 = windows.NewLazySystemDLL("kernel32.dll")
	procGetTickCount64       = kernel32.NewProc("GetTickCount64")
)

const noSession = 0xFFFFFFFF

func activeSession() uint32 { return windows.WTSGetActiveConsoleSessionId() }

func activeUserName() string {
	sid := activeSession()
	if sid == noSession {
		return ""
	}
	const wtsUserName = 5
	var buf *uint16
	var n uint32
	r, _, _ := procWTSQuerySessionInfoW.Call(0, uintptr(sid), wtsUserName,
		uintptr(unsafe.Pointer(&buf)), uintptr(unsafe.Pointer(&n)))
	if r == 0 || buf == nil {
		return ""
	}
	defer procWTSFreeMemory.Call(uintptr(unsafe.Pointer(buf)))
	return windows.UTF16PtrToString(buf)
}

func uptimeSeconds() int64 {
	r, _, _ := procGetTickCount64.Call()
	return int64(r / 1000)
}

func osVersion() string {
	k, err := registry.OpenKey(registry.LOCAL_MACHINE, `SOFTWARE\Microsoft\Windows NT\CurrentVersion`, registry.QUERY_VALUE)
	if err != nil {
		return "Windows"
	}
	defer k.Close()
	name, _, _ := k.GetStringValue("ProductName")
	disp, _, _ := k.GetStringValue("DisplayVersion")
	build, _, _ := k.GetStringValue("CurrentBuild")
	// Windows 11 kayıt defterinde hâlâ "Windows 10" yazar; build numarasından düzelt.
	if len(build) >= 5 && build >= "22000" && len(name) > 10 && name[:10] == "Windows 10" {
		name = "Windows 11" + name[10:]
	}
	return fmt.Sprintf("%s %s (build %s)", name, disp, build)
}

// sendMessage aktif oturumda mesaj kutusu gösterir (beklemeden döner).
func sendMessage(title, text string, timeoutS int) error {
	sid := activeSession()
	if sid == noSession {
		return fmt.Errorf("aktif oturum yok")
	}
	t, _ := windows.UTF16FromString(title)
	m, _ := windows.UTF16FromString(text)
	const style = 0x40 | 0x40000 // MB_ICONINFORMATION | MB_TOPMOST
	var resp uint32
	r, _, err := procWTSSendMessageW.Call(0, uintptr(sid),
		uintptr(unsafe.Pointer(&t[0])), uintptr((len(t)-1)*2),
		uintptr(unsafe.Pointer(&m[0])), uintptr((len(m)-1)*2),
		style, uintptr(timeoutS), uintptr(unsafe.Pointer(&resp)), 0)
	if r == 0 {
		return err
	}
	return nil
}

// startInSessionAsSystem verilen komutu aktif kullanıcının masaüstünde,
// ama SYSTEM hesabıyla başlatır. Böylece öğrenci süreci kapatamaz.
func startInSessionAsSystem(cmdline string) (windows.Handle, error) {
	sid := activeSession()
	if sid == noSession {
		return 0, fmt.Errorf("aktif oturum yok")
	}
	var tok windows.Token
	if err := windows.OpenProcessToken(windows.CurrentProcess(), windows.TOKEN_ALL_ACCESS, &tok); err != nil {
		return 0, fmt.Errorf("OpenProcessToken: %w", err)
	}
	defer tok.Close()
	var dup windows.Token
	if err := windows.DuplicateTokenEx(tok, windows.MAXIMUM_ALLOWED, nil,
		windows.SecurityImpersonation, windows.TokenPrimary, &dup); err != nil {
		return 0, fmt.Errorf("DuplicateTokenEx: %w", err)
	}
	defer dup.Close()
	if err := windows.SetTokenInformation(dup, windows.TokenSessionId,
		(*byte)(unsafe.Pointer(&sid)), uint32(unsafe.Sizeof(sid))); err != nil {
		return 0, fmt.Errorf("SetTokenInformation: %w", err)
	}
	si := windows.StartupInfo{Desktop: windows.StringToUTF16Ptr(`winsta0\default`)}
	si.Cb = uint32(unsafe.Sizeof(si))
	var pi windows.ProcessInformation
	cl, err := syscall.UTF16PtrFromString(cmdline)
	if err != nil {
		return 0, err
	}
	if err := windows.CreateProcessAsUser(dup, nil, cl, nil, nil, false,
		windows.CREATE_UNICODE_ENVIRONMENT, nil, nil, &si, &pi); err != nil {
		return 0, fmt.Errorf("CreateProcessAsUser: %w", err)
	}
	windows.CloseHandle(pi.Thread)
	return pi.Process, nil
}

func processAlive(h windows.Handle) bool {
	if h == 0 {
		return false
	}
	ev, err := windows.WaitForSingleObject(h, 0)
	return err == nil && ev == uint32(windows.WAIT_TIMEOUT)
}
